<?php

namespace Tests\Feature\Mazani;

use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Media\MazaniFotek;
use App\Services\Obsah\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Co o společném mazání ví obrazovka: `MAZANI`, `KE_SCHVALENI`, značka
 * `navrhSmazat` u dlaždice, odznak koše a řádek v nastavení.
 *
 * `MAZANI` je objekt a klient v objektu přepisuje jen klíče, které přišly —
 * chodí proto vždycky a celý, jinak by po stažení návrhu zůstal viset starý.
 * Trezor platí i tady: se zamčeným trezorem se skryté fotky nepočítají ani
 * nevypisují, rozdíl v čísle by prozradil, že tam něco je.
 */
class ObsahMazaniTest extends TestCase
{
    use DvojiceSHostem, RefreshDatabase;

    private User $vlastnik;

    private User $partner;

    private GallerySpace $prostor;

    private MazaniFotek $mazani;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 10:00:00'));
        [$this->vlastnik, $this->partner, , $this->prostor] = $this->dvojiceSHostem();
        $this->vlastnik->update(['name' => 'Bára']);
        $this->partner->update(['name' => 'Ctibor']);
        $this->mazani = app(MazaniFotek::class);
    }

    public function test_mazani_chodi_vzdy_a_cele(): void
    {
        Sanctum::actingAs($this->vlastnik);

        $mazani = $this->getJson('/api/data/system')->assertOk()->json('data.MAZANI');

        $this->assertSame([
            'rezim' => 'spolecne',
            'muzuSam' => false,
            'partner' => 'Ctibor',
            'navrhRezimu' => null,
            'cekaNaMe' => 0,
            'cekaNaPartnera' => 0,
        ], $mazani);
    }

    public function test_jediny_z_dvojice_maze_sam_a_partnera_nema(): void
    {
        $sam = User::factory()->create(['is_active' => true]);
        $prostor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Jen já', 'slug' => 'jen-ja-'.Str::random(5), 'owner_id' => $sam->id, 'is_default' => true]);
        $prostor->members()->attach($sam->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        Sanctum::actingAs($sam);

        $data = $this->getJson('/api/data/system')->assertOk()->json('data');

        $this->assertTrue($data['MAZANI']['muzuSam']);
        $this->assertNull($data['MAZANI']['partner']);
        $this->assertContains(['Mazání fotek', 'Mažete sami — v galerii zatím nikdo další není', 'sami'], $data['SETROWS']['zamek']['rows']);
    }

    public function test_ke_schvaleni_a_pocty_respektuji_trezor(): void
    {
        $prvni = $this->fotka($this->prostor, $this->vlastnik, 'a.jpg');
        $druha = $this->fotka($this->prostor, $this->vlastnik, 'b.jpg', ['media_type' => 'video']);
        $skryta = $this->fotka($this->prostor, $this->vlastnik, 'pas-a-obcanka.jpg', ['is_hidden' => true]);
        $this->actingAs($this->vlastnik);
        $this->mazani->doKose($this->prostor, $this->vlastnik, [$prvni->uuid, $druha->uuid, $skryta->uuid], 'knihovna', true);

        // Partner se zamčeným trezorem: dvě fotky, o třetí ani slovo.
        Sanctum::actingAs($this->partner);
        $odpoved = $this->getJson('/api/data/system')->assertOk();
        $data = $odpoved->json('data');

        $this->assertSame(2, $data['MAZANI']['cekaNaMe']);
        $this->assertSame(0, $data['MAZANI']['cekaNaPartnera']);
        $this->assertCount(2, $data['KE_SCHVALENI']);
        $this->assertEqualsCanonicalizing([$prvni->uuid, $druha->uuid], array_column($data['KE_SCHVALENI'], 'id'));
        $radek = collect($data['KE_SCHVALENI'])->firstWhere('id', $druha->uuid);
        $this->assertSame('b.jpg', $radek['name']);
        $this->assertSame('Video', $radek['from']);
        $this->assertSame('Bára', $radek['by']);
        $this->assertSame('dnes v 12:00', $radek['when']);
        $this->assertFalse($radek['ja']);
        $this->assertStringContainsString('/thumb', (string) $radek['bg']);
        $this->assertStringNotContainsString('pas-a-obcanka', $odpoved->getContent());
        $this->assertStringNotContainsString($skryta->uuid, $odpoved->getContent());

        // Navrhující s odemčeným trezorem vidí všechny tři jako své.
        $data = $this->sOdemcenymTrezorem($this->vlastnik)->getJson('/api/data/system')->assertOk()->json('data');
        $this->assertSame(0, $data['MAZANI']['cekaNaMe']);
        $this->assertSame(3, $data['MAZANI']['cekaNaPartnera']);
        $this->assertCount(3, $data['KE_SCHVALENI']);
        $this->assertTrue($data['KE_SCHVALENI'][0]['ja']);
        $this->assertArrayNotHasKey('bg', collect($data['KE_SCHVALENI'])->firstWhere('id', $skryta->uuid) ?? [], 'Náhled z trezoru se nevydává.');
    }

    public function test_navrh_rezimu_na_obrazovce_a_v_nastaveni(): void
    {
        $this->actingAs($this->vlastnik);
        $this->mazani->navrhniRezim($this->prostor, $this->vlastnik, 'kazdy', null, null);

        Sanctum::actingAs($this->vlastnik);
        $data = $this->getJson('/api/data/system')->assertOk()->json('data');
        $this->assertSame(['rezim' => 'kazdy', 'kdo' => 'Bára', 'ja' => true, 'kdy' => 'dnes v 12:00'], $data['MAZANI']['navrhRezimu']);
        $this->assertContains(['Mazání fotek', 'Návrh čeká, až ho potvrdí Ctibor', 'Zrušit návrh'], $data['SETROWS']['zamek']['rows']);

        Sanctum::actingAs($this->partner);
        $data = $this->getJson('/api/data/system')->assertOk()->json('data');
        $this->assertFalse($data['MAZANI']['navrhRezimu']['ja']);
        $this->assertSame('Bára', $data['MAZANI']['navrhRezimu']['kdo']);
        $this->assertContains(['Mazání fotek', 'Bára navrhuje, aby každý mazal sám — potvrďte kódem zámku nebo heslem', 'Potvrdit změnu'], $data['SETROWS']['zamek']['rows']);

        // Odmítnout jde taky — hned pod návrhem, ne jen potvrdit.
        $radky = $data['SETROWS']['zamek']['rows'];
        $i = array_search('Mazání fotek', array_column($radky, 0), true);
        $this->assertSame(['Návrh na mazání bez schválení', 'Navrhuje Bára · dnes v 12:00 — když nesouhlasíte, zrušte ho', 'Zrušit návrh'], $radky[$i + 1]);
    }

    public function test_odmitnuti_navrhu_je_jen_u_ciziho_navrhu(): void
    {
        Sanctum::actingAs($this->vlastnik);
        $pocet = count($this->getJson('/api/data/system')->assertOk()->json('data.SETROWS.zamek.rows'));

        $this->actingAs($this->vlastnik);
        $this->mazani->navrhniRezim($this->prostor, $this->vlastnik, 'kazdy', null, null);

        // Navrhující má „Zrušit návrh" přímo v řádku — druhý řádek nepřibude.
        Sanctum::actingAs($this->vlastnik);
        $radky = $this->getJson('/api/data/system')->assertOk()->json('data.SETROWS.zamek.rows');
        $this->assertCount($pocet, $radky);
        $this->assertNotContains('Návrh na mazání bez schválení', array_column($radky, 0));

        Sanctum::actingAs($this->partner);
        $this->assertCount($pocet + 1, $this->getJson('/api/data/system')->assertOk()->json('data.SETROWS.zamek.rows'));
    }

    public function test_radek_nastaveni_podle_rezimu(): void
    {
        Sanctum::actingAs($this->vlastnik);
        $radky = $this->getJson('/api/data/system')->assertOk()->json('data.SETROWS.zamek.rows');
        $this->assertContains(['Mazání fotek', 'Jen po společném schválení — „Do koše" fotku navrhne, smaže ji až souhlas druhého', 'Navrhnout mazání bez schválení'], $radky);

        $this->prostor->forceFill(['media_delete_mode' => 'kazdy'])->save();
        $radky = $this->getJson('/api/data/system')->assertOk()->json('data.SETROWS.zamek.rows');
        $this->assertContains(['Mazání fotek', 'Každý maže sám — potvrdili jste to oba', 'Vrátit společné schvalování'], $radky);
    }

    public function test_akce_mazani_maji_v_nastaveni_obsluhu(): void
    {
        $js = file_get_contents(public_path('galerie-data.js'));

        foreach ([
            "'Navrhnout mazání bez schválení': ['ph-users', 'mazani-navrh']",
            "'Potvrdit změnu': ['ph-check', 'mazani-potvrdit']",
            "'Zrušit návrh': ['ph-x', 'mazani-zrusit']",
            "'Vrátit společné schvalování': ['ph-shield-check', 'mazani-spolecne']",
        ] as $akce) {
            $this->assertStringContainsString($akce, $js);
        }
    }

    public function test_dlazdice_nese_navrh_smazat(): void
    {
        $navrzena = $this->fotka($this->prostor, $this->vlastnik, 'a.jpg');
        $obycejna = $this->fotka($this->prostor, $this->vlastnik, 'b.jpg');
        $this->actingAs($this->vlastnik);
        $this->mazani->doKose($this->prostor, $this->vlastnik, [$navrzena->uuid], 'knihovna', false);

        Sanctum::actingAs($this->partner);
        $data = $this->getJson('/api/data/knihovna')->assertOk()->json('data');

        $dlazdice = collect($data['PHOTOS'])->keyBy('id');
        $this->assertSame(['kdo' => 'Bára', 'ja' => false, 'kdy' => 'dnes v 12:00'], $dlazdice[$navrzena->uuid]['navrhSmazat']);
        $this->assertArrayNotHasKey('navrhSmazat', $dlazdice[$obycejna->uuid]);
        $this->assertFalse($dlazdice[$navrzena->uuid]['pending'], '`pending` zůstává stavem zpracování.');

        $telefon = collect($data['MOBIL']['PHOTOS'])->keyBy('id');
        $this->assertSame(['kdo' => 'Bára', 'ja' => false, 'kdy' => 'dnes v 12:00'], $telefon[$navrzena->uuid]['navrhSmazat']);
        $this->assertArrayNotHasKey('navrhSmazat', $telefon[$obycejna->uuid]);

        // Odznak koše počítá i to, co čeká na můj souhlas.
        $this->assertSame('1', $data['NAVCNT']['trash']);

        Sanctum::actingAs($this->vlastnik);
        $data = $this->getJson('/api/data/knihovna')->assertOk()->json('data');
        $this->assertTrue(collect($data['PHOTOS'])->firstWhere('id', $navrzena->uuid)['navrhSmazat']['ja']);
        $this->assertSame('', $data['NAVCNT']['trash'], 'Na vlastní návrh se nečeká u mě.');
    }

    public function test_odznak_kose_nepocita_skryte_se_zamcenym_trezorem(): void
    {
        $videt = $this->fotka($this->prostor, $this->vlastnik, 'a.jpg');
        $skryta = $this->fotka($this->prostor, $this->vlastnik, 'pas.jpg', ['is_hidden' => true]);
        $this->actingAs($this->vlastnik);
        $this->mazani->doKose($this->prostor, $this->vlastnik, [$videt->uuid, $skryta->uuid], 'knihovna', true);

        Sanctum::actingAs($this->partner);
        $this->assertSame('1', $this->getJson('/api/data/knihovna')->assertOk()->json('data.NAVCNT.trash'));

        $this->assertSame('2', $this->sOdemcenymTrezorem($this->partner)->getJson('/api/data/knihovna')->assertOk()->json('data.NAVCNT.trash'));
    }

    public function test_prazdne_tvary(): void
    {
        $prazdne = app(System::class)->prazdne();

        $this->assertSame([
            'rezim' => 'spolecne',
            'muzuSam' => true,
            'partner' => null,
            'navrhRezimu' => null,
            'cekaNaMe' => 0,
            'cekaNaPartnera' => 0,
        ], $prazdne['MAZANI']);
        $this->assertSame([], $prazdne['KE_SCHVALENI']);
        $this->assertContains('MAZANI', app(System::class)->uplne());
        $this->assertContains('KE_SCHVALENI', app(System::class)->uplne());
    }

    public function test_aktivita_popisuje_spolecne_mazani_bez_jmen_z_trezoru(): void
    {
        $videt = $this->fotka($this->prostor, $this->vlastnik, 'more.jpg');
        $skryta = $this->fotka($this->prostor, $this->vlastnik, 'pas-a-obcanka.jpg', ['is_hidden' => true]);
        $this->actingAs($this->vlastnik);
        $this->mazani->doKose($this->prostor, $this->vlastnik, [$videt->uuid], 'knihovna', false);
        $this->actingAs($this->partner);
        $this->mazani->doKose($this->prostor, $this->partner, [$skryta->uuid], 'trezor', true);
        $this->travel(2)->hours();
        $this->mazani->navrhniRezim($this->prostor, $this->partner, 'kazdy', null, null);

        Sanctum::actingAs($this->vlastnik);
        $odpoved = $this->getJson('/api/data/dnes')->assertOk();
        $radky = array_column($odpoved->json('data.DNES.aktivita'), 1);

        $this->assertContains('Bára · návrh ke smazání more.jpg', $radky);
        $this->assertContains('Ctibor · návrh ke smazání', $radky);
        $this->assertContains('Ctibor · návrh, aby každý mazal sám', $radky);
        $this->assertStringNotContainsString('pas-a-obcanka', $odpoved->getContent());
        $this->assertSame(1, AuditLog::where('action', 'gallery.delete_mode_proposed')->count());
    }
}
