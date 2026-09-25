<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

        // Trvalé smazání sahá na disk — jen na falešný.
        Storage::fake('public');

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

    /**
     * Vrátit z koše se zamčeným trezorem jde jen to, co koš ukazuje.
     *
     * Kdo znal uuid položky z trezoru, vrátil ji z koše, aniž by trezor
     * odemkl — a hromadné vracení ji vzalo i bez toho, ať jsou v seznamu
     * jakákoli uuid.
     */
    public function test_zamceny_trezor_z_kose_nevrati_skryte(): void
    {
        $skryta = MediaItem::where('is_hidden', true)->sole();
        $this->actingAs($this->adri);

        $this->postJson('/trash/'.$skryta->uuid.'/restore')->assertNotFound();
        $this->assertNotNull($skryta->fresh()->trashed_at);

        $this->postJson('/trash/bulk-restore', ['uuids' => [$skryta->uuid, $this->bezna->uuid]])
            ->assertOk()->assertJson(['count' => 1]);
        $this->assertNotNull($skryta->fresh()->trashed_at);
        $this->assertNull($this->bezna->fresh()->trashed_at);
    }

    public function test_odemceny_trezor_z_kose_vrati_skryte(): void
    {
        $skryta = MediaItem::where('is_hidden', true)->sole();

        $this->actingAs($this->adri)->withSession($this->odemcenyTrezor($this->adri))
            ->postJson('/trash/'.$skryta->uuid.'/restore')->assertOk()->assertJson(['status' => 'restored']);
        $this->assertNull($skryta->fresh()->trashed_at);
    }

    /** Trvalé smazání a vysypání koše skryté se zamčeným trezorem nechají být. */
    public function test_zamceny_trezor_trvale_nesmaze_skryte(): void
    {
        $skryta = MediaItem::where('is_hidden', true)->sole();
        $this->actingAs($this->adri);

        $this->deleteJson('/trash/'.$skryta->uuid.'/purge')->assertNotFound();
        $this->deleteJson('/trash/empty')->assertOk()->assertJson(['count' => 1]);

        $this->assertNotNull(MediaItem::withoutGlobalScopes()->find($skryta->id));
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
