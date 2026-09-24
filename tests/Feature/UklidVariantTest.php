<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Limity mezipaměti variant něco dělají.
 *
 * `variant_cache_max_size_gb` a `variant_cache_max_age_days` byly v konfiguraci
 * od začátku a nečetl je nikdo — dvacet gigabajtů byl jen údaj v souboru.
 *
 * Úklid je schválně dvakrát opatrný:
 *
 *  * **Velikost rozhoduje, stáří jen vybírá.** Dokud se mezipaměť do limitu
 *    vejde, nemaže se nic — i kdyby byla celá roky stará. Zmenšenina, kterou
 *    nic netlačí, je užitečná.
 *  * **Zahodit jde jen to, co nikomu nechybí.** Originál je sama fotka, náhled
 *    je tvář galerie a `edited_*` je výsledek úpravy dvojice. Zůstává i to,
 *    u čeho originál na disku není — tam by zmenšenina byla poslední kopie.
 */
class UklidVariantTest extends TestCase
{
    use RefreshDatabase;

    /** Velikost jedné zkušební varianty v bajtech. */
    private const KUS = 400;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        $this->limit(1000);
        config(['gallery.variant_cache_max_age_days' => 90]);
    }

    public function test_pod_limitem_se_nemaze_ani_letita_zmensenina(): void
    {
        $this->limit(10_000);
        $fotka = $this->fotka();
        $this->varianta($fotka, 'large', dni: 900);

        $this->artisan('gallery:uklid-variant')->assertExitCode(0);

        $this->assertTrue($this->existuje($fotka, 'large'),
            'Mezipaměť se do limitu vejde — nebyl důvod na cokoli sahat.');
    }

    public function test_nad_limitem_jde_prvni_nejstarsi_a_konci_se_pod_limitem(): void
    {
        $fotka = $this->fotka();
        // Pořadí vkládání je schválně jiné než pořadí stáří: kdyby se mazalo
        // podle `id`, šla by první `small` a test by to poznal.
        $this->varianta($fotka, 'small', dni: 100);
        $this->varianta($fotka, 'medium', dni: 400);
        $this->varianta($fotka, 'large', dni: 200);

        // 3 × 400 B = 1200 B nad limitem 1000 B; stačí zahodit jednu.
        $this->artisan('gallery:uklid-variant')->assertExitCode(0);

        $this->assertFalse($this->existuje($fotka, 'medium'), 'Nejstarší měla jít první.');
        $this->assertTrue($this->existuje($fotka, 'large'), 'Po jedné se mezipaměť vešla — víc mazat netřeba.');
        $this->assertTrue($this->existuje($fotka, 'small'));
    }

    public function test_originalu_nahledu_ani_upravy_se_nedotkne(): void
    {
        $fotka = $this->fotka();
        $this->varianta($fotka, 'thumbnail', dni: 800);
        $this->varianta($fotka, 'edited_preview', dni: 800);
        $this->varianta($fotka, 'video_poster', dni: 800);

        $this->artisan('gallery:uklid-variant')
            ->expectsOutputToContain('nevešla')
            ->assertExitCode(0);

        $this->assertTrue($this->existuje($fotka, 'original'), 'Originál je sama fotka.');
        $this->assertTrue($this->existuje($fotka, 'thumbnail'), 'Bez náhledu je z galerie prázdná mřížka.');
        $this->assertTrue($this->existuje($fotka, 'edited_preview'), 'Úprava dvojice by se ztratila.');
        $this->assertTrue($this->existuje($fotka, 'video_poster'));
    }

    public function test_bez_originalu_na_disku_zmensenina_zustava(): void
    {
        $fotka = $this->fotka();
        $this->varianta($fotka, 'large', dni: 800);
        $this->varianta($fotka, 'medium', dni: 800);
        Storage::disk('public')->delete('media/'.$fotka->uuid.'/original.jpg');

        $this->artisan('gallery:uklid-variant')->assertExitCode(0);

        $this->assertTrue($this->existuje($fotka, 'large'),
            'Bez originálu je zmenšenina poslední kopie — smazat ji je ztráta, ne úklid.');
        $this->assertTrue($this->existuje($fotka, 'medium'));
    }

    public function test_cerstva_zmensenina_zustava_i_nad_limitem(): void
    {
        $fotka = $this->fotka();
        $this->varianta($fotka, 'large', dni: 2);
        $this->varianta($fotka, 'medium', dni: 2);
        $this->varianta($fotka, 'small', dni: 2);

        $this->artisan('gallery:uklid-variant')->assertExitCode(0);

        $this->assertTrue($this->existuje($fotka, 'large'),
            'Zmenšenina z tohohle týdne se používá; zahodit ji znamená vyrobit ji zítra znovu.');
    }

    public function test_nasucho_jen_vypise(): void
    {
        $fotka = $this->fotka();
        $this->varianta($fotka, 'large', dni: 400);
        $this->varianta($fotka, 'medium', dni: 200);
        $this->varianta($fotka, 'small', dni: 100);

        $this->artisan('gallery:uklid-variant --nasucho')->assertExitCode(0);

        $this->assertTrue($this->existuje($fotka, 'large'), 'Nasucho se nemaže.');
        $this->assertTrue(Storage::disk('public')->exists('media/'.$fotka->uuid.'/large.webp'));
    }

    // ——— pomocné ———

    private function limit(int $bajtu): void
    {
        config(['gallery.variant_cache_max_size_gb' => $bajtu / (1024 ** 3)]);
    }

    private function existuje(MediaItem $fotka, string $typ): bool
    {
        return DB::table('media_variants')
            ->where('media_item_id', $fotka->id)
            ->where('type', $typ)
            ->exists();
    }

    private function fotka(): MediaItem
    {
        $m = MediaItem::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'vylet.jpg',
            'safe_filename' => 'vylet.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 5000,
        ]);

        $cesta = 'media/'.$m->uuid.'/original.jpg';
        Storage::disk('public')->put($cesta, str_repeat('o', 50));
        DB::table('media_variants')->insert([
            'media_item_id' => $m->id, 'type' => 'original', 'disk' => 'public', 'path' => $cesta,
            // Originál se do mezipaměti nepočítá, ať je jakkoli velký.
            'size_bytes' => 5_000_000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $m;
    }

    private function varianta(MediaItem $m, string $typ, int $dni): void
    {
        $cesta = 'media/'.$m->uuid.'/'.$typ.'.webp';
        Storage::disk('public')->put($cesta, str_repeat('v', self::KUS));
        DB::table('media_variants')->insert([
            'media_item_id' => $m->id, 'type' => $typ, 'disk' => 'public', 'path' => $cesta,
            'size_bytes' => self::KUS,
            'created_at' => now()->subDays($dni), 'updated_at' => now()->subDays($dni),
        ]);
    }
}
