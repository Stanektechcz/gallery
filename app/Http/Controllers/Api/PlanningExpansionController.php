<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Optional planning tools with explicit consent; no external data is silently imported. */
class PlanningExpansionController extends Controller
{
    public function templates(Request $request): JsonResponse
    {
        if (! $this->tablesExist(['event_templates'])) return response()->json([]);
        return response()->json(DB::table('event_templates')->whereIn('gallery_space_id', $this->spaceIds($request->user()))->latest()->get());
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $this->requireTables(['event_templates']);
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'title' => 'required|string|max:160', 'type' => 'nullable|in:event,trip,outing,birthday,anniversary,reservation,custom', 'description' => 'nullable|string|max:10000', 'defaults' => 'nullable|array', 'tasks' => 'nullable|array|max:50', 'tasks.*.title' => 'required_with:tasks|string|max:255']);
        $this->space($request->user(), $data['gallery_space_id']);
        $id = DB::table('event_templates')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $data['gallery_space_id'], 'created_by' => $request->user()->id, 'title' => $data['title'], 'type' => $data['type'] ?? 'event', 'description' => $data['description'] ?? null, 'defaults' => json_encode($data['defaults'] ?? []), 'tasks' => json_encode($data['tasks'] ?? []), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('event_templates')->find($id), 201);
    }

    public function applyTemplate(Request $request, string $uuid): JsonResponse
    {
        $this->requireTables(['event_templates']);
        $template = DB::table('event_templates')->where('uuid', $uuid)->whereIn('gallery_space_id', $this->spaceIds($request->user()))->firstOrFail();
        $data = $request->validate(['starts_at' => 'required|date', 'ends_at' => 'nullable|date|after_or_equal:starts_at', 'title' => 'nullable|string|max:160']);
        $defaults = json_decode($template->defaults ?: '{}', true) ?: [];
        $event = CalendarEvent::create([
            'gallery_space_id' => $template->gallery_space_id, 'created_by' => $request->user()->id,
            'title' => $data['title'] ?? $template->title, 'description' => $template->description,
            'type' => $template->type, 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'] ?? null,
            'timezone' => $defaults['timezone'] ?? 'Europe/Prague', 'place_name' => $defaults['place_name'] ?? null,
            'departure_buffer_minutes' => $defaults['departure_buffer_minutes'] ?? null,
            'color' => $defaults['color'] ?? null,
        ]);
        $event->participants()->attach($request->user()->id, ['role' => 'owner', 'response' => 'accepted']);
        foreach (json_decode($template->tasks ?: '[]', true) ?: [] as $order => $task) $event->tasks()->create(['title' => $task['title'], 'priority' => $task['priority'] ?? 'normal', 'sort_order' => $order]);
        return response()->json($event->load('tasks'), 201);
    }

    public function exceptions(Request $request, string $eventUuid): JsonResponse
    {
        if (! $this->tablesExist(['calendar_event_exceptions'])) return response()->json([]);
        $event = $this->event($request->user(), $eventUuid);
        return response()->json(DB::table('calendar_event_exceptions')->where('event_id', $event->id)->orderBy('occurs_at')->get());
    }

    public function storeException(Request $request, string $eventUuid): JsonResponse
    {
        $this->requireTables(['calendar_event_exceptions']);
        $event = $this->editableEvent($request->user(), $eventUuid);
        $data = $request->validate(['occurs_at' => 'required|date', 'action' => 'required|in:skip,move', 'replacement_starts_at' => 'required_if:action,move|nullable|date', 'replacement_ends_at' => 'nullable|date|after_or_equal:replacement_starts_at', 'replacement_title' => 'nullable|string|max:160']);
        DB::table('calendar_event_exceptions')->updateOrInsert(['event_id' => $event->id, 'occurs_at' => $data['occurs_at']], ['action' => $data['action'], 'replacement_starts_at' => $data['replacement_starts_at'] ?? null, 'replacement_ends_at' => $data['replacement_ends_at'] ?? null, 'replacement_title' => $data['replacement_title'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('calendar_event_exceptions')->where('event_id', $event->id)->where('occurs_at', $data['occurs_at'])->first());
    }

    public function availability(Request $request): JsonResponse
    {
        $preferences = $request->user()->preferences ?? [];
        return response()->json(['availability' => $preferences['planning_availability'] ?? [], 'quiet_hours' => $preferences['quiet_hours'] ?? null]);
    }

    public function updateAvailability(Request $request): JsonResponse
    {
        $data = $request->validate(['availability' => 'required|array|max:14', 'availability.*.from' => 'required|date_format:H:i', 'availability.*.to' => 'required|date_format:H:i|after:availability.*.from', 'availability.*.weekday' => 'required|integer|between:0,6', 'quiet_hours' => 'nullable|array', 'quiet_hours.from' => 'required_with:quiet_hours|date_format:H:i', 'quiet_hours.to' => 'required_with:quiet_hours|date_format:H:i']);
        $preferences = $request->user()->preferences ?? [];
        $preferences['planning_availability'] = $data['availability']; $preferences['quiet_hours'] = $data['quiet_hours'] ?? null;
        $request->user()->update(['preferences' => $preferences]);
        return response()->json(['availability' => $preferences['planning_availability'], 'quiet_hours' => $preferences['quiet_hours']]);
    }

    public function wishlists(Request $request): JsonResponse
    {
        if (! $this->tablesExist(['travel_wishlists', 'travel_wishlist_items'])) return response()->json([]);
        $lists = DB::table('travel_wishlists')->whereIn('gallery_space_id', $this->spaceIds($request->user()))->latest()->get();
        foreach ($lists as $list) $list->items = DB::table('travel_wishlist_items')->where('wishlist_id', $list->id)->where('status', 'open')->orderBy('priority')->get();
        return response()->json($lists);
    }

    public function storeWishlist(Request $request): JsonResponse
    {
        $this->requireTables(['travel_wishlists']);
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'title' => 'required|string|max:160', 'is_shared' => 'nullable|boolean']);
        $this->space($request->user(), $data['gallery_space_id']);
        $id = DB::table('travel_wishlists')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $data['gallery_space_id'], 'created_by' => $request->user()->id, 'title' => $data['title'], 'is_shared' => $data['is_shared'] ?? true, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('travel_wishlists')->find($id), 201);
    }

    public function storeWishlistItem(Request $request, string $uuid): JsonResponse
    {
        $this->requireTables(['travel_wishlists', 'travel_wishlist_items']);
        $list = DB::table('travel_wishlists')->where('uuid', $uuid)->whereIn('gallery_space_id', $this->spaceIds($request->user()))->firstOrFail();
        $data = $request->validate(['title' => 'required|string|max:255', 'notes' => 'nullable|string|max:5000', 'category' => 'nullable|in:place,food,experience,stay,photo,other', 'season' => 'nullable|string|max:32', 'priority' => 'nullable|integer|between:1,5', 'estimated_cost' => 'nullable|numeric|min:0|max:999999999', 'currency' => 'nullable|string|size:3', 'estimated_minutes' => 'nullable|integer|min:0|max:10080', 'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180']);
        $id = DB::table('travel_wishlist_items')->insertGetId($data + ['wishlist_id' => $list->id, 'created_by' => $request->user()->id, 'category' => $data['category'] ?? 'place', 'priority' => $data['priority'] ?? 3, 'currency' => strtoupper($data['currency'] ?? 'CZK'), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('travel_wishlist_items')->find($id), 201);
    }

    public function wishlistSuggestions(Request $request, string $uuid): JsonResponse
    {
        $this->requireTables(['travel_wishlists', 'travel_wishlist_items']);
        $list = DB::table('travel_wishlists')->where('uuid', $uuid)->whereIn('gallery_space_id', $this->spaceIds($request->user()))->firstOrFail();
        $from = now()->startOfDay(); $to = now()->addDays(90)->endOfDay();
        $events = CalendarEvent::where('gallery_space_id', $list->gallery_space_id)->whereBetween('starts_at', [$from, $to])->get(['starts_at', 'ends_at']);
        $freeWeekends = collect(range(0, 12))->map(function (int $week) use ($events) {
            $date = now()->startOfWeek()->addWeeks($week)->next(Carbon::SATURDAY)->startOfDay();
            $busy = $events->contains(fn ($event) => $event->starts_at->betweenIncluded($date, $date->copy()->endOfDay()));
            return ['date' => $date->toDateString(), 'available' => !$busy];
        })->filter(fn ($slot) => $slot['available'])->values();
        return response()->json(['items' => DB::table('travel_wishlist_items')->where('wishlist_id', $list->id)->where('status', 'open')->orderBy('priority')->get(), 'free_weekends' => $freeWeekends]);
    }

    /** Turn a wish into one shared calendar plan, without creating a disconnected copy of it. */
    public function planWishlistItem(Request $request, string $uuid, int $itemId): JsonResponse
    {
        $this->requireTables(['travel_wishlists', 'travel_wishlist_items', 'calendar_events', 'event_participants', 'event_reminders']);
        abort_unless(Schema::hasColumn('travel_wishlist_items', 'calendar_event_id'), 503, 'Pro převod přání do kalendáře dokončete migrace aplikace.');
        $list = DB::table('travel_wishlists')->where('uuid', $uuid)->whereIn('gallery_space_id', $this->spaceIds($request->user()))->firstOrFail();
        $item = DB::table('travel_wishlist_items')->where('id', $itemId)->where('wishlist_id', $list->id)->firstOrFail();
        if ($item->calendar_event_id) return response()->json(CalendarEvent::findOrFail($item->calendar_event_id)->load('participants:id,name,email', 'reminders'));
        $data = $request->validate(['starts_at' => 'nullable|date|after:now']);
        $startsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : $this->nextFreeSaturday($list->gallery_space_id);
        $event = CalendarEvent::create([
            'gallery_space_id' => $list->gallery_space_id,
            'created_by' => $request->user()->id,
            'title' => $item->title,
            'description' => $item->notes ?: 'Vzniklo ze společného seznamu přání.',
            'type' => 'outing',
            'status' => 'planned',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(max(30, (int) ($item->estimated_minutes ?: 120))),
            'timezone' => 'Europe/Prague',
            'place_name' => $item->category === 'place' ? $item->title : null,
            'latitude' => $item->latitude,
            'longitude' => $item->longitude,
            'is_private' => false,
            'metadata' => ['source' => 'wishlist'],
        ]);
        foreach (DB::table('gallery_space_user')->where('gallery_space_id', $list->gallery_space_id)->pluck('user_id') as $memberId) {
            $event->participants()->syncWithoutDetaching([(int) $memberId => ['role' => (int) $memberId === $request->user()->id ? 'owner' : 'guest', 'response' => (int) $memberId === $request->user()->id ? 'accepted' : 'pending']]);
            $event->reminders()->create(['user_id' => $memberId, 'channel' => 'database', 'remind_at' => $startsAt->copy()->subDays(7), 'status' => 'pending']);
        }
        DB::table('travel_wishlist_items')->where('id', $item->id)->update(['calendar_event_id' => $event->id, 'status' => 'planned', 'updated_at' => now()]);
        return response()->json($event->load('participants:id,name,email', 'reminders'), 201);
    }

    public function polls(Request $request): JsonResponse
    {
        if (! $this->tablesExist(['decision_polls', 'decision_poll_options', 'decision_poll_votes'])) return response()->json([]);
        $polls = DB::table('decision_polls')->whereIn('gallery_space_id', $this->spaceIds($request->user()))->latest()->get();
        $hasCalendarLink = Schema::hasColumn('decision_poll_options', 'calendar_event_id');
        foreach ($polls as $poll) {
            $groups = ['o.id', 'o.title', 'o.notes', 'o.sort_order', 'o.created_at', 'o.updated_at'];
            if ($hasCalendarLink) $groups[] = 'o.calendar_event_id';
            $poll->options = DB::table('decision_poll_options as o')->leftJoin('decision_poll_votes as v', 'v.poll_option_id', '=', 'o.id')->where('o.poll_id', $poll->id)->groupBy(...$groups)->select('o.*', DB::raw('COUNT(v.id) as votes'))->orderBy('o.sort_order')->get();
        }
        return response()->json($polls);
    }

    public function storePoll(Request $request): JsonResponse
    {
        $this->requireTables(['decision_polls', 'decision_poll_options', 'decision_poll_votes']);
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'question' => 'required|string|max:255', 'closes_at' => 'nullable|date|after:now', 'options' => 'required|array|min:2|max:8', 'options.*.title' => 'required|string|max:255', 'options.*.notes' => 'nullable|string|max:5000']);
        $this->space($request->user(), $data['gallery_space_id']);
        $id = DB::transaction(function () use ($data, $request) { $id = DB::table('decision_polls')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $data['gallery_space_id'], 'created_by' => $request->user()->id, 'question' => $data['question'], 'closes_at' => $data['closes_at'] ?? null, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]); foreach ($data['options'] as $order => $option) DB::table('decision_poll_options')->insert(['poll_id' => $id, 'title' => $option['title'], 'notes' => $option['notes'] ?? null, 'sort_order' => $order, 'created_at' => now(), 'updated_at' => now()]); return $id; });
        return response()->json(DB::table('decision_polls')->find($id), 201);
    }

    public function vote(Request $request, string $uuid): JsonResponse
    {
        $this->requireTables(['decision_polls', 'decision_poll_options', 'decision_poll_votes']);
        $poll = DB::table('decision_polls')->where('uuid', $uuid)->whereIn('gallery_space_id', $this->spaceIds($request->user()))->where('status', 'open')->firstOrFail();
        abort_if($poll->closes_at && Carbon::parse($poll->closes_at)->isPast(), 422, 'Hlasování již skončilo.');
        $data = $request->validate(['option_id' => 'required|integer']);
        DB::table('decision_poll_options')->where('id', $data['option_id'])->where('poll_id', $poll->id)->firstOrFail();
        DB::table('decision_poll_votes')->where('user_id', $request->user()->id)->whereIn('poll_option_id', DB::table('decision_poll_options')->where('poll_id', $poll->id)->pluck('id'))->delete();
        DB::table('decision_poll_votes')->insert(['poll_option_id' => $data['option_id'], 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['status' => 'voted']);
    }

    /** A decision becomes a shared date, instead of remaining an orphaned poll result. */
    public function planPollOption(Request $request, string $uuid, int $optionId): JsonResponse
    {
        $this->requireTables(['decision_polls', 'decision_poll_options', 'decision_poll_votes', 'calendar_events', 'event_participants', 'event_reminders']);
        abort_unless(Schema::hasColumn('decision_poll_options', 'calendar_event_id'), 503, 'Pro převod rozhodnutí do kalendáře dokončete migrace aplikace.');
        $poll = DB::table('decision_polls')->where('uuid', $uuid)->whereIn('gallery_space_id', $this->spaceIds($request->user()))->firstOrFail();
        $option = DB::table('decision_poll_options')->where('id', $optionId)->where('poll_id', $poll->id)->firstOrFail();
        if ($option->calendar_event_id) return response()->json(CalendarEvent::findOrFail($option->calendar_event_id)->load('participants:id,name,email', 'reminders'));
        abort_if($poll->status === 'decided', 422, 'Toto hlasování už je převedené na společnou akci.');
        $data = $request->validate(['starts_at' => 'nullable|date|after:now']);
        $startsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : $this->nextFreeSaturday($poll->gallery_space_id);
        $event = CalendarEvent::create(['gallery_space_id' => $poll->gallery_space_id, 'created_by' => $request->user()->id, 'title' => $option->title, 'description' => "Vzniklo ze společného rozhodnutí: {$poll->question}", 'type' => 'outing', 'status' => 'planned', 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHours(2), 'timezone' => 'Europe/Prague', 'is_private' => false, 'metadata' => ['source' => 'poll']]);
        foreach (DB::table('gallery_space_user')->where('gallery_space_id', $poll->gallery_space_id)->pluck('user_id') as $memberId) {
            $event->participants()->syncWithoutDetaching([(int) $memberId => ['role' => (int) $memberId === $request->user()->id ? 'owner' : 'guest', 'response' => (int) $memberId === $request->user()->id ? 'accepted' : 'pending']]);
            $event->reminders()->create(['user_id' => $memberId, 'channel' => 'database', 'remind_at' => $startsAt->copy()->subDays(7), 'status' => 'pending']);
        }
        DB::table('decision_poll_options')->where('id', $option->id)->update(['calendar_event_id' => $event->id, 'updated_at' => now()]);
        DB::table('decision_polls')->where('id', $poll->id)->update(['status' => 'decided', 'updated_at' => now()]);
        return response()->json($event->load('participants:id,name,email', 'reminders'), 201);
    }

    public function emergencyCard(Request $request, int $tripId): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId);
        return response()->json(DB::table('travel_emergency_cards')->where('trip_id', $trip->id)->first());
    }

    public function updateEmergencyCard(Request $request, int $tripId): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId);
        $data = $request->validate(['accommodation_name' => 'nullable|string|max:255', 'accommodation_address' => 'nullable|string|max:5000', 'accommodation_phone' => 'nullable|string|max:64', 'insurance_provider' => 'nullable|string|max:255', 'insurance_number' => 'nullable|string|max:255', 'contacts' => 'nullable|array|max:10', 'important_numbers' => 'nullable|array|max:20', 'notes' => 'nullable|string|max:5000']);
        $payload = $data;
        $payload['updated_by'] = $request->user()->id;
        $payload['contacts'] = json_encode($data['contacts'] ?? []);
        $payload['important_numbers'] = json_encode($data['important_numbers'] ?? []);
        $existing = DB::table('travel_emergency_cards')->where('trip_id', $trip->id)->first();
        if ($existing) DB::table('travel_emergency_cards')->where('trip_id', $trip->id)->update($payload + ['updated_at' => now()]);
        else DB::table('travel_emergency_cards')->insert($payload + ['trip_id' => $trip->id, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('travel_emergency_cards')->where('trip_id', $trip->id)->first());
    }

    public function partnerRules(Request $request): JsonResponse
    {
        return response()->json(DB::table('partner_share_rules')->where('owner_user_id', $request->user()->id)->whereIn('gallery_space_id', $this->spaceIds($request->user()))->latest()->get());
    }

    public function storePartnerRule(Request $request): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'recipient_user_id' => 'required|integer|different:' . $request->user()->id, 'name' => 'required|string|max:160', 'is_active' => 'nullable|boolean', 'filters' => 'nullable|array']);
        $space = $this->space($request->user(), $data['gallery_space_id']);
        abort_unless($space->members()->whereKey($data['recipient_user_id'])->exists(), 422, 'Příjemce musí být členem společného prostoru.');
        $id = DB::table('partner_share_rules')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $space->id, 'owner_user_id' => $request->user()->id, 'recipient_user_id' => $data['recipient_user_id'], 'name' => $data['name'], 'is_active' => $data['is_active'] ?? true, 'filters' => json_encode($data['filters'] ?? []), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('partner_share_rules')->find($id), 201);
    }

    public function previewPartnerRule(Request $request, string $uuid): JsonResponse
    {
        $rule = DB::table('partner_share_rules')->where('uuid', $uuid)->where('owner_user_id', $request->user()->id)->firstOrFail();
        $filters = json_decode($rule->filters ?: '{}', true) ?: []; $query = MediaItem::where('gallery_space_id', $rule->gallery_space_id)->whereNull('trashed_at')->where('is_hidden', false);
        if (!empty($filters['from'])) $query->whereDate('taken_at', '>=', $filters['from']); if (!empty($filters['to'])) $query->whereDate('taken_at', '<=', $filters['to']);
        $items = $query->latest('taken_at')->limit(24)->get(['id', 'uuid', 'display_title', 'original_filename', 'taken_at']);
        DB::table('partner_share_rules')->where('id', $rule->id)->update(['last_previewed_at' => now(), 'updated_at' => now()]);
        return response()->json(['rule' => $rule, 'preview' => $items, 'notice' => 'Náhled nic automaticky nesdílí.']);
    }

    public function exportIcs(Request $request, string $eventUuid)
    {
        $event = $this->event($request->user(), $eventUuid); $start = $event->starts_at->format('Ymd\\THis'); $end = ($event->ends_at ?? $event->starts_at)->format('Ymd\\THis');
        $esc = fn (?string $value) => str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], $value ?? '');
        $calendar = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Stanektech Gallery//CS\r\nBEGIN:VEVENT\r\nUID:{$event->uuid}\r\nDTSTART;TZID={$event->timezone}:{$start}\r\nDTEND;TZID={$event->timezone}:{$end}\r\nSUMMARY:" . $esc($event->title) . "\r\nLOCATION:" . $esc($event->place_name) . "\r\nDESCRIPTION:" . $esc($event->description) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        return response($calendar, 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="akce-' . $event->uuid . '.ics"']);
    }

    private function spaceIds(User $user): array { return $user->gallerySpaces()->pluck('gallery_spaces.id')->all(); }
    private function tablesExist(array $tables): bool { foreach ($tables as $table) if (! Schema::hasTable($table)) return false; return true; }
    private function requireTables(array $tables): void { abort_unless($this->tablesExist($tables), 503, 'Plánovací data nejsou ještě připravená. Spusťte na serveru php artisan migrate --force.'); }
    private function nextFreeSaturday(int $spaceId): Carbon { $events = CalendarEvent::where('gallery_space_id', $spaceId)->where('starts_at', '>=', now()->startOfDay())->get(['starts_at', 'ends_at']); for ($week = 0; $week < 13; $week++) { $candidate = now()->startOfWeek()->addWeeks($week)->next(Carbon::SATURDAY)->setTime(10, 0); if (! $events->contains(fn (CalendarEvent $event) => $event->starts_at->lte($candidate->copy()->endOfDay()) && ($event->ends_at ?? $event->starts_at)->gte($candidate->copy()->startOfDay()))) return $candidate; } return now()->addWeeks(13)->next(Carbon::SATURDAY)->setTime(10, 0); }
    private function space(User $user, int $id) { return $user->gallerySpaces()->whereKey($id)->firstOrFail(); }
    private function trip(User $user, int $id): object { return DB::table('trips')->where('id', $id)->whereIn('gallery_space_id', $this->spaceIds($user))->firstOrFail(); }
    private function event(User $user, string $uuid): CalendarEvent { return CalendarEvent::whereIn('gallery_space_id', $this->spaceIds($user))->where('uuid', $uuid)->where(fn ($q) => $q->where('is_private', false)->orWhere('created_by', $user->id)->orWhereHas('participants', fn ($p) => $p->whereKey($user->id)))->firstOrFail(); }
    private function editableEvent(User $user, string $uuid): CalendarEvent { $event = $this->event($user, $uuid); abort_unless($event->created_by === $user->id || $event->participants()->whereKey($user->id)->wherePivot('role', 'editor')->exists(), 403); return $event; }
}
