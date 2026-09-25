<?php

namespace Tests\Feature\PrazskyDen;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Přehled po měsících bral aktuální rok podle UTC serveru — na Nový rok v Praze ještě chvíli chyběl leden. */
class StatistikyPrazskyDenTest extends TestCase
{
    use RefreshDatabase;

    public function test_prehled_mesicu_pouziva_prazsky_novy_rok(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Naše', 'slug' => 'nase-'.Str::random(6), 'owner_id' => $owner->id, 'is_default' => true]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->actingAs($owner);

        // V Praze je už Nový rok, server v UTC má ještě loňský Silvestr.
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30', 'Europe/Prague'));
        MediaItem::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $space->id, 'owner_user_id' => $owner->id, 'uploaded_by' => $owner->id,
            'original_filename' => 'novyrok.jpg', 'safe_filename' => 'novyrok.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg',
            'media_type' => 'photo', 'size_bytes' => 4096, 'status' => 'ready', 'storage_status' => 'local_only', 'is_hidden' => false,
            'taken_at' => '2027-01-01 08:00:00', 'uploaded_at' => now(),
        ]);

        $this->get('/stats')->assertOk()
            ->assertInertia(fn ($page) => $page->where('stats.per_month.0.total', 1));
    }
}
