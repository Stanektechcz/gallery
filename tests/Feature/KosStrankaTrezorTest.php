<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Stará stránka koše (`/trash`) a zamčený trezor.
 *
 * Vysypání koše skryté položky se zamčeným trezorem vynechávalo, ale samotný
 * seznam je vypisoval — s názvem souboru i titulkem. Kdo otevřel koš, viděl,
 * co je v trezoru, aniž by ho odemkl.
 */
class KosStrankaTrezorTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    private MediaItem $bezna;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['role' => 'owner']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$this->prostor->id => ['role' => 'owner']]);

        $this->bezna = $this->fotka('dovolena.jpg', false);
        $this->fotka('pas-v-trezoru.jpg', true);
    }

    public function test_zamceny_trezor_v_kosi_neukaze_skryte(): void
    {
        $this->actingAs($this->adri)->get('/trash')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $stranka) => $stranka
                ->has('media.data', 1)
                ->where('media.data.0.uuid', $this->bezna->uuid));
    }

    public function test_odemceny_trezor_v_kosi_ukaze_vse(): void
    {
        $this->actingAs($this->adri)->withSession($this->odemcenyTrezor($this->adri))->get('/trash')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $stranka) => $stranka->has('media.data', 2));
    }

    private function fotka(string $nazev, bool $skryta): MediaItem
    {
        return MediaItem::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => $nazev,
            'safe_filename' => Str::slug($nazev).'.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1000,
            'taken_at' => now()->subDays(2),
            'uploaded_at' => now()->subDays(2),
            'status' => 'ready',
            'storage_status' => 'local',
            'is_hidden' => $skryta,
            'trashed_at' => now()->subDay(),
        ]);
    }
}
