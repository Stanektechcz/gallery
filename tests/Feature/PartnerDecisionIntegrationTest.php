<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\CoupleDateIdea;
use App\Models\EntertainmentTitle;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartnerDecisionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_decision_inbox_updates_original_features_and_continues_into_calendar(): void
    {
        [$owner, $partner, $space] = $this->couple();
        $idea = CoupleDateIdea::create([
            'gallery_space_id' => $space->id, 'created_by' => $owner->id, 'generation_key' => str_repeat('d', 64),
            'title' => 'Piknik u přehrady', 'summary' => 'Levné společné odpoledne s fotografickou procházkou.',
            'theme' => 'low_cost', 'status' => 'generated', 'travel_scope' => 'nearby', 'transport_mode' => 'transit',
            'estimated_cost' => 350, 'currency' => 'CZK', 'estimated_minutes' => 180, 'novelty_percent' => 90,
            'suggested_starts_at' => now()->addWeek()->setTime(15, 0), 'destination' => ['location_name' => 'Brněnská přehrada'],
            'parameters' => [], 'plan' => ['blocks' => [['key' => 'walk', 'title' => 'Procházka', 'minutes' => 90]], 'preparation_tasks' => ['Připravit deku']],
        ]);
        DB::table('couple_date_idea_reactions')->insert([
            'date_idea_id' => $idea->id, 'user_id' => $owner->id, 'reaction' => 'love', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $title = EntertainmentTitle::create([
            'gallery_space_id' => $space->id, 'added_by' => $owner->id, 'media_type' => 'movie', 'title' => 'Matrix',
            'external_source' => 'manual', 'status' => 'proposed', 'runtime_minutes' => 136, 'overview' => 'Společný filmový večer.',
        ]);
        DB::table('entertainment_votes')->insert([
            'entertainment_title_id' => $title->id, 'user_id' => $owner->id, 'interest' => 5, 'cinema_preferred' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $proposalUuid = (string) Str::uuid();
        $proposalId = DB::table('viewing_date_proposals')->insertGetId([
            'uuid' => $proposalUuid, 'entertainment_title_id' => $title->id, 'proposed_by' => $owner->id,
            'starts_at' => now()->addDays(3)->setTime(19, 30), 'venue' => 'home', 'status' => 'proposed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('viewing_proposal_votes')->insert([
            'viewing_date_proposal_id' => $proposalId, 'user_id' => $owner->id, 'response' => 'yes',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $pollUuid = (string) Str::uuid();
        $pollId = DB::table('decision_polls')->insertGetId([
            'uuid' => $pollUuid, 'gallery_space_id' => $space->id, 'created_by' => $owner->id,
            'question' => 'Kam půjdeme na večeři?', 'closes_at' => now()->addDays(2), 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $firstOption = DB::table('decision_poll_options')->insertGetId([
            'poll_id' => $pollId, 'title' => 'Italská restaurace', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('decision_poll_options')->insert([
            'poll_id' => $pollId, 'title' => 'Vietnamská restaurace', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('decision_poll_votes')->insert([
            'poll_option_id' => $firstOption, 'user_id' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $inbox = $this->actingAs($partner)->getJson('/api/v1/coordination/decisions?gallery_space_id=' . $space->id . '&limit=20')
            ->assertOk()->assertJsonPath('summary.total', 4)->assertJsonPath('summary.date_ideas', 1)
            ->assertJsonPath('summary.watchlist', 2)->assertJsonPath('summary.polls', 1);
        $this->assertSame(['viewing_date', 'poll', 'date_idea', 'entertainment_title'], collect($inbox->json('items'))->pluck('type')->all());
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.partner_hub.decisions.summary.total', 4)
            ->where('data.partner_hub.decisions.items.0.type', 'viewing_date'));

        $this->putJson('/api/v1/coordination/decisions/date_idea/' . $idea->uuid, [
            'gallery_space_id' => $space->id, 'response' => 'love',
        ])->assertOk()->assertJsonPath('summary.total', 3);
        $this->assertDatabaseHas('couple_date_idea_reactions', ['date_idea_id' => $idea->id, 'user_id' => $partner->id, 'reaction' => 'love']);
        $this->assertDatabaseHas('couple_date_ideas', ['id' => $idea->id, 'status' => 'saved']);

        $this->putJson('/api/v1/coordination/decisions/entertainment_title/' . $title->uuid, [
            'gallery_space_id' => $space->id, 'response' => 'love',
        ])->assertOk()->assertJsonPath('summary.total', 2);
        $this->assertDatabaseHas('entertainment_votes', ['entertainment_title_id' => $title->id, 'user_id' => $partner->id, 'interest' => 5]);

        $this->putJson('/api/v1/coordination/decisions/viewing_date/' . $proposalUuid, [
            'gallery_space_id' => $space->id, 'response' => 'yes',
        ])->assertOk()->assertJsonPath('summary.total', 1);
        $this->assertDatabaseHas('viewing_proposal_votes', ['viewing_date_proposal_id' => $proposalId, 'user_id' => $partner->id, 'response' => 'yes']);

        $this->putJson('/api/v1/coordination/decisions/poll/' . $pollUuid, [
            'gallery_space_id' => $space->id, 'response' => (string) $firstOption,
        ])->assertOk()->assertJsonPath('summary.total', 0);
        $this->assertDatabaseHas('decision_poll_votes', ['poll_option_id' => $firstOption, 'user_id' => $partner->id]);

        $plannedDate = $this->postJson('/api/v1/date-ideas/' . $idea->uuid . '/plan', [
            'starts_at' => now()->addWeek()->setTime(15, 0)->toIso8601String(), 'create_trip' => false,
        ])->assertSuccessful();
        $this->postJson('/api/v1/entertainment/date-proposals/' . $proposalUuid . '/select')->assertCreated();
        $this->postJson('/api/v1/calendar/polls/' . $pollUuid . '/options/' . $firstOption . '/plan')->assertCreated();

        $this->assertDatabaseHas('calendar_events', ['uuid' => $plannedDate->json('event_uuid'), 'type' => 'outing']);
        $this->assertSame(3, CalendarEvent::where('gallery_space_id', $space->id)->count());
        $this->assertSame(6, DB::table('event_participants')->count());
    }

    public function test_decision_inbox_is_space_scoped_and_respects_read_only_mode(): void
    {
        [$owner, $partner, $space] = $this->couple();
        $idea = CoupleDateIdea::create([
            'gallery_space_id' => $space->id, 'created_by' => $owner->id, 'generation_key' => str_repeat('e', 64),
            'title' => 'Soukromý nápad', 'summary' => 'Jen pro nás.', 'theme' => 'romantic', 'status' => 'generated',
            'travel_scope' => 'home', 'transport_mode' => 'walk', 'estimated_cost' => 0, 'currency' => 'CZK',
            'estimated_minutes' => 60, 'novelty_percent' => 100, 'parameters' => [], 'plan' => ['blocks' => []],
        ]);
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->getJson('/api/v1/coordination/decisions?gallery_space_id=' . $space->id)->assertNotFound();

        $partner->update(['read_only_mode' => true]);
        $this->actingAs($partner)->putJson('/api/v1/coordination/decisions/date_idea/' . $idea->uuid, [
            'gallery_space_id' => $space->id, 'response' => 'love',
        ])->assertForbidden();
        $this->assertDatabaseMissing('couple_date_idea_reactions', ['date_idea_id' => $idea->id, 'user_id' => $partner->id]);
    }

    private function couple(): array
    {
        $owner = User::factory()->create(['name' => 'Adrian', 'is_active' => true]);
        $partner = User::factory()->create(['name' => 'Markétka', 'is_active' => true]);
        $space = GallerySpace::create(['name' => 'My dva', 'slug' => 'partner-decisions', 'owner_id' => $owner->id, 'is_default' => true]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $space->members()->attach($partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        return [$owner, $partner, $space];
    }
}
