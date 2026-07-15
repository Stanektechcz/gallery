<?php

namespace Tests\Feature;

use App\Console\Commands\SendPlanningFollowupsCommand;
use App\Console\Commands\SendRelationshipMilestoneRemindersCommand;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlanningExpansionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $partner;
    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->partner = User::factory()->create(['role' => 'partner']);
        $this->space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Plán', 'slug' => 'plan', 'owner_id' => $this->owner->id]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->space->members()->attach($this->partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->actingAs($this->owner);
    }

    public function test_template_wishlist_poll_and_private_share_preview_are_scoped_to_the_space(): void
    {
        $template = $this->postJson('/api/v1/calendar/templates', ['gallery_space_id' => $this->space->id, 'title' => 'Víkend', 'tasks' => [['title' => 'Sbalit věci']]])->assertCreated()->json();
        $event = $this->postJson("/api/v1/calendar/templates/{$template['uuid']}/apply", ['starts_at' => now()->addWeek()->toDateTimeString()])->assertCreated()->json();
        $this->assertDatabaseHas('event_tasks', ['event_id' => $event['id'], 'title' => 'Sbalit věci']);
        $this->postJson("/api/v1/calendar/events/{$event['uuid']}/exceptions", ['occurs_at' => now()->addWeek()->toDateTimeString(), 'action' => 'skip'])->assertOk();

        $wishlist = $this->postJson('/api/v1/calendar/wishlists', ['gallery_space_id' => $this->space->id, 'title' => 'Přání'])->assertCreated()->json();
        $wish = $this->postJson("/api/v1/calendar/wishlists/{$wishlist['uuid']}/items", ['title' => 'Mikulov', 'priority' => 1, 'estimated_minutes' => 180])->assertCreated()->json();
        $this->getJson("/api/v1/calendar/wishlists/{$wishlist['uuid']}/suggestions")->assertOk()->assertJsonPath('items.0.title', 'Mikulov');
        $wishEvent = $this->postJson("/api/v1/calendar/wishlists/{$wishlist['uuid']}/items/{$wish['id']}/plan")->assertCreated()->assertJsonPath('title', 'Mikulov')->assertJsonPath('type', 'outing')->json();
        $this->assertDatabaseHas('travel_wishlist_items', ['id' => $wish['id'], 'calendar_event_id' => $wishEvent['id'], 'status' => 'planned']);
        $this->assertDatabaseHas('event_participants', ['event_id' => $wishEvent['id'], 'user_id' => $this->partner->id]);
        $this->postJson("/api/v1/calendar/wishlists/{$wishlist['uuid']}/items/{$wish['id']}/plan")->assertOk()->assertJsonPath('id', $wishEvent['id']);

        $poll = $this->postJson('/api/v1/calendar/polls', ['gallery_space_id' => $this->space->id, 'question' => 'Kam?', 'options' => [['title' => 'Brno'], ['title' => 'Olomouc']]])->assertCreated()->json();
        $optionId = DB::table('decision_poll_options')->where('poll_id', $poll['id'])->value('id');
        $this->postJson("/api/v1/calendar/polls/{$poll['uuid']}/vote", ['option_id' => $optionId])->assertOk();
        $this->getJson('/api/v1/calendar/polls')->assertOk()->assertJsonPath('0.options.0.votes', 1);
        $pollEvent = $this->postJson("/api/v1/calendar/polls/{$poll['uuid']}/options/{$optionId}/plan")->assertCreated()->assertJsonPath('title', 'Brno')->assertJsonPath('type', 'outing')->json();
        $this->assertDatabaseHas('decision_poll_options', ['id' => $optionId, 'calendar_event_id' => $pollEvent['id']]);
        $this->assertDatabaseHas('decision_polls', ['id' => $poll['id'], 'status' => 'decided']);
        $this->postJson("/api/v1/calendar/polls/{$poll['uuid']}/options/{$optionId}/plan")->assertOk()->assertJsonPath('id', $pollEvent['id']);

        $rule = $this->postJson('/api/v1/calendar/partner-rules', ['gallery_space_id' => $this->space->id, 'recipient_user_id' => $this->partner->id, 'name' => 'Letní výlet', 'filters' => ['from' => now()->subDay()->toDateString()]])->assertCreated()->json();
        $this->getJson("/api/v1/calendar/partner-rules/{$rule['uuid']}/preview")->assertOk()->assertJsonPath('notice', 'Náhled nic automaticky nesdílí.');
    }

    public function test_planning_screen_dependencies_degrade_safely_when_an_optional_migration_is_not_yet_present(): void
    {
        Schema::dropIfExists('event_templates');
        Schema::dropIfExists('travel_wishlist_items');
        Schema::dropIfExists('travel_wishlists');
        Schema::dropIfExists('decision_poll_votes');
        Schema::dropIfExists('decision_poll_options');
        Schema::dropIfExists('decision_polls');

        $this->getJson('/api/v1/calendar/templates')->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/calendar/wishlists')->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/calendar/polls')->assertOk()->assertExactJson([]);
        $this->postJson('/api/v1/calendar/templates', ['gallery_space_id' => $this->space->id, 'title' => 'Víkend'])->assertStatus(503);

        Schema::dropIfExists('calendar_event_exceptions');
        $this->postJson('/api/v1/calendar/events', ['gallery_space_id' => $this->space->id, 'title' => 'Bez výjimek', 'starts_at' => now()->addWeek()->toDateTimeString(), 'recurrence_rule' => ['frequency' => 'weekly']])->assertCreated();
        $this->getJson('/api/v1/calendar/events')->assertOk();
    }

    public function test_emergency_card_is_available_only_to_trip_space_members(): void
    {
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Tatry', 'start_date' => now()->addMonth()->toDateString(), 'end_date' => now()->addMonth()->addDays(3)->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $this->putJson("/api/v1/trips/{$tripId}/emergency-card", ['accommodation_name' => 'Hotel', 'contacts' => [['name' => 'ICE', 'phone' => '+420123']], 'important_numbers' => ['112']])->assertOk();
        $this->getJson("/api/v1/trips/{$tripId}/emergency-card")->assertOk()->assertJsonPath('accommodation_name', 'Hotel');
    }

    public function test_planning_a_saved_place_invites_every_partner_into_the_linked_calendar_event(): void
    {
        $place = $this->postJson('/api/v1/places', ['name' => 'Infinit Maximus', 'type' => 'business', 'city' => 'Brno'])->assertCreated()->json();
        $plan = $this->postJson("/api/v1/places/{$place['id']}/plans", ['planned_for' => now()->addWeek()->toDateString(), 'notes' => 'Vzít si župan.'])->assertCreated()->json();

        $this->assertDatabaseHas('event_participants', ['event_id' => $plan['calendar_event_id'], 'user_id' => $this->owner->id, 'role' => 'owner']);
        $this->assertDatabaseHas('event_participants', ['event_id' => $plan['calendar_event_id'], 'user_id' => $this->partner->id, 'role' => 'editor']);
        $eventUuid = DB::table('calendar_events')->where('id', $plan['calendar_event_id'])->value('uuid');
        $this->actingAs($this->partner)->getJson("/api/v1/calendar/events/{$eventUuid}")->assertOk()->assertJsonPath('title', 'Infinit Maximus');
    }

    public function test_skipped_recurrence_exception_is_not_returned_by_calendar(): void
    {
        $startsAt = now()->addDays(10)->setTime(18, 0);
        $event = $this->postJson('/api/v1/calendar/events', ['gallery_space_id' => $this->space->id, 'title' => 'Opakovaná večeře', 'starts_at' => $startsAt->toDateTimeString(), 'recurrence_rule' => ['frequency' => 'weekly', 'interval' => 1]])->assertCreated()->json();
        $this->postJson("/api/v1/calendar/events/{$event['uuid']}/exceptions", ['occurs_at' => $startsAt->toDateTimeString(), 'action' => 'skip'])->assertOk();
        $calendar = $this->getJson('/api/v1/calendar/events?from=' . $startsAt->toDateString() . '&to=' . $startsAt->toDateString())->assertOk()->json('events');
        $this->assertSame([], $calendar);
    }

    public function test_availability_and_ics_export_use_authenticated_user_data(): void
    {
        $this->putJson('/api/v1/calendar/availability', ['availability' => [['weekday' => 6, 'from' => '09:00', 'to' => '18:00']], 'quiet_hours' => ['from' => '22:00', 'to' => '07:00']])->assertOk()->assertJsonPath('availability.0.weekday', 6);
        $event = $this->postJson('/api/v1/calendar/events', ['gallery_space_id' => $this->space->id, 'title' => 'Výlet', 'starts_at' => now()->addDays(4)->toDateTimeString()])->assertCreated()->json();
        $this->get("/api/v1/calendar/events/{$event['uuid']}/ics")->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->assertSee('BEGIN:VCALENDAR');
    }

    public function test_trip_readiness_budget_documents_and_consents_are_scoped_and_calculated(): void
    {
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Vídeň', 'start_date' => now()->addMonth()->toDateString(), 'end_date' => now()->addMonth()->addDays(2)->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $this->putJson("/api/v1/trips/{$tripId}/budget-limits", ['category' => 'food', 'amount' => 1500])->assertOk();
        $this->postJson("/api/v1/trips/{$tripId}/expenses", ['title' => 'Večeře', 'category' => 'food', 'amount' => 1300, 'state' => 'actual', 'paid_by_user_id' => $this->owner->id])->assertCreated();
        $this->postJson("/api/v1/trips/{$tripId}/documents", ['type' => 'insurance', 'title' => 'Cestovní pojištění', 'status' => 'ready'])->assertCreated();
        $this->postJson("/api/v1/trips/{$tripId}/settlements", ['from_user_id' => $this->partner->id, 'to_user_id' => $this->owner->id, 'amount' => 650])->assertCreated();
        $this->postJson("/api/v1/trips/{$tripId}/location-consent", ['recipient_user_id' => $this->partner->id, 'expires_at' => now()->addDay()->toDateTimeString()])->assertOk();
        $this->postJson("/api/v1/trips/{$tripId}/track-points", ['latitude' => 48.2082, 'longitude' => 16.3738])->assertCreated();
        $settlement = DB::table('trip_settlements')->where('trip_id', $tripId)->first();
        $this->postJson("/api/v1/trips/{$tripId}/settlements/{$settlement->id}/settle")->assertOk()->assertJsonPath('status', 'settled');
        $this->getJson("/api/v1/trips/{$tripId}/offline-package")->assertOk()->assertJsonPath('trip.name', 'Vídeň');
        $this->postJson('/api/v1/currency-rates', ['base_currency' => 'EUR', 'quote_currency' => 'CZK', 'rate' => 25.1, 'effective_on' => now()->toDateString()])->assertOk();
        $this->getJson("/api/v1/trips/{$tripId}/readiness")->assertOk()->assertJsonPath('budget.0.status', 'warning')->assertJsonPath('documents.0.title', 'Cestovní pojištění');
    }

    public function test_packing_templates_are_deduplicated_and_readiness_reports_missing_essentials(): void
    {
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Víkend', 'start_date' => now()->addWeek()->toDateString(), 'end_date' => now()->addWeek()->addDay()->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson("/api/v1/trips/{$tripId}/packing-items/apply-template", ['template' => 'weekend'])->assertCreated()->assertJsonPath('created', 4);
        $this->postJson("/api/v1/trips/{$tripId}/packing-items/apply-template", ['template' => 'weekend'])->assertCreated()->assertJsonPath('created', 0);
        $item = DB::table('trip_packing_items')->where('trip_id', $tripId)->where('is_essential', true)->first();
        $this->patchJson("/api/v1/trips/{$tripId}/packing-items/{$item->id}", ['is_packed' => true])->assertOk()->assertJsonPath('is_packed', true);
        $this->getJson("/api/v1/trips/{$tripId}/readiness")->assertOk()->assertJsonPath('packing.total', 4)->assertJsonCount(2, 'packing.unpacked_essentials');
    }

    public function test_packing_responsibility_is_shared_and_records_who_completed_the_item(): void
    {
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Společné balení', 'start_date' => now()->addWeek()->toDateString(), 'end_date' => now()->addWeek()->addDay()->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson("/api/v1/trips/{$tripId}/packing-members")->assertOk()->assertJsonCount(2);
        $item = $this->postJson("/api/v1/trips/{$tripId}/packing-items", ['title' => 'Cestovní doklad', 'category' => 'documents', 'is_essential' => true, 'assigned_to' => $this->partner->id])->assertCreated()->assertJsonPath('assigned_to', $this->partner->id)->json();
        $this->getJson("/api/v1/trips/{$tripId}/readiness")->assertOk()->assertJsonPath('packing.assigned', 1)->assertJsonPath('packing.unassigned_essentials', 0);

        $this->actingAs($this->partner);
        $this->patchJson("/api/v1/trips/{$tripId}/packing-items/{$item['id']}", ['is_packed' => true])->assertOk()->assertJsonPath('is_packed', true)->assertJsonPath('packed_by', $this->partner->id);
        $this->getJson("/api/v1/trips/{$tripId}/packing-items")->assertOk()->assertJsonPath('0.packed_by_name', $this->partner->name);
    }

    public function test_vehicle_costs_calculate_trip_cost_per_kilometre_and_flag_expired_vignette(): void
    {
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Autovýlet', 'start_date' => today()->toDateString(), 'end_date' => now()->addDay()->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson("/api/v1/trips/{$tripId}/vehicle-costs", ['type' => 'fuel', 'title' => 'Benzín', 'amount' => 500, 'liters' => 20, 'distance_km' => 250])->assertCreated();
        $this->postJson("/api/v1/trips/{$tripId}/vehicle-costs", ['type' => 'vignette', 'title' => 'Rakouská známka', 'amount' => 250, 'valid_until' => now()->subDay()->toDateString()])->assertCreated();
        $this->getJson("/api/v1/trips/{$tripId}/vehicle-costs")->assertOk()->assertJsonPath('summary.total', 750)->assertJsonPath('summary.cost_per_km', 3);
        $this->getJson("/api/v1/trips/{$tripId}/readiness")->assertOk()->assertJsonCount(1, 'vehicle.expired_vignettes');
    }

    public function test_low_cost_finance_calculates_partner_balance_and_savings_goal(): void
    {
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Levný výlet', 'start_date' => now()->addWeek()->toDateString(), 'end_date' => now()->addWeek()->addDay()->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson("/api/v1/trips/{$tripId}/expenses", ['title' => 'Hotel', 'amount' => 1000, 'paid_by_user_id' => $this->owner->id, 'split' => [['user_id' => $this->owner->id, 'amount' => 500], ['user_id' => $this->partner->id, 'amount' => 500]]])->assertCreated();
        $summary = $this->getJson("/api/v1/trips/{$tripId}/finance-summary")->assertOk()->assertJsonPath('proposals.0.from_user_id', $this->partner->id)->assertJsonPath('proposals.0.to_user_id', $this->owner->id)->assertJsonPath('proposals.0.amount', 500);
        $this->putJson("/api/v1/trips/{$tripId}/savings-goal", ['target_amount' => 5000, 'saved_amount' => 1250, 'monthly_contribution' => 500])->assertOk()->assertJsonPath('saved_amount', 1250);
    }

    public function test_partner_expenses_joint_account_and_settlement_share_one_stable_trip_balance(): void
    {
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'name' => 'Finance ve dvou', 'start_date' => now()->addWeek()->toDateString(), 'end_date' => now()->addWeek()->addDay()->toDateString(),
            'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $personal = $this->postJson("/api/v1/trips/{$tripId}/expenses", [
            'title' => 'Hotel', 'category' => 'accommodation', 'amount' => 1000, 'paid_by_user_id' => $this->owner->id,
            'payment_source' => 'personal', 'split' => [
                ['user_id' => $this->owner->id, 'amount' => 250],
                ['user_id' => $this->partner->id, 'amount' => 750],
            ],
        ])->assertCreated()->json();
        $this->postJson("/api/v1/trips/{$tripId}/expenses", [
            'title' => 'Večeře ze společného účtu', 'category' => 'food', 'amount' => 400, 'payment_source' => 'joint',
        ])->assertCreated()->assertJsonPath('payment_source', 'joint')->assertJsonPath('paid_by_user_id', null);

        $summary = $this->getJson("/api/v1/trips/{$tripId}/finance-summary")->assertOk()
            ->assertJsonPath('joint_paid', 400)
            ->assertJsonPath('proposals.0.from_user_id', $this->partner->id)
            ->assertJsonPath('proposals.0.to_user_id', $this->owner->id)
            ->assertJsonPath('proposals.0.amount', 750)
            ->assertJsonPath('currencies.0.currency', 'CZK')->json();
        $this->assertSame(750.0, (float) collect($summary['members'])->firstWhere('user_id', $this->owner->id)['balance']);

        $this->patchJson("/api/v1/trips/{$tripId}/expenses/{$personal['id']}", [
            'split' => [['user_id' => $this->owner->id, 'amount' => 500], ['user_id' => $this->partner->id, 'amount' => 500]],
        ])->assertOk();
        $this->getJson("/api/v1/trips/{$tripId}/finance-summary")->assertOk()->assertJsonPath('proposals.0.amount', 500);

        $settlement = $this->postJson("/api/v1/trips/{$tripId}/settlements", [
            'from_user_id' => $this->partner->id, 'to_user_id' => $this->owner->id, 'amount' => 500, 'currency' => 'CZK',
            'note' => 'Vyrovnání po návratu',
        ])->assertCreated()->json();
        $this->postJson("/api/v1/trips/{$tripId}/settlements", [
            'from_user_id' => $this->partner->id, 'to_user_id' => $this->owner->id, 'amount' => 500, 'currency' => 'CZK',
        ])->assertOk();
        $this->assertDatabaseCount('trip_settlements', 1);
        $this->getJson("/api/v1/trips/{$tripId}/finance-summary")->assertOk()
            ->assertJsonPath('proposals.0.settlement_id', $settlement['id'])
            ->assertJsonPath('proposals.0.status', 'suggested');

        $this->actingAs($this->partner)->postJson("/api/v1/trips/{$tripId}/settlements/{$settlement['id']}/settle")
            ->assertOk()->assertJsonPath('status', 'settled');
        $settled = $this->getJson("/api/v1/trips/{$tripId}/finance-summary")->assertOk()->assertJsonPath('currencies.0.settled_total', 500)->json();
        $this->assertSame([], $settled['proposals']);
        $this->deleteJson("/api/v1/trips/{$tripId}/settlements/{$settlement['id']}")->assertStatus(409);
    }

    public function test_low_cost_advisor_unifies_trip_budget_saved_places_and_calendar_tasks(): void
    {
        $start = now()->addMonth()->startOfDay();
        $tripId = DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->space->id,
            'created_by' => $this->owner->id,
            'name' => 'Úsporná Vídeň',
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(2)->toDateString(),
            'budget' => 3000,
            'currency' => 'CZK',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $eventUuid = (string) Str::uuid();
        $eventId = DB::table('calendar_events')->insertGetId([
            'uuid' => $eventUuid,
            'gallery_space_id' => $this->space->id,
            'created_by' => $this->owner->id,
            'trip_id' => $tripId,
            'title' => 'Úsporná Vídeň',
            'type' => 'trip',
            'status' => 'planned',
            'starts_at' => $start,
            'ends_at' => $start->copy()->addDays(2),
            'timezone' => 'Europe/Prague',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->postJson('/api/v1/places', ['name' => 'Levná vyhlídka', 'type' => 'custom', 'city' => 'Vídeň', 'price_level' => 1])->assertCreated();
        $this->postJson("/api/v1/trips/{$tripId}/expenses", ['title' => 'Apartmán', 'category' => 'accommodation', 'amount' => 1800, 'state' => 'planned'])->assertCreated();
        $this->postJson("/api/v1/trips/{$tripId}/expenses", ['title' => 'Jídlo', 'category' => 'food', 'amount' => 900, 'state' => 'actual', 'occurred_at' => $start])->assertCreated();

        $response = $this->putJson("/api/v1/trips/{$tripId}/budget-plan", [
            'budget_profile' => 'economy',
            'budget' => 3000,
            'daily_budget_limit' => 1000,
            'apply_defaults' => true,
            'sync_calendar_tasks' => true,
        ])->assertOk()
            ->assertJsonPath('advisor.profile', 'economy')
            ->assertJsonPath('advisor.days_count', 3)
            ->assertJsonPath('advisor.projected', 2700)
            ->assertJsonPath('advisor.categories.1.category', 'accommodation')
            ->assertJsonPath('advisor.categories.1.limit', 1050)
            ->assertJsonPath('advisor.saved_place_suggestions.0.name', 'Levná vyhlídka')
            ->assertJsonPath('automation.limits_applied', 6)
            ->assertJsonPath('automation.calendar_tasks.event_uuid', $eventUuid);

        $this->assertSame('over', $response->json('advisor.status'));
        $this->assertDatabaseHas('trips', ['id' => $tripId, 'budget_profile' => 'economy', 'daily_budget_limit' => 1000]);
        $this->assertDatabaseHas('event_tasks', ['event_id' => $eventId, 'title' => 'Zkontrolovat rozpočet: Ubytování']);
        $taskCount = DB::table('event_tasks')->where('event_id', $eventId)->count();

        $this->putJson("/api/v1/trips/{$tripId}/budget-plan", ['apply_defaults' => true, 'sync_calendar_tasks' => true])->assertOk()->assertJsonPath('automation.limits_applied', 0);
        $this->assertSame($taskCount, DB::table('event_tasks')->where('event_id', $eventId)->count(), 'Automatizace nesmí při opakovaném spuštění duplikovat úkoly.');
        $this->getJson("/api/v1/trips/{$tripId}/budget-advisor")->assertOk()->assertJsonPath('projected', 2700);
        $this->getJson("/api/v1/trips/{$tripId}/readiness")->assertOk()->assertJsonPath('budget_advisor.profile', 'economy');
    }

    public function test_preparation_timeline_connects_transport_stay_documents_vehicle_and_partner_reminders(): void
    {
        $start = now()->addDays(30)->setTime(8, 0)->startOfMinute();
        $end = $start->copy()->addDays(2)->setTime(20, 0);
        $tripId = DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->space->id,
            'created_by' => $this->owner->id,
            'name' => 'Propojená cesta',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'timezone' => 'Europe/Prague',
            'currency' => 'CZK',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $dayId = DB::table('trip_days')->insertGetId(['trip_id' => $tripId, 'date' => $start->toDateString(), 'title' => 'Den 1', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $eventId = DB::table('calendar_events')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->space->id,
            'created_by' => $this->owner->id,
            'trip_id' => $tripId,
            'title' => 'Propojená cesta',
            'type' => 'trip',
            'status' => 'planned',
            'starts_at' => $start,
            'ends_at' => $end,
            'timezone' => 'Europe/Prague',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transport = $this->postJson("/api/v1/trips/{$tripId}/travel-choices/transport", [
            'title' => 'Vlak s přestupem',
            'provider' => 'Transitous',
            'amount' => 1200,
            'estimated_minutes' => 180,
            'transport_modes' => ['train'],
            'details' => [
                'departure' => $start->toIso8601String(),
                'arrival' => $start->copy()->addHours(3)->toIso8601String(),
                'legs' => [
                    ['from' => 'Brno', 'to' => 'Břeclav', 'departure' => $start->toIso8601String(), 'arrival' => $start->copy()->addHour()->toIso8601String()],
                    ['from' => 'Břeclav', 'to' => 'Vídeň', 'departure' => $start->copy()->addHour()->addMinutes(8)->toIso8601String(), 'arrival' => $start->copy()->addHours(3)->toIso8601String()],
                ],
            ],
        ])->assertCreated()->json();
        $this->postJson("/api/v1/trips/{$tripId}/travel-choices/accommodation", [
            'trip_day_id' => $dayId,
            'title' => 'Hotel Central',
            'amount' => 2500,
            'reference' => 'BOOK-123',
            'checkin' => $start->toDateString(),
            'checkout' => $end->toDateString(),
        ])->assertCreated();
        $document = $this->postJson("/api/v1/trips/{$tripId}/documents", ['type' => 'id_card', 'title' => 'Občanský průkaz', 'status' => 'ready', 'expires_on' => $start->copy()->addDay()->toDateString()])->assertCreated()->json();
        $vignette = $this->postJson("/api/v1/trips/{$tripId}/vehicle-costs", ['type' => 'vignette', 'title' => 'Rakouská známka', 'amount' => 300, 'valid_until' => $start->copy()->addDay()->toDateString()])->assertCreated()->json();

        $timeline = $this->getJson("/api/v1/trips/{$tripId}/preparation-timeline")
            ->assertOk()
            ->assertJsonPath('summary.selected_transport', 1)
            ->assertJsonPath('summary.selected_accommodation', 1)
            ->assertJsonPath('connection_checks.0.minutes', 8)
            ->assertJsonPath('connection_checks.0.risk', 'critical')
            ->json();
        $actionKeys = collect($timeline['actions'])->pluck('key');
        $this->assertTrue($actionKeys->contains("transfer_{$transport['id']}_0"));
        $this->assertTrue($actionKeys->contains("document_expiry_{$document['id']}"));
        $this->assertTrue($actionKeys->contains("vignette_expiry_{$vignette['id']}"));
        $this->assertDatabaseHas('event_tasks', ['event_id' => $eventId, 'automation_source' => 'trip_preparation', 'automation_key' => "transfer_{$transport['id']}_0"]);
        $this->assertDatabaseHas('event_reminders', ['event_id' => $eventId, 'user_id' => $this->owner->id, 'automation_key' => 'trip_start_1440']);
        $this->assertDatabaseHas('event_reminders', ['event_id' => $eventId, 'user_id' => $this->partner->id, 'automation_key' => 'trip_start_1440']);

        $taskCount = DB::table('event_tasks')->where('event_id', $eventId)->where('automation_source', 'trip_preparation')->count();
        $reminderCount = DB::table('event_reminders')->where('event_id', $eventId)->where('automation_source', 'trip_preparation')->count();
        $this->postJson("/api/v1/trips/{$tripId}/preparation-timeline/sync")->assertOk()->assertJsonPath('preparation.connection_checks.0.risk', 'critical');
        $this->assertSame($taskCount, DB::table('event_tasks')->where('event_id', $eventId)->where('automation_source', 'trip_preparation')->count());
        $this->assertSame($reminderCount, DB::table('event_reminders')->where('event_id', $eventId)->where('automation_source', 'trip_preparation')->count());
        $this->getJson("/api/v1/trips/{$tripId}/readiness")->assertOk()->assertJsonPath('preparation.summary.risky_connections', 1);
        $this->getJson("/api/v1/trips/{$tripId}/offline-package")->assertOk()->assertJsonPath('preparation.connection_checks.0.risk', 'critical');
    }

    public function test_relationship_milestones_share_anniversaries_without_exposing_private_entries(): void
    {
        $shared = $this->postJson('/api/v1/relationship-milestones', [
            'gallery_space_id' => $this->space->id,
            'title' => 'První společný výlet',
            'occurred_on' => now()->subYear()->toDateString(),
            'visibility' => 'shared',
            'remind_annually' => true,
        ])->assertCreated()->json();
        $this->postJson('/api/v1/relationship-milestones', [
            'gallery_space_id' => $this->space->id,
            'title' => 'Soukromá poznámka',
            'occurred_on' => now()->subMonth()->toDateString(),
            'visibility' => 'private',
        ])->assertCreated();

        $this->actingAs($this->partner);
        $this->getJson('/api/v1/relationship-milestones')->assertOk()->assertJsonCount(1)->assertJsonPath('0.uuid', $shared['uuid']);
        $this->getJson('/api/v1/relationship-milestones/upcoming')->assertOk()->assertJsonPath('0.uuid', $shared['uuid']);
        $this->deleteJson("/api/v1/relationship-milestones/{$shared['uuid']}")->assertForbidden();
    }

    public function test_relationship_milestone_reminder_notifies_shared_space_only_once_per_day(): void
    {
        $this->postJson('/api/v1/relationship-milestones', ['gallery_space_id' => $this->space->id, 'title' => 'Naše výročí', 'occurred_on' => today()->subYears(2)->toDateString(), 'visibility' => 'shared', 'remind_annually' => true])->assertCreated();
        $this->artisan(SendRelationshipMilestoneRemindersCommand::class)->assertSuccessful();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->owner->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->partner->id]);
        $this->artisan(SendRelationshipMilestoneRemindersCommand::class)->assertSuccessful();
        $this->assertSame(2, DB::table('notifications')->count());
    }

    public function test_shared_memory_moment_keeps_selected_photos_inside_the_partner_space(): void
    {
        $mediaId = DB::table('media_items')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'owner_user_id' => $this->owner->id, 'uploaded_by' => $this->owner->id, 'original_filename' => 'vylety.jpg', 'safe_filename' => 'vylety.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1, 'status' => 'ready', 'storage_status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        $moment = $this->postJson('/api/v1/shared-memory-moments', ['gallery_space_id' => $this->space->id, 'title' => 'Naše výlety', 'note' => 'Ještě jednou brzy.', 'happened_on' => today()->toDateString(), 'media_item_ids' => [$mediaId], 'is_favorite' => true])->assertCreated()->json();
        $this->actingAs($this->partner)->getJson('/api/v1/shared-memory-moments')->assertOk()->assertJsonPath('0.uuid', $moment['uuid'])->assertJsonPath('0.media.0.title', 'vylety.jpg');
        $this->deleteJson("/api/v1/shared-memory-moments/{$moment['uuid']}")->assertForbidden();
    }

    public function test_private_memory_note_is_encrypted_and_visible_only_to_its_author(): void
    {
        $uuid = (string) Str::uuid();
        DB::table('media_items')->insert(['uuid' => $uuid, 'gallery_space_id' => $this->space->id, 'owner_user_id' => $this->owner->id, 'uploaded_by' => $this->owner->id, 'original_filename' => 'tajne.jpg', 'safe_filename' => 'tajne.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1, 'status' => 'ready', 'storage_status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        $this->putJson("/api/v1/media/{$uuid}/private-note", ['content' => 'Jen pro mě'])->assertOk();
        $this->getJson("/api/v1/media/{$uuid}/private-note")->assertOk()->assertJsonPath('content', 'Jen pro mě');
        $this->assertDatabaseMissing('media_private_notes', ['encrypted_content' => 'Jen pro mě']);
    }

    public function test_gift_ideas_day_notes_and_followups_are_private_and_automated(): void
    {
        $gift = $this->postJson('/api/v1/calendar/gifts', ['gallery_space_id' => $this->space->id, 'title' => 'Kniha', 'due_date' => now()->toDateString(), 'reminder_days' => [0]])->assertCreated()->json();
        $this->putJson('/api/v1/calendar/day-note', ['gallery_space_id' => $this->space->id, 'content' => 'Nezapomenout na květiny'])->assertOk();
        $this->getJson('/api/v1/calendar/day-note?gallery_space_id=' . $this->space->id)->assertOk()->assertJsonPath('content', 'Nezapomenout na květiny');
        $tomorrow = now()->addDay()->toDateString();
        $this->putJson('/api/v1/calendar/day-note', ['gallery_space_id' => $this->space->id, 'date' => $tomorrow, 'content' => 'Koupit lístky na zítřek'])->assertOk();
        $this->getJson('/api/v1/calendar/day-note?gallery_space_id=' . $this->space->id . '&date=' . $tomorrow)->assertOk()->assertJsonPath('content', 'Koupit lístky na zítřek');
        $this->assertDatabaseMissing('shared_day_notes', ['encrypted_content' => 'Nezapomenout na květiny']);
        $this->artisan(SendPlanningFollowupsCommand::class)->assertSuccessful();
        $this->assertDatabaseHas('gift_ideas', ['id' => $gift['id']]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->owner->id]);
    }
}
