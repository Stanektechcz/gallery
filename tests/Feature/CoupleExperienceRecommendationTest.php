<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\Place;
use App\Models\PlaceReview;
use App\Models\PlaceReviewItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CoupleExperienceRecommendationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $partner;
    private GallerySpace $space;
    private Place $favorite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['name' => 'Adrian', 'role' => 'owner']);
        $this->partner = User::factory()->create(['name' => 'Markétka', 'role' => 'partner']);
        $this->space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Naše galerie', 'slug' => 'nase-galerie', 'owner_id' => $this->owner->id]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->space->members()->attach($this->partner->id, ['role' => 'editor', 'can_delete' => false, 'can_share' => true, 'joined_at' => now()]);
        $this->favorite = Place::create([
            'gallery_space_id' => $this->space->id, 'name' => 'Naše oblíbené bistro', 'type' => 'restaurant',
            'city' => 'Brno', 'price_level' => 2, 'estimated_visit_minutes' => 150,
            'is_rain_friendly' => true, 'created_by' => $this->owner->id,
        ]);
        $this->review($this->owner, 5, 'Zopakovat výroční menu.');
        $partnerReview = $this->review($this->partner, 4, null);
        PlaceReviewItem::create([
            'place_review_id' => $partnerReview->id, 'category' => 'food', 'name' => 'Dýňové risotto',
            'overall_rating' => 5, 'would_order_again' => true, 'sort_order' => 0,
        ]);
        $this->actingAs($this->owner);
    }

    public function test_calendar_and_dashboard_use_explainable_shared_recommendations(): void
    {
        Place::create([
            'gallery_space_id' => $this->space->id, 'name' => 'Drahý nový tip', 'type' => 'restaurant',
            'price_level' => 4, 'personal_rating' => 5, 'created_by' => $this->owner->id,
        ]);

        $idea = $this->getJson('/api/v1/calendar/date-ideas?' . http_build_query([
            'gallery_space_id' => $this->space->id, 'theme' => 'budget', 'date' => now()->addWeek()->toDateString(),
        ]))->assertOk()
            ->assertJsonPath('ideas.0.id', $this->favorite->id)
            ->assertJsonPath('ideas.0.kind', 'return')
            ->assertJsonPath('ideas.0.review_average', 4.5)
            ->assertJsonPath('ideas.0.return_percent', 100)
            ->assertJsonPath('ideas.0.top_item.name', 'Dýňové risotto')
            ->json('ideas.0');

        $this->assertStringContainsString('shodli jste se', $idea['reason']);
        $this->assertStringContainsString('low-cost', $idea['reason']);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->where('data.partner_hub.experience_recommendation.id', $this->favorite->id)
            ->where('data.partner_hub.experience_recommendation.top_item.name', 'Dýňové risotto'));
    }

    public function test_one_plan_action_creates_shared_idempotent_event_plan_and_reminders(): void
    {
        $startsAt = now()->addDays(8)->setTime(18, 0)->startOfMinute();
        $payload = [
            'starts_at' => $startsAt->toIso8601String(),
            'duration_minutes' => 150,
            'reminder_minutes' => 1440,
            'from_recommendation' => true,
            'recommendation_reason' => 'shodli jste se na něm oba',
            'recommended_item' => 'Dýňové risotto',
        ];

        $plan = $this->postJson("/api/v1/places/{$this->favorite->id}/plans", $payload)
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->json();

        $this->assertDatabaseHas('calendar_events', [
            'id' => $plan['calendar_event_id'], 'gallery_space_id' => $this->space->id,
            'title' => 'Rande · Naše oblíbené bistro', 'type' => 'outing',
        ]);
        $this->assertDatabaseHas('place_plans', ['id' => $plan['id'], 'place_id' => $this->favorite->id, 'state' => 'planned']);
        $this->assertSame(2, DB::table('event_participants')->where('event_id', $plan['calendar_event_id'])->count());
        $this->assertSame(2, DB::table('event_reminders')->where('event_id', $plan['calendar_event_id'])->count());
        $metadata = json_decode(DB::table('calendar_events')->where('id', $plan['calendar_event_id'])->value('metadata'), true);
        $this->assertSame('couple_experience_recommendation', $metadata['source']);
        $this->assertSame('Dýňové risotto', $metadata['recommended_item']);

        $this->postJson("/api/v1/places/{$this->favorite->id}/plans", $payload)
            ->assertOk()->assertJsonPath('created', false)->assertJsonPath('id', $plan['id']);
        $this->assertSame(1, DB::table('place_plans')->where('place_id', $this->favorite->id)->count());

        $this->getJson('/api/v1/calendar/date-ideas?' . http_build_query(['gallery_space_id' => $this->space->id]))
            ->assertOk()->assertJsonMissing(['id' => $this->favorite->id]);
    }

    private function review(User $author, int $rating, ?string $nextTimeNote): PlaceReview
    {
        return PlaceReview::create([
            'gallery_space_id' => $this->space->id,
            'place_id' => $this->favorite->id,
            'author_user_id' => $author->id,
            'status' => 'published',
            'visited_at' => now()->subMonths(7),
            'overall_rating' => $rating,
            'would_return' => true,
            'currency' => 'CZK',
            'next_time_note' => $nextTimeNote,
        ]);
    }
}
