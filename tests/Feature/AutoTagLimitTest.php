<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `--limit` nesmí navždy zablokovat frontu za sebou.
 *
 * `gallery:auto-tag` bral prvních `--limit` položek bez řazení a bez
 * podmínky „už zpracováno" — každý běh tak sáhl na tutéž frontu a cokoli za
 * hranicí limitu se štítků nedočkalo nikdy.
 */
class AutoTagLimitTest extends TestCase
{
    use RefreshDatabase;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $adri->id]);
    }

    private function media(string $mesic): MediaItem
    {
        return MediaItem::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->prostor->owner_id,
            'uploaded_by' => $this->prostor->owner_id,
            'original_filename' => 'foto.jpg',
            'safe_filename' => 'foto.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1,
            'status' => 'ready',
            'storage_status' => 'ready',
            'is_hidden' => false,
            'taken_at' => "2025-{$mesic}-10 12:00:00",
            'uploaded_at' => now(),
        ]);
    }

    public function test_treti_polozka_se_oznatkuje_az_v_druhem_behu(): void
    {
        $prvni = $this->media('01');
        $druha = $this->media('02');
        $treti = $this->media('03');

        $this->artisan('gallery:auto-tag --apply --limit=2')->assertSuccessful();

        $this->assertDatabaseHas('media_tag', ['media_item_id' => $prvni->id]);
        $this->assertDatabaseHas('media_tag', ['media_item_id' => $druha->id]);
        $this->assertDatabaseMissing('media_tag', ['media_item_id' => $treti->id]);

        $this->artisan('gallery:auto-tag --apply --limit=2')->assertSuccessful();

        $this->assertDatabaseHas('media_tag', ['media_item_id' => $treti->id]);
    }

    /** Fotka v trezoru štítky nedostane, ani kdyby jinak splňovala pravidla. */
    public function test_fotka_v_trezoru_nedostane_stitky(): void
    {
        $skryta = $this->media('01');
        $skryta->update(['is_hidden' => true]);

        $this->artisan('gallery:auto-tag --apply --limit=10')->assertSuccessful();

        $this->assertDatabaseMissing('media_tag', ['media_item_id' => $skryta->id]);
    }
}
