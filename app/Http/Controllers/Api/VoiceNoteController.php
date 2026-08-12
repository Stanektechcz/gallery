<?php

namespace App\Http\Controllers\Api;

use App\Support\AudioUploads;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\GallerySpace;
use App\Models\VoiceNote;
use App\Models\VoiceNoteListen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VoiceNoteController extends Controller
{
    private const DISK = 'local';

    public function index(Request $request): JsonResponse
    {
        $this->available();
        $user = $request->user();
        $space = $this->space($request, $request->integer('gallery_space_id') ?: null);

        $notes = VoiceNote::with('author:id,name')
            ->where('gallery_space_id', $space->id)
            ->orderByDesc('recorded_at')->orderByDesc('id')
            ->limit(200)->get();

        $heard = VoiceNoteListen::whereIn('voice_note_id', $notes->pluck('id'))
            ->where('user_id', $user->id)->pluck('voice_note_id')->all();

        return response()->json([
            'space_id' => $space->id,
            'notes' => $notes->map(fn (VoiceNote $note) => $this->payload($note, in_array($note->id, $heard, true)))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->available();
        $this->write($request);
        $data = $request->validate([
            'gallery_space_id' => 'nullable|integer',
            'audio' => 'required|file|max:25600|' . AudioUploads::rule(),
            'title' => 'nullable|string|max:180',
            'duration_ms' => 'nullable|integer|between:200,1800000',
            'transcript' => 'nullable|string|max:5000',
        ]);

        $space = $this->space($request, $data['gallery_space_id'] ?? null);
        $file = $request->file('audio');
        // Private disk: audio is only ever reachable through stream() below.
        $path = $file->store("voice-notes/{$space->id}", self::DISK);
        abort_unless($path, 500, 'Nahrávku se nepodařilo uložit.');

        $note = VoiceNote::create([
            'gallery_space_id' => $space->id,
            'created_by' => $request->user()->id,
            'title' => $data['title'] ?? null,
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'duration_ms' => $data['duration_ms'] ?? null,
            'transcript' => $data['transcript'] ?? null,
            'recorded_at' => now(),
        ]);

        return response()->json($this->payload($note->fresh('author'), true), 201);
    }

    /**
     * Keeps a voice message from a conversation on the timeline.
     *
     * The file is copied, not referenced. A chat message can be deleted, and a note pinned
     * because it mattered should not disappear with the conversation it happened in — the
     * whole reason for pinning is that the timeline outlives the chat.
     *
     * Once per message, enforced by a unique column rather than by the button being
     * disabled: two people can press it at the same moment.
     */
    public function pinFromChat(Request $request, string $messageUuid): JsonResponse
    {
        $this->available();
        $this->write($request);

        abort_unless(Schema::hasColumn('voice_notes', 'source_message_uuid'), 503,
            'Pro připínání hlasovek dokončete databázové migrace.');

        $message = ChatMessage::where('uuid', $messageUuid)->firstOrFail();
        abort_unless($message->attachment_type === 'voice' && $message->media_path, 422,
            'Tahle zpráva není hlasovka.');

        // Readable by this person, not merely existing: a uuid is not a permission.
        abort_unless(
            Conversation::where('id', $message->conversation_id)->forUser($request->user())->exists(),
            403, 'K téhle konverzaci nemáte přístup.',
        );

        $existing = VoiceNote::where('source_message_uuid', $messageUuid)->first();
        if ($existing) return response()->json($this->payload($existing->fresh('author'), true));

        $space = $this->space($request, $message->gallery_space_id);

        $source = Storage::disk(self::DISK)->exists($message->media_path) ? self::DISK : 'local';
        abort_unless(Storage::disk($source)->exists($message->media_path), 404, 'Zvuk zprávy se nepodařilo najít.');

        $path = "voice-notes/{$space->id}/" . Str::uuid() . '.' . (pathinfo($message->media_path, PATHINFO_EXTENSION) ?: 'webm');
        Storage::disk(self::DISK)->put($path, Storage::disk($source)->get($message->media_path));

        $note = VoiceNote::create([
            'gallery_space_id' => $space->id,
            'created_by' => $message->created_by,
            'source_message_uuid' => $messageUuid,
            'title' => 'Z konverzace',
            'path' => $path,
            'mime_type' => $message->media_mime,
            'size_bytes' => $message->media_size,
            // Chat stores a voice message's length in media_height; see ChatController.
            'duration_ms' => $message->media_height ?: null,
            // The moment it was said, not the moment somebody pinned it. A timeline
            // ordered by when things were noticed rather than when they happened is not
            // a timeline.
            'recorded_at' => $message->created_at,
        ]);

        return response()->json($this->payload($note->fresh('author'), true), 201);
    }

    /** Streams the audio to a member of the owning space; never a public URL. */
    public function stream(Request $request, string $uuid): StreamedResponse
    {
        $this->available();
        $note = $this->note($request, $uuid);
        abort_unless(Storage::disk(self::DISK)->exists($note->path), 404);

        return Storage::disk(self::DISK)->response($note->path, null, [
            'Content-Type' => $note->mime_type,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function markListened(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $note = $this->note($request, $uuid);
        VoiceNoteListen::updateOrCreate(
            ['voice_note_id' => $note->id, 'user_id' => $request->user()->id],
            ['listened_at' => now()]
        );

        return response()->json(['listened' => true]);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $this->write($request);
        $note = $this->note($request, $uuid);
        $data = $request->validate(['title' => 'nullable|string|max:180', 'transcript' => 'nullable|string|max:5000']);
        $note->update($data);

        return response()->json($this->payload($note->fresh('author'), true));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $this->write($request);
        $note = $this->note($request, $uuid);
        // Only the person who recorded it may delete it.
        abort_unless($note->created_by === $request->user()->id, 403, 'Smazat hlasovku může jen ten, kdo ji nahrál.');

        Storage::disk(self::DISK)->delete($note->path);
        $note->delete();

        return response()->json(['deleted' => true]);
    }

    private function payload(VoiceNote $note, bool $heard): array
    {
        return [
            'uuid' => $note->uuid,
            'title' => $note->title,
            'author' => ['id' => $note->author?->id, 'name' => $note->author?->name],
            'duration_ms' => $note->duration_ms,
            'size_bytes' => $note->size_bytes,
            'transcript' => $note->transcript,
            'recorded_at' => optional($note->recorded_at)->toIso8601String(),
            'stream_url' => "/api/v1/voice-notes/{$note->uuid}/stream",
            'heard' => $heard,
            // Where it came from. Only whether it was pinned, not from which message —
            // the timeline has no use for the identifier and it would be one more thing
            // travelling to the browser for nothing.
            'from_chat' => (bool) $note->source_message_uuid,
            'can_delete' => $note->created_by === request()->user()?->id,
        ];
    }

    private function note(Request $request, string $uuid): VoiceNote
    {
        return VoiceNote::with('author:id,name')->where('uuid', $uuid)
            ->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))
            ->firstOrFail();
    }

    private function space(Request $request, ?int $id): GallerySpace
    {
        $query = GallerySpace::whereHas('members', fn ($members) => $members->whereKey($request->user()->id));

        return $id ? $query->findOrFail($id) : $query->orderByDesc('is_default')->firstOrFail();
    }

    private function write(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze nahrávat hlasovky.');
    }

    private function available(): void
    {
        abort_unless(Schema::hasTable('voice_notes'), 503, 'Pro hlasovky dokončete databázové migrace.');
    }
}
