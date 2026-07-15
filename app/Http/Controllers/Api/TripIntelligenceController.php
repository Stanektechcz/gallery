<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Planning\TripBudgetAdvisorService;
use App\Services\Planning\TripPartnerFinanceService;
use App\Services\Planning\TripPreparationTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TripIntelligenceController extends Controller
{
    public function readiness(Request $request, int $tripId, TripBudgetAdvisorService $budgetAdvisor, TripPreparationTimelineService $preparation): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId);
        $limits = DB::table('trip_budget_limits')->where('trip_id', $tripId)->get();
        $expenses = DB::table('trip_expenses')->where('trip_id', $tripId)->where('state', 'actual')->selectRaw('category, currency, SUM(amount) as total')->groupBy('category', 'currency')->get();
        $budget = $limits->map(function ($limit) use ($expenses) { $entry = $expenses->first(fn ($row) => $row->category === $limit->category && $row->currency === $limit->currency); $actual = (float) ($entry?->total ?? 0); return ['category' => $limit->category, 'limit' => (float) $limit->amount, 'actual' => $actual, 'currency' => $limit->currency, 'status' => $actual >= $limit->amount ? 'over' : ($actual >= $limit->amount * $limit->warn_percent / 100 ? 'warning' : 'ok')]; });
        $documentsQuery = DB::table('trip_document_checks as document')->where('document.trip_id', $tripId)->orderBy('document.expires_on');
        $documents = Schema::hasColumn('trip_document_checks', 'assigned_to')
            ? $documentsQuery->leftJoin('users as assignee', 'assignee.id', '=', 'document.assigned_to')->get(['document.*', 'assignee.name as assignee_name'])
            : $documentsQuery->get(['document.*']);
        $expired = $documents->filter(fn ($document) => $document->expires_on && now()->toDateString() > $document->expires_on)->values();
        $activities = DB::table('trip_activities as a')->join('trip_days as d', 'd.id', '=', 'a.trip_day_id')->where('d.trip_id', $tripId)->orderBy('d.date')->orderBy('a.starts_at')->select('a.*', 'd.date')->get();
        $conflicts = $activities->groupBy('date')->flatMap(function ($day) { return $day->values()->zip($day->values()->slice(1))->filter(fn ($pair) => $pair[0]->ends_at && $pair[1]->starts_at && $pair[0]->ends_at > $pair[1]->starts_at)->map(fn ($pair) => ['date' => $pair[0]->date, 'first' => $pair[0]->title, 'second' => $pair[1]->title]); })->values();
        $packing = DB::table('trip_packing_items')->where('trip_id', $tripId);
        $unpackedEssentials = DB::table('trip_packing_items as item')->leftJoin('users as assignee', 'assignee.id', '=', 'item.assigned_to')->where('item.trip_id', $tripId)->where('item.is_essential', true)->where('item.is_packed', false)->get(['item.id', 'item.title', 'item.category', 'item.assigned_to', 'assignee.name as assignee_name']);
        return response()->json(['trip' => $trip, 'budget' => $budget, 'budget_advisor' => $budgetAdvisor->snapshot($trip), 'preparation' => $preparation->snapshot($trip), 'documents' => $documents, 'expired_documents' => $expired, 'time_conflicts' => $conflicts, 'settlements' => DB::table('trip_settlements')->where('trip_id', $tripId)->get(), 'packing' => ['total' => (clone $packing)->count(), 'packed' => (clone $packing)->where('is_packed', true)->count(), 'assigned' => (clone $packing)->whereNotNull('assigned_to')->count(), 'unassigned_essentials' => (clone $packing)->where('is_essential', true)->whereNull('assigned_to')->where('is_packed', false)->count(), 'unpacked_essentials' => $unpackedEssentials], 'vehicle' => $this->vehicleSummary($tripId)]);
    }

    public function upsertBudgetLimit(Request $request, int $tripId): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId); $data = $request->validate(['category' => 'required|in:transport,accommodation,food,activities,insurance,other', 'amount' => 'required|numeric|min:0|max:999999999', 'currency' => 'nullable|string|size:3', 'warn_percent' => 'nullable|integer|between:1,100']);
        DB::table('trip_budget_limits')->updateOrInsert(['trip_id' => $tripId, 'category' => $data['category']], ['amount' => $data['amount'], 'currency' => strtoupper($data['currency'] ?? $trip->currency ?? 'CZK'), 'warn_percent' => $data['warn_percent'] ?? 80, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('trip_budget_limits')->where('trip_id', $tripId)->where('category', $data['category'])->first());
    }

    public function storeDocument(Request $request, int $tripId, TripPreparationTimelineService $preparation): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId); $data = $request->validate(['type' => 'required|in:passport,id_card,insurance,ticket,visa,booking,other', 'title' => 'required|string|max:255', 'expires_on' => 'nullable|date', 'status' => 'nullable|in:required,ready,missing,expired', 'reference' => 'nullable|string|max:5000', 'assigned_to' => 'nullable|integer']);
        if (!empty($data['assigned_to'])) abort_unless($this->member($trip->gallery_space_id, (int) $data['assigned_to']), 422, 'Doklad lze přiřadit pouze členovi společného prostoru.');
        if (!Schema::hasColumn('trip_document_checks', 'assigned_to')) unset($data['assigned_to']);
        $id = DB::table('trip_document_checks')->insertGetId($data + ['trip_id' => $tripId, 'created_by' => $request->user()->id, 'status' => $data['status'] ?? 'required', 'created_at' => now(), 'updated_at' => now()]);
        if ($preparation->canSync()) $preparation->sync($trip);
        return response()->json(DB::table('trip_document_checks')->find($id), 201);
    }

    public function preparationTimeline(Request $request, int $tripId, TripPreparationTimelineService $preparation): JsonResponse
    {
        return response()->json($preparation->snapshot($this->trip($request->user(), $tripId)));
    }

    public function syncPreparationTimeline(Request $request, int $tripId, TripPreparationTimelineService $preparation): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId);
        abort_unless($preparation->canSync(), 503, 'Pro automatickou přípravu dokončete migrace aplikace.');
        $snapshot = $preparation->snapshot($trip);
        return response()->json(['preparation' => $snapshot, 'automation' => $preparation->sync($trip, $snapshot)]);
    }

    public function proposeSettlement(Request $request, int $tripId, TripPartnerFinanceService $finance): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId);
        $data = $request->validate([
            'from_user_id' => 'required|integer|different:to_user_id', 'to_user_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01|max:999999999', 'currency' => 'nullable|string|size:3',
            'note' => 'nullable|string|max:500',
        ]);
        foreach ([$data['from_user_id'], $data['to_user_id']] as $id) {
            abort_unless($this->member($trip->gallery_space_id, $id), 422, 'Vyrovnání je možné jen mezi členy prostoru.');
        }
        $currency = strtoupper($data['currency'] ?? $trip->currency ?? 'CZK');
        $currencySnapshot = collect($finance->snapshot($trip)['currencies'])->firstWhere('currency', $currency);
        $proposal = collect($currencySnapshot['proposals'] ?? [])->first(fn ($item) => (int) $item['from_user_id'] === (int) $data['from_user_id']
            && (int) $item['to_user_id'] === (int) $data['to_user_id']);
        abort_unless($proposal && (float) $data['amount'] <= (float) $proposal['amount'] + 0.004, 422,
            'Saldo se mezitím změnilo. Obnovte přehled a použijte aktuální návrh vyrovnání.');
        $values = ['amount' => round((float) $data['amount'], 2), 'currency' => $currency, 'status' => 'suggested', 'updated_at' => now()];
        if (Schema::hasColumn('trip_settlements', 'created_by')) $values['created_by'] = $request->user()->id;
        if (Schema::hasColumn('trip_settlements', 'note')) $values['note'] = $data['note'] ?? null;
        $existing = DB::table('trip_settlements')->where('trip_id', $tripId)->where('from_user_id', $data['from_user_id'])
            ->where('to_user_id', $data['to_user_id'])->where('currency', $currency)->where('status', 'suggested')->first();
        if ($existing) {
            DB::table('trip_settlements')->where('id', $existing->id)->update($values);
            return response()->json(DB::table('trip_settlements')->find($existing->id));
        }
        $id = DB::table('trip_settlements')->insertGetId($values + ['trip_id' => $tripId, 'from_user_id' => $data['from_user_id'],
            'to_user_id' => $data['to_user_id'], 'created_at' => now()]);
        return response()->json(DB::table('trip_settlements')->find($id), 201);
    }

    public function settle(Request $request, int $tripId, int $settlementId): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId);
        $settlement = DB::table('trip_settlements')->where('id', $settlementId)->where('trip_id', $trip->id)->firstOrFail();
        abort_unless(in_array($request->user()->id, [$settlement->from_user_id, $settlement->to_user_id], true), 403);
        DB::table('trip_settlements')->where('id', $settlementId)->update(['status' => 'settled', 'settled_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('trip_settlements')->find($settlementId));
    }

    public function destroySettlement(Request $request, int $tripId, int $settlementId): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId);
        $settlement = DB::table('trip_settlements')->where('id', $settlementId)->where('trip_id', $trip->id)->firstOrFail();
        abort_unless(in_array($request->user()->id, [(int) $settlement->from_user_id, (int) $settlement->to_user_id], true), 403);
        abort_if($settlement->status === 'settled', 409, 'Uhrazené vyrovnání zůstává v historii a nelze je odstranit.');
        DB::table('trip_settlements')->where('id', $settlementId)->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function financeSummary(Request $request, int $tripId, TripBudgetAdvisorService $budgetAdvisor, TripPartnerFinanceService $finance): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId);
        $goal = DB::table('trip_savings_goals')->where('trip_id', $tripId)->first();
        return response()->json($finance->snapshot($trip) + ['savings_goal' => $goal, 'advisor' => $budgetAdvisor->snapshot($trip)]);
    }

    public function budgetAdvisor(Request $request, int $tripId, TripBudgetAdvisorService $budgetAdvisor): JsonResponse
    {
        return response()->json($budgetAdvisor->snapshot($this->trip($request->user(), $tripId)));
    }

    public function updateBudgetPlan(Request $request, int $tripId, TripBudgetAdvisorService $budgetAdvisor): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId);
        abort_unless(Schema::hasColumn('trips', 'budget_profile') && Schema::hasColumn('trips', 'daily_budget_limit'), 503, 'Pro low-cost plán dokončete migrace aplikace.');
        $data = $request->validate([
            'budget_profile' => 'sometimes|required|in:economy,balanced,comfort',
            'budget' => 'sometimes|nullable|numeric|min:0|max:999999999',
            'daily_budget_limit' => 'sometimes|nullable|numeric|min:0|max:999999999',
            'apply_defaults' => 'nullable|boolean',
            'replace_limits' => 'nullable|boolean',
            'sync_calendar_tasks' => 'nullable|boolean',
        ]);
        $update = collect($data)->only(['budget_profile', 'budget', 'daily_budget_limit'])->all();
        if ($update !== []) {
            DB::table('trips')->where('id', $tripId)->update($update + ['updated_at' => now()]);
        }
        $trip = $this->trip($request->user(), $tripId);
        $limitsApplied = ! empty($data['apply_defaults'])
            ? $budgetAdvisor->applyDefaultLimits($trip, (bool) ($data['replace_limits'] ?? false))
            : 0;
        $snapshot = $budgetAdvisor->snapshot($trip);
        $tasks = ! empty($data['sync_calendar_tasks'])
            ? $budgetAdvisor->syncCalendarTasks($trip, $snapshot)
            : ['event_uuid' => null, 'created' => 0, 'updated' => 0];

        return response()->json(['advisor' => $snapshot, 'automation' => ['limits_applied' => $limitsApplied, 'calendar_tasks' => $tasks]]);
    }

    public function upsertSavingsGoal(Request $request, int $tripId): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId); $data = $request->validate(['target_amount' => 'required|numeric|min:0|max:999999999', 'saved_amount' => 'nullable|numeric|min:0|max:999999999', 'currency' => 'nullable|string|size:3', 'target_date' => 'nullable|date', 'monthly_contribution' => 'nullable|numeric|min:0|max:999999999']);
        DB::table('trip_savings_goals')->updateOrInsert(['trip_id' => $tripId], $data + ['saved_amount' => $data['saved_amount'] ?? 0, 'currency' => strtoupper($data['currency'] ?? $trip->currency ?? 'CZK'), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('trip_savings_goals')->where('trip_id', $tripId)->first());
    }

    public function storeCurrencyRate(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['base_currency' => 'required|string|size:3', 'quote_currency' => 'required|string|size:3|different:base_currency', 'rate' => 'required|numeric|gt:0|max:999999999', 'effective_on' => 'required|date']);
        $row = ['base_currency' => strtoupper($data['base_currency']), 'quote_currency' => strtoupper($data['quote_currency']), 'effective_on' => $data['effective_on']];
        DB::table('currency_rates')->updateOrInsert($row, ['rate' => $data['rate'], 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('currency_rates')->where($row)->first());
    }

    public function savedTransportRoutes(Request $request): JsonResponse
    {
        return response()->json(DB::table('saved_transport_routes')->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->latest()->get());
    }

    public function offlinePackage(Request $request, int $tripId, TripPreparationTimelineService $preparation): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId);
        $days = DB::table('trip_days')->where('trip_id', $tripId)->orderBy('sort_order')->get();
        foreach ($days as $day) $day->activities = DB::table('trip_activities')->where('trip_day_id', $day->id)->orderBy('sort_order')->get();
        $reservations = Schema::hasTable('trip_reservation_imports')
            ? DB::table('trip_reservation_imports')->where('trip_id', $tripId)->where('status', 'confirmed')->orderBy('confirmed_at')->get()
                ->map(function ($item) use ($tripId) {
                    $data = json_decode($item->confirmed_data ?: '{}', true) ?: [];
                    return $data + ['uuid' => $item->uuid, 'original_name' => $item->original_name,
                        'document_url' => $item->storage_path ? "/api/v1/trips/{$tripId}/reservation-imports/{$item->uuid}/download" : null];
                })->values()
            : collect();
        return response()->json([
            'generated_at' => now()->toIso8601String(), 'trip' => $trip, 'days' => $days,
            'documents' => DB::table('trip_document_checks')->where('trip_id', $tripId)->where('status', 'ready')->get(),
            'reservations' => $reservations,
            'emergency_card' => DB::table('travel_emergency_cards')->where('trip_id', $tripId)->first(),
            'selected_route' => DB::table('trip_route_variants')->where('trip_id', $tripId)->where('is_selected', true)->first(),
            'preparation' => $preparation->snapshot($trip),
        ]);
    }

    public function saveTransportRoute(Request $request): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'name' => 'required|string|max:160', 'origin' => 'required|string|max:255', 'destination' => 'required|string|max:255', 'preferences' => 'nullable|array']);
        abort_unless($request->user()->gallerySpaces()->whereKey($data['gallery_space_id'])->exists(), 404);
        $id = DB::table('saved_transport_routes')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $data['gallery_space_id'], 'created_by' => $request->user()->id, 'name' => $data['name'], 'origin' => $data['origin'], 'destination' => $data['destination'], 'preferences' => json_encode($data['preferences'] ?? []), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('saved_transport_routes')->find($id), 201);
    }

    public function locationConsent(Request $request, int $tripId): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId); $data = $request->validate(['recipient_user_id' => 'required|integer|different:' . $request->user()->id, 'expires_at' => 'required|date|after:now']);
        abort_unless($this->member($trip->gallery_space_id, $data['recipient_user_id']), 422, 'Příjemce musí být členem prostoru.');
        DB::table('trip_location_shares')->updateOrInsert(['trip_id' => $tripId, 'owner_user_id' => $request->user()->id, 'recipient_user_id' => $data['recipient_user_id']], ['expires_at' => $data['expires_at'], 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['status' => 'active', 'expires_at' => $data['expires_at']]);
    }

    public function storeTrackPoint(Request $request, int $tripId): JsonResponse
    {
        $this->trip($request->user(), $tripId); $data = $request->validate(['latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'recorded_at' => 'nullable|date']);
        $id = DB::table('trip_track_points')->insertGetId($data + ['trip_id' => $tripId, 'user_id' => $request->user()->id, 'recorded_at' => $data['recorded_at'] ?? now(), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(DB::table('trip_track_points')->find($id), 201);
    }

    public function packingItems(Request $request, int $tripId): JsonResponse
    {
        $this->trip($request->user(), $tripId);
        return response()->json($this->packingRows($tripId)->map(fn ($item) => $this->packingPayload($item))->values());
    }

    public function packingMembers(Request $request, int $tripId): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId);
        return response()->json(DB::table('gallery_space_user as membership')->join('users', 'users.id', '=', 'membership.user_id')->where('membership.gallery_space_id', $trip->gallery_space_id)->orderBy('users.name')->get(['users.id', 'users.name']));
    }

    public function storePackingItem(Request $request, int $tripId): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId);
        $data = $request->validate(['title' => 'required|string|max:255', 'category' => 'nullable|in:documents,clothing,hygiene,electronics,health,car,food,other', 'quantity' => 'nullable|integer|min:1|max:99', 'is_essential' => 'nullable|boolean', 'assigned_to' => 'nullable|integer']);
        if (!empty($data['assigned_to'])) abort_unless($this->member($trip->gallery_space_id, $data['assigned_to']), 422, 'Položku lze přiřadit pouze členovi společného prostoru.');
        $id = DB::table('trip_packing_items')->insertGetId($data + ['uuid' => (string) Str::uuid(), 'trip_id' => $tripId, 'created_by' => $request->user()->id, 'category' => $data['category'] ?? 'other', 'quantity' => $data['quantity'] ?? 1, 'is_essential' => $data['is_essential'] ?? false, 'sort_order' => ((int) DB::table('trip_packing_items')->where('trip_id', $tripId)->max('sort_order')) + 1, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json($this->packingPayload(DB::table('trip_packing_items')->find($id)), 201);
    }

    public function updatePackingItem(Request $request, int $tripId, int $itemId): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId);
        $item = DB::table('trip_packing_items')->where('id', $itemId)->where('trip_id', $tripId)->firstOrFail();
        $data = $request->validate(['title' => 'sometimes|string|max:255', 'category' => 'nullable|in:documents,clothing,hygiene,electronics,health,car,food,other', 'quantity' => 'nullable|integer|min:1|max:99', 'is_essential' => 'nullable|boolean', 'assigned_to' => 'nullable|integer', 'is_packed' => 'nullable|boolean', 'sort_order' => 'nullable|integer|min:0']);
        if (!empty($data['assigned_to'])) abort_unless($this->member($trip->gallery_space_id, $data['assigned_to']), 422, 'Položku lze přiřadit pouze členovi společného prostoru.');
        if (array_key_exists('is_packed', $data)) { $data['packed_at'] = $data['is_packed'] ? now() : null; if (Schema::hasColumn('trip_packing_items', 'packed_by')) $data['packed_by'] = $data['is_packed'] ? $request->user()->id : null; }
        DB::table('trip_packing_items')->where('id', $item->id)->update($data + ['updated_at' => now()]);
        return response()->json($this->packingPayload($this->packingRows($tripId)->firstWhere('id', $item->id)));
    }

    public function destroyPackingItem(Request $request, int $tripId, int $itemId): JsonResponse
    {
        $this->trip($request->user(), $tripId);
        DB::table('trip_packing_items')->where('id', $itemId)->where('trip_id', $tripId)->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function applyPackingTemplate(Request $request, int $tripId): JsonResponse
    {
        $this->trip($request->user(), $tripId);
        $data = $request->validate(['template' => 'required|in:weekend,flight,car,first_aid']);
        $templates = [
            'weekend' => [['Občanský průkaz', 'documents', true], ['Nabíječka', 'electronics', true], ['Základní oblečení', 'clothing', true], ['Hygienické potřeby', 'hygiene', false]],
            'flight' => [['Cestovní doklad', 'documents', true], ['Palubní vstupenka', 'documents', true], ['Powerbanka', 'electronics', true], ['Adaptér do zásuvky', 'electronics', false]],
            'car' => [['Řidičský průkaz', 'documents', true], ['Dálniční známka', 'car', false], ['Parkovací lístek', 'car', false], ['Voda do auta', 'food', false]],
            'first_aid' => [['Léky', 'health', true], ['Kartička pojištěnce', 'documents', true], ['Náplasti', 'health', false], ['Dezinfekce', 'health', false]],
        ];
        $existing = DB::table('trip_packing_items')->where('trip_id', $tripId)->pluck('title')->map(fn ($title) => mb_strtolower($title))->all();
        $order = (int) DB::table('trip_packing_items')->where('trip_id', $tripId)->max('sort_order'); $created = 0;
        foreach ($templates[$data['template']] as [$title, $category, $essential]) {
            if (in_array(mb_strtolower($title), $existing, true)) continue;
            DB::table('trip_packing_items')->insert(['uuid' => (string) Str::uuid(), 'trip_id' => $tripId, 'created_by' => $request->user()->id, 'title' => $title, 'category' => $category, 'quantity' => 1, 'is_essential' => $essential, 'is_packed' => false, 'source_template' => $data['template'], 'sort_order' => ++$order, 'created_at' => now(), 'updated_at' => now()]);
            $created++;
        }
        return response()->json(['created' => $created, 'items' => $this->packingRows($tripId)->map(fn ($item) => $this->packingPayload($item))->values()], 201);
    }

    public function vehicleCosts(Request $request, int $tripId): JsonResponse
    {
        $this->trip($request->user(), $tripId);
        return response()->json(['items' => DB::table('trip_vehicle_costs')->where('trip_id', $tripId)->orderByDesc('occurred_on')->orderByDesc('id')->get(), 'summary' => $this->vehicleSummary($tripId)]);
    }

    public function storeVehicleCost(Request $request, int $tripId, TripPreparationTimelineService $preparation): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId);
        $data = $this->validatedVehicleCost($request);
        $id = DB::table('trip_vehicle_costs')->insertGetId($data + ['uuid' => (string) Str::uuid(), 'trip_id' => $tripId, 'created_by' => $request->user()->id, 'currency' => strtoupper($data['currency'] ?? $trip->currency ?? 'CZK'), 'occurred_on' => $data['occurred_on'] ?? now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        if ($preparation->canSync()) $preparation->sync($trip);
        return response()->json(DB::table('trip_vehicle_costs')->find($id), 201);
    }

    public function updateVehicleCost(Request $request, int $tripId, int $costId, TripPreparationTimelineService $preparation): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId);
        $cost = DB::table('trip_vehicle_costs')->where('id', $costId)->where('trip_id', $tripId)->firstOrFail();
        $data = $this->validatedVehicleCost($request, true);
        if (isset($data['currency'])) $data['currency'] = strtoupper($data['currency']);
        DB::table('trip_vehicle_costs')->where('id', $cost->id)->update($data + ['updated_at' => now()]);
        if ($preparation->canSync()) $preparation->sync($trip);
        return response()->json(DB::table('trip_vehicle_costs')->find($cost->id));
    }

    public function destroyVehicleCost(Request $request, int $tripId, int $costId, TripPreparationTimelineService $preparation): JsonResponse
    {
        $trip = $this->trip($request->user(), $tripId);
        DB::table('trip_vehicle_costs')->where('id', $costId)->where('trip_id', $tripId)->delete();
        if ($preparation->canSync()) $preparation->sync($trip);
        return response()->json(['status' => 'deleted']);
    }

    private function trip(User $user, int $id): object { return DB::table('trips')->where('id', $id)->whereIn('gallery_space_id', $user->gallerySpaces()->pluck('gallery_spaces.id'))->firstOrFail(); }
    private function member(int $spaceId, int $userId): bool { return DB::table('gallery_space_user')->where('gallery_space_id', $spaceId)->where('user_id', $userId)->exists(); }
    private function packingRows(int $tripId): \Illuminate\Support\Collection { $query = DB::table('trip_packing_items as item')->leftJoin('users as assignee', 'assignee.id', '=', 'item.assigned_to')->where('item.trip_id', $tripId)->orderBy('item.is_packed')->orderBy('item.category')->orderBy('item.sort_order'); if (Schema::hasColumn('trip_packing_items', 'packed_by')) $query->leftJoin('users as packer', 'packer.id', '=', 'item.packed_by')->select(['item.*', 'assignee.name as assignee_name', 'packer.name as packed_by_name']); else $query->select(['item.*', 'assignee.name as assignee_name']); return $query->get(); }
    private function packingPayload(object $item): array { $payload = (array) $item; $payload['is_packed'] = (bool) $item->is_packed; $payload['is_essential'] = (bool) $item->is_essential; return $payload; }
    private function validatedVehicleCost(Request $request, bool $partial = false): array { $prefix = $partial ? 'sometimes|' : 'required|'; return $request->validate(['type' => $prefix . 'in:fuel,parking,vignette,toll,maintenance,other', 'title' => $prefix . 'string|max:255', 'amount' => $prefix . 'numeric|min:0|max:999999999', 'currency' => 'nullable|string|size:3', 'liters' => 'nullable|numeric|min:0|max:9999', 'distance_km' => 'nullable|numeric|min:0|max:9999999', 'odometer_km' => 'nullable|integer|min:0|max:9999999', 'occurred_on' => $partial ? 'nullable|date' : 'nullable|date', 'valid_until' => 'nullable|date', 'notes' => 'nullable|string|max:5000']); }
    private function vehicleSummary(int $tripId): array { $items = DB::table('trip_vehicle_costs')->where('trip_id', $tripId)->get(); $total = (float) $items->sum('amount'); $distance = (float) $items->sum('distance_km'); $fuel = $items->where('type', 'fuel'); return ['total' => $total, 'distance_km' => $distance, 'fuel_liters' => (float) $fuel->sum('liters'), 'cost_per_km' => $distance > 0 ? round($total / $distance, 2) : null, 'expired_vignettes' => $items->where('type', 'vignette')->filter(fn ($item) => $item->valid_until && $item->valid_until < now()->toDateString())->values()]; }
}
