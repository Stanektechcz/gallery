<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RelationshipMilestoneController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->visible($request)->orderBy('occurred_on')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        abort_unless($request->user()->gallerySpaces()->whereKey($data['gallery_space_id'])->exists(), 404);
        if (!empty($data['media_item_id'])) DB::table('media_items')->where('id', $data['media_item_id'])->where('gallery_space_id', $data['gallery_space_id'])->whereNull('trashed_at')->firstOrFail();
        $id = DB::table('relationship_milestones')->insertGetId($data + ['uuid' => (string) Str::uuid(), 'created_by' => $request->user()->id, 'icon' => $data['icon'] ?? '❤️', 'visibility' => $data['visibility'] ?? 'shared', 'remind_annually' => $data['remind_annually'] ?? true, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('relationship_milestones')->find($id), 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $milestone = $this->visible($request)->where('uuid', $uuid)->firstOrFail();
        abort_unless($milestone->created_by === $request->user()->id || $milestone->visibility === 'shared', 403);
        $data = $this->validated($request, true);
        // A milestone must stay in the space in which it was created.  Moving it
        // through a PATCH request would bypass the membership check from store().
        unset($data['gallery_space_id']);
        if (!empty($data['media_item_id'])) DB::table('media_items')->where('id', $data['media_item_id'])->where('gallery_space_id', $milestone->gallery_space_id)->whereNull('trashed_at')->firstOrFail();
        DB::table('relationship_milestones')->where('id', $milestone->id)->update($data + ['updated_at' => now()]);
        return response()->json(DB::table('relationship_milestones')->find($milestone->id));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $milestone = $this->visible($request)->where('uuid', $uuid)->firstOrFail();
        abort_unless($milestone->created_by === $request->user()->id, 403);
        DB::table('relationship_milestones')->where('id', $milestone->id)->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function upcoming(Request $request): JsonResponse
    {
        $today = now();
        $items = $this->visible($request)->where('remind_annually', true)->get()->map(function ($item) use ($today) { $next = \Carbon\Carbon::parse($item->occurred_on)->year($today->year); if ($next->lt($today->copy()->startOfDay())) $next->addYear(); $item->next_anniversary = $next->toDateString(); $item->days_until = $today->startOfDay()->diffInDays($next->startOfDay()); return $item; })->sortBy('days_until')->values();
        return response()->json($items);
    }

    private function visible(Request $request)
    {
        return DB::table('relationship_milestones')->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->where(fn ($query) => $query->where('visibility', 'shared')->orWhere('created_by', $request->user()->id));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes|' : 'required|';
        return $request->validate(['gallery_space_id' => $partial ? 'sometimes|integer' : 'required|integer', 'title' => $prefix . 'string|max:160', 'description' => 'nullable|string|max:5000', 'occurred_on' => $prefix . 'date', 'icon' => 'nullable|string|max:16', 'visibility' => 'nullable|in:shared,private', 'remind_annually' => 'nullable|boolean', 'media_item_id' => 'nullable|integer']);
    }
}
