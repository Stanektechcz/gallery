<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Koš po třiceti dnech.
 *
 * `purge_after` se dosud jen zapisovalo a nikdo podle něj neuklízel. Smazaná
 * fotka zůstávala na disku i v součtu úložiště navždy, takže se platilo za místo,
 * které podle obrazovky ubylo. Testy proto hlídají obě strany: že se po lhůtě
 * opravdu smaže, a že se nesmaže nic, co je v koši teprve chvíli.
 */
class TrashPurgeTest extends TestCase
{
    use RefreshDatabase;

    private GallerySpace $prostor;

    private User $adri;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$this->prostor->id => ['role' => 'owner']]);
    }

    public function test_polozka_po_lhute_zmizi_i_z_disku(): void
    {
        $media = $this->vKosi(now()->subDay());
        $cesta = $media->variants()->sole()->path;

        $this->artisan('gallery:purge-trash')->expectsOutputToContain('Smazáno: 1')->assertSuccessful();

        $this->assertSame(0, MediaItem::withTrashed()->count(), 'Řádek má zmizet úplně, ne měkce.');
        Storage::disk('public')->assertMissing($cesta);
    }

    public function test_cerstve_smazana_polozka_zustane(): void
    {
        $this->vKosi(now()->addDays(29));

        $this->artisan('gallery:purge-trash')->expectsOutputToContain('Koš je prázdný')->assertSuccessful();

        $this->assertSame(1, MediaItem::count());
    }

    /** Nesmazaná fotka nemá v úklidu co dělat, ať je jakkoli stará. */
    public function test_nesmazana_polozka_se_nedotkne(): void
    {
        $this->media(['created_at' => now()->subYears(3)]);

        $this->artisan('gallery:purge-trash')->assertSuccessful();

        $this->assertSame(1, MediaItem::count());
    }

    /**
     * Řádek bez data úklidu se posoudí podle data smazání.
     *
     * Fotky smazané dřív, než `purge_after` existovalo, by jinak v koši zůstaly
     * navždy — a to je přesně ta chyba, kterou tenhle příkaz uklízí.
     */
    public function test_polozka_bez_data_uklidu_se_posoudi_podle_smazani(): void
    {
        $this->media(['trashed_at' => now()->subDays(45), 'purge_after' => null]);

        $this->artisan('gallery:purge-trash')->expectsOutputToContain('Smazáno: 1')->assertSuccessful();

        $this->assertSame(0, MediaItem::withTrashed()->count());
    }

    public function test_nasucho_nic_nesmaze(): void
    {
        $media = $this->vKosi(now()->subDay());

        $this->artisan('gallery:purge-trash --nasucho')
            ->expectsOutputToContain('Ke smazání: 1')
            ->assertSuccessful();

        $this->assertSame(1, MediaItem::count());
        Storage::disk('public')->assertExists($media->variants()->sole()->path);
    }

    private function vKosi(\Carbon\CarbonInterface $uklidPo): MediaItem
    {
        return $this->media(['trashed_at' => now()->subDays(31), 'purge_after' => $uklidPo]);
    }

    private function media(array $navic = []): MediaItem
    {
        $media = MediaItem::create([
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'vylet.jpg',
            'safe_filename' => 'vylet.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'status' => 'ready',
            ...$navic,
        ]);

        $cesta = "media/{$media->uuid}/original.jpg";
        Storage::disk('public')->put($cesta, 'obsah fotky');
        $media->variants()->create([
            'type' => 'original',
            'disk' => 'public',
            'path' => $cesta,
            'size_bytes' => 1024,
        ]);

        return $media;
    }
}
