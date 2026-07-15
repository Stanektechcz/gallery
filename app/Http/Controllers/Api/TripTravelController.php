<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Planning\TripPreparationTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Saves a real travel choice once and projects it into itinerary, budget and documents. */
class TripTravelController extends Controller
{
    public function choices(Request $request, int $tripId): JsonResponse
    {
        $this->trip($request, $tripId);
        return response()->json(DB::table('trip_travel_choices')->where('trip_id', $tripId)->latest()->get());
    }

    public function bookingSearch(Request $request, int $tripId): JsonResponse
    {
        $this->trip($request, $tripId);
        $data = $request->validate(['destination' => 'required|string|max:255', 'checkin' => 'required|date', 'checkout' => 'required|date|after:checkin', 'adults' => 'nullable|integer|min:1|max:12']);
        return response()->json(['provider' => 'Booking.com', 'kind' => 'search_link', 'url' => 'https://www.booking.com/searchresults.html?' . http_build_query(['ss' => $data['destination'], 'checkin' => $data['checkin'], 'checkout' => $data['checkout'], 'group_adults' => $data['adults'] ?? 2, 'no_rooms' => 1, 'group_children' => 0, 'lang' => 'cs']), 'notice' => 'Cena a dostupnost se ověřují přímo u Booking.com; po výběru ubytování jej uložte zpět do cesty.']);
    }

    public function storeTransport(Request $request, int $tripId, TripPreparationTimelineService $preparation): JsonResponse
    {
        $trip = $this->trip($request, $tripId);
        $data = $request->validate(['title' => 'required|string|max:255', 'provider' => 'nullable|string|max:80', 'source_url' => 'nullable|url|max:2048', 'amount' => 'nullable|numeric|min:0|max:999999999', 'currency' => 'nullable|string|size:3', 'estimated_minutes' => 'nullable|integer|min:0|max:10080', 'transport_modes' => 'nullable|array|max:8', 'details' => 'nullable|array', 'is_selected' => 'nullable|boolean']);
        $this->secureUrl($data['source_url'] ?? null);
        $selected = $data['is_selected'] ?? true;
        if ($selected) { DB::table('trip_travel_choices')->where('trip_id', $tripId)->where('kind', 'transport')->update(['is_selected' => false, 'updated_at' => now()]); DB::table('trip_route_variants')->where('trip_id', $tripId)->update(['is_selected' => false, 'updated_at' => now()]); }
        $variantId = DB::table('trip_route_variants')->insertGetId(['trip_id' => $tripId, 'created_by' => $request->user()->id, 'title' => $data['title'], 'strategy' => 'custom', 'transport_modes' => json_encode($data['transport_modes'] ?? []), 'estimated_minutes' => $data['estimated_minutes'] ?? null, 'estimated_cost' => $data['amount'] ?? null, 'currency' => strtoupper($data['currency'] ?? $trip->currency ?? 'CZK'), 'is_selected' => $selected, 'data' => json_encode($data['details'] ?? []), 'created_at' => now(), 'updated_at' => now()]);
        $expenseId = array_key_exists('amount', $data) && $data['amount'] !== null ? DB::table('trip_expenses')->insertGetId(['trip_id' => $tripId, 'created_by' => $request->user()->id, 'title' => $data['title'], 'category' => 'transport', 'amount' => $data['amount'], 'currency' => strtoupper($data['currency'] ?? $trip->currency ?? 'CZK'), 'state' => 'planned', 'created_at' => now(), 'updated_at' => now()]) : null;
        $choice = $this->choice($tripId, $request->user()->id, 'transport', $data, $variantId, null, $expenseId, $selected);
        if ($preparation->canSync()) $preparation->sync($trip);
        return response()->json($choice, 201);
    }

