<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\MemoryEvening;
use App\Services\Memories\MemoryEveningService;
use App\Services\Media\MemoryDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MemoryEveningController extends Controller
{
    public function __construct(private readonly MemoryEveningService $evenings) {}

    public function index(Request $request): JsonResponse
    {
        $this->available();
        $data = $request->validate(['gallery_space_id' => 'nullable|integer', 'include_completed' => 'nullable|boolean']);
        $space = $this->space($request, isset($data['gallery_space_id']) ? (int) $data['gallery_space_id'] : null);
        $items = MemoryEvening::where('gallery_space_id', $space->id)
            ->when(! ($data['include_completed'] ?? true), fn ($query) => $query->whereNotIn('status', ['completed', 'cancelled']))
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'planned' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END")
            ->orderBy('scheduled_for')->limit(30)->get();
        return response()->json([
            'space' => ['id' => $space->id, 'name' => $space->name],
            'items' => $items->map(fn ($item) => $this->payload($item, $request->user()->id)),
            'suggested_for' => $this->evenings->nextFreeEvening($space)->toIso8601String(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->write($request); $this->available();
        $data = $request->validate([
            'gallery_space_id' => 'required|integer', 'fingerprint' => 'required|string|size:64',
            'source_type' => 'required|in:' . implode(',', MemoryDiscoveryService::TYPES), 'title' => 'required|string|max:160',
            'description' => 'nullable|string|max:5000', 'source_happened_on' => 'nullable|date',
            'scheduled_for' => 'nullable|date|after:now', 'repeat_annually' => 'nullable|boolean',
            'media_uuids' => 'required|array|min:1|max:30', 'media_uuids.*' => 'uuid|distinct',
        ]);
        $space = $this->space($request, (int) $data['gallery_space_id']);
        $media = MediaItem::where('gallery_space_id', $space->id)->whereIn('uuid', $data['media_uuids'])->whereNull('trashed_at')->where('is_hidden', false)->get();
        abort_unless($media->count() === count($data['media_uuids']), 422, 'Některé vybrané momenty už nejsou ve společné galerii dostupné.');
        $evening = $this->evenings->schedule($space, $request->user(), $data, $media);
        return response()->json($this->payload($evening->fresh(), $request->user()->id), $evening->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->payload($this->evening($request, $uuid), $request->user()->id));
    }

    public function start(Request $request, string $uuid): JsonResponse
    {
        $this->write($request); $evening = $this->evening($request, $uuid);
        abort_if(in_array($evening->status, ['completed', 'cancelled'], true), 422, 'Dokončený nebo zrušený večer už nelze spustit.');
        $evening->update(['status' => 'active', 'started_at' => $evening->started_at ?: now()]);
        CalendarEvent::whereKey($evening->calendar_event_id)->where('status', 'planned')->update(['status' => 'confirmed']);
        return response()->json($this->payload($evening->fresh(), $request->user()->id));
    }

    public function voteMedia(Request $request, string $uuid, string $mediaUuid): JsonResponse
    {
        $this->write($request); $evening = $this->evening($request, $uuid);
        abort_if(in_array($evening->status, ['completed', 'cancelled'], true), 422, 'Výběr tohoto večera je už uzavřený.');
        $data = $request->validate(['is_selected' => 'required|boolean']);
        $item = DB::table('curation_board_items as item')->join('media_items as media', 'media.id', '=', 'item.media_item_id')
            ->where('item.curation_board_id', $evening->curation_board_id)->where('media.uuid', $mediaUuid)->select('item.id')->firstOrFail();
        $existing = DB::table('curation_board_votes')->where('curation_board_item_id', $item->id)->where('user_id', $request->user()->id)->first();
        DB::table('curation_board_votes')->updateOrInsert(
            ['curation_board_item_id' => $item->id, 'user_id' => $request->user()->id],
            ['is_selected' => $data['is_selected'], 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()]
        );
        return response()->json($this->payload($evening->fresh(), $request->user()->id));
    }

    public function reflection(Request $request, string $uuid): JsonResponse
    {
        $this->write($request); $evening = $this->evening($request, $uuid);
        abort_if($evening->status === 'cancelled', 422, 'Zrušený večer nelze hodnotit.');
        $data = $request->validate(['mood' => 'nullable|in:joyful,cozy,grateful,funny,nostalgic,moved', 'note' => 'nullable|string|max:3000']);
        abort_if(empty($data['mood']) && blank($data['note'] ?? null), 422, 'Vyberte náladu nebo přidejte vlastní pohled.');
        $lookup = ['memory_evening_id' => $evening->id, 'user_id' => $request->user()->id];
        $existing = DB::table('memory_evening_reflections')->where($lookup)->first();
        DB::table('memory_evening_reflections')->updateOrInsert($lookup, ['mood' => $data['mood'] ?? null, 'note' => filled($data['note'] ?? null) ? trim($data['note']) : null, 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()]);
        return response()->json($this->payload($evening->fresh(), $request->user()->id));
    }

    public function complete(Request $request, string $uuid): JsonResponse
    {
        $this->write($request); $evening = $this->evening($request, $uuid);
        $evening = $this->evenings->complete($evening, $request->user());
        return response()->json($this->payload($evening, $request->user()->id));
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $this->write($request); $evening = $this->evening($request, $uuid);
        abort_if($evening->status === 'completed', 422, 'Dokončený večer už nelze zrušit.');
        $evening->update(['status' => 'cancelled']);
        CalendarEvent::whereKey($evening->calendar_event_id)->update(['status' => 'cancelled']);
        return response()->json($this->payload($evening->fresh(), $request->user()->id));
    }

    private function payload(MemoryEvening $evening, int $viewerId): array
    {
        $items = DB::table('curation_board_items as item')->join('media_items as media', 'media.id', '=', 'item.media_item_id')
            ->where('item.curation_board_id', $evening->curation_board_id)->whereNull('media.trashed_at')->orderBy('item.sort_order')
            ->get(['item.id', 'item.status', 'media.id as media_id', 'media.uuid', 'media.media_type', 'media.display_title', 'media.original_filename', 'media.taken_at']);
        $votes = DB::table('curation_board_votes')->whereIn('curation_board_item_id', $items->pluck('id'))->get()->groupBy('curation_board_item_id');
        $models = MediaItem::whereIn('id', $items->pluck('media_id'))->with(['variants' => fn ($query) => $query->whereIn('type', ['thumbnail', 'video_poster', 'placeholder'])])->get()->keyBy('id');
        $reflections = DB::table('memory_evening_reflections as reflection')->join('users', 'users.id', '=', 'reflection.user_id')->where('reflection.memory_evening_id', $evening->id)->orderBy('reflection.created_at')->get(['reflection.user_id', 'users.name as user_name', 'reflection.mood', 'reflection.note', 'reflection.updated_at']);
        $eventUuid = $evening->calendar_event_id ? CalendarEvent::whereKey($evening->calendar_event_id)->value('uuid') : null;
        $albumUuid = $evening->album_id ? DB::table('albums')->where('id', $evening->album_id)->value('uuid') : null;
        $momentUuid = $evening->shared_memory_moment_id ? DB::table('shared_memory_moments')->where('id', $evening->shared_memory_moment_id)->value('uuid') : null;
        return [
            'uuid' => $evening->uuid, 'title' => $evening->title, 'description' => $evening->description,
            'source_type' => $evening->source_type, 'scheduled_for' => $evening->scheduled_for?->toIso8601String(),
            'status' => $evening->status, 'repeat_annually' => $evening->repeat_annually, 'started_at' => $evening->started_at?->toIso8601String(), 'completed_at' => $evening->completed_at?->toIso8601String(),
            'event' => $eventUuid ? ['uuid' => $eventUuid, 'href' => '/calendar/events/' . $eventUuid] : null,
            'album' => $albumUuid ? ['uuid' => $albumUuid, 'href' => '/albums/' . $albumUuid] : null,
            'shared_memory' => $momentUuid ? ['uuid' => $momentUuid, 'href' => '/shared-memories'] : null,
            'items' => $items->map(function ($item) use ($votes, $models, $viewerId) {
                $itemVotes = collect($votes->get($item->id, [])); $model = $models->get($item->media_id);
                return ['uuid' => $item->uuid, 'media_type' => $item->media_type, 'title' => $item->display_title ?: $item->original_filename, 'taken_at' => $item->taken_at,
                    'thumbnail_url' => $model?->thumbnail_url, 'detail_url' => '/media/' . $item->uuid, 'status' => $item->status,
                    'selected_count' => $itemVotes->where('is_selected', true)->count(), 'my_vote' => ($mine = $itemVotes->firstWhere('user_id', $viewerId)) ? (bool) $mine->is_selected : null];
            })->values(),
            'reflections' => $reflections->map(fn ($item) => (array) $item + ['is_mine' => (int) $item->user_id === $viewerId])->values(),
            'my_reflection' => ($mine = $reflections->firstWhere('user_id', $viewerId)) ? (array) $mine : null,
        ];
    }

    private function evening(Request $request, string $uuid): MemoryEvening { return MemoryEvening::where('uuid', $uuid)->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->firstOrFail(); }
    private function space(Request $request, ?int $id): GallerySpace { $query = GallerySpace::whereHas('members', fn ($members) => $members->whereKey($request->user()->id)); return $id ? $query->findOrFail($id) : $query->orderByDesc('is_default')->firstOrFail(); }
    private function write(Request $request): void { abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze večer se vzpomínkami měnit.'); }
    private function available(): void { abort_unless(Schema::hasTable('memory_evenings') && Schema::hasTable('memory_evening_reflections'), 503, 'Pro večery se vzpomínkami dokončete databázové migrace.'); }
}
