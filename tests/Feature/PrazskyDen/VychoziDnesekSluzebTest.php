<?php

namespace Tests\Feature\PrazskyDen;

use App\Models\CycleDay;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Dashboard\PersonalSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `PersonalSummaryService` a `QueryInterpreter` bez zadaného dne spadaly na
 * `Carbon::today()`, tedy UTC den serveru, místo pražského dneška dvojice.
 */
class VychoziDnesekSluzebTest extends TestCase
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

    public function test_nastenka_ukazuje_dnesni_den_cyklu_podle_prahy(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 00:30', 'Europe/Prague'));
        CycleDay::create(['user_id' => $this->owner->id, 'gallery_space_id' => $this->space->id, 'day' => '2026-09-25', 'is_cycle_start' => true, 'flow' => 'medium']);

        $summary = app(PersonalSummaryService::class)->forUser($this->space, $this->owner);

        $this->assertSame('2026-09-25', $summary['cycle']['today']);
    }

    public function test_hledani_dnes_najde_fotku_z_prazskeho_dneska(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 00:30', 'Europe/Prague'));
        $media = MediaItem::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'owner_user_id' => $this->owner->id, 'uploaded_by' => $this->owner->id,
            'original_filename' => 'dnes.jpg', 'safe_filename' => 'dnes.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg',
            'media_type' => 'photo', 'size_bytes' => 4096, 'status' => 'ready', 'storage_status' => 'local_only', 'is_hidden' => false,
            'taken_at' => '2026-09-25 08:00:00', 'uploaded_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/search?q=dnes')->assertOk();

        $uuids = collect($response->json('data'))->pluck('uuid')->all();
        $this->assertContains($media->uuid, $uuids, 'Fotka pořízená dnešní pražský den nebyla nalezena přes „dnes".');
    }
}
