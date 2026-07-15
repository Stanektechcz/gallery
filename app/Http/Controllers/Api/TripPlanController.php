<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Travel\TravelJournalStoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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
        $meals = Schema::hasTable('planned_meals') && $day
            ? DB::table('planned_meals as meal')
                ->join('recipes as recipe', 'recipe.id', '=', 'meal.recipe_id')
                ->leftJoin('recipe_cooking_sessions as session', 'session.id', '=', 'meal.cooking_session_id')
                ->where('meal.trip_id', $id)->whereDate('meal.planned_for', $day->date)
                ->orderBy('meal.planned_for')
                ->get(['meal.uuid', 'meal.meal_type', 'meal.planned_for', 'meal.servings', 'meal.status', 'recipe.uuid as recipe_uuid', 'recipe.title', 'session.uuid as cooking_session_uuid'])
            : collect();

        if (Schema::hasColumn('travel_journal_entries', 'visibility')) {
            $journalQuery = DB::table('travel_journal_entries as entry')->join('users', 'users.id', '=', 'entry.user_id')
                ->where('entry.trip_id', $id)
                ->where(fn ($visible) => $visible->where('entry.visibility', 'shared')->orWhere('entry.user_id', $request->user()->id))
                ->latest('entry.recorded_at')->limit(50);
            if (Schema::hasTable('travel_journal_recordings')) {
                $journalQuery->leftJoin('travel_journal_recordings as recording', 'recording.journal_entry_id', '=', 'entry.id');
                $journal = $journalQuery->get(['entry.*', 'users.name as user_name', 'recording.uuid as recording_uuid', 'recording.duration_ms as recording_duration_ms'])
                    ->map(fn ($entry) => (array) $entry + ['is_mine' => (int) $entry->user_id === (int) $request->user()->id,
                        'recording_url' => $entry->recording_uuid ? "/api/v1/trips/{$id}/journal/{$entry->id}/recording" : null]);
            } else {
                $journal = $journalQuery->get(['entry.*', 'users.name as user_name'])
                    ->map(fn ($entry) => (array) $entry + ['is_mine' => (int) $entry->user_id === (int) $request->user()->id]);
            }
        } else {
            $journal = DB::table('travel_journal_entries')->where('trip_id', $id)->where('user_id', $request->user()->id)->latest('recorded_at')->limit(20)->get()
                ->map(fn ($entry) => (array) $entry + ['user_name' => $request->user()->name, 'is_mine' => true, 'visibility' => 'private', 'mood' => null, 'is_story_worthy' => false]);
        }

        return response()->json([
            'trip' => $trip,
            'day' => $day,
            'activities' => $activities,
            'current' => $current,
            'next' => $next,
            'progress' => $activities->count() ? (int) round($done / $activities->count() * 100) : 0,
            'meals' => $meals,
            'journal' => $journal,
        ]);
    }

    public function addJournalEntry(Request $request, int $id, TravelJournalStoryService $stories): JsonResponse
    {
        $trip = $this->trip($request, $id);
        abort_unless(Schema::hasColumn('travel_journal_entries', 'visibility'), 503, 'Pro partnerský cestovní deník dokončete migrace aplikace.');
        $data = $request->validate([
            'type' => 'required|in:note,voice,expense,location', 'content' => 'nullable|string|max:5000',
            'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180',
            'amount' => 'nullable|numeric|min:0|max:999999999', 'currency' => 'nullable|string|size:3',
            'category' => 'nullable|in:transport,accommodation,food,activities,insurance,other',
            'expense_share' => 'nullable|in:shared,personal',
            'visibility' => 'nullable|in:shared,private',
            'mood' => 'nullable|in:joyful,calm,adventurous,cozy,tired,grateful,funny',
            'is_story_worthy' => 'nullable|boolean',
        ]);
        abort_if(in_array($data['type'], ['note', 'voice'], true) && blank($data['content'] ?? null), 422, 'Záznam nesmí být prázdný.');
        abort_if($data['type'] === 'expense' && (blank($data['content'] ?? null) || ! array_key_exists('amount', $data)), 422, 'U výdaje doplňte název a částku.');

        $entryId = DB::transaction(function () use ($data, $trip, $id, $request) {
            $metadata = [];
            if ($data['type'] === 'expense') {
                $amount = round((float) $data['amount'], 2);
                $memberIds = DB::table('gallery_space_user')->where('gallery_space_id', $trip->gallery_space_id)->orderBy('user_id')->pluck('user_id')->all();
                $share = $data['expense_share'] ?? 'shared';
                $splitIds = $share === 'personal' ? [$request->user()->id] : $memberIds;
                $parts = [];
                $base = round($amount / max(1, count($splitIds)), 2);
                foreach ($splitIds as $userId) $parts[] = ['user_id' => $userId, 'amount' => $base];
                if ($parts) $parts[array_key_last($parts)]['amount'] += round($amount - array_sum(array_column($parts, 'amount')), 2);

                $expenseId = DB::table('trip_expenses')->insertGetId([
                    'trip_id' => $id, 'created_by' => $request->user()->id, 'title' => $data['content'],
                    'category' => $data['category'] ?? 'other', 'amount' => $amount,
                    'currency' => strtoupper($data['currency'] ?? $trip->currency ?? 'CZK'),
                    'paid_by_user_id' => $request->user()->id, 'state' => 'actual', 'occurred_at' => now(),
                    'split' => json_encode($parts), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $metadata = ['trip_expense_id' => $expenseId, 'expense_share' => $share, 'category' => $data['category'] ?? 'other'];
            }

            $visibility = $data['type'] === 'expense'
                ? (($data['expense_share'] ?? 'shared') === 'shared' ? 'shared' : 'private')
                : ($data['visibility'] ?? 'shared');
            $journal = array_intersect_key($data, array_flip(['type', 'content', 'latitude', 'longitude', 'amount', 'currency', 'mood']));
            $journal['currency'] = isset($journal['currency']) ? strtoupper($journal['currency']) : ($data['type'] === 'expense' ? strtoupper($trip->currency ?? 'CZK') : null);
            $journal['metadata'] = $metadata ? json_encode($metadata) : null;
            $journal['trip_day_id'] = DB::table('trip_days')->where('trip_id', $id)->where('date', now()->toDateString())->value('id');
            $journal['visibility'] = $visibility;
            $journal['is_story_worthy'] = $visibility === 'shared' && in_array($data['type'], ['note', 'voice', 'location'], true) && ($data['is_story_worthy'] ?? true);
            return DB::table('travel_journal_entries')->insertGetId($journal + [
                'trip_id' => $id, 'user_id' => $request->user()->id, 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $stories->syncEntry($id, $entryId);

        return response()->json(DB::table('travel_journal_entries')->find($entryId), 201);
    }

    public function updateJournalEntry(Request $request, int $id, int $entryId, TravelJournalStoryService $stories): JsonResponse
    {
        $this->trip($request, $id);
        abort_unless(Schema::hasColumn('travel_journal_entries', 'visibility'), 503, 'Pro partnerský cestovní deník dokončete migrace aplikace.');
        $entry = DB::table('travel_journal_entries')->where('id', $entryId)->where('trip_id', $id)->where('user_id', $request->user()->id)->firstOrFail();
        $data = $request->validate([
            'content' => 'nullable|string|max:5000',
            'visibility' => 'nullable|in:shared,private',
            'mood' => 'nullable|in:joyful,calm,adventurous,cozy,tired,grateful,funny',
            'is_story_worthy' => 'nullable|boolean',
        ]);
        $visibility = $data['visibility'] ?? $entry->visibility;
        if ($entry->type === 'expense') $data['is_story_worthy'] = false;
        if ($visibility === 'private') $data['is_story_worthy'] = false;
        DB::table('travel_journal_entries')->where('id', $entry->id)->update($data + ['updated_at' => now()]);
        $stories->syncEntry($id, $entry->id);

        return response()->json(DB::table('travel_journal_entries')->find($entry->id));
    }

    public function removeJournalEntry(Request $request, int $id, int $entryId, TravelJournalStoryService $stories): JsonResponse
    {
        $this->trip($request, $id);
        $entry = DB::table('travel_journal_entries')->where('id', $entryId)->where('trip_id', $id)->where('user_id', $request->user()->id)->first();
        abort_unless($entry, 404);
        $recording = Schema::hasTable('travel_journal_recordings') ? DB::table('travel_journal_recordings')->where('journal_entry_id', $entry->id)->first() : null;
        DB::transaction(function () use ($entry, $id, $request) {
            $metadata = json_decode($entry->metadata ?: '{}', true) ?: [];
            if (! empty($metadata['trip_expense_id'])) {
                DB::table('trip_expenses')->where('id', $metadata['trip_expense_id'])->where('trip_id', $id)->where('created_by', $request->user()->id)->delete();
            }
            DB::table('travel_journal_entries')->where('id', $entry->id)->delete();
        });
        $stories->syncEntry($id, $entry->id);
        if ($recording) Storage::disk($recording->disk)->delete($recording->path);
        return response()->json(null, 204);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $trip = $this->trip($request, $id);
        $this->ensureDays($trip);

        $inboxColumns = ['uuid', 'title', 'notes', 'source_url', 'kind', 'state', 'trip_id', 'trip_day_id', 'trip_activity_id'];
        $inbox = DB::table('travel_inbox_items')->where('trip_id', $id)->where('state', '!=', 'archived')->orderByDesc('id')->get($inboxColumns);
        $days = DB::table('trip_days')->where('trip_id', $id)->orderBy('sort_order')->get()->map(function ($day) use ($inbox) {
            $day->inbox_items = $inbox->where('trip_day_id', $day->id)->whereNull('trip_activity_id')->values();
            $day->activities = DB::table('trip_activities')->where('trip_day_id', $day->id)->orderBy('sort_order')->get()->map(function ($activity) {
                $activity->metadata = $activity->metadata ? json_decode($activity->metadata, true) : null;
                $activity->latitude = $activity->latitude !== null ? (float) $activity->latitude : null;
                $activity->longitude = $activity->longitude !== null ? (float) $activity->longitude : null;
                $activity->cost = $activity->cost !== null ? (float) $activity->cost : null;
                return $activity;
            });
            foreach ($day->activities as $activity) $activity->inbox_items = $inbox->where('trip_activity_id', $activity->id)->values();
            return $day;
        });

        $availableInbox = DB::table('travel_inbox_items')->where('gallery_space_id', $trip->gallery_space_id)
            ->where('state', 'inbox')->where(function ($query) use ($id) {
                $query->whereNull('trip_id')->orWhere('trip_id', $id);
            })->latest()->limit(30)->get($inboxColumns);

        return response()->json([
            'trip' => $trip,
            'days' => $days,
            'trip_inbox_items' => $inbox->whereNull('trip_day_id')->values(),
            'available_inbox_items' => $availableInbox,
        ]);
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

    /** Turn a captured link, reservation or idea into one editable itinerary block. */
    public function promoteInboxItem(Request $request, int $id, int $dayId, string $uuid): JsonResponse
    {
        $trip = $this->trip($request, $id);
        $day = DB::table('trip_days')->where('id', $dayId)->where('trip_id', $id)->firstOrFail();
        $data = $request->validate([
            'type' => 'nullable|in:activity,reservation,note,checklist',
            'starts_at' => 'nullable|date_format:H:i',
            'ends_at' => 'nullable|date_format:H:i|after:starts_at',
            'place_name' => 'nullable|string|max:255',
            'cost' => 'nullable|numeric|min:0|max:999999999',
            'currency' => 'nullable|string|size:3',
        ]);

        [$activity, $item, $created] = DB::transaction(function () use ($request, $trip, $day, $uuid, $data, $id) {
            $item = DB::table('travel_inbox_items')->where('uuid', $uuid)
                ->where('gallery_space_id', $trip->gallery_space_id)->lockForUpdate()->firstOrFail();
            if ($item->trip_id && (int) $item->trip_id !== $id) {
                abort(422, 'Podklad je už přiřazen k jiné cestě.');
            }

            if ($item->trip_activity_id) {
                $existing = DB::table('trip_activities as activity')
                    ->join('trip_days as day', 'day.id', '=', 'activity.trip_day_id')
                    ->where('activity.id', $item->trip_activity_id)->where('day.trip_id', $id)
                    ->select('activity.*')->first();
                if ($existing) return [$existing, $item, false];
            }

            $type = $data['type'] ?? match ($item->kind) {
                'reservation' => 'reservation',
                'note' => 'note',
                default => 'activity',
            };
            $metadata = [
                'source' => 'travel_inbox',
                'travel_inbox_uuid' => $item->uuid,
                'source_kind' => $item->kind,
                'source_url' => $item->source_url,
            ];
            $activityId = DB::table('trip_activities')->insertGetId([
                'trip_day_id' => $day->id, 'created_by' => $request->user()->id,
                'type' => $type, 'title' => $item->title, 'description' => $item->notes,
                'starts_at' => $data['starts_at'] ?? null, 'ends_at' => $data['ends_at'] ?? null,
                'place_name' => $data['place_name'] ?? null, 'status' => 'planned',
                'cost' => $data['cost'] ?? null, 'currency' => strtoupper($data['currency'] ?? $trip->currency ?? 'CZK'),
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort_order' => ((int) DB::table('trip_activities')->where('trip_day_id', $day->id)->max('sort_order')) + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $itemMetadata = is_string($item->metadata) ? (json_decode($item->metadata, true) ?: []) : ((array) ($item->metadata ?? []));
            $itemMetadata['promoted_at'] = now()->toIso8601String();
            DB::table('travel_inbox_items')->where('id', $item->id)->update([
                'trip_id' => $id, 'trip_day_id' => $day->id, 'trip_activity_id' => $activityId,
                'state' => 'assigned', 'metadata' => json_encode($itemMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

            return [DB::table('trip_activities')->find($activityId), DB::table('travel_inbox_items')->find($item->id), true];
        });

        $activity->metadata = $activity->metadata ? json_decode($activity->metadata, true) : null;
        $activity->cost = $activity->cost !== null ? (float) $activity->cost : null;
        $activity->inbox_items = [collect((array) $item)->only(['uuid', 'title', 'notes', 'source_url', 'kind', 'state', 'trip_day_id', 'trip_activity_id'])->all()];

        return response()->json(['activity' => $activity, 'inbox_item' => $item, 'created' => $created], $created ? 201 : 200);
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
        $spaceIds = $request->user()->gallerySpaces()->pluck('gallery_spaces.id');
        $trip = DB::table('trips')->where('id', $id)->whereIn('gallery_space_id', $spaceIds)->first();
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
