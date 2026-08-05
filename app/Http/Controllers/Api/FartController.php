<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Fart;
use App\Models\FartRating;
use App\Models\GallerySpace;
use App\Models\VoiceNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Companion to the burp module with its own criteria — a burp is judged on artistry, a
 * fart on aroma and stealth. Route registration applies `module:farts`, so everything
 * here already sits behind the entitlement check.
 *
 * Audio may either be recorded on the spot or attached from an existing voice note.
 */
class FartController extends Controller
{
    private const DISK = 'local';
    private const ALLOWED_MIME = ['audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/x-m4a', 'audio/aac'];
    private const CRITERIA = ['loudness', 'aroma', 'stealth', 'timing'];

    public function index(Request $request): JsonResponse
    {
        $this->available();
        $user = $request->user();
        $space = $this->space($request, $request->integer('gallery_space_id') ?: null);

        $farts = Fart::with(['author:id,name', 'ratings.user:id,name', 'voiceNote:id,uuid,title'])
            ->where('gallery_space_id', $space->id)
            ->orderByDesc('happened_at')->orderByDesc('id')
            ->limit(200)->get();

        return response()->json([
            'space_id' => $space->id,
            'farts' => $farts->map(fn (Fart $fart) => $this->payload($fart, $user->id))->values(),
            'leaderboard' => $this->leaderboard($farts),
            'champion' => $this->championOfMonth($farts),
            // Lets the UI offer existing recordings instead of only a fresh one.
            'voice_notes' => VoiceNote::where('gallery_space_id', $space->id)
                ->orderByDesc('recorded_at')->limit(50)
                ->get(['uuid', 'title', 'duration_ms', 'recorded_at'])
                ->map(fn (VoiceNote $note) => [
                    'uuid' => $note->uuid, 'title' => $note->title,
                    'duration_ms' => $note->duration_ms,
                    'recorded_at' => optional($note->recorded_at)->toIso8601String(),
                ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->available();
        $this->write($request);
        $data = $request->validate([
            'gallery_space_id' => 'nullable|integer',
            'title' => 'nullable|string|max:180',
            'occasion' => 'nullable|string|max:120',
            'happened_at' => 'nullable|date',
            'duration_ms' => 'nullable|integer|between:100,120000',
            'audio' => 'nullable|file|max:10240|mimetypes:' . implode(',', self::ALLOWED_MIME),
            // Attach an existing recording rather than making a new one.
            'voice_note_uuid' => 'nullable|uuid',
        ]);

        $space = $this->space($request, $data['gallery_space_id'] ?? null);
        $path = null; $mime = null; $size = null; $voiceNoteId = null;

        if ($request->hasFile('audio')) {
            $file = $request->file('audio');
            $path = $file->store("farts/{$space->id}", self::DISK);
            abort_unless($path, 500, 'Nahrávku se nepodařilo uložit.');
            $mime = $file->getClientMimeType();
            $size = $file->getSize();
        } elseif (! empty($data['voice_note_uuid'])) {
            $note = VoiceNote::where('uuid', $data['voice_note_uuid'])
                ->where('gallery_space_id', $space->id)->firstOrFail();
            $voiceNoteId = $note->id;
        }

        $fart = Fart::create([
            'gallery_space_id' => $space->id,
            'created_by' => $request->user()->id,
            'title' => $data['title'] ?? null,
            'occasion' => $data['occasion'] ?? null,
            'duration_ms' => $data['duration_ms'] ?? null,
            'path' => $path, 'mime_type' => $mime, 'size_bytes' => $size,
            'voice_note_id' => $voiceNoteId,
            'happened_at' => isset($data['happened_at']) ? Carbon::parse($data['happened_at']) : now(),
        ]);

        return response()->json($this->payload($fart->fresh(['author', 'ratings.user', 'voiceNote']), $request->user()->id), 201);
    }

    public function rate(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $this->write($request);
        $fart = $this->fart($request, $uuid);
        abort_if($fart->created_by === $request->user()->id, 422, 'Vlastní úlovek si ohodnotit nemůžete.');

        $rules = ['comment' => 'nullable|string|max:400'];
        foreach (self::CRITERIA as $criterion) $rules[$criterion] = 'required|integer|between:1,5';
        $data = $request->validate($rules);

        $score = round(array_sum(array_map(fn ($key) => (int) $data[$key], self::CRITERIA)) / count(self::CRITERIA), 2);
        FartRating::updateOrCreate(
            ['fart_id' => $fart->id, 'user_id' => $request->user()->id],
            collect($data)->only([...self::CRITERIA, 'comment'])->all() + ['score' => $score]
        );

        return response()->json($this->payload($fart->fresh(['author', 'ratings.user', 'voiceNote']), $request->user()->id));
    }

    public function stream(Request $request, string $uuid): StreamedResponse
    {
        $this->available();
        $fart = $this->fart($request, $uuid);

        // Either its own recording or the voice note it was attached to.
        $path = $fart->path ?: $fart->voiceNote?->path;
        $mime = $fart->mime_type ?: $fart->voiceNote?->mime_type ?: 'audio/webm';
        abort_unless($path && Storage::disk(self::DISK)->exists($path), 404);

        return Storage::disk(self::DISK)->response($path, null, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $this->write($request);
        $fart = $this->fart($request, $uuid);
        abort_unless($fart->created_by === $request->user()->id, 403, 'Smazat záznam může jen jeho autor.');

        // Only its own file is removed; an attached voice note belongs to its library.
        if ($fart->path) Storage::disk(self::DISK)->delete($fart->path);
        $fart->delete();

        return response()->json(['deleted' => true]);
    }

    private function payload(Fart $fart, int $viewerId): array
    {
        $ratings = $fart->ratings;
        $mine = $ratings->firstWhere('user_id', $viewerId);
        $hasAudio = (bool) ($fart->path || $fart->voice_note_id);

        return [
            'uuid' => $fart->uuid,
            'title' => $fart->title,
            'occasion' => $fart->occasion,
            'duration_ms' => $fart->duration_ms,
            'happened_at' => optional($fart->happened_at)->toIso8601String(),
            'author' => ['id' => $fart->author?->id, 'name' => $fart->author?->name],
            'has_audio' => $hasAudio,
            'from_voice_note' => $fart->voiceNote ? ['uuid' => $fart->voiceNote->uuid, 'title' => $fart->voiceNote->title] : null,
            'stream_url' => $hasAudio ? "/api/v1/farts/{$fart->uuid}/stream" : null,
            'average_score' => $ratings->count() ? round($ratings->avg('score'), 2) : null,
            'ratings' => $ratings->map(fn (FartRating $rating) => [
                'user' => ['id' => $rating->user?->id, 'name' => $rating->user?->name],
                'loudness' => $rating->loudness, 'aroma' => $rating->aroma,
                'stealth' => $rating->stealth, 'timing' => $rating->timing,
                'score' => $rating->score, 'comment' => $rating->comment,
            ])->values(),
            'my_rating' => $mine ? [
                'loudness' => $mine->loudness, 'aroma' => $mine->aroma,
                'stealth' => $mine->stealth, 'timing' => $mine->timing, 'comment' => $mine->comment,
            ] : null,
            'can_rate' => $fart->created_by !== $viewerId,
            'can_delete' => $fart->created_by === $viewerId,
        ];
    }

    private function leaderboard($farts): array
    {
        return $farts->filter(fn (Fart $fart) => $fart->ratings->count() > 0)
            ->groupBy('created_by')
            ->map(fn ($group) => [
                'user' => ['id' => $group->first()->author?->id, 'name' => $group->first()->author?->name],
                'farts' => $group->count(),
                'average_score' => round($group->flatMap->ratings->avg('score'), 2),
                'best_score' => round($group->flatMap->ratings->max('score'), 2),
            ])
            ->sortByDesc('average_score')->values()->all();
    }

    private function championOfMonth($farts): ?array
    {
        $month = $farts->filter(fn (Fart $fart) => $fart->happened_at?->isSameMonth(now()) && $fart->ratings->count() > 0);
        if ($month->isEmpty()) return null;

        $best = $month->sortByDesc(fn (Fart $fart) => $fart->ratings->avg('score'))->first();

        return [
            'user' => ['id' => $best->author?->id, 'name' => $best->author?->name],
            'title' => $best->title,
            'score' => round($best->ratings->avg('score'), 2),
            'happened_at' => optional($best->happened_at)->toIso8601String(),
        ];
    }

    private function fart(Request $request, string $uuid): Fart
    {
        return Fart::with(['author:id,name', 'ratings.user:id,name', 'voiceNote'])->where('uuid', $uuid)
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
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze zapisovat.');
    }

    private function available(): void
    {
        abort_unless(Schema::hasTable('farts') && Schema::hasTable('fart_ratings'), 503, 'Pro tento modul dokončete databázové migrace.');
    }
}
