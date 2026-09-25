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

    /**
     * Připomínky prvního měsíce a půl roku jsou okamžik v UTC.
     *
     * Oslava je v 18:00 podle pražských hodin (tak se ukládá i začátek akce);
     * plánovač porovnává `remind_at` s `now()` v UTC — dřív chodily později.
     */
    public function test_anniversary_reminders_are_stored_as_utc_moments(): void
    {
        $this->travelTo('2026-07-01 08:00:00');
        $owner = User::factory()->create(['role' => 'owner']);
        $space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'My dva', 'slug' => 'my-dva', 'owner_id' => $owner->id]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true]);

        $this->actingAs($owner)->putJson('/api/v1/relationship-milestones/relationship-anniversary', [
            'gallery_space_id' => $space->id, 'started_on' => '2026-06-15', 'reminder_days' => [7],
        ])->assertOk();

        $kdy = DB::table('event_reminders')->where('user_id', $owner->id)->orderBy('remind_at')
            ->pluck('remind_at')->map(fn ($v) => substr((string) $v, 0, 19))->all();

        // 15. 7. v 18:00 SELČ = 16:00 UTC; 15. 12. v 18:00 SEČ = 17:00 UTC — vždy týden předem.
        $this->assertSame(['2026-07-08 16:00:00', '2026-12-08 17:00:00'], $kdy);
    }

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
        $this->assertDatabaseHas('event_participants', ['event_id' => DB::table('calendar_events')->where('uuid', $annual['uuid'])->value('id'), 'user_id' => $partner->id, 'role' => 'editor', 'response' => 'accepted']);
        $this->assertGreaterThan(0, DB::table('event_reminders')->count());

        $this->actingAs($partner)->getJson('/api/v1/relationship-milestones/relationship-anniversary?gallery_space_id='.$space->id)
            ->assertOk()->assertJsonPath('started_on', today()->toDateString())->assertJsonCount(3, 'events');
        $this->patchJson("/api/v1/calendar/events/{$annual['uuid']}", ['description' => 'Společně vybereme oblíbenou vzpomínku.'])
            ->assertOk()->assertJsonPath('description', 'Společně vybereme oblíbenou vzpomínku.');
    }
}
