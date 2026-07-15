<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\SharedTodo;
use App\Models\SharedTodoComment;
use App\Models\SharedTodoList;
use App\Notifications\GalleryNotification;
use App\Services\Planning\SharedTodoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class SharedTodoController extends Controller
{
    public function __construct(private readonly SharedTodoService $todos) {}

    public function index(Request $request): JsonResponse
    {
        $this->available();
        $data = $request->validate([
            'gallery_space_id' => 'nullable|integer', 'list_uuid' => 'nullable|uuid',
            'scope' => 'nullable|in:all,mine,unassigned', 'status' => 'nullable|in:active,open,in_progress,waiting,completed,cancelled',
            'search' => 'nullable|string|max:120',
        ]);
        $space = $this->space($request, isset($data['gallery_space_id']) ? (int) $data['gallery_space_id'] : null);
        $this->todos->ensureDefaultList($space, $request->user());
        $listId = ! empty($data['list_uuid']) ? SharedTodoList::where('uuid', $data['list_uuid'])->where('gallery_space_id', $space->id)->value('id') : null;
        $query = SharedTodo::query()->where('gallery_space_id', $space->id)->whereNull('parent_id')
            ->with(['assignee:id,name', 'creator:id,name', 'list:id,uuid,title,color,icon', 'children.assignee:id,name', 'comments.user:id,name'])
            ->withCount(['children', 'comments']);
        if ($listId) $query->where('list_id', $listId);
        match ($data['scope'] ?? 'all') {
            'mine' => $query->where('assigned_to', $request->user()->id),
            'unassigned' => $query->whereNull('assigned_to'),
            default => null,
        };
        if (($data['status'] ?? 'active') === 'active') $query->whereNotIn('status', ['completed', 'cancelled']);
        elseif (! empty($data['status'])) $query->where('status', $data['status']);
        if (! empty($data['search'])) $query->where(fn ($search) => $search->where('title', 'like', '%' . $data['search'] . '%')->orWhere('description', 'like', '%' . $data['search'] . '%'));
        $tasks = $query->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')->orderBy('due_at')->orderBy('sort_order')->limit(200)->get();
        $active = SharedTodo::where('gallery_space_id', $space->id)->whereNotIn('status', ['completed', 'cancelled']);
        return response()->json([
            'space' => ['id' => $space->id, 'name' => $space->name],
            'lists' => SharedTodoList::where('gallery_space_id', $space->id)->whereNull('archived_at')->orderBy('sort_order')->get(),
            'tasks' => $tasks->map(fn (SharedTodo $todo) => $this->payload($todo)),
            'members' => $this->members($space),
            'summary' => [
                'active' => (clone $active)->count(), 'mine' => (clone $active)->where('assigned_to', $request->user()->id)->count(),
                'unassigned' => (clone $active)->whereNull('assigned_to')->count(),
                'overdue' => (clone $active)->whereNotNull('due_at')->where('due_at', '<', now())->count(),
                'completed_this_week' => SharedTodo::where('gallery_space_id', $space->id)->where('completed_at', '>=', now()->startOfWeek())->count(),
            ],
        ]);
    }

    public function storeList(Request $request): JsonResponse
    {
        $this->write($request); $this->available();
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'title' => 'required|string|max:120', 'description' => 'nullable|string|max:2000', 'kind' => 'nullable|in:general,home,shopping,travel,date,admin', 'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'icon' => 'nullable|string|max:16']);
        $space = $this->space($request, (int) $data['gallery_space_id']);
        $list = SharedTodoList::create($data + ['created_by' => $request->user()->id, 'gallery_space_id' => $space->id, 'kind' => $data['kind'] ?? 'general', 'color' => $data['color'] ?? '#14b8a6', 'icon' => $data['icon'] ?? '✅', 'sort_order' => ((int) SharedTodoList::where('gallery_space_id', $space->id)->max('sort_order')) + 1]);
        return response()->json($list, 201);
    }

    public function updateList(Request $request, string $uuid): JsonResponse
    {
        $this->write($request); $list = $this->list($request, $uuid);
        $data = $request->validate(['title' => 'sometimes|string|max:120', 'description' => 'nullable|string|max:2000', 'kind' => 'nullable|in:general,home,shopping,travel,date,admin', 'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'icon' => 'nullable|string|max:16', 'archived' => 'nullable|boolean']);
        if (array_key_exists('archived', $data)) { $data['archived_at'] = $data['archived'] ? now() : null; unset($data['archived']); }
        $list->update($data);
        return response()->json($list->fresh());
    }

    public function store(Request $request): JsonResponse
    {
        $this->write($request); $this->available();
        $data = $this->validatedTask($request);
        $space = $this->space($request, (int) $data['gallery_space_id']);
        $list = ! empty($data['list_uuid']) ? $this->listInSpace($space, $data['list_uuid']) : $this->todos->ensureDefaultList($space, $request->user());
        $parent = ! empty($data['parent_uuid']) ? SharedTodo::where('uuid', $data['parent_uuid'])->where('gallery_space_id', $space->id)->firstOrFail() : null;
        $this->validateLinks($space, $data);
        $dependencies = $data['dependency_uuids'] ?? [];
        $createCalendar = (bool) ($data['create_calendar_event'] ?? false);
        $attributes = collect($data)->except(['list_uuid', 'parent_uuid', 'event_uuid', 'dependency_uuids', 'create_calendar_event'])->all();
        $attributes['calendar_event_id'] = ! empty($data['event_uuid']) ? CalendarEvent::where('uuid', $data['event_uuid'])->where('gallery_space_id', $space->id)->value('id') : null;
        $attributes += ['created_by' => $request->user()->id, 'list_id' => $list->id, 'parent_id' => $parent?->id, 'status' => 'open', 'sort_order' => ((int) SharedTodo::where('list_id', $list->id)->where('parent_id', $parent?->id)->max('sort_order')) + 1];
        $todo = SharedTodo::create($attributes);
        $this->syncDependencies($todo, $dependencies);
        if ($createCalendar) $this->todos->schedule($todo, $request->user(), ! empty($data['starts_at']) ? Carbon::parse($data['starts_at']) : null);
        $this->notifyAssignment($todo, $request->user()->id);
        return response()->json($this->payload($todo->fresh()->load(['assignee:id,name', 'creator:id,name', 'list:id,uuid,title,color,icon', 'children.assignee:id,name', 'comments.user:id,name'])), 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->write($request); $todo = $this->todo($request, $uuid); $previousAssignee = $todo->assigned_to;
        $data = $this->validatedTask($request, true);
        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] !== null) $this->member($todo->gallery_space_id, (int) $data['assigned_to']);
        if (! empty($data['list_uuid'])) $data['list_id'] = $this->listInSpace(GallerySpace::findOrFail($todo->gallery_space_id), $data['list_uuid'])->id;
        if (! empty($data['event_uuid'])) $data['calendar_event_id'] = CalendarEvent::where('uuid', $data['event_uuid'])->where('gallery_space_id', $todo->gallery_space_id)->value('id');
        if (array_key_exists('completed', $data)) {
            $next = $this->todos->complete($todo, $request->user(), (bool) $data['completed']);
            unset($data['completed']);
            if ($next) $this->notifyAssignment($next, $request->user()->id, 'Opakovaný úkol je připraven');
        }
        $dependencies = $data['dependency_uuids'] ?? null;
        $createCalendar = (bool) ($data['create_calendar_event'] ?? false);
        $todo->update(collect($data)->except(['gallery_space_id', 'list_uuid', 'parent_uuid', 'event_uuid', 'dependency_uuids', 'create_calendar_event'])->all());
        if ($dependencies !== null) $this->syncDependencies($todo, $dependencies);
        if ($createCalendar) $this->todos->schedule($todo, $request->user(), ! empty($data['starts_at']) ? Carbon::parse($data['starts_at']) : null);
        if (array_key_exists('assigned_to', $data) && (int) $previousAssignee !== (int) $data['assigned_to']) $this->notifyAssignment($todo, $request->user()->id);
        return response()->json($this->payload($todo->fresh()->load(['assignee:id,name', 'creator:id,name', 'list:id,uuid,title,color,icon', 'children.assignee:id,name', 'comments.user:id,name'])));
    }

    public function schedule(Request $request, string $uuid): JsonResponse
    {
        $this->write($request); $todo = $this->todo($request, $uuid);
        $data = $request->validate(['starts_at' => 'nullable|date|after:now']);
        $event = $this->todos->schedule($todo, $request->user(), ! empty($data['starts_at']) ? Carbon::parse($data['starts_at']) : null);
        return response()->json(['todo' => $this->payload($todo->fresh()->load(['assignee:id,name', 'creator:id,name', 'list:id,uuid,title,color,icon', 'children.assignee:id,name', 'comments.user:id,name'])), 'event' => ['uuid' => $event->uuid, 'title' => $event->title, 'starts_at' => $event->starts_at]], 201);
    }

    public function comment(Request $request, string $uuid): JsonResponse
    {
        $this->write($request); $todo = $this->todo($request, $uuid);
        $data = $request->validate(['body' => 'required|string|max:3000']);
        $comment = SharedTodoComment::create(['todo_id' => $todo->id, 'user_id' => $request->user()->id, 'body' => $data['body']]);
        foreach (array_unique(array_filter([$todo->created_by, $todo->assigned_to])) as $recipientId) {
            if ((int) $recipientId !== $request->user()->id) \App\Models\User::find($recipientId)?->notify(new GalleryNotification('todo.comment', $request->user()->name . ' přidal/a komentář k úkolu: ' . $todo->title, '/planning#todos', '💬'));
        }
        return response()->json($comment->load('user:id,name'), 201);
    }

    public function reorder(Request $request): JsonResponse
    {
        $this->write($request); $data = $request->validate(['gallery_space_id' => 'required|integer', 'order' => 'required|array|max:300', 'order.*' => 'uuid']);
        $space = $this->space($request, (int) $data['gallery_space_id']);
        DB::transaction(function () use ($data, $space) { foreach ($data['order'] as $position => $uuid) SharedTodo::where('uuid', $uuid)->where('gallery_space_id', $space->id)->update(['sort_order' => $position]); });
        return response()->json(['reordered' => count($data['order'])]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->write($request); $todo = $this->todo($request, $uuid); $todo->delete();
        return response()->json(['status' => 'deleted']);
    }

    private function validatedTask(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'gallery_space_id' => ($updating ? 'sometimes' : 'required') . '|integer', 'list_uuid' => 'nullable|uuid', 'parent_uuid' => 'nullable|uuid',
            'event_uuid' => 'nullable|uuid', 'trip_id' => 'nullable|integer', 'title' => ($updating ? 'sometimes' : 'required') . '|string|max:255',
            'description' => 'nullable|string|max:10000', 'assigned_to' => 'sometimes|nullable|integer',
            'priority' => 'nullable|in:low,normal,high,urgent', 'status' => 'nullable|in:open,in_progress,waiting,completed,cancelled',
            'starts_at' => 'nullable|date', 'due_at' => 'nullable|date|after_or_equal:starts_at', 'remind_at' => 'nullable|date|before_or_equal:due_at', 'estimate_minutes' => 'nullable|integer|between:1,10080',
            'location' => 'nullable|string|max:255', 'tags' => 'nullable|array|max:20', 'tags.*' => 'string|max:40',
            'recurrence' => 'nullable|array', 'recurrence.frequency' => 'required_with:recurrence|in:daily,weekly,monthly,yearly',
            'recurrence.interval' => 'nullable|integer|between:1,365', 'recurrence.until' => 'nullable|date',
            'dependency_uuids' => 'nullable|array|max:20', 'dependency_uuids.*' => 'uuid', 'completed' => 'sometimes|boolean',
            'create_calendar_event' => 'nullable|boolean',
        ]);
    }

    private function validateLinks(GallerySpace $space, array $data): void
    {
        if (! empty($data['assigned_to'])) $this->member($space->id, (int) $data['assigned_to']);
        if (! empty($data['trip_id'])) DB::table('trips')->where('id', $data['trip_id'])->where('gallery_space_id', $space->id)->firstOrFail();
        if (! empty($data['event_uuid'])) CalendarEvent::where('uuid', $data['event_uuid'])->where('gallery_space_id', $space->id)->firstOrFail();
    }

    private function syncDependencies(SharedTodo $todo, array $uuids): void
    {
        $ids = SharedTodo::where('gallery_space_id', $todo->gallery_space_id)->whereIn('uuid', $uuids)->where('id', '!=', $todo->id)->pluck('id');
        abort_if($ids->count() !== count(array_unique($uuids)), 422, 'Některý závislý úkol neexistuje ve společném prostoru.');
        DB::table('shared_todo_dependencies')->where('todo_id', $todo->id)->delete();
        foreach ($ids as $id) DB::table('shared_todo_dependencies')->insert(['todo_id' => $todo->id, 'depends_on_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function payload(SharedTodo $todo): array
    {
        $dependencies = DB::table('shared_todo_dependencies as dependency')->join('shared_todos as source', 'source.id', '=', 'dependency.depends_on_id')->where('dependency.todo_id', $todo->id)->get(['source.uuid', 'source.title', 'source.status']);
        return array_merge($todo->toArray(), [
            'assignee' => $todo->assignee ? ['id' => $todo->assignee->id, 'name' => $todo->assignee->name] : null,
            'creator' => $todo->creator ? ['id' => $todo->creator->id, 'name' => $todo->creator->name] : null,
            'list' => $todo->list, 'children' => $todo->children?->map(fn ($child) => array_merge($child->toArray(), ['assignee' => $child->assignee ? ['id' => $child->assignee->id, 'name' => $child->assignee->name] : null]))->values(),
            'comments' => $todo->comments?->map(fn ($comment) => ['uuid' => $comment->uuid, 'body' => $comment->body, 'created_at' => $comment->created_at, 'user' => $comment->user ? ['id' => $comment->user->id, 'name' => $comment->user->name] : null])->values(),
            'dependencies' => $dependencies, 'is_blocked' => $dependencies->contains(fn ($dependency) => $dependency->status !== 'completed'),
            'href' => $todo->calendar_event_id ? '/calendar/events/' . CalendarEvent::whereKey($todo->calendar_event_id)->value('uuid') : ($todo->trip_id ? '/trips/' . $todo->trip_id . '/plan' : '/planning#todos'),
        ]);
    }

    private function notifyAssignment(SharedTodo $todo, int $actorId, string $prefix = 'Nový společný úkol'): void
    {
        if ($todo->assigned_to && $todo->assigned_to !== $actorId) \App\Models\User::find($todo->assigned_to)?->notify(new GalleryNotification('todo.assigned', $prefix . ': ' . $todo->title, '/planning#todos', '✅', ['todo_uuid' => $todo->uuid]));
    }

    private function members(GallerySpace $space): array { return $space->members()->where('users.is_active', true)->orderBy('users.name')->get(['users.id', 'users.name'])->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->all(); }
    private function member(int $spaceId, int $userId): void { abort_unless(DB::table('gallery_space_user')->where('gallery_space_id', $spaceId)->where('user_id', $userId)->exists(), 422, 'Úkol lze přiřadit pouze členovi společného prostoru.'); }
    private function space(Request $request, ?int $id): GallerySpace { $query = GallerySpace::whereHas('members', fn ($members) => $members->whereKey($request->user()->id)); return $id ? $query->findOrFail($id) : $query->orderByDesc('is_default')->firstOrFail(); }
    private function todo(Request $request, string $uuid): SharedTodo { return SharedTodo::where('uuid', $uuid)->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->firstOrFail(); }
    private function list(Request $request, string $uuid): SharedTodoList { return SharedTodoList::where('uuid', $uuid)->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->firstOrFail(); }
    private function listInSpace(GallerySpace $space, string $uuid): SharedTodoList { return SharedTodoList::where('uuid', $uuid)->where('gallery_space_id', $space->id)->firstOrFail(); }
    private function write(Request $request): void { abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze společné úkoly měnit.'); }
    private function available(): void { abort_unless(Schema::hasTable('shared_todos') && Schema::hasTable('shared_todo_lists'), 503, 'Pro společné úkoly dokončete databázové migrace.'); }
}