    public function storeAccommodation(Request $request, int $tripId, TripPreparationTimelineService $preparation): JsonResponse
    {
        $trip = $this->trip($request, $tripId);
        $data = $request->validate(['trip_day_id' => 'required|integer', 'title' => 'required|string|max:255', 'provider' => 'nullable|string|max:80', 'source_url' => 'nullable|url|max:2048', 'amount' => 'nullable|numeric|min:0|max:999999999', 'currency' => 'nullable|string|size:3', 'reference' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:5000', 'checkin' => 'nullable|date', 'checkout' => 'nullable|date|after_or_equal:checkin', 'is_selected' => 'nullable|boolean']);
        $this->secureUrl($data['source_url'] ?? null);
        $day = DB::table('trip_days')->where('id', $data['trip_day_id'])->where('trip_id', $tripId)->firstOrFail();
        $selected = $data['is_selected'] ?? true;
        if ($selected) DB::table('trip_travel_choices')->where('trip_id', $tripId)->where('kind', 'accommodation')->update(['is_selected' => false, 'updated_at' => now()]);
        $activityId = DB::table('trip_activities')->insertGetId(['trip_day_id' => $day->id, 'created_by' => $request->user()->id, 'type' => 'stay', 'title' => $data['title'], 'description' => $data['notes'] ?? null, 'place_name' => $data['title'], 'status' => 'planned', 'currency' => strtoupper($data['currency'] ?? $trip->currency ?? 'CZK'), 'metadata' => json_encode(['provider' => $data['provider'] ?? 'Booking.com', 'source_url' => $data['source_url'] ?? null, 'checkin' => $data['checkin'] ?? null, 'checkout' => $data['checkout'] ?? null]), 'sort_order' => ((int) DB::table('trip_activities')->where('trip_day_id', $day->id)->max('sort_order')) + 1, 'created_at' => now(), 'updated_at' => now()]);
        $expenseId = array_key_exists('amount', $data) && $data['amount'] !== null ? DB::table('trip_expenses')->insertGetId(['trip_id' => $tripId, 'created_by' => $request->user()->id, 'title' => $data['title'], 'category' => 'accommodation', 'amount' => $data['amount'], 'currency' => strtoupper($data['currency'] ?? $trip->currency ?? 'CZK'), 'state' => 'planned', 'created_at' => now(), 'updated_at' => now()]) : null;
        if (!empty($data['reference'])) DB::table('trip_document_checks')->updateOrInsert(['trip_id' => $tripId, 'title' => $data['title'], 'reference' => $data['reference']], ['created_by' => $request->user()->id, 'type' => 'booking', 'status' => 'ready', 'updated_at' => now(), 'created_at' => now()]);
        $choice = $this->choice($tripId, $request->user()->id, 'accommodation', $data, null, $activityId, $expenseId, $selected);
        if ($preparation->canSync()) $preparation->sync($trip);
        return response()->json($choice, 201);
    }

    private function choice(int $tripId, int $userId, string $kind, array $data, ?int $variantId, ?int $activityId, ?int $expenseId, bool $selected): object
    {
        $id = DB::table('trip_travel_choices')->insertGetId(['uuid' => (string) Str::uuid(), 'trip_id' => $tripId, 'created_by' => $userId, 'trip_route_variant_id' => $variantId, 'trip_activity_id' => $activityId, 'trip_expense_id' => $expenseId, 'kind' => $kind, 'provider' => $data['provider'] ?? null, 'title' => $data['title'], 'source_url' => $data['source_url'] ?? null, 'amount' => $data['amount'] ?? null, 'currency' => strtoupper($data['currency'] ?? 'CZK'), 'is_selected' => $selected, 'details' => json_encode($data['details'] ?? array_filter(['reference' => $data['reference'] ?? null, 'checkin' => $data['checkin'] ?? null, 'checkout' => $data['checkout'] ?? null])), 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('trip_travel_choices')->find($id);
    }
    private function trip(Request $request, int $tripId): object { return DB::table('trips')->where('id', $tripId)->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->firstOrFail(); }
    private function secureUrl(?string $url): void { if ($url && ! Str::startsWith($url, 'https://')) abort(422, 'Odkaz musí používat HTTPS.'); }
}
