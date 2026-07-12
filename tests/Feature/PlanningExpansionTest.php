<?php

namespace Tests\Feature;

use App\Console\Commands\SendPlanningFollowupsCommand;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->postJson("/api/v1/calendar/wishlists/{$wishlist['uuid']}/items", ['title' => 'Mikulov', 'priority' => 1])->assertCreated();
        $this->getJson("/api/v1/calendar/wishlists/{$wishlist['uuid']}/suggestions")->assertOk()->assertJsonPath('items.0.title', 'Mikulov');

        $poll = $this->postJson('/api/v1/calendar/polls', ['gallery_space_id' => $this->space->id, 'question' => 'Kam?', 'options' => [['title' => 'Brno'], ['title' => 'Olomouc']]])->assertCreated()->json();
        $optionId = DB::table('decision_poll_options')->where('poll_id', $poll['id'])->value('id');
        $this->postJson("/api/v1/calendar/polls/{$poll['uuid']}/vote", ['option_id' => $optionId])->assertOk();
        $this->getJson('/api/v1/calendar/polls')->assertOk()->assertJsonPath('0.options.0.votes', 1);

        $rule = $this->postJson('/api/v1/calendar/partner-rules', ['gallery_space_id' => $this->space->id, 'recipient_user_id' => $this->partner->id, 'name' => 'Letní výlet', 'filters' => ['from' => now()->subDay()->toDateString()]])->assertCreated()->json();
        $this->getJson("/api/v1/calendar/partner-rules/{$rule['uuid']}/preview")->assertOk()->assertJsonPath('notice', 'Náhled nic automaticky nesdílí.');
    }

    public function test_emergency_card_is_available_only_to_trip_space_members(): void
    {
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Tatry', 'start_date' => now()->addMonth()->toDateString(), 'end_date' => now()->addMonth()->addDays(3)->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $this->putJson("/api/v1/trips/{$tripId}/emergency-card", ['accommodation_name' => 'Hotel', 'contacts' => [['name' => 'ICE', 'phone' => '+420123']], 'important_numbers' => ['112']])->assertOk();
        $this->getJson("/api/v1/trips/{$tripId}/emergency-card")->assertOk()->assertJsonPath('accommodation_name', 'Hotel');
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
        $this->postJson("/api/v1/trips/{$tripId}/expenses", ['title' => 'Večeře', 'category' => 'food', 'amount' => 1300, 'state' => 'actual'])->assertCreated();
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

    public function test_vehicle_costs_calculate_trip_cost_per_kilometre_and_flag_expired_vignette(): void
    {
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Autovýlet', 'start_date' => today()->toDateString(), 'end_date' => now()->addDay()->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson("/api/v1/trips/{$tripId}/vehicle-costs", ['type' => 'fuel', 'title' => 'Benzín', 'amount' => 500, 'liters' => 20, 'distance_km' => 250])->assertCreated();
        $this->postJson("/api/v1/trips/{$tripId}/vehicle-costs", ['type' => 'vignette', 'title' => 'Rakouská známka', 'amount' => 250, 'valid_until' => now()->subDay()->toDateString()])->assertCreated();
        $this->getJson("/api/v1/trips/{$tripId}/vehicle-costs")->assertOk()->assertJsonPath('summary.total', 750)->assertJsonPath('summary.cost_per_km', 3);
        $this->getJson("/api/v1/trips/{$tripId}/readiness")->assertOk()->assertJsonCount(1, 'vehicle.expired_vignettes');
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
        $this->assertDatabaseMissing('shared_day_notes', ['encrypted_content' => 'Nezapomenout na květiny']);
        $this->artisan(SendPlanningFollowupsCommand::class)->assertSuccessful();
        $this->assertDatabaseHas('gift_ideas', ['id' => $gift['id']]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->owner->id]);
    }
}
