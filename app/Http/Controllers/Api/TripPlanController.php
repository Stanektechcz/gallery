<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TripPlanController extends Controller
{
    public function now(Request $request, int $id): JsonResponse
    {
        $trip = $this->trip($request, $id);
        $this->ensureDays($trip);
        $today = now()->toDateString();
        $day = DB::table('trip_days')->where('trip_id', $id)->where('date', $today)->first()
            ?? DB::table('trip_days')->where('trip_id', $id)->orderBy('date')->first();
        $activities = $day ? DB::table('trip_activities')->where('trip_day_id', $day->id)->orderByRaw('starts_at is null, starts_at')->orderBy('sort_order')->get() : collect();
        $currentTime = now()->format('H:i:s');
        $current = $activities->first(fn ($item) => $item->starts_at && $item->starts_at <= $currentTime && (! $item->ends_at || $item->ends_at >= $currentTime));
        $next = $activities->first(fn ($item) => $item->status !== 'done' && (! $item->starts_at || $item->starts_at > $currentTime));
        $done = $activities->where('status', 'done')->count();

        return response()->json([
            'trip' => $trip,
            'day' => $day,
            'activities' => $activities,
            'current' => $current,
            'next' => $next,
            'progress' => $activities->count() ? (int) round($done / $activities->count() * 100) : 0,
            'journal' => DB::table('travel_journal_entries')->where('trip_id', $id)->where('user_id', $request->user()->id)->latest('recorded_at')->limit(20)->get(),
        ]);
    }

    public function addJournalEntry(Request $request, int $id): JsonResponse
    {
        $this->trip($request, $id);
        $data = $request->validate([
            'type' => 'required|in:note,voice,expense,location', 'content' => 'nullable|string|max:5000',
            'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180',
            'amount' => 'nullable|numeric|min:0|max:999999999', 'currency' => 'nullable|string|size:3',
        ]);
        abort_if(in_array($data['type'], ['note', 'voice'], true) && blank($data['content'] ?? null), 422, 'Záznam nesmí být prázdný.');
        $entryId = DB::table('travel_journal_entries')->insertGetId(array_merge($data, [
            'trip_id' => $id, 'user_id' => $request->user()->id, 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));

        return response()->json(DB::table('travel_journal_entries')->find($entryId), 201);
    }

    public function removeJournalEntry(Request $request, int $id, int $entryId): JsonResponse
    {
        $this->trip($request, $id);
        $deleted = DB::table('travel_journal_entries')->where('id', $entryId)->where('trip_id', $id)->where('user_id', $request->user()->id)->delete();
        abort_unless($deleted, 404);
        return response()->json(null, 204);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $trip = $this->trip($request, $id);
        $this->ensureDays($trip);

        $days = DB::table('trip_days')->where('trip_id', $id)->orderBy('sort_order')->get()->map(function ($day) {
            $day->activities = DB::table('trip_activities')->where('trip_day_id', $day->id)->orderBy('sort_order')->get()->map(function ($activity) {
                $activity->metadata = $activity->metadata ? json_decode($activity->metadata, true) : null;
                $activity->latitude = $activity->latitude !== null ? (float) $activity->latitude : null;
                $activity->longitude = $activity->longitude !== null ? (float) $activity->longitude : null;
                $activity->cost = $activity->cost !== null ? (float) $activity->cost : null;
                return $activity;
            });
            return $day;
        });

        return response()->json(['trip' => $trip, 'days' => $days]);
    }

    public function updateDay(Request $request, int $id, int $dayId): JsonResponse
    {
        $this->trip($request, $id);
        $data = $request->validate(['title' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:5000']);
        $updated = DB::table('trip_days')->where('id', $dayId)->where('trip_id', $id)->update(array_merge($data, ['updated_at' => now()]));
        abort_unless($updated || DB::table('trip_days')->where('id', $dayId)->where('trip_id', $id)->exists(), 404);
        return response()->json(DB::table('trip_days')->find($dayId));
    }

    public function addActivity(Request $request, int $id, int $dayId): JsonResponse
    {
        $this->trip($request, $id);
        abort_unless(DB::table('trip_days')->where('id', $dayId)->where('trip_id', $id)->exists(), 404);
        $data = $request->validate($this->activityRules());
        $maxOrder = DB::table('trip_activities')->where('trip_day_id', $dayId)->max('sort_order') ?? -1;
        $activityId = DB::table('trip_activities')->insertGetId(array_merge($data, [
            'trip_day_id' => $dayId,
            'created_by' => $request->user()->id,
            'sort_order' => $maxOrder + 1,
            'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        return response()->json(DB::table('trip_activities')->find($activityId), 201);
    }

    public function updateActivity(Request $request, int $id, int $activityId): JsonResponse
    {
        $this->trip($request, $id);
        $activity = $this->activity($id, $activityId);
        $data = $request->validate($this->activityRules(true));
        if (array_key_exists('metadata', $data)) $data['metadata'] = json_encode($data['metadata']);
        DB::table('trip_activities')->where('id', $activityId)->update(array_merge($data, ['updated_at' => now()]));
        return response()->json(DB::table('trip_activities')->find($activity->id));
    }

    public function removeActivity(Request $request, int $id, int $activityId): JsonResponse
    {
        $this->trip($request, $id);
        $activity = $this->activity($id, $activityId);
        DB::table('trip_activities')->where('id', $activity->id)->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function reorderActivities(Request $request, int $id, int $dayId): JsonResponse
    {
        $this->trip($request, $id);
        $data = $request->validate(['order' => 'required|array', 'order.*' => 'integer|distinct']);
        $current = DB::table('trip_activities')->where('trip_day_id', $dayId)->pluck('id')->map(fn ($value) => (int) $value)->sort()->values()->all();
        $requested = array_map('intval', $data['order']);
        $sorted = $requested; sort($sorted);
        abort_unless($current === $sorted, 422, 'Pořadí musí obsahovat všechny bloky právě jednou.');
        DB::transaction(function () use ($requested, $dayId) {
            foreach ($requested as $order => $activityId) {
                DB::table('trip_activities')->where('id', $activityId)->where('trip_day_id', $dayId)->update(['sort_order' => $order, 'updated_at' => now()]);
            }
        });
        return response()->json(['reordered' => count($requested)]);
    }

    private function trip(Request $request, int $id): object
    {
        $space = $request->user()->gallerySpaces()->first();
        $trip = DB::table('trips')->where('id', $id)->where('gallery_space_id', $space->id)->first();
        abort_unless($trip, 404);
        return $trip;
    }

    private function activity(int $tripId, int $activityId): object
    {
        $activity = DB::table('trip_activities')
            ->join('trip_days', 'trip_days.id', '=', 'trip_activities.trip_day_id')
            ->where('trip_activities.id', $activityId)->where('trip_days.trip_id', $tripId)
            ->select('trip_activities.*')->first();
        abort_unless($activity, 404);
        return $activity;
    }

    private function ensureDays(object $trip): void
    {
        if (DB::table('trip_days')->where('trip_id', $trip->id)->exists()) return;
        $start = Carbon::parse($trip->start_date);
        $end = Carbon::parse($trip->end_date);
        $rows = [];
        $order = 0;
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $rows[] = ['trip_id' => $trip->id, 'date' => $date->toDateString(), 'title' => 'Den ' . ($order + 1), 'sort_order' => $order++, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('trip_days')->insert($rows);
    }

    private function activityRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        return [
            'type' => 'sometimes|in:activity,transport,stay,reservation,note,checklist,expense',
            'title' => "{$required}|string|max:255",
            'description' => 'nullable|string|max:5000',
            'starts_at' => 'nullable|date_format:H:i',
            'ends_at' => 'nullable|date_format:H:i|after:starts_at',
            'place_name' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'status' => 'sometimes|in:planned,confirmed,done,cancelled',
            'cost' => 'nullable|numeric|min:0|max:999999999',
            'currency' => 'sometimes|string|size:3',
            'metadata' => 'nullable|array',
        ];
    }
}
