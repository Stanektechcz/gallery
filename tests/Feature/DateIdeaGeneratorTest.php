<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\CoupleDateIdea;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DateIdeaGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_unique_budget_safe_ideas_and_learns_a_partner_reaction(): void
    {
        [$owner, $partner, $space] = $this->couple();
        $parameters = [
            'gallery_space_id' => $space->id,
            'count' => 4,
            'theme' => 'low_cost',
            'budget_max' => 0,
            'travel_scope' => 'nearby',
            'transport_mode' => 'walk',
            'duration' => 'evening',
            'time_of_day' => 'evening',
            'setting' => 'any',
            'energy' => 'medium',
            'food' => 'none',
            'weather_aware' => false,
            'destination' => ['location_name' => 'Brno', 'latitude' => 49.1951, 'longitude' => 16.6068],
        ];

        $first = $this->actingAs($owner)->postJson('/api/v1/date-ideas/generate', $parameters)
            ->assertCreated()->assertJsonCount(4, 'ideas');

        foreach ($first->json('ideas') as $idea) {
            $this->assertSame(0.0, (float) $idea['estimated_cost']);
            $this->assertNotEmpty($idea['plan']['blocks']);
            $this->assertTrue($idea['plan']['budget']['is_estimate']);
        }

        $this->actingAs($owner)->postJson('/api/v1/date-ideas/generate', $parameters)
            ->assertCreated()->assertJsonCount(4, 'ideas');
        $this->assertSame(8, CoupleDateIdea::where('gallery_space_id', $space->id)->count());
        $this->assertSame(8, CoupleDateIdea::where('gallery_space_id', $space->id)->distinct('generation_key')->count('generation_key'));

        $uuid = $first->json('ideas.0.uuid');
        $this->actingAs($partner)->patchJson("/api/v1/date-ideas/{$uuid}/reaction", ['reaction' => 'love'])
            ->assertOk()->assertJsonPath('status', 'saved')->assertJsonPath('my_reaction', 'love');
        $this->assertDatabaseHas('couple_date_idea_reactions', ['user_id' => $partner->id, 'reaction' => 'love']);
    }

    public function test_an_idea_becomes_one_shared_calendar_plan_with_reminders_and_tasks(): void
    {
        [$owner, $partner, $space] = $this->couple();
        $idea = $this->actingAs($owner)->postJson('/api/v1/date-ideas/generate', [
            'gallery_space_id' => $space->id,
            'count' => 1,
            'theme' => 'romantic',
            'budget_max' => 1000,
            'travel_scope' => 'city',
            'transport_mode' => 'transit',
            'duration' => 'evening',
            'setting' => 'any',
            'energy' => 'medium',
            'food' => 'any',
            'weather_aware' => false,
            'destination' => ['location_name' => 'Brno', 'latitude' => 49.1951, 'longitude' => 16.6068],
        ])->assertCreated()->json('ideas.0');

        $response = $this->actingAs($owner)->postJson("/api/v1/date-ideas/{$idea['uuid']}/plan", [
            'starts_at' => now()->addWeek()->setTime(18, 0)->toIso8601String(),
            'create_trip' => false,
            'reminder_minutes' => 180,
        ])->assertSuccessful()->assertJsonPath('idea.status', 'planned');

        $event = CalendarEvent::where('uuid', $response->json('event_uuid'))->firstOrFail();
        $this->assertSame('outing', $event->type);
        $this->assertSame('Brno', $event->place_name);
        $this->assertSame($idea['uuid'], $event->metadata['date_idea_uuid']);
        $this->assertSame(2, $event->participants()->count());
        $this->assertSame(2, $event->reminders()->count());
        $this->assertGreaterThan(0, $event->tasks()->count());

        $this->actingAs($owner)->postJson("/api/v1/date-ideas/{$idea['uuid']}/plan", ['create_trip' => false])
            ->assertOk()->assertJsonPath('event_uuid', $event->uuid);
        $this->assertSame(1, CalendarEvent::where('metadata->date_idea_uuid', $idea['uuid'])->count());

        $tripResponse = $this->actingAs($owner)->postJson("/api/v1/calendar/events/{$event->uuid}/trip")
            ->assertCreated()->assertJsonPath('date_idea_sync.status', 'synced')
            ->assertJsonPath('date_idea_sync.activities', count($idea['plan']['blocks']))
            ->assertJsonPath('date_idea_sync.routes', 1);
        $tripId = (int) $tripResponse->json('id');
        $this->assertDatabaseHas('couple_date_ideas', ['uuid' => $idea['uuid'], 'trip_id' => $tripId]);
        $this->assertDatabaseHas('trip_route_variants', ['trip_id' => $tripId, 'automation_source' => 'generated_date_idea', 'is_selected' => true]);
        $this->assertDatabaseHas('trip_packing_items', ['trip_id' => $tripId, 'automation_source' => 'generated_date_idea', 'category' => 'documents']);

        $activity = DB::table('trip_activities as activity')->join('trip_days as day', 'day.id', '=', 'activity.trip_day_id')
            ->where('day.trip_id', $tripId)->where('activity.automation_source', 'generated_date_idea')->first(['activity.id']);
        DB::table('trip_activities')->where('id', $activity->id)->update(['title' => 'Náš ručně upravený program']);
        $this->postJson("/api/v1/calendar/events/{$event->uuid}/trip")
            ->assertOk()->assertJsonPath('date_idea_sync.activities', count($idea['plan']['blocks']))
            ->assertJsonPath('date_idea_sync.created.activities', 0)
            ->assertJsonPath('date_idea_sync.created.routes', 0);
        $this->assertSame('Náš ručně upravený program', DB::table('trip_activities')->where('id', $activity->id)->value('title'));
        $this->assertSame(count($idea['plan']['blocks']), DB::table('trip_activities as activity')
            ->join('trip_days as day', 'day.id', '=', 'activity.trip_day_id')->where('day.trip_id', $tripId)
            ->where('activity.automation_source', 'generated_date_idea')->count());
    }

    public function test_a_date_idea_is_private_to_its_shared_space(): void
    {
        [$owner, , $space] = $this->couple();
        $outsider = User::factory()->create();
        $idea = CoupleDateIdea::create([
            'gallery_space_id' => $space->id, 'created_by' => $owner->id, 'generation_key' => str_repeat('a', 64),
            'title' => 'Soukromé rande', 'summary' => 'Jen pro nás', 'theme' => 'romantic', 'status' => 'generated',
            'travel_scope' => 'home', 'transport_mode' => 'walk', 'estimated_cost' => 0, 'currency' => 'CZK',
            'estimated_minutes' => 90, 'novelty_percent' => 100, 'parameters' => [], 'plan' => ['blocks' => []],
        ]);

        $this->actingAs($outsider)->patchJson("/api/v1/date-ideas/{$idea->uuid}/reaction", ['reaction' => 'love'])->assertNotFound();
        $this->actingAs($outsider)->postJson("/api/v1/date-ideas/{$idea->uuid}/plan", [])->assertNotFound();
    }

    public function test_a_trip_date_is_expanded_directly_into_itinerary_budget_route_and_packing(): void
    {
        [$owner, , $space] = $this->couple();
        $idea = CoupleDateIdea::create([
            'gallery_space_id' => $space->id, 'created_by' => $owner->id, 'generation_key' => str_repeat('b', 64),
            'title' => 'Celodenní rande v Brně', 'summary' => 'Výlet připravený od programu až po vzpomínku.',
            'theme' => 'low_cost', 'status' => 'generated', 'travel_scope' => 'day_trip', 'transport_mode' => 'train',
            'estimated_cost' => 750, 'currency' => 'CZK', 'estimated_minutes' => 600, 'novelty_percent' => 96,
            'suggested_starts_at' => now()->addWeek()->setTime(9, 0),
            'destination' => ['location_name' => 'Brno', 'latitude' => 49.1951, 'longitude' => 16.6068],
            'parameters' => ['setting' => 'outdoor'],
            'plan' => [
                'blocks' => [
                    ['key' => 'photo_mission', 'stage' => 'start', 'title' => 'Fotografická mise', 'description' => 'Společné snímky.', 'icon' => '📷', 'minutes' => 60, 'estimated_cost' => 0],
                    ['key' => 'place_42', 'stage' => 'place', 'title' => 'Piknik u přehrady', 'description' => 'Uložená zastávka.', 'icon' => '📍', 'minutes' => 120, 'estimated_cost' => 500, 'latitude' => 49.2301, 'longitude' => 16.5205],
                    ['key' => 'memory_pick', 'stage' => 'finish', 'title' => 'Jedna fotka, jedna vzpomínka', 'description' => 'Vybrat fotografii dne.', 'icon' => '💞', 'minutes' => 30, 'estimated_cost' => 0],
                ],
                'budget' => ['activities' => 500, 'transport' => 250, 'total' => 750, 'limit' => 1000, 'currency' => 'CZK', 'is_estimate' => true],
                'route' => ['scope' => 'day_trip', 'mode' => 'train', 'radius_km' => 120, 'estimated_travel_minutes' => 90],
                'preparation_tasks' => ['Koupit jízdenky'], 'is_trip_recommended' => true,
            ],
        ]);

        $response = $this->actingAs($owner)->postJson("/api/v1/date-ideas/{$idea->uuid}/plan", [
            'starts_at' => now()->addWeek()->setTime(9, 0)->toIso8601String(), 'create_trip' => true,
        ])->assertSuccessful()->assertJsonPath('idea.plan.trip_sync.status', 'synced')
            ->assertJsonPath('idea.plan.trip_sync.activities', 3)
            ->assertJsonPath('idea.plan.trip_sync.expenses', 2)
            ->assertJsonPath('idea.plan.trip_sync.routes', 1)
            ->assertJsonPath('idea.plan.trip_sync.waypoints', 1);

        $tripId = (int) $response->json('trip_id');
        $this->assertDatabaseHas('trips', ['id' => $tripId, 'budget' => 750, 'currency' => 'CZK', 'budget_profile' => 'lowcost']);
        $this->assertDatabaseHas('trip_waypoints', ['trip_id' => $tripId, 'place_name' => 'Piknik u přehrady', 'automation_source' => 'generated_date_idea']);
        $this->assertDatabaseHas('trip_packing_items', ['trip_id' => $tripId, 'title' => 'Nabitý telefon nebo fotoaparát']);
        $this->assertDatabaseHas('trip_expenses', ['trip_id' => $tripId, 'category' => 'transport', 'amount' => 250, 'state' => 'planned']);
    }

    public function test_a_planned_date_closes_the_loop_through_partner_feedback_and_a_gallery_memory(): void
    {
        [$owner, $partner, $space] = $this->couple();
        $idea = $this->actingAs($owner)->postJson('/api/v1/date-ideas/generate', [
            'gallery_space_id' => $space->id, 'count' => 1, 'theme' => 'creative', 'budget_max' => 1000,
            'travel_scope' => 'city', 'transport_mode' => 'transit', 'duration' => 'evening',
            'setting' => 'any', 'energy' => 'medium', 'food' => 'none', 'weather_aware' => false,
            'destination' => ['location_name' => 'Brno'],
        ])->assertCreated()->json('ideas.0');
        $planned = $this->actingAs($owner)->postJson("/api/v1/date-ideas/{$idea['uuid']}/plan", [
            'starts_at' => now()->addWeek()->setTime(18, 0)->toIso8601String(), 'create_trip' => false,
        ])->assertSuccessful();
        $event = CalendarEvent::where('uuid', $planned->json('event_uuid'))->firstOrFail();
        $event->update(['starts_at' => now()->subHours(4), 'ends_at' => now()->subHour()]);

        $this->actingAs($partner)->patchJson("/api/v1/date-ideas/{$idea['uuid']}/reaction", [
            'reaction' => 'love', 'rating' => 5, 'note' => 'Nejlepší byla tvořivá část. Příště přidat delší procházku.',
        ])->assertOk();
        $this->assertDatabaseHas('couple_date_idea_reactions', [
            'date_idea_id' => CoupleDateIdea::where('uuid', $idea['uuid'])->value('id'),
            'user_id' => $partner->id, 'reaction' => 'love', 'rating' => 5,
        ]);
        $this->assertDatabaseHas('couple_date_ideas', ['uuid' => $idea['uuid'], 'status' => 'completed']);

        $this->actingAs($owner)->putJson("/api/v1/calendar/events/{$event->uuid}/reflection", [
            'rating' => 3, 'mood' => 'calm', 'highlight' => 'Příjemný společný večer.',
        ])->assertCreated();
        $detail = $this->actingAs($owner)->getJson("/api/v1/calendar/events/{$event->uuid}")
            ->assertOk()->assertJsonPath('date_idea.uuid', $idea['uuid'])
            ->assertJsonPath('date_idea.my_rating', 3)
            ->assertJsonPath('date_idea.feedback.rated_count', 2)
            ->assertJsonPath('date_idea.feedback.complete', true)
            ->assertJsonCount(2, 'date_idea.reactions');
        $this->assertSame(4.0, (float) $detail->json('date_idea.feedback.average_rating'));

        $this->postJson("/api/v1/calendar/events/{$event->uuid}/shared-memory", ['media_ids' => []])
            ->assertCreated()->assertJsonPath('title', $idea['title']);
        $event->refresh();
        $this->assertSame('completed', $event->status);
        $this->assertNotNull($event->album_id);
        $this->assertDatabaseHas('shared_memory_moments', ['calendar_event_id' => $event->id]);
        $this->assertDatabaseHas('couple_date_ideas', ['uuid' => $idea['uuid'], 'status' => 'completed']);
    }

    private function couple(): array
    {
        $owner = User::factory()->create(['name' => 'Adrian']);
        $partner = User::factory()->create(['name' => 'Markétka']);
        $space = GallerySpace::create(['name' => 'My dva', 'owner_id' => $owner->id, 'is_default' => true]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $space->members()->attach($partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        return [$owner, $partner, $space];
    }
}
