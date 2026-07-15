<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\Place;
use App\Models\PlaceReview;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SharedLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $adrian;
    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adrian = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->space  = GallerySpace::create([
            'uuid'     => \Str::uuid(),
            'name'     => 'Test',
            'slug'     => 'test',
            'owner_id' => $this->adrian->id,
        ]);
        $this->space->members()->attach($this->adrian->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true]);
    }

    /** @test */
    public function test_can_create_shared_link(): void
    {
        $response = $this->actingAs($this->adrian)
            ->postJson('/shares', [
                'target_type'    => 'selection',
                'allow_download' => true,
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['token', 'url']);
        $this->assertDatabaseHas('shared_links', ['created_by' => $this->adrian->id, 'target_type' => 'selection']);
    }

    /** @test */
    public function test_shared_link_with_password(): void
    {
        $response = $this->actingAs($this->adrian)
            ->postJson('/shares', [
                'target_type' => 'selection',
                'password'    => 'secret1234',
            ]);

        $token = $response->json('token');
        $this->assertDatabaseHas('shared_links', ['token' => $token]);

        $link = \App\Models\SharedLink::where('token', $token)->first();
        $this->assertNotNull($link->password_hash);
        $this->assertTrue(\Hash::check('secret1234', $link->password_hash));
    }

    /** @test */
    public function test_shared_link_with_expiry(): void
    {
        $expiry = now()->addDay()->format('Y-m-d H:i:s');

        $response = $this->actingAs($this->adrian)
            ->postJson('/shares', [
                'target_type' => 'selection',
                'expires_at'  => $expiry,
            ]);

        $token = $response->json('token');
        $link  = \App\Models\SharedLink::where('token', $token)->first();
        $this->assertNotNull($link->expires_at);
    }

    /** @test */
    public function test_can_delete_shared_link(): void
    {
        $response = $this->actingAs($this->adrian)
            ->postJson('/shares', ['target_type' => 'selection']);

        $token = $response->json('token');
        $link  = \App\Models\SharedLink::where('token', $token)->first();

        $this->actingAs($this->adrian)
            ->deleteJson("/shares/{$link->id}")
            ->assertOk();

        $this->assertDatabaseMissing('shared_links', ['id' => $link->id]);
    }

    /** @test */
    public function test_recipe_can_be_shared_without_exposing_private_cooking_history(): void
    {
        $recipe = Recipe::create([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->adrian->id,
            'title' => 'Naše lasagne', 'summary' => 'Rodinný recept.', 'category' => 'main_course',
            'difficulty' => 'medium', 'status' => 'published', 'base_servings' => 2,
            'prep_minutes' => 15, 'cook_minutes' => 40, 'currency' => 'CZK',
        ]);
        $recipe->ingredients()->create(['section' => 'Omáčka', 'name' => 'Rajčata', 'quantity' => 400, 'unit' => 'g']);
        $recipe->steps()->create(['title' => 'Připravit', 'instruction' => 'Uvařte omáčku.', 'sort_order' => 0]);
        $recipe->cookingSessions()->create([
            'uuid' => (string) Str::uuid(), 'created_by' => $this->adrian->id, 'status' => 'completed',
            'servings' => 2, 'partner_feedback' => 'Toto je jen pro nás.', 'currency' => 'CZK',
        ]);

        $response = $this->actingAs($this->adrian)->postJson('/api/v1/shares', [
            'target_type' => 'recipe', 'target_uuid' => $recipe->uuid,
            'name' => 'Recept pro rodinu', 'allow_download' => true, 'allow_guest_upload' => true,
        ])->assertOk()->assertJsonStructure(['id', 'token', 'url']);

        $this->assertDatabaseHas('shared_links', [
            'target_type' => 'recipe', 'target_id' => $recipe->id, 'gallery_space_id' => $this->space->id,
            'allow_download' => false, 'allow_guest_upload' => false, 'hide_gps' => true, 'show_metadata' => false,
        ]);
        $this->get('/s/' . $response->json('token'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shares/Content')
            ->where('content.type', 'recipe')
            ->where('content.title', 'Naše lasagne')
            ->where('content.data.ingredients.0.name', 'Rajčata')
            ->where('content.data.steps.0.instruction', 'Uvařte omáčku.')
            ->missing('content.data.cooking_sessions')
            ->missing('content.data.partner_feedback'));
    }

    /** @test */
    public function test_published_own_place_review_can_be_shared_without_internal_follow_up_note_or_gps(): void
    {
        $place = Place::create([
            'gallery_space_id' => $this->space->id, 'name' => 'Bistro U parku', 'type' => 'restaurant',
            'city' => 'Brno', 'address' => 'Parková 1', 'latitude' => 49.2, 'longitude' => 16.6,
            'created_by' => $this->adrian->id,
        ]);
        $review = PlaceReview::create([
            'gallery_space_id' => $this->space->id, 'place_id' => $place->id,
            'author_user_id' => $this->adrian->id, 'status' => 'published', 'overall_rating' => 5,
            'service_rating' => 4, 'currency' => 'CZK', 'positives' => 'Milá obsluha.',
            'notes' => 'Výborná večeře.', 'next_time_note' => 'Soukromě: objednat stůl u okna.',
        ]);
        $review->items()->create(['category' => 'food', 'name' => 'Rizoto', 'overall_rating' => 5, 'currency' => 'CZK']);

        $response = $this->actingAs($this->adrian)->postJson('/api/v1/shares', [
            'target_type' => 'place_review', 'target_uuid' => $review->uuid,
        ])->assertOk();

        $this->get('/s/' . $response->json('token'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shares/Content')
            ->where('content.type', 'place_review')
            ->where('content.data.place.name', 'Bistro U parku')
            ->where('content.data.ratings.overall', 5)
            ->where('content.data.items.0.name', 'Rizoto')
            ->missing('content.data.next_time_note')
            ->missing('content.data.place.latitude')
            ->missing('content.data.place.longitude'));
    }

    /** @test */
    public function test_draft_or_another_persons_review_cannot_be_shared(): void
    {
        $partner = User::factory()->create(['role' => 'partner']);
        $this->space->members()->attach($partner->id, ['role' => 'editor', 'can_share' => true]);
        $place = Place::create(['gallery_space_id' => $this->space->id, 'name' => 'Kavárna', 'type' => 'cafe', 'created_by' => $this->adrian->id]);
        $draft = PlaceReview::create([
            'gallery_space_id' => $this->space->id, 'place_id' => $place->id,
            'author_user_id' => $this->adrian->id, 'status' => 'draft', 'currency' => 'CZK',
        ]);
        $published = PlaceReview::create([
            'gallery_space_id' => $this->space->id, 'place_id' => $place->id,
            'author_user_id' => $this->adrian->id, 'status' => 'published', 'overall_rating' => 4, 'currency' => 'CZK',
        ]);

        $this->actingAs($this->adrian)->postJson('/api/v1/shares', ['target_type' => 'place_review', 'target_uuid' => $draft->uuid])->assertNotFound();
        $this->actingAs($partner)->postJson('/api/v1/shares', ['target_type' => 'place_review', 'target_uuid' => $published->uuid])->assertNotFound();
    }
}
