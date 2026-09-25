<?php

namespace Tests\Feature\PrazskyDen;

use App\Models\GallerySpace;
use App\Models\Place;
use App\Models\PlaceReview;
use App\Models\User;
use App\Support\Cas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `$base->isPast()` je pravda skoro pořád po půlnoci — výslovně zadaný
 * „dnešek" tak vždy skončil jako „zítra".
 */
class DnesovyTerminTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Naše', 'slug' => 'nase-'.Str::random(6), 'owner_id' => $this->owner->id, 'is_default' => true]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->actingAs($this->owner);
    }

    public function test_generovany_randicek_na_vyslovne_dnes_zustane_dnes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 08:00', 'Europe/Prague'));
        $dnes = Cas::dnes()->toDateString();

        $response = $this->postJson('/api/v1/date-ideas/generate', [
            'gallery_space_id' => $this->space->id, 'preferred_date' => $dnes, 'budget_max' => 5000, 'currency' => 'CZK',
        ])->assertCreated();

        $idea = collect($response->json('ideas'))->first();
        $this->assertNotNull($idea, 'Nevygeneroval se žádný nápad.');
        $this->assertStringStartsWith($dnes, $idea['suggested_starts_at']);
    }

    public function test_doporuceny_podnik_na_vyslovne_dnes_zustane_dnes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 08:00', 'Europe/Prague'));
        $place = Place::create([
            'gallery_space_id' => $this->space->id, 'name' => 'Naše bistro', 'type' => 'restaurant',
            'city' => 'Brno', 'price_level' => 2, 'estimated_visit_minutes' => 90, 'created_by' => $this->owner->id,
        ]);
        PlaceReview::create(['gallery_space_id' => $this->space->id, 'place_id' => $place->id, 'author_user_id' => $this->owner->id, 'overall_rating' => 5, 'visited_on' => '2026-08-01']);
        $dnes = Cas::dnes()->toDateString();

        $response = $this->getJson("/api/v1/calendar/date-ideas?gallery_space_id={$this->space->id}&date={$dnes}")->assertOk();

        $idea = collect($response->json('ideas'))->first();
        $this->assertNotNull($idea, 'Nevznikl žádný nápad na podnik.');
        $this->assertStringStartsWith($dnes, $idea['suggested_starts_at']);
    }
}
