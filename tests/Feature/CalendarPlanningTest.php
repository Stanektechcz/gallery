<?php

namespace Tests\Feature;

use App\Console\Commands\DeliverPlanningRemindersCommand;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CalendarPlanningTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $partner;
    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->partner = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $this->space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Společně', 'slug' => 'spolecne', 'owner_id' => $this->owner->id, 'is_default' => true]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->space->members()->attach($this->partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->actingAs($this->owner);
    }

    public function test_shared_event_workflow_keeps_data_inside_the_gallery_space(): void
    {
        $event = $this->postJson('/api/v1/calendar/events', [
            'gallery_space_id' => $this->space->id, 'title' => 'Víkend v Brně', 'type' => 'trip',
            'starts_at' => now()->addDays(2)->setTime(10, 0)->toDateTimeString(), 'place_name' => 'Brno',
            'participant_ids' => [$this->partner->id],
            'reminders' => [['minutes_before' => 60, 'channel' => 'database', 'user_id' => $this->partner->id]],
        ])->assertCreated()->json();

        $this->assertDatabaseHas('event_participants', ['event_id' => $event['id'], 'user_id' => $this->partner->id]);
        $this->postJson("/api/v1/calendar/events/{$event['uuid']}/tasks", ['title' => 'Zabalit nabíječku', 'assigned_to' => $this->partner->id])->assertCreated();
        $this->actingAs($this->partner)->getJson("/api/v1/calendar/events/{$event['uuid']}")->assertOk()->assertJsonPath('title', 'Víkend v Brně');
        $this->postJson("/api/v1/calendar/events/{$event['uuid']}/response", ['response' => 'tentative'])->assertOk()->assertJsonPath('my_response', 'tentative');
        $this->assertDatabaseHas('event_participants', ['event_id' => $event['id'], 'user_id' => $this->partner->id, 'response' => 'tentative']);

        $outsider = User::factory()->create(['is_active' => true]);
        $otherSpace = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Jiné', 'slug' => 'jine', 'owner_id' => $outsider->id]);
        $otherSpace->members()->attach($outsider->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->actingAs($outsider)->getJson("/api/v1/calendar/events/{$event['uuid']}")->assertNotFound();
    }

    public function test_trip_budget_and_route_variant_are_persisted(): void
    {
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Jižní Morava', 'start_date' => now()->addMonth()->toDateString(), 'end_date' => now()->addMonth()->addDays(2)->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson("/api/v1/trips/{$tripId}/expenses", ['title' => 'Hotel', 'category' => 'accommodation', 'amount' => 2400, 'state' => 'planned'])->assertCreated();
        $variant = $this->postJson("/api/v1/trips/{$tripId}/route-variants", ['title' => 'Vlakem přes Prahu', 'strategy' => 'fastest', 'estimated_minutes' => 180])->assertCreated()->json();
        $this->postJson("/api/v1/trips/{$tripId}/route-variants/{$variant['id']}/select")->assertOk();
        $this->getJson("/api/v1/trips/{$tripId}/planning")->assertOk()->assertJsonPath('totals.planned', 2400)->assertJsonPath('route_variants.0.is_selected', 1);
    }

    public function test_due_reminder_and_time_capsule_are_delivered_once(): void
    {
        $event = $this->postJson('/api/v1/calendar/events', ['gallery_space_id' => $this->space->id, 'title' => 'Večeře', 'starts_at' => now()->addMinute()->toDateTimeString(), 'reminders' => [['minutes_before' => 1, 'channel' => 'database']]])->assertCreated()->json();
        $this->postJson('/api/v1/calendar/time-capsules', ['gallery_space_id' => $this->space->id, 'recipient_user_id' => $this->partner->id, 'title' => 'Naše vzpomínka', 'deliver_at' => now()->addMinute()->toDateTimeString()])->assertCreated();
        $this->artisan(DeliverPlanningRemindersCommand::class)->assertSuccessful();
        $this->assertDatabaseHas('event_reminders', ['event_id' => $event['id'], 'status' => 'delivered']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->owner->id]);
        DB::table('time_capsules')->update(['deliver_at' => now()->subMinute()]);
        $this->artisan(DeliverPlanningRemindersCommand::class)->assertSuccessful();
        $this->assertDatabaseHas('time_capsules', ['title' => 'Naše vzpomínka', 'status' => 'delivered']);
    }
}
