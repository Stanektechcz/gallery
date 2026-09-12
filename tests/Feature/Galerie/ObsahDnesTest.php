<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Úvodní obrazovka ze skutečných dat.
 *
 * Skoro všechno na ní bylo napsané v kódu: „Makinka přidala 34 fotek do
 * Beskydy → Pustevny", cíl „Cesta na Islandu", návrh „Rezervace v Lisabonu
 * není zaplacená" a aktivita cizí dvojice.
 */
class ObsahDnesTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-16 18:00:00');
        Carbon::setTestNow('2026-09-16 18:00:00');

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->maki = User::factory()->create(['name' => 'Markéta Kubíčková']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_prazdna_galerie_nic_nevymysli(): void
    {
        $dnes = $this->getJson('/api/data/dnes')->assertOk()->json('data.DNES');

        $this->assertNull($dnes['nahrani']);
        $this->assertSame([], $dnes['archiv']);
        $this->assertNull($dnes['rytmus']);
        $this->assertNull($dnes['cil']);
        $this->assertSame([], $dnes['den']['radky']);
        $this->assertSame([], $dnes['navrhy']);
        $this->assertSame('16. září', $dnes['vyroci']['den']);
    }

    /** Poslední nahrávání je dávka jednoho člověka, ne všechno za den. */
    public function test_posledni_nahravani_je_davka_jednoho_cloveka(): void
    {
        foreach (['2026-09-16 17:00:00', '2026-09-16 17:05:00', '2026-09-16 17:10:00'] as $kdy) {
            $this->fotka(['uploaded_by' => $this->maki->id, 'uploaded_at' => $kdy]);
        }
        // Starší dávka někoho jiného se nepočítá.
        $this->fotka(['uploaded_by' => $this->adri->id, 'uploaded_at' => '2026-09-15 09:00:00']);

        $nahrani = $this->getJson('/api/data/dnes')->assertOk()->json('data.DNES.nahrani');

        $this->assertSame('Markéta Kubíčková', $nahrani['kdo']);
        $this->assertSame('3 soubory', $nahrani['pocet']);
        $this->assertSame('dnes v 17:10', $nahrani['kdy']);
    }

    /** Vzpomínky z tohoto dne jsou z minulých let, ne z letoška. */
    public function test_vyroci_je_z_minulych_let(): void
    {
        $this->fotka(['taken_at' => '2024-09-16 10:00:00', 'location_name' => 'Pálava', 'caption' => 'Vinobraní']);
        $this->fotka(['taken_at' => '2026-09-16 10:00:00']);
        $this->fotka(['taken_at' => '2024-09-17 10:00:00']);

        $dnes = $this->getJson('/api/data/dnes')->assertOk()->json('data.DNES');

        $this->assertSame([2024], $dnes['vyroci']['roky']);
        $this->assertCount(1, $dnes['vyroci']['fotky']);
        $this->assertSame(['Fotka', 'ph-image', 'Pálava'], array_slice($dnes['archiv'][0], 0, 3));
        $this->assertSame('Vinobraní', $dnes['archiv'][0][4]);
    }

    /** Cizí soukromý zápis se v archivu ani na ose dne neukáže. */
    public function test_cizi_soukromy_zapis_se_neukaze(): void
    {
        DB::table('journal_entries')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->maki->id,
            'title' => 'Jen pro mě', 'body' => 'Tajné', 'entry_date' => '2025-09-16', 'visibility' => 'private',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertStringNotContainsString('Jen pro mě', $this->getJson('/api/data/dnes')->assertOk()->getContent());
    }

    /** Aktivita z auditního logu; po sobě jdoucí nahrání se slijí do jednoho řádku. */
    public function test_aktivita_slije_po_sobe_jdouci_nahrani(): void
    {
        foreach (range(1, 4) as $i) {
            DB::table('audit_logs')->insert([
                'user_id' => $this->maki->id, 'action' => 'media.upload', 'payload' => json_encode(['filename' => 'IMG_'.$i.'.jpg']),
                'created_at' => '2026-09-16 12:0'.$i.':00',
            ]);
        }
        DB::table('audit_logs')->insert([
            'user_id' => $this->adri->id, 'action' => 'share.create', 'payload' => null, 'created_at' => '2026-09-15 20:00:00',
        ]);

        $aktivita = $this->getJson('/api/data/dnes')->assertOk()->json('data.DNES.aktivita');

        $this->assertCount(2, $aktivita);
        $this->assertSame('M', $aktivita[0][0]);
        $this->assertSame('Markéta Kubíčková · nahrání (4 soubory)', $aktivita[0][1]);
        $this->assertSame('Adrian · nový sdílený odkaz', $aktivita[1][1]);
        $this->assertSame('včera ve 20:00', $aktivita[1][2]);
    }

    /** Návrh na album je jen tam, kde opravdu leží hromada fotek bez alba. */
    public function test_navrh_alba_z_fotek_bez_alba(): void
    {
        foreach (range(0, 11) as $i) {
            $this->fotka(['taken_at' => '2026-09-13 10:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).':00', 'location_name' => 'Pálava']);
        }

        $navrhy = $this->getJson('/api/data/dnes')->assertOk()->json('data.DNES.navrhy');

        $this->assertSame('12 fotek z 13. 9. nemá album', $navrhy[0]['title']);
        $this->assertSame('Pálava', $navrhy[0]['album']);
        // Z téhož dne chybí i zápis v deníku.
        $this->assertSame('s-diary-2026-09-13', $navrhy[1]['id']);
    }

    // ——— pomůcky ———

    private function fotka(array $navic = []): MediaItem
    {
        static $poradi = 0;
        $poradi++;

        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG_'.$poradi.'.jpg',
            'safe_filename' => 'img-'.$poradi.'.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'uploaded_at' => '2026-09-01 09:00:00',
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }
}
