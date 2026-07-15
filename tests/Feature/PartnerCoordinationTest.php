<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerCoordinationTest extends TestCase
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
        $this->space = GallerySpace::create(['name' => 'Náš prostor', 'slug' => 'partner-pulse', 'owner_id' => $this->owner->id, 'is_default' => true]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->space->members()->attach($this->partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
    }

    public function test_pulse_unifies_original_actions_and_excludes_inaccessible_private_events(): void
    {
        $sources = $this->sources();
        $privateEventId = DB::table('calendar_events')->insertGetId($this->eventRow('Soukromý plán', true));
        DB::table('event_tasks')->insert(['event_id' => $privateEventId, 'title' => 'Tajný úkol', 'priority' => 'high', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($this->partner)->getJson('/api/v1/coordination/pulse?gallery_space_id=' . $this->space->id . '&limit=20')
            ->assertOk()
            ->assertJsonPath('summary.total', 5)
            ->assertJsonPath('summary.unassigned', 2)
            ->assertJsonPath('recommendation.code', 'assign')
            ->assertJsonMissing(['title' => 'Tajný úkol']);

        $types = collect($response->json('actions'))->pluck('type')->sort()->values()->all();
        $this->assertSame(['event_task', 'gift', 'packing_item', 'planning_item', 'trip_document'], $types);
        $this->assertSame($sources['task'], collect($response->json('actions'))->firstWhere('type', 'event_task')['task_id']);
    }

    public function test_assignment_completion_and_personal_snooze_update_the_real_sources(): void
    {
        $sources = $this->sources();
        $this->actingAs($this->partner)->patchJson('/api/v1/calendar/gifts/' . $sources['gift_uuid'], [
            'assigned_to' => $this->partner->id,
        ])->assertOk()->assertJsonPath('assigned_to', $this->partner->id);
        $this->getJson('/api/v1/calendar/gifts')->assertOk()->assertJsonFragment([
            'uuid' => $sources['gift_uuid'], 'assigned_to' => $this->partner->id, 'assignee_name' => $this->partner->name,
        ]);
        $this->postJson('/api/v1/trips/' . $sources['trip'] . '/documents', [
            'type' => 'insurance', 'title' => 'Cestovní pojištění', 'status' => 'ready', 'assigned_to' => $this->partner->id,
        ])->assertCreated()->assertJsonPath('assigned_to', $this->partner->id);
        $this->getJson('/api/v1/trips/' . $sources['trip'] . '/readiness')->assertOk()->assertJsonFragment([
            'title' => 'Cestovní pojištění', 'assigned_to' => $this->partner->id, 'assignee_name' => $this->partner->name,
        ]);

        $completion = $this->actingAs($this->partner)->patchJson('/api/v1/coordination/actions/trip_document/' . $sources['document'], [
            'gallery_space_id' => $this->space->id, 'assigned_to' => $this->partner->id, 'completed' => true,
        ])->assertOk();
        $this->assertFalse(collect($completion->json('actions'))->contains(fn ($action) => $action['type'] === 'trip_document' && $action['source_key'] === (string) $sources['document']));
        $this->assertDatabaseHas('trip_document_checks', ['id' => $sources['document'], 'assigned_to' => $this->partner->id, 'status' => 'ready']);

        $snoozed = $this->patchJson('/api/v1/coordination/actions/event_task/' . $sources['task'], [
            'gallery_space_id' => $this->space->id, 'snoozed_until' => now()->addDay()->toIso8601String(),
        ])->assertOk();
        $this->assertFalse(collect($snoozed->json('actions'))->contains(fn ($action) => $action['type'] === 'event_task' && $action['source_key'] === (string) $sources['task']));
        $this->assertDatabaseHas('event_tasks', ['id' => $sources['task'], 'completed_at' => null]);
        $this->assertDatabaseHas('coordination_action_states', ['user_id' => $this->partner->id, 'source_type' => 'event_task', 'source_key' => (string) $sources['task']]);

        $this->actingAs($this->owner)->getJson('/api/v1/coordination/pulse?gallery_space_id=' . $this->space->id)
            ->assertOk()->assertJsonFragment(['source_key' => (string) $sources['task'], 'title' => 'Koupit vstupenky']);

        $outsider = User::factory()->create();
        $this->actingAs($this->partner)->patchJson('/api/v1/coordination/actions/gift/' . $sources['gift_uuid'], [
            'gallery_space_id' => $this->space->id, 'assigned_to' => $outsider->id,
        ])->assertUnprocessable();
    }

    public function test_shared_check_in_informs_partner_but_private_check_in_does_not(): void
    {
        $this->actingAs($this->owner)->putJson('/api/v1/coordination/check-in', [
            'gallery_space_id' => $this->space->id, 'mood' => 'tired', 'energy' => 2,
            'capacity' => 'light', 'focus' => 'Odpočinout si', 'is_shared' => true,
        ])->assertOk()->assertJsonPath('my_check_in.energy', 2);

        $this->actingAs($this->partner)->getJson('/api/v1/coordination/pulse?gallery_space_id=' . $this->space->id)
            ->assertOk()->assertJsonFragment(['user_id' => $this->owner->id, 'energy' => 2, 'capacity' => 'light']);

        $this->actingAs($this->owner)->putJson('/api/v1/coordination/check-in', [
            'gallery_space_id' => $this->space->id, 'mood' => 'calm', 'energy' => 4,
            'capacity' => 'normal', 'is_shared' => false,
        ])->assertOk()->assertJsonPath('my_check_in.is_shared', false);

        $partnerView = $this->actingAs($this->partner)->getJson('/api/v1/coordination/pulse?gallery_space_id=' . $this->space->id)->assertOk();
        $this->assertFalse(collect($partnerView->json('check_ins'))->contains('user_id', $this->owner->id));
    }

    public function test_read_only_user_cannot_change_coordination(): void
    {
        $sources = $this->sources();
        $this->partner->update(['read_only_mode' => true]);
        $this->actingAs($this->partner)->patchJson('/api/v1/coordination/actions/event_task/' . $sources['task'], [
            'gallery_space_id' => $this->space->id, 'completed' => true,
        ])->assertForbidden();
        $this->putJson('/api/v1/coordination/check-in', [
            'gallery_space_id' => $this->space->id, 'capacity' => 'normal',
        ])->assertForbidden();
    }

    private function sources(): array
    {
        $eventId = DB::table('calendar_events')->insertGetId($this->eventRow('Víkend ve Vídni'));
        $taskId = DB::table('event_tasks')->insertGetId([
            'event_id' => $eventId, 'assigned_to' => $this->owner->id, 'title' => 'Koupit vstupenky',
            'due_at' => now()->subHour(), 'priority' => 'high', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tripId = DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Vídeň',
            'start_date' => now()->addWeeks(2)->toDateString(), 'end_date' => now()->addWeeks(2)->addDays(2)->toDateString(),
            'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $packingId = DB::table('trip_packing_items')->insertGetId([
            'uuid' => (string) Str::uuid(), 'trip_id' => $tripId, 'created_by' => $this->owner->id, 'assigned_to' => $this->partner->id,
            'title' => 'Nabíječka', 'category' => 'electronics', 'quantity' => 1, 'is_essential' => true, 'is_packed' => false,
            'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $documentId = DB::table('trip_document_checks')->insertGetId([
            'trip_id' => $tripId, 'created_by' => $this->owner->id, 'type' => 'identity', 'title' => 'Občanský průkaz',
            'status' => 'required', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $inboxUuid = (string) Str::uuid();
        DB::table('travel_inbox_items')->insert([
            'uuid' => $inboxUuid, 'gallery_space_id' => $this->space->id, 'added_by' => $this->owner->id, 'trip_id' => $tripId,
            'title' => 'Porovnat ubytování', 'kind' => 'idea', 'state' => 'inbox', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $giftUuid = (string) Str::uuid();
        DB::table('gift_ideas')->insert([
            'uuid' => $giftUuid, 'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'assigned_to' => $this->owner->id,
            'title' => 'Dárek k výročí', 'due_date' => now()->addMonth()->toDateString(), 'currency' => 'CZK', 'status' => 'idea',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return ['event' => $eventId, 'task' => $taskId, 'trip' => $tripId, 'packing' => $packingId, 'document' => $documentId, 'inbox_uuid' => $inboxUuid, 'gift_uuid' => $giftUuid];
    }

    private function eventRow(string $title, bool $private = false): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'title' => $title, 'type' => 'trip', 'status' => 'planned', 'starts_at' => now()->addWeek(),
            'timezone' => 'Europe/Prague', 'is_private' => $private, 'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
