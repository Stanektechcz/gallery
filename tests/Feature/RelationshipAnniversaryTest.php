<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RelationshipAnniversaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationship_start_creates_shared_monthly_half_year_and_recurring_annual_plan(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $partner = User::factory()->create(['role' => 'partner']);
        $space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'My dva', 'slug' => 'my-dva', 'owner_id' => $owner->id]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true]);
        $space->members()->attach($partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true]);

        $response = $this->actingAs($owner)->putJson('/api/v1/relationship-milestones/relationship-anniversary', [
            'gallery_space_id' => $space->id,
            'started_on' => today()->toDateString(),
            'reminder_days' => [30, 7, 1],
        ])->assertOk()
            ->assertJsonPath('started_on', today()->toDateString())
            ->assertJsonCount(3, 'events');

        $annual = collect($response->json('events'))->first(fn (array $event) => data_get($event, 'recurrence_rule.frequency') === 'yearly');
        $this->assertNotNull($annual);
        $this->assertDatabaseHas('relationship_milestones', ['gallery_space_id' => $space->id, 'title' => 'Začátek našeho vztahu', 'occurred_on' => today()->toDateString()]);
        $this->assertSame(3, DB::table('calendar_events')->where('gallery_space_id', $space->id)->where('type', 'anniversary')->count());
        $this->assertDatabaseCount('event_participants', 6);
        $this->assertGreaterThan(0, DB::table('event_reminders')->count());

        $this->actingAs($partner)->getJson('/api/v1/relationship-milestones/relationship-anniversary?gallery_space_id=' . $space->id)
            ->assertOk()->assertJsonPath('started_on', today()->toDateString())->assertJsonCount(3, 'events');
    }
}
