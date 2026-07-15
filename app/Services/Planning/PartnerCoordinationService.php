<?php

namespace App\Services\Planning;

use App\Models\GallerySpace;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PartnerCoordinationService
{
    public const TYPES = ['shared_todo', 'event_task', 'packing_item', 'planning_item', 'trip_document', 'gift', 'settlement'];

    public function snapshot(GallerySpace $space, User $viewer, int $limit = 12): array
    {
        $now = now();
        $allActions = collect()
            ->concat($this->sharedTodos($space, $now))
            ->concat($this->eventTasks($space, $viewer, $now))
            ->concat($this->packingItems($space, $now))
            ->concat($this->planningItems($space))
            ->concat($this->tripDocuments($space, $now))
            ->concat($this->gifts($space, $now))
            ->concat($this->settlements($space));

        $snoozed = Schema::hasTable('coordination_action_states')
            ? DB::table('coordination_action_states')->where('gallery_space_id', $space->id)->where('user_id', $viewer->id)
                ->whereNotNull('snoozed_until')->where('snoozed_until', '>', $now)->get()->keyBy(fn ($state) => $state->source_type . ':' . $state->source_key)
            : collect();
        $allActions = $allActions->map(function (array $action) use ($now) {
                $due = ! empty($action['due_at']) ? Carbon::parse($action['due_at']) : null;
                $action['is_overdue'] = $due?->lt($now) ?? false;
                $action['_sort'] = [
                    $action['is_overdue'] ? 0 : 1,
                    $due?->timestamp ?? PHP_INT_MAX,
                    ($action['priority'] ?? 'normal') === 'high' ? 0 : 1,
                    $action['assigned_to'] ? 1 : 0,
                ];
                return $action;
            })->sort(function (array $left, array $right) {
                foreach ($left['_sort'] as $index => $value) {
                    $comparison = $value <=> $right['_sort'][$index];
                    if ($comparison !== 0) return $comparison;
                }
                return strcmp($left['key'], $right['key']);
            })->values();
        $actions = $allActions->reject(fn (array $action) => $snoozed->has($action['type'] . ':' . $action['source_key']))->values();

        $checkIns = $this->checkIns($space, $viewer);
        $members = DB::table('gallery_space_user as membership')->join('users', 'users.id', '=', 'membership.user_id')
            ->where('membership.gallery_space_id', $space->id)->where('users.is_active', true)->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.avatar_path'])
            ->map(function ($member) use ($allActions, $checkIns) {
                $assigned = $allActions->where('assigned_to.id', (int) $member->id);
                $checkIn = $checkIns->firstWhere('user_id', (int) $member->id);
                return [
                    'id' => (int) $member->id, 'name' => $member->name, 'avatar_path' => $member->avatar_path,
                    'open_actions' => $assigned->count(), 'overdue_actions' => $assigned->where('is_overdue', true)->count(),
                    'check_in' => $checkIn,
                ];
            })->values();

        $unassigned = $allActions->whereNull('assigned_to')->count();
        $overdue = $allActions->where('is_overdue', true)->count();
        $mine = $allActions->where('assigned_to.id', $viewer->id)->count();

        return [
            'space' => ['id' => $space->id, 'name' => $space->name],
            'actions' => $actions->take(max(1, min(50, $limit)))->map(fn (array $action) => collect($action)->except('_sort')->all())->values(),
            'members' => $members,
            'check_ins' => $checkIns->values(),
            'my_check_in' => $checkIns->firstWhere('user_id', $viewer->id),
            'summary' => [
                'total' => $allActions->count(), 'mine' => $mine, 'unassigned' => $unassigned, 'overdue' => $overdue,
                'due_this_week' => $allActions->filter(fn ($action) => ! empty($action['due_at']) && Carbon::parse($action['due_at'])->between($now, $now->copy()->addDays(7)))->count(),
                'snoozed_for_me' => $snoozed->count(),
            ],
            'recommendation' => $this->recommendation($members, $unassigned),
            'check_in_available' => Schema::hasTable('partner_check_ins'),
        ];
    }

    private function sharedTodos(GallerySpace $space, Carbon $now): Collection
    {
        if (! Schema::hasTable('shared_todos')) return collect();

        return DB::table('shared_todos as todo')
            ->leftJoin('shared_todo_lists as list', 'list.id', '=', 'todo.list_id')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'todo.assigned_to')
            ->where('todo.gallery_space_id', $space->id)
            ->whereNull('todo.parent_id')
            ->whereNotIn('todo.status', ['completed', 'cancelled'])
            ->where(function ($query) use ($now) {
                $query->whereNull('todo.due_at')
                    ->orWhere('todo.due_at', '<=', $now->copy()->addDays(60))
                    ->orWhereIn('todo.priority', ['high', 'urgent']);
            })
            ->limit(80)
            ->get(['todo.uuid', 'todo.title', 'todo.due_at', 'todo.priority', 'todo.assigned_to', 'assignee.name as assignee_name', 'list.title as list_title'])
            ->map(fn ($todo) => $this->action(
                'shared_todo', $todo->uuid, $todo->title, $todo->list_title ?: 'Společné úkoly', $todo->due_at,
                $todo->priority, $todo->assigned_to, $todo->assignee_name, '/planning#todos', ['todo_uuid' => $todo->uuid]
            ));
    }

    private function eventTasks(GallerySpace $space, User $viewer, Carbon $now): Collection
    {
        if (! Schema::hasTable('event_tasks')) return collect();
        return DB::table('event_tasks as task')->join('calendar_events as event', 'event.id', '=', 'task.event_id')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'task.assigned_to')
            ->where('event.gallery_space_id', $space->id)->whereNull('task.completed_at')->where('event.status', '!=', 'cancelled')
            ->where(function ($query) use ($viewer) {
                $query->where('event.is_private', false)->orWhere('event.created_by', $viewer->id)
                    ->orWhereExists(fn ($participants) => $participants->selectRaw('1')->from('event_participants as participant')
                        ->whereColumn('participant.event_id', 'event.id')->where('participant.user_id', $viewer->id));
            })
            ->where(function ($query) use ($now) {
                $query->where('event.starts_at', '<=', $now->copy()->addDays(60))->orWhere('task.due_at', '<=', $now->copy()->addDays(60));
            })->limit(60)
            ->get(['task.id', 'task.title', 'task.due_at', 'task.priority', 'task.assigned_to', 'assignee.name as assignee_name', 'event.uuid as event_uuid', 'event.title as event_title', 'event.starts_at'])
            ->map(fn ($task) => $this->action('event_task', $task->id, $task->title, $task->event_title, $task->due_at ?: $task->starts_at, $task->priority, $task->assigned_to, $task->assignee_name, '/calendar/events/' . $task->event_uuid, ['event_uuid' => $task->event_uuid, 'task_id' => (int) $task->id]));
    }

    private function packingItems(GallerySpace $space, Carbon $now): Collection
    {
        if (! Schema::hasTable('trip_packing_items')) return collect();
        return DB::table('trip_packing_items as item')->join('trips as trip', 'trip.id', '=', 'item.trip_id')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'item.assigned_to')
            ->where('trip.gallery_space_id', $space->id)->where('trip.end_date', '>=', $now->toDateString())
            ->where('trip.start_date', '<=', $now->copy()->addDays(90)->toDateString())->where('item.is_packed', false)
            ->orderByDesc('item.is_essential')->orderBy('trip.start_date')->limit(60)
            ->get(['item.id', 'item.title', 'item.is_essential', 'item.assigned_to', 'assignee.name as assignee_name', 'trip.id as trip_id', 'trip.name as trip_name', 'trip.start_date'])
            ->map(fn ($item) => $this->action('packing_item', $item->id, $item->title, 'Balení · ' . $item->trip_name, $item->start_date . ' 08:00:00', $item->is_essential ? 'high' : 'normal', $item->assigned_to, $item->assignee_name, '/trips/' . $item->trip_id . '/plan', ['trip_id' => (int) $item->trip_id, 'packing_item_id' => (int) $item->id]));
    }

    private function planningItems(GallerySpace $space): Collection
    {
        if (! Schema::hasTable('travel_inbox_items')) return collect();
        $select = ['item.uuid', 'item.title', 'item.state', 'event.uuid as event_uuid', 'event.title as event_title', 'event.starts_at', 'trip.id as trip_id', 'trip.name as trip_name', 'trip.start_date'];
        if (Schema::hasColumn('travel_inbox_items', 'assigned_to')) $select = array_merge($select, ['item.assigned_to', 'assignee.name as assignee_name']);
        $query = DB::table('travel_inbox_items as item')->leftJoin('calendar_events as event', 'event.id', '=', 'item.event_id')->leftJoin('trips as trip', 'trip.id', '=', 'item.trip_id')
            ->where('item.gallery_space_id', $space->id)->whereIn('item.state', ['inbox', 'assigned'])
            ->whereNull('item.trip_activity_id')->latest('item.updated_at')->limit(50);
        if (Schema::hasColumn('travel_inbox_items', 'assigned_to')) $query->leftJoin('users as assignee', 'assignee.id', '=', 'item.assigned_to');
        return $query->get($select)->map(function ($item) {
            $eventUuid = $item->event_uuid ?? null; $tripId = $item->trip_id ?? null;
            return $this->action('planning_item', $item->uuid, $item->title, $item->event_title ?: ($item->trip_name ?: 'Společný podklad'), $item->starts_at ?: (($item->start_date ?? null) ? $item->start_date . ' 08:00:00' : null), 'normal', $item->assigned_to ?? null, $item->assignee_name ?? null, $eventUuid ? '/calendar/events/' . $eventUuid : ($tripId ? '/trips/' . $tripId . '/plan' : '/travel-inbox'), ['inbox_uuid' => $item->uuid, 'event_uuid' => $eventUuid, 'trip_id' => $tripId ? (int) $tripId : null]);
        });
    }

    private function tripDocuments(GallerySpace $space, Carbon $now): Collection
    {
        if (! Schema::hasTable('trip_document_checks')) return collect();
        $select = ['document.id', 'document.title', 'document.status', 'document.expires_on', 'trip.id as trip_id', 'trip.name as trip_name', 'trip.start_date'];
        if (Schema::hasColumn('trip_document_checks', 'assigned_to')) $select = array_merge($select, ['document.assigned_to', 'assignee.name as assignee_name']);
        $query = DB::table('trip_document_checks as document')->join('trips as trip', 'trip.id', '=', 'document.trip_id')
            ->where('trip.gallery_space_id', $space->id)->where('trip.end_date', '>=', $now->toDateString())
            ->whereIn('document.status', ['required', 'missing'])->where('trip.start_date', '<=', $now->copy()->addDays(120)->toDateString())->limit(40);
        if (Schema::hasColumn('trip_document_checks', 'assigned_to')) $query->leftJoin('users as assignee', 'assignee.id', '=', 'document.assigned_to');
        return $query->get($select)->map(fn ($document) => $this->action('trip_document', $document->id, $document->title, 'Doklady · ' . $document->trip_name, ($document->expires_on ?: $document->start_date) . ' 08:00:00', 'high', $document->assigned_to ?? null, $document->assignee_name ?? null, '/trips/' . $document->trip_id . '/plan', ['trip_id' => (int) $document->trip_id, 'document_id' => (int) $document->id]));
    }

    private function gifts(GallerySpace $space, Carbon $now): Collection
    {
        if (! Schema::hasTable('gift_ideas')) return collect();
        $select = ['gift.uuid', 'gift.title', 'gift.occasion', 'gift.due_date'];
        if (Schema::hasColumn('gift_ideas', 'assigned_to')) $select = array_merge($select, ['gift.assigned_to', 'assignee.name as assignee_name']);
        $query = DB::table('gift_ideas as gift')->where('gift.gallery_space_id', $space->id)->whereNotIn('gift.status', ['purchased', 'archived'])
            ->where(fn ($due) => $due->whereNull('gift.due_date')->orWhere('gift.due_date', '<=', $now->copy()->addDays(90)->toDateString()))->limit(30);
        if (Schema::hasColumn('gift_ideas', 'assigned_to')) $query->leftJoin('users as assignee', 'assignee.id', '=', 'gift.assigned_to');
        return $query->get($select)->map(fn ($gift) => $this->action('gift', $gift->uuid, $gift->title, $gift->occasion ? 'Dárek · ' . $gift->occasion : 'Nápad na dárek', $gift->due_date ? $gift->due_date . ' 18:00:00' : null, 'normal', $gift->assigned_to ?? null, $gift->assignee_name ?? null, '/planning', ['gift_uuid' => $gift->uuid]));
    }

    private function settlements(GallerySpace $space): Collection
    {
        if (! Schema::hasTable('trip_settlements')) return collect();

        return DB::table('trip_settlements as settlement')
            ->join('trips as trip', 'trip.id', '=', 'settlement.trip_id')
            ->join('users as debtor', 'debtor.id', '=', 'settlement.from_user_id')
            ->join('users as creditor', 'creditor.id', '=', 'settlement.to_user_id')
            ->where('trip.gallery_space_id', $space->id)
            ->where('settlement.status', 'suggested')
            ->orderBy('trip.end_date')->orderBy('settlement.id')->limit(30)
            ->get([
                'settlement.id', 'settlement.from_user_id', 'settlement.to_user_id', 'settlement.amount', 'settlement.currency',
                'trip.id as trip_id', 'trip.name as trip_name', 'trip.end_date',
                'debtor.name as debtor_name', 'creditor.name as creditor_name',
            ])->map(fn ($settlement) => $this->action(
                'settlement', $settlement->id,
                'Vyrovnat ' . number_format((float) $settlement->amount, 2, ',', ' ') . ' ' . $settlement->currency . ' s ' . $settlement->creditor_name,
                'Finance · ' . $settlement->trip_name,
                $settlement->end_date . ' 20:00:00', 'high', $settlement->from_user_id, $settlement->debtor_name,
                '/trips/' . $settlement->trip_id . '/plan#partner-finance',
                ['trip_id' => (int) $settlement->trip_id, 'settlement_id' => (int) $settlement->id, 'assignment_locked' => true]
            ));
    }

    private function checkIns(GallerySpace $space, User $viewer): Collection
    {
        if (! Schema::hasTable('partner_check_ins')) return collect();
        return DB::table('partner_check_ins as checkin')->join('users', 'users.id', '=', 'checkin.user_id')
            ->where('checkin.gallery_space_id', $space->id)->where('checkin.check_in_on', now()->toDateString())
            ->where(fn ($visible) => $visible->where('checkin.is_shared', true)->orWhere('checkin.user_id', $viewer->id))
            ->orderBy('users.name')->get(['checkin.uuid', 'checkin.user_id', 'users.name as user_name', 'checkin.check_in_on', 'checkin.mood', 'checkin.energy', 'checkin.capacity', 'checkin.focus', 'checkin.note', 'checkin.is_shared'])
            ->map(fn ($item) => ['uuid' => $item->uuid, 'user_id' => (int) $item->user_id, 'user_name' => $item->user_name, 'check_in_on' => $item->check_in_on, 'mood' => $item->mood, 'energy' => $item->energy !== null ? (int) $item->energy : null, 'capacity' => $item->capacity, 'focus' => $item->focus, 'note' => $item->note, 'is_shared' => (bool) $item->is_shared]);
    }

    private function action(string $type, int|string $key, string $title, string $context, mixed $dueAt, string $priority, mixed $assignedTo, ?string $assigneeName, string $href, array $extra = []): array
    {
        return array_merge([
            'key' => $type . '-' . $key, 'type' => $type, 'source_key' => (string) $key, 'title' => $title,
            'context' => $context, 'due_at' => $dueAt ? Carbon::parse($dueAt)->toIso8601String() : null,
            'priority' => $priority, 'assigned_to' => $assignedTo ? ['id' => (int) $assignedTo, 'name' => $assigneeName] : null,
            'href' => $href,
        ], array_filter($extra, fn ($value) => $value !== null));
    }

    private function recommendation(Collection $members, int $unassigned): ?array
    {
        if ($unassigned > 0) return ['code' => 'assign', 'title' => 'Domluvte odpovědnost', 'message' => $unassigned . ' položek zatím neví, kdo je zařídí.'];
        if ($members->count() < 2) return null;
        $low = $members->first(fn ($member) => in_array($member['check_in']['capacity'] ?? null, ['light', 'unavailable'], true) || (($member['check_in']['energy'] ?? 5) <= 2));
        if ($low && $low['open_actions'] > 0) return ['code' => 'low_capacity', 'title' => 'Dnes zvolnit', 'message' => $low['name'] . ' hlásí nižší kapacitu. Zvažte přesun nedůležitých kroků.'];
        $most = $members->sortByDesc('open_actions')->first(); $least = $members->sortBy('open_actions')->first();
        if ($most && $least && $most['open_actions'] - $least['open_actions'] >= 2) {
            return ['code' => 'rebalance', 'title' => 'Rozdělení lze vyvážit', 'message' => $most['name'] . ' má o ' . ($most['open_actions'] - $least['open_actions']) . ' otevřených kroků více než ' . $least['name'] . '.', 'suggested_user_id' => $least['id']];
        }
        return ['code' => 'balanced', 'title' => 'Plán je rozdělený vyváženě', 'message' => 'Otevřené kroky mají mezi vámi podobnou zátěž.'];
    }
}
