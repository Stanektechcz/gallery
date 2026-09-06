<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Čtyři věci na obrazovkách rozhodování, které aplikace vědět nemůže.
 *
 * Krytí domácnosti, pre-mortem, vlastní minulost a vstupy rozhodnutí. Zbytek
 * sekce se počítá; tyhle čtyři musí někdo napsat, a tak k nim vede formulář.
 */
class ObsahRozhodovaniTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->maki = User::factory()->create(['name' => 'Makinka']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    /** Prázdno nechává ukázku — prázdná obrazovka vypadá jako rozbitá. */
    public function test_bez_zaznamu_se_nic_neposila(): void
    {
        $data = $this->getJson('/api/data/rozhodovani')->assertOk()->json('data');

        $this->assertSame([], $data);
    }

    /** Věc, kterou umí jen jeden, zná svého člověka i váhu. */
    public function test_kryti_domacnosti_zna_kdo_to_umi(): void
    {
        $this->postJson('/api/zaznamy/bus', [
            'name' => 'Jak se přepíná bojler',
            'who' => 'Makinka',
            'kind' => 'postup',
            'crit' => 3,
        ])->assertOk();

        $bus = $this->getJson('/api/data/rozhodovani')->assertOk()->json('data.BUS');

        $this->assertCount(1, $bus);
        $this->assertSame('Jak se přepíná bojler', $bus[0]['name']);
        $this->assertSame('Makinka', $bus[0]['who']);
        $this->assertSame(3, $bus[0]['crit']);
        $this->assertFalse($bus[0]['doc']);
    }

    /** Prázdné „kdo" znamená oba — a to je jediný stav, který není riziko. */
    public function test_bez_jmena_to_umi_oba(): void
    {
        $this->postJson('/api/zaznamy/bus', ['name' => 'Kde je hasicí přístroj'])->assertOk();

        $bus = $this->getJson('/api/data/rozhodovani')->json('data.BUS');

        $this->assertSame('oba', $bus[0]['who']);
        $this->assertSame('postup', $bus[0]['kind']);
    }

    /**
     * „Zapsat pro oba" přepne příznak, nesmaže řádek.
     *
     * Kdyby zapsaná věc ze seznamu zmizela, počítalo by se procento krytí
     * z čím dál menšího zbytku a rostlo by tím, že se nic neděje.
     */
    public function test_zapsani_pro_oba_nechava_radek_v_seznamu(): void
    {
        $this->postJson('/api/zaznamy/bus', ['name' => 'Kontakt na doktorku', 'who' => 'Adrian'])->assertOk();

        $odpoved = $this->postJson('/api/zaznamy/bus-zapsano', ['name' => 'Kontakt na doktorku'])->assertOk();

        $bus = $odpoved->json('data.BUS');
        $this->assertCount(1, $bus);
        $this->assertTrue($bus[0]['doc']);
    }

    /** Neznámá věc se nezapisuje potichu. */
    public function test_zapsani_neznamé_veci_je_chyba(): void
    {
        $this->postJson('/api/zaznamy/bus-zapsano', ['name' => 'Něco, co nikdo nezapsal'])
            ->assertStatus(422);
    }

    /** Obavy obou stran jdou do dvou sloupců podle toho, kdo je psal. */
    public function test_premortem_rozdeluje_obavy_podle_autora(): void
    {
        $this->postJson('/api/zaznamy/premortem', [
            'title' => 'Rekonstrukce koupelny na jaře',
            'when' => 'březen 2027',
        ])->assertOk();

        $this->postJson('/api/zaznamy/riziko', [
            'risk' => 'Rozpočet přeteče o třetinu',
            'fix' => 'Rezerva 25 % předem',
            'l' => 3,
            's' => 3,
        ])->assertOk();

        Sanctum::actingAs($this->maki);
        $this->postJson('/api/zaznamy/riziko', ['risk' => 'Protáhne se to o měsíc', 'l' => 2, 's' => 1])->assertOk();

        // Zpátky Adrian: `PM_MINE` je vždycky sloupec toho, kdo se dívá.
        Sanctum::actingAs($this->adri);
        $data = $this->getJson('/api/data/rozhodovani')->json('data');

        $this->assertSame([['q' => 'Rekonstrukce koupelny na jaře', 'when' => 'březen 2027']], $data['PM_DEC']);
        $this->assertSame('Rozpočet přeteče o třetinu', $data['PM_MINE'][0]['risk']);
        $this->assertSame(3, $data['PM_MINE'][0]['l']);
        $this->assertSame('Protáhne se to o měsíc', $data['PM_THEIRS'][0]['risk']);
    }

    /**
     * `PM_MINE` jsou obavy toho, kdo se dívá.
     *
     * Prostor tady založil Adrian, ale dívá se Makinka — a musí vidět svou
     * vlastní obavu ve svém sloupci. Kdyby se řadilo podle vlastníka, viděla
     * by ji ve sloupci partnera, tedy přesně tam, kam se u pre-mortemu
     * dívat nemá.
     */
    public function test_prvni_sloupec_patri_tomu_kdo_se_diva(): void
    {
        $this->postJson('/api/zaznamy/premortem', ['title' => 'Větší hypotéka'])->assertOk();
        $this->postJson('/api/zaznamy/riziko', ['risk' => 'Sebere nám to rezervu'])->assertOk();

        Sanctum::actingAs($this->maki);
        $this->postJson('/api/zaznamy/riziko', ['risk' => 'Nezvládneme splátky při rodičovské'])->assertOk();

        $data = $this->getJson('/api/data/rozhodovani')->json('data');

        $this->assertSame('Nezvládneme splátky při rodičovské', $data['PM_MINE'][0]['risk']);
        $this->assertSame('Sebere nám to rezervu', $data['PM_THEIRS'][0]['risk']);

        Sanctum::actingAs($this->adri);
        $data = $this->getJson('/api/data/rozhodovani')->json('data');

        $this->assertSame('Sebere nám to rezervu', $data['PM_MINE'][0]['risk']);
        $this->assertSame('Nezvládneme splátky při rodičovské', $data['PM_THEIRS'][0]['risk']);
    }

    /** Obava bez rozhodnutí nemá kam patřit — a je poctivější to říct. */
    public function test_obava_bez_rozhodnuti_se_nezahazuje_potichu(): void
    {
        $this->postJson('/api/zaznamy/riziko', ['risk' => 'Něco se pokazí'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('risk');
    }

    /** Uzavřený pre-mortem říká, čí obava se vyplnila. */
    public function test_kalibrace_vychazi_z_jednotlivych_obav(): void
    {
        $rozhodnuti = DB::table('couple_premortems')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Dovolená 2025',
            'closed_on' => '2025-09-01',
            'outcome_note' => 'Trajekt jsme opravdu nestihli.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([[$this->adri->id, 'Nestihneme trajekt', true], [$this->maki->id, 'Bude pršet celý týden', false]] as [$kdo, $riziko, $vyplnilo]) {
            DB::table('couple_premortem_risks')->insert([
                'uuid' => (string) Str::uuid(),
                'couple_premortem_id' => $rozhodnuti,
                'author_user_id' => $kdo,
                'risk' => $riziko,
                'likelihood' => 3,
                'severity' => 2,
                'came_true' => $vyplnilo,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $hist = $this->getJson('/api/data/rozhodovani')->json('data.PM_HIST');

        $this->assertCount(1, $hist);
        $this->assertSame('mine', $hist[0]['hit']);
        $this->assertSame('Nestihneme trajekt', $hist[0]['mine']);
        $this->assertSame('Bude pršet celý týden', $hist[0]['theirs']);
    }

    /** S hodnocením je to zkušenost, bez něj otázka. Jedna tabulka, dvě kolekce. */
    public function test_pripad_s_hodnocenim_je_zkusenost_bez_nej_otazka(): void
    {
        $this->postJson('/api/zaznamy/pripad', [
            'title' => 'Pračka, 2023 — čekali jsme na akci',
            'tags' => 'nákup, odklad',
            'out' => 4,
            'note' => 'Čekání ušetřilo 4 000.',
        ])->assertOk();

        $this->postJson('/api/zaznamy/pripad', [
            'title' => 'Koupit gauč hned, nebo čekat?',
            'tags' => 'nákup; odklad',
        ])->assertOk();

        $data = $this->getJson('/api/data/rozhodovani')->json('data');

        $this->assertSame(['nákup', 'odklad'], $data['PAST_CASES'][0]['tags']);
        $this->assertSame(4, $data['PAST_CASES'][0]['out']);
        $this->assertSame('Koupit gauč hned, nebo čekat?', $data['PAST_DEC'][0]['q']);
        $this->assertSame(['nákup', 'odklad'], $data['PAST_DEC'][0]['tags']);
    }

    /** Vstupy visí na rozhodnutí a směr se počítá z obou hodnot. */
    public function test_vstupy_se_radi_pod_rozhodnuti_a_smer_se_pocita(): void
    {
        $uuid = (string) Str::uuid();

        DB::table('couple_decisions')->insert([
            'uuid' => $uuid,
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Zůstat v nájmu',
            'decided_on' => '2026-01-14',
            'status' => 'platí',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/zaznamy/vstup', [
            'decision' => $uuid,
            'label' => 'Nájem',
            'then' => '13 200 Kč',
            'now' => '14 500 Kč',
        ])->assertOk();

        $this->postJson('/api/zaznamy/vstup', [
            'decision' => $uuid,
            'label' => 'Úroková sazba',
            'then' => '5,4 %',
            'now' => '4,1 %',
        ])->assertOk();

        $revisit = $this->getJson('/api/data/rozhodovani')->json('data.REVISIT');

        $this->assertCount(2, $revisit[$uuid]);
        $this->assertSame('up', $revisit[$uuid][0]['dir']);
        $this->assertSame('down', $revisit[$uuid][1]['dir']);
    }

    /** Vstup k cizímu rozhodnutí se nezapíše. */
    public function test_vstup_k_cizimu_rozhodnuti_neprojde(): void
    {
        $cizi = User::factory()->create();
        $cizi_prostor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $uuid = (string) Str::uuid();

        DB::table('couple_decisions')->insert([
            'uuid' => $uuid,
            'gallery_space_id' => $cizi_prostor->id,
            'title' => 'Cizí rozhodnutí',
            'decided_on' => '2026-01-14',
            'status' => 'platí',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/zaznamy/vstup', [
            'decision' => $uuid,
            'label' => 'Nájem',
            'then' => '1',
            'now' => '2',
        ])->assertStatus(422);
    }

    /** Odpověď na zápis nese celou skupinu — obrazovka se nemá na co doptávat. */
    public function test_odpoved_na_zapis_nese_cely_obsah(): void
    {
        $this->postJson('/api/zaznamy/bus', ['name' => 'Kde jsou papíry od auta', 'who' => 'Adrian'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.BUS.0.name', 'Kde jsou papíry od auta');
    }

    /** Neznámý druh záznamu je 404, ne tiché nic. */
    public function test_neznamy_druh_je_ctyristacityri(): void
    {
        $this->postJson('/api/zaznamy/vymyslene', ['name' => 'x'])->assertNotFound();
    }
}
