<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CurationBoardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $boards = $this->visibleBoards($request)
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($board) => $this->serializeBoard($board, $request->user()->id));

        return response()->json($boards);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gallery_space_id' => 'nullable|integer',
            'title' => 'required|string|max:160',
            'description' => 'nullable|string|max:5000',
            'visibility' => 'nullable|in:private,shared',
        ]);
        $spaceId = $data['gallery_space_id'] ?? $this->spaceIds($request)[0] ?? null;
        abort_unless($spaceId && in_array($spaceId, $this->spaceIds($request), true), 404);

        $id = DB::table('curation_boards')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $spaceId,
            'created_by' => $request->user()->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'visibility' => $data['visibility'] ?? 'shared',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json($this->serializeBoard(DB::table('curation_boards')->find($id), $request->user()->id), 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->serializeBoard($this->board($request, $uuid), $request->user()->id, true));
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $board = $this->board($request, $uuid);
        $data = $request->validate([
            'title' => 'sometimes|string|max:160',
            'description' => 'nullable|string|max:5000',
            'visibility' => 'nullable|in:private,shared',
        ]);
        DB::table('curation_boards')->where('id', $board->id)->update($data + ['updated_at' => now()]);

        return response()->json($this->serializeBoard(DB::table('curation_boards')->find($board->id), $request->user()->id));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $board = $this->board($request, $uuid);
        DB::table('curation_boards')->where('id', $board->id)->delete();

        return response()->json(['status' => 'deleted']);
    }

    public function addItems(Request $request, string $uuid): JsonResponse
    {
        $board = $this->board($request, $uuid);
        $data = $request->validate(['media_uuids' => 'required|array|min:1|max:100', 'media_uuids.*' => 'uuid']);
        $media = MediaItem::query()->where('gallery_space_id', $board->gallery_space_id)
            ->whereIn('uuid', array_values(array_unique($data['media_uuids'])))->get(['id', 'uuid']);
        if ($media->count() !== count(array_unique($data['media_uuids']))) {
            abort(422, 'Některá média nepatří do této galerie nebo neexistují.');
        }

        $order = (int) DB::table('curation_board_items')->where('curation_board_id', $board->id)->max('sort_order');
        foreach ($media as $item) {
            DB::table('curation_board_items')->updateOrInsert(
                ['curation_board_id' => $board->id, 'media_item_id' => $item->id],
                ['added_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now(), 'sort_order' => ++$order]
            );
        }
        DB::table('curation_boards')->where('id', $board->id)->update(['updated_at' => now()]);

        return response()->json($this->serializeBoard($this->board($request, $uuid), $request->user()->id, true), 201);
    }

    public function updateItem(Request $request, string $uuid, int $itemId): JsonResponse
    {
        $board = $this->board($request, $uuid);
        $item = DB::table('curation_board_items')->where('id', $itemId)->where('curation_board_id', $board->id)->firstOrFail();
        $data = $request->validate(['status' => 'nullable|in:pending,shortlisted,selected,rejected', 'note' => 'nullable|string|max:2000', 'sort_order' => 'nullable|integer|min:0']);
        DB::table('curation_board_items')->where('id', $item->id)->update($data + ['updated_at' => now()]);
        DB::table('curation_boards')->where('id', $board->id)->update(['updated_at' => now()]);

        return response()->json(['item' => DB::table('curation_board_items')->find($item->id)]);
    }

    public function removeItem(Request $request, string $uuid, int $itemId): JsonResponse
    {
        $board = $this->board($request, $uuid);
        DB::table('curation_board_items')->where('id', $itemId)->where('curation_board_id', $board->id)->delete();
        DB::table('curation_boards')->where('id', $board->id)->update(['updated_at' => now()]);

        return response()->json(['status' => 'deleted']);
    }

    public function vote(Request $request, string $uuid, int $itemId): JsonResponse
    {
        $board = $this->board($request, $uuid);
        $item = DB::table('curation_board_items')->where('id', $itemId)->where('curation_board_id', $board->id)->firstOrFail();
        $data = $request->validate(['is_selected' => 'required|boolean']);
        DB::table('curation_board_votes')->updateOrInsert(
            ['curation_board_item_id' => $item->id, 'user_id' => $request->user()->id],
            ['is_selected' => $data['is_selected'], 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json($this->voteSummary($item->id, $request->user()->id));
    }

    private function board(Request $request, string $uuid): object
    {
        return $this->visibleBoards($request)->where('uuid', $uuid)->firstOrFail();
    }

    private function serializeBoard(object $board, int $userId, bool $withItems = false): array
    {
        $result = (array) $board;
        $items = DB::table('curation_board_items as i')->join('media_items as m', 'm.id', '=', 'i.media_item_id')
            ->where('i.curation_board_id', $board->id)->whereNull('m.trashed_at')
            ->orderBy('i.sort_order')->select('i.*', 'm.uuid as media_uuid', 'm.original_filename', 'm.display_title', 'm.media_type', 'm.taken_at')->get();
        $result['items_count'] = $items->count();
        $result['status_counts'] = $items->countBy('status');
        if ($withItems) {
            $result['items'] = $items->map(function ($item) use ($userId) {
                $item->votes = $this->voteSummary($item->id, $userId);
                return $item;
            })->values();
        }
        return $result;
    }

    private function voteSummary(int $itemId, int $userId): array
    {
        $votes = DB::table('curation_board_votes')->where('curation_board_item_id', $itemId);
        return ['selected' => (clone $votes)->where('is_selected', true)->count(), 'not_selected' => (clone $votes)->where('is_selected', false)->count(), 'my_vote' => (clone $votes)->where('user_id', $userId)->value('is_selected')];
    }

    private function spaceIds(Request $request): array
    {
        return $request->user()->gallerySpaces()->pluck('gallery_spaces.id')->all();
    }

    private function visibleBoards(Request $request)
    {
        return DB::table('curation_boards')
            ->whereIn('gallery_space_id', $this->spaceIds($request))
            ->where(function ($query) use ($request) {
                $query->where('visibility', 'shared')->orWhere('created_by', $request->user()->id);
            });
    }
}
