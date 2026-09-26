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
        $this->maki = User::factory()->create(['name' => 'Makinka Kubíčková']);
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

        $this->assertSame('Makinka Kubíčková', $nahrani['kdo']);
        $this->assertSame('3 soubory', $nahrani['pocet']);
        // Uloženo v UTC (17:10), dvojice čte pražský čas (App\Support\Cas).
        $this->assertSame('dnes v 19:10', $nahrani['kdy']);
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
                'user_id' => $this->maki->id, 'gallery_space_id' => $this->prostor->id,
                'action' => 'media.upload', 'payload' => json_encode(['filename' => 'IMG_'.$i.'.jpg']),
                'created_at' => '2026-09-16 12:0'.$i.':00',
            ]);
        }
        DB::table('audit_logs')->insert([
            'user_id' => $this->adri->id, 'gallery_space_id' => $this->prostor->id,
            'action' => 'share.create', 'payload' => null, 'created_at' => '2026-09-15 20:00:00',
        ]);

        $aktivita = $this->getJson('/api/data/dnes')->assertOk()->json('data.DNES.aktivita');

        $this->assertCount(2, $aktivita);
        $this->assertSame('M', $aktivita[0][0]);
        $this->assertSame('Makinka Kubíčková · nahrání (4 soubory)', $aktivita[0][1]);
        $this->assertSame('Adrian · nový sdílený odkaz', $aktivita[1][1]);
        // 20:00 UTC je ve 22:00 v Praze.
        $this->assertSame('včera ve 22:00', $aktivita[1][2]);
    }

    /**
     * Co se stalo v jiné galerii, do téhle nepatří.
     *
     * `audit_logs` prostor nenesly a filtrovalo se jen podle členů, takže
     * kdo je ve dvou galeriích, viděl v jedné jména souborů z druhé.
     */
    public function test_aktivita_z_jine_galerie_se_neukazuje(): void
    {
        $druha = GallerySpace::create(['name' => 'Jiná galerie', 'owner_id' => $this->adri->id]);
        $druha->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        DB::table('audit_logs')->insert([
            'user_id' => $this->adri->id, 'gallery_space_id' => $druha->id,
            'action' => 'media.upload', 'payload' => json_encode(['filename' => 'CIZI.jpg']),
            'created_at' => '2026-09-16 12:00:00',
        ]);

        $aktivita = $this->getJson('/api/data/dnes')->assertOk()->json('data.DNES.aktivita');

        $this->assertSame([], $aktivita);
    }

    /**
     * Náhled v aktivitě jen u fotky, kterou náhledová adresa vydá.
     *
     * Nahrání fotky, která se pak trvale smazala, neslo podepsanou adresu
     * s odpovědí 404; totéž by čekalo fotku v koši a v trezoru.
     */
    public function test_aktivita_nema_nahled_smazane_ani_skryte_fotky(): void
    {
        $ok = $this->fotka();
        $smazana = $this->fotka(['trashed_at' => now()]);
        $smazana->delete();
        $vTrezoru = $this->fotka(['is_hidden' => true]);

        foreach ([[$ok, '09'], [$smazana, '11'], [$vTrezoru, '13']] as [$m, $hodina]) {
            DB::table('media_variants')->insert([
                'media_item_id' => $m->id, 'type' => 'thumbnail', 'disk' => 'local',
                'path' => 'nahledy/'.$m->uuid.'.jpg', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'user_id' => $this->adri->id, 'gallery_space_id' => $this->prostor->id,
                'action' => 'media.upload', 'subject_type' => MediaItem::class,
                'subject_id' => $m->id, 'payload' => null, 'created_at' => '2026-09-16 '.$hodina.':00:00',
            ]);
        }

        $aktivita = $this->getJson('/api/data/dnes')->assertOk()->json('data.DNES.aktivita');

        $this->assertCount(3, $aktivita);
        $nahledy = array_column($aktivita, 3);
        $this->assertStringContainsString($ok->uuid, (string) $nahledy[2]);
        $this->assertNull($nahledy[1], 'Smazaná fotka nemá náhled.');
        $this->assertNull($nahledy[0], 'Fotka v trezoru nemá náhled.');
    }

    /**
     * Aktivita nevypíše jméno fotky, která je teď v trezoru.
     *
     * Záznam o nahrání nese jméno i u fotky přesunuté do trezoru až potom —
     * přehled by ho ukázal i se zamčeným trezorem.
     */
    public function test_aktivita_nevypise_jmeno_fotky_v_trezoru(): void
    {
        $bezna = $this->fotka();
        $vTrezoru = $this->fotka(['is_hidden' => true]);

        foreach ([[$bezna, 'more.jpg', '09'], [$vTrezoru, 'pas.jpg', '13']] as [$m, $jmeno, $hodina]) {
            DB::table('audit_logs')->insert([
                'user_id' => $this->adri->id, 'gallery_space_id' => $this->prostor->id,
                'action' => 'media.upload', 'subject_type' => 'MediaItem',
                'subject_id' => $m->id, 'payload' => json_encode(['filename' => $jmeno]),
                'created_at' => '2026-09-16 '.$hodina.':00:00',
            ]);
        }

        $texty = array_column($this->getJson('/api/data/dnes')->assertOk()->json('data.DNES.aktivita'), 1);

        $this->assertCount(2, $texty);
        $this->assertStringNotContainsString('pas.jpg', $texty[0]);
        $this->assertStringContainsString('more.jpg', $texty[1]);
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
        // Fotky pro album jdou s návrhem — dřív „Vytvořit album" založilo prázdné.
        $this->assertCount(12, $navrhy[0]['media']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $navrhy[0]['media'][0]);
        // Z téhož dne chybí i zápis v deníku.
        $this->assertSame('s-diary-2026-09-13', $navrhy[1]['id']);

        // Album z těch fotek návrh uzavře.
        $this->postJson('/api/alba', ['nazev' => 'Pálava', 'media' => $navrhy[0]['media']])->assertSuccessful();
        $po = $this->getJson('/api/data/dnes')->assertOk()->json('data.DNES.navrhy');
        $this->assertNotContains('s-album-2026-09-13', array_column($po, 'id'));
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
