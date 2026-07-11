<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExperienceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $partner;
    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'password' => 'secret-pass']);
        $this->partner = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $this->space = GallerySpace::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Rodinná galerie', 'slug' => 'rodinna-galerie',
            'owner_id' => $this->owner->id, 'is_default' => true,
        ]);
        foreach ([[$this->owner, 'owner'], [$this->partner, 'editor']] as [$user, $role]) {
            $this->space->members()->attach($user->id, ['role' => $role, 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        }
    }

    public function test_czech_natural_search_is_interpreted_into_filters(): void
    {
        $this->media(['taken_at' => '2025-07-10 12:00:00', 'is_favorite' => true]);
        $this->media(['taken_at' => '2024-07-10 12:00:00', 'is_favorite' => true]);

        $this->actingAs($this->owner)->getJson('/api/v1/search?q=obl%C3%ADben%C3%A9%20fotografie%202025')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('interpreted.filters.favorites_only', true)
            ->assertJsonPath('interpreted.filters.media_type', 'photo');
    }

    public function test_private_saved_view_cannot_be_changed_by_another_member(): void
    {
        $view = SavedSearch::create([
            'user_id' => $this->owner->id, 'gallery_space_id' => $this->space->id,
            'name' => 'Soukromý výběr', 'filters_json' => ['favorites_only' => true],
        ]);

        $this->actingAs($this->partner)
            ->patchJson("/api/v1/saved-searches/{$view->id}", ['is_pinned' => true])
            ->assertNotFound();
    }

    public function test_trip_plan_creates_days_and_accepts_workspace_blocks(): void
    {
        $tripId = DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'name' => 'Vídeň', 'start_date' => '2026-08-01', 'end_date' => '2026-08-03',
            'status' => 'planned', 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $plan = $this->actingAs($this->owner)->getJson("/api/v1/trips/{$tripId}/plan")
            ->assertOk()->assertJsonCount(3, 'days');
        $dayId = $plan->json('days.0.id');

        $first = $this->postJson("/api/v1/trips/{$tripId}/plan/days/{$dayId}/activities", [
            'type' => 'reservation', 'title' => 'Schönbrunn', 'starts_at' => '10:00',
            'ends_at' => '12:00', 'cost' => 780, 'currency' => 'CZK',
        ])->assertCreated()->assertJsonPath('title', 'Schönbrunn');
        $second = $this->postJson("/api/v1/trips/{$tripId}/plan/days/{$dayId}/activities", [
            'type' => 'transport', 'title' => 'Přesun do centra', 'starts_at' => '13:00', 'currency' => 'CZK',
        ])->assertCreated();

        $this->putJson("/api/v1/trips/{$tripId}/plan/days/{$dayId}/activities/reorder", [
            'order' => [$second->json('id'), $first->json('id')],
        ])->assertOk()->assertJsonPath('reordered', 2);
        $this->patchJson("/api/v1/trips/{$tripId}/plan/activities/{$first->json('id')}", [
            'title' => 'Schönbrunn – nový čas', 'starts_at' => '09:30', 'ends_at' => '11:30',
        ])->assertOk()->assertJsonPath('title', 'Schönbrunn – nový čas');
        $this->assertDatabaseHas('trip_activities', ['id' => $second->json('id'), 'sort_order' => 0]);
    }

    public function test_memory_engine_returns_on_this_day_and_accepts_feedback(): void
    {
        $this->media(['taken_at' => now()->subYear()->setTime(12, 0), 'is_favorite' => true]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/memories')
            ->assertOk();
        $memory = collect($response->json())->firstWhere('type', 'on_this_day');
        $this->assertNotNull($memory);

        $this->postJson('/api/v1/memories/interactions', [
            'fingerprint' => $memory['fingerprint'], 'memory_type' => 'on_this_day', 'action' => 'dismissed',
        ])->assertOk();

        $this->getJson('/api/v1/memories')->assertOk()->assertJsonMissing(['fingerprint' => $memory['fingerprint']]);
    }

    public function test_private_vault_requires_password_before_listing_hidden_media(): void
    {
        $mediaId = $this->media([]);
        $uuid = DB::table('media_items')->where('id', $mediaId)->value('uuid');

        $this->actingAs($this->owner)->postJson("/vault/media/{$uuid}/toggle")
            ->assertOk()->assertJsonPath('is_hidden', true);
        $this->get("/media/{$uuid}")->assertRedirect('/vault');
        $this->get('/vault')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Vault/Gate'));

        $this->post('/vault/unlock', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post('/vault/unlock', ['password' => 'secret-pass'])->assertRedirect('/vault');
        $this->get('/vault')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Vault/Index')
            ->where('media.data.0.uuid', $uuid));
    }

    private function media(array $overrides = []): int
    {
        return DB::table('media_items')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id,
            'owner_user_id' => $this->owner->id, 'uploaded_by' => $this->owner->id,
            'original_filename' => Str::random(10) . '.jpg', 'safe_filename' => Str::random(10) . '.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1000,
            'status' => 'ready', 'storage_status' => 'ready', 'taken_at' => now()->subYear(),
            'is_favorite' => false, 'is_archived' => false, 'is_hidden' => false,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }
}
