<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The diary. Entries begin private and are shared one at a time.
 *
 * Every read goes through JournalEntry::readableBy(), so an entry that was never shared
 * is invisible to the other member even by direct uuid — there is no route in this
 * controller that reaches an entry without it.
 */
class JournalController extends Controller
{
    private const MOODS = ['radost', 'klid', 'láska', 'únava', 'smutek', 'vztek', 'vděk', 'nejistota'];

    public function index(Request $request): JsonResponse
    {
        $this->available();
        $user = $request->user();
        $space = $this->space($request, $request->integer('gallery_space_id') ?: null);

        $scope = $request->string('scope')->toString();      // '', 'mine', 'shared'

        $entries = JournalEntry::with('author:id,name')
            ->where('gallery_space_id', $space->id)
            ->readableBy($user)
            ->when($scope === 'mine', fn ($query) => $query->where('created_by', $user->id))
            ->when($scope === 'shared', fn ($query) => $query->where('visibility', JournalEntry::VISIBILITY_SHARED))
            ->when($request->filled('q'), function ($query) use ($request) {
                $needle = '%' . $request->string('q')->toString() . '%';
                $query->where(fn ($inner) => $inner->where('title', 'like', $needle)->orWhere('body', 'like', $needle));
            })
            ->orderByDesc('entry_date')->orderByDesc('id')
            ->limit(300)->get();

        return response()->json([
            'space_id' => $space->id,
            'moods' => self::MOODS,
            'entries' => $entries->map(fn (JournalEntry $entry) => $this->payload($entry, $user))->values(),
            'counts' => [
                'mine' => $entries->where('created_by', $user->id)->count(),
                'shared' => $entries->where('visibility', JournalEntry::VISIBILITY_SHARED)->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->available();
        $this->write($request);
        $space = $this->space($request, $request->integer('gallery_space_id') ?: null);
        $data = $this->validated($request);

        $entry = JournalEntry::create([
            'gallery_space_id' => $space->id,
            'created_by' => $request->user()->id,
            'title' => $data['title'] ?? null,
            'body' => $data['body'],
            'mood' => $data['mood'] ?? null,
            'entry_date' => $data['entry_date'] ?? now()->toDateString(),
            // Sharing is its own action; a new entry is never born public.
            'visibility' => JournalEntry::VISIBILITY_PRIVATE,
        ]);

        return response()->json($this->payload($entry->fresh('author'), $request->user()), 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $this->write($request);
        $entry = $this->own($request, $uuid);
        $data = $this->validated($request);

        // Title and mood are assigned even when absent: clearing them is a real edit.
        $entry->update([
            'title' => $data['title'] ?? null,
            'body' => $data['body'],
            'mood' => $data['mood'] ?? null,
            'entry_date' => $data['entry_date'] ?? $entry->entry_date,
        ]);

        return response()->json($this->payload($entry->fresh('author'), $request->user()));
    }

    /** Sharing and unsharing are the same endpoint, because they are one decision. */
    public function visibility(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $this->write($request);
        $entry = $this->own($request, $uuid);
        $shared = $request->boolean('shared');

        $entry->update([
            'visibility' => $shared ? JournalEntry::VISIBILITY_SHARED : JournalEntry::VISIBILITY_PRIVATE,
            // Kept as a record of when it was last opened up, cleared when taken back.
            'shared_at' => $shared ? now() : null,
        ]);

        return response()->json($this->payload($entry->fresh('author'), $request->user()));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->available();
        $this->write($request);
        $this->own($request, $uuid)->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(JournalEntry $entry, User $viewer): array
    {
        return [
            'uuid' => $entry->uuid,
            'title' => $entry->title,
            'body' => $entry->body,
            'mood' => $entry->mood,
            'entry_date' => $entry->entry_date?->toDateString(),
            'shared' => $entry->isShared(),
            'shared_at' => $entry->shared_at?->toIso8601String(),
            'author' => ['id' => $entry->created_by, 'name' => $entry->author?->name],
            'is_mine' => $entry->created_by === $viewer->id,
            'can_edit' => $entry->isEditableBy($viewer),
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => 'nullable|string|max:180',
            'body' => 'required|string|max:50000',
            'mood' => 'nullable|string|in:' . implode(',', self::MOODS),
            'entry_date' => 'nullable|date|before_or_equal:today',
        ]);
    }

    /** Resolves an entry the caller is allowed to change — which means one they wrote. */
    private function own(Request $request, string $uuid): JournalEntry
    {
        $space = $this->space($request, $request->integer('gallery_space_id') ?: null);

        $entry = JournalEntry::where('gallery_space_id', $space->id)
            ->readableBy($request->user())
            ->where('uuid', $uuid)
            ->firstOrFail();

        abort_unless($entry->isEditableBy($request->user()), 403, 'Upravovat může jen autor zápisku.');

        return $entry;
    }

    private function space(Request $request, ?int $id): GallerySpace
    {
        $query = GallerySpace::whereHas('members', fn ($members) => $members->whereKey($request->user()->id));

        return $id ? $query->findOrFail($id) : $query->orderByDesc('is_default')->firstOrFail();
    }

    private function write(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze psát do deníku.');
    }

    private function available(): void
    {
        abort_unless(Schema::hasTable('journal_entries'), 503, 'Pro deník dokončete databázové migrace.');
    }
}
