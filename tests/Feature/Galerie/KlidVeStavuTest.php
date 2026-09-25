<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Klepnutí do mapy energie se opravdu uloží.
 *
 * Celá ta mapa je o tom najít okno, kdy mají sílu **oba** — a klepnutí do ní
 * končilo v prohlížeči toho, kdo klikl. Druhý se k ní nedostal, takže se to
 * okno nedalo najít nikdy.
 */
class KlidVeStavuTest extends TestCase
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

    /**
     * Do mapy energie smí každý psát jen svůj řádek.
     *
     * Počítač posílá mřížku obou lidí naráz, takže kliknutí na jednu buňku
     * vzalo i všech 42 buněk toho druhého — a protože je `klEn` mezi
     * serverovými klíči, ze stavu se vzít zpátky nedá. Mapa přitom existuje
     * právě proto, aby se našlo okno, kdy mají sílu **oba**.
     */
    public function test_energie_partnera_se_neda_prepsat(): void
    {
        // Makinka si vyplní pondělí.
        Sanctum::actingAs($this->maki);
        $this->stav(['klEn' => ['Makinka' => ['222', '000', '000', '000', '000', '000', '000']]])->assertOk();

        // Adrian má starou záložku: posílá mřížku obou, v ní Makinku prázdnou.
        Sanctum::actingAs($this->adri);
        $this->stav(['klEn' => [
            'Adrian' => ['111', '000', '000', '000', '000', '000', '000'],
            'Makinka' => ['000', '000', '000', '000', '000', '000', '000'],
        ]])->assertOk();

        $jeji = DB::table('wellbeing_energy')
            ->where('user_id', $this->maki->id)->where('weekday', 0)->where('slot', 0)->value('level');

        $this->assertSame(2, (int) $jeji, 'Adrianův zápis nesmí sáhnout na Makinčin řádek.');

        $jeho = DB::table('wellbeing_energy')
            ->where('user_id', $this->adri->id)->where('weekday', 0)->where('slot', 0)->value('level');

        $this->assertSame(1, (int) $jeho, 'Svůj vlastní řádek zapsat musí.');
    }

    /** Mřížka se rozloží na buňky — a uloží se za toho, komu patří. */
    public function test_mapa_energie_se_ulozi_po_bunkach(): void
    {
        // Každý svůj řádek: počítač sice posílá mřížku obou, ale zapsat se smí
        // jen ten vlastní (viz test výš).
        Sanctum::actingAs($this->maki);
        $this->stav(['klEn' => ['Makinka' => ['000', '000', '000', '000', '000', '000', '020']]])->assertOk();

        Sanctum::actingAs($this->adri);
        $odpoved = $this->stav(['klEn' => [
            'Adrian' => ['201', '000', '000', '000', '000', '000', '000'],
        ]])->assertOk();

        $bunky = DB::table('wellbeing_energy')->where('level', '>', 0)->get();

        $this->assertCount(3, $bunky);
        $this->assertSame(2, (int) $bunky->firstWhere('slot', 0)->level);
        $this->assertSame($this->maki->id, (int) $bunky->firstWhere('weekday', 6)->user_id);

        // Ve stavu klíč nezůstane a odpověď nese mapu ze serveru.
        $this->assertArrayNotHasKey('klEn', (array) $this->getJson('/api/state')->assertOk()->json('data'));
        $this->assertSame('201', $odpoved->json('data.klEn.Adrian.0'));
        $this->assertContains('klEn', $odpoved->json('docasne'));
    }

    /** Druhé klepnutí do téže buňky ji přepíše, nezaloží druhou. */
    public function test_druhe_klepnuti_bunku_prepise(): void
    {
        $this->stav(['klEn' => ['Adrian' => ['100', '000', '000', '000', '000', '000', '000']]])->assertOk();
        $this->stav(['klEn' => ['Adrian' => ['200', '000', '000', '000', '000', '000', '000']]])->assertOk();

        $this->assertSame(21, DB::table('wellbeing_energy')->count());
        $this->assertSame(2, (int) DB::table('wellbeing_energy')
            ->where('user_id', $this->adri->id)->where('weekday', 0)->where('slot', 0)->value('level'));
    }

    /** Jméno, které do páru nepatří, se neuloží. */
    public function test_cizi_jmeno_se_neulozi(): void
    {
        $this->stav(['klEn' => ['Někdo cizí' => ['222', '222', '222', '222', '222', '222', '222']]])->assertOk();

        $this->assertSame(0, DB::table('wellbeing_energy')->count());
    }

    /** Posun v rozpočtu pozornosti se uloží — a poprvé si ho i založí. */
    public function test_rozpocet_pozornosti_se_ulozi(): void
    {
        $odpoved = $this->stav(['klAttn' => [30, 25, 16, 12, 8, 14]])->assertOk();

        $radky = DB::table('wellbeing_attention')
            ->where('gallery_space_id', $this->prostor->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(6, $radky);
        $this->assertSame('my', $radky[1]->key);
        $this->assertSame(25, (int) $radky[1]->want);
        $this->assertSame([30, 25, 16, 12, 8, 14], $odpoved->json('data.klAttn'));
    }

    /** Přání mimo rozsah se ořízne — posuvník nedá víc než šedesát. */
    public function test_prani_mimo_rozsah_se_orizne(): void
    {
        $this->stav(['klAttn' => [999, -5, 16, 12, 8, 14]])->assertOk();

        $radky = DB::table('wellbeing_attention')->orderBy('sort_order')->get();

        $this->assertSame(60, (int) $radky[0]->want);
        $this->assertSame(0, (int) $radky[1]->want);
    }

    /** Odeslaná odpověď se uloží k té otázce, na kterou byla. */
    public function test_odpoved_se_ulozi_ke_sve_otazce(): void
    {
        $this->stav([
            'klAskDone' => true,
            'klAskQ' => 'Co jsem tenhle týden odložil?',
            'klAskMine' => 'Prohlídku u zubaře. Podruhé.',
        ])->assertOk();

        $radek = DB::table('wellbeing_answers')->first();

        $this->assertSame('Co jsem tenhle týden odložil?', $radek->question);
        $this->assertSame('Prohlídku u zubaře. Podruhé.', $radek->answer);
        $this->assertSame($this->adri->id, (int) $radek->user_id);
    }

    /**
     * Rozepsaná odpověď ve společném stavu nezůstane.
     *
     * Druhému se objevila v jeho políčku ještě před odesláním a „odesláno"
     * mu odemklo cizí odpověď dřív, než napsal svou. Obrazovka ji teď posílá
     * jednou zprávou s potvrzením.
     */
    public function test_rozepsana_odpoved_druhy_neuvidi(): void
    {
        $this->stav(['klAskMine' => 'Tři večery po deváté v práci.', 'klAskDone' => false])->assertOk();

        $stav = (array) $this->getJson('/api/state')->assertOk()->json('data');
        $this->assertArrayNotHasKey('klAskMine', $stav);
        $this->assertArrayNotHasKey('klAskDone', $stav);
        $this->assertSame(0, DB::table('wellbeing_answers')->count());

        // Potvrzení bez textu odpověď nevyrobí — nic se nedohledává.
        $this->stav(['klAskDone' => true, 'klAskQ' => 'Co z tohoto týdne bys nechtěl opakovat?'])->assertOk();
        $this->assertSame(0, DB::table('wellbeing_answers')->count());
        $this->assertArrayNotHasKey('klAskQ', (array) $this->getJson('/api/state')->assertOk()->json('data'));
    }

    /** Rozepsaná věta v poli není odpověď. */
    public function test_neodeslana_odpoved_se_neuklada(): void
    {
        $this->stav([
            'klAskQ' => 'Co jsem tenhle týden odložil?',
            'klAskMine' => 'Zub',
        ])->assertOk();

        $this->assertSame(0, DB::table('wellbeing_answers')->count());
    }

    /** Odpověď bez otázky se neuloží — nebylo by k čemu. */
    public function test_odpoved_bez_otazky_se_neulozi(): void
    {
        $this->stav(['klAskDone' => true, 'klAskMine' => 'Něco'])->assertOk();

        $this->assertSame(0, DB::table('wellbeing_answers')->count());
    }

    /**
     * Co čeká na okno, se dá zapsat — a přežije to zavření záložky.
     *
     * `wellbeing_tasks` se jen četla. Seznam pod mapou energie tak zůstával
     * cizí a doplnit se do něj nedalo nic.
     */
    public function test_ceka_na_okno_se_da_zapsat(): void
    {
        $odpoved = $this->stav(['klTasks' => [
            ['id' => 0, 'name' => 'Probrat, co nás poslední měsíc štve', 'need' => 2, 'route' => null, 'tab' => null, 'label' => ''],
            ['id' => 0, 'name' => 'Zavolat na úřad', 'need' => 1, 'route' => 'x-plan', 'tab' => null, 'label' => 'Do úkolů'],
        ]])->assertOk();

        $ukoly = DB::table('wellbeing_tasks')->orderBy('sort_order')->get();

        $this->assertCount(2, $ukoly);
        $this->assertSame('Probrat, co nás poslední měsíc štve', $ukoly[0]->name);
        $this->assertSame(2, (int) $ukoly[0]->needs_people);
        $this->assertSame('x-plan', $ukoly[1]->route);

        $this->assertSame('Zavolat na úřad', $odpoved->json('data.klTasks.1.name'));
        $this->assertContains('klTasks', $odpoved->json('docasne'));
        $this->assertArrayNotHasKey('klTasks', (array) $this->getJson('/api/state')->assertOk()->json('data'));
    }

    /**
     * Co ze seznamu zmizí, je hotové — ne smazané.
     *
     * Odstranit řádek by znamenalo, že po tom nezbude stopa, a příště se to
     * samé zapíše znovu jako nová věc.
     */
    public function test_vec_ze_seznamu_se_oznaci_za_hotovou(): void
    {
        $this->stav(['klTasks' => [
            ['id' => 0, 'name' => 'Zůstává', 'need' => 2],
            ['id' => 0, 'name' => 'Uděláme to', 'need' => 1],
        ]])->assertOk();

        $zustava = DB::table('wellbeing_tasks')->where('name', 'Zůstává')->value('id');

        $this->stav(['klTasks' => [
            ['id' => $zustava, 'name' => 'Zůstává', 'need' => 2],
        ]])->assertOk();

        $this->assertSame(2, DB::table('wellbeing_tasks')->count());
        $this->assertNotNull(DB::table('wellbeing_tasks')->where('name', 'Uděláme to')->value('done_at'));
        $this->assertNull(DB::table('wellbeing_tasks')->where('name', 'Zůstává')->value('done_at'));

        // A hotová věc už se v čekání neukazuje.
        $this->assertSame(['Zůstává'], collect($this->getJson('/api/data/klid')->assertOk()->json('data.KL_TASKS'))->pluck('name')->all());
    }

    /** Přejmenování nezaloží druhý řádek. */
    public function test_uprava_meni_stejny_radek(): void
    {
        $this->stav(['klTasks' => [['id' => 0, 'name' => 'Vyklidit sklep', 'need' => 1]]])->assertOk();
        $id = DB::table('wellbeing_tasks')->value('id');

        $this->stav(['klTasks' => [['id' => $id, 'name' => 'Vyklidit sklep a půdu', 'need' => 2]]])->assertOk();

        $this->assertSame(1, DB::table('wellbeing_tasks')->count());
        $this->assertSame('Vyklidit sklep a půdu', DB::table('wellbeing_tasks')->value('name'));
        $this->assertSame(2, (int) DB::table('wellbeing_tasks')->value('needs_people'));
    }

    /**
     * Hotová věc se ze staršího opisu nevrátí jako nová.
     *
     * Číslo řádku vydal server; když mezi čekajícími není, je hotová (nebo
     * patří jinam). Dřív se pod ním založil nový úkol — odškrtnuté „Zavolat
     * na úřad" se po úpravě jiného řádku v kartě od rána vrátilo.
     */
    public function test_hotova_vec_se_starsim_opisem_nevrati(): void
    {
        $this->stav(['klTasks' => [['id' => 0, 'name' => 'Zavolat na úřad', 'need' => 1]]])->assertOk();
        $id = (int) DB::table('wellbeing_tasks')->value('id');
        $this->stav(['klTasks' => [], '__odebrane' => ['klTasks' => [(string) $id]]])->assertOk();
        $this->assertNotNull(DB::table('wellbeing_tasks')->where('id', $id)->value('done_at'));

        $this->stav([
            'klTasks' => [['id' => $id, 'name' => 'Zavolat na úřad', 'need' => 1]],
            '__odebrane' => ['klTasks' => []],
        ])->assertOk();

        $this->assertSame(1, DB::table('wellbeing_tasks')->count());
        $this->assertSame(0, DB::table('wellbeing_tasks')->whereNull('done_at')->count());
    }

    /**
     * Znovu poslaná nová věc se nezaloží podruhé.
     *
     * Nová věc měla `id: 0` a každá nula se založila — seznam poslaný
     * podruhé (úprava během rozjetého zápisu, opakování po chybě) přidal
     * „Zavolat na úřad" dvakrát. Obrazovka teď posílá vlastní klíč.
     */
    public function test_znovu_poslana_nova_vec_se_nezalozi_podruhe(): void
    {
        $ukol = ['id' => 'k1757000000000', 'name' => 'Zavolat na úřad', 'need' => 1];

        $this->stav(['klTasks' => [$ukol]])->assertOk();
        $odpoved = $this->stav(['klTasks' => [$ukol]])->assertOk();

        $this->assertSame(1, DB::table('wellbeing_tasks')->count());
        $this->assertSame('k1757000000000', DB::table('wellbeing_tasks')->value('client_id'));
        $this->assertIsInt($odpoved->json('data.klTasks.0.id'), 'Obrazovka dostane zpátky číslo řádku.');
        $this->assertNull(DB::table('wellbeing_tasks')->value('done_at'));
    }

    /** Starší obrazovka posílá u nové věci pořád `0` — a ta se zakládá jako dřív. */
    public function test_starsi_obrazovka_s_nulou_dal_zaklada(): void
    {
        $this->stav(['klTasks' => [['id' => 0, 'name' => 'Vyklidit sklep', 'need' => 1]]])->assertOk();
        $id = (int) DB::table('wellbeing_tasks')->value('id');

        $this->stav(['klTasks' => [
            ['id' => $id, 'name' => 'Vyklidit sklep', 'need' => 1],
            ['id' => 0, 'name' => 'Zavolat na úřad', 'need' => 1],
        ]])->assertOk();

        $this->assertSame(2, DB::table('wellbeing_tasks')->whereNull('done_at')->count());
        $this->assertNull(DB::table('wellbeing_tasks')->where('name', 'Zavolat na úřad')->value('client_id'));
    }

    /** Věc odškrtnutá dřív, než obrazovka dostala její číslo, je hotová. */
    public function test_nova_vec_odskrtnuta_pred_cislem_je_hotova(): void
    {
        $this->stav(['klTasks' => [['id' => 'k1757000000000', 'name' => 'Zavolat na úřad', 'need' => 1]]])->assertOk();

        $this->stav(['klTasks' => [], '__odebrane' => ['klTasks' => ['k1757000000000']]])->assertOk();

        $this->assertNotNull(DB::table('wellbeing_tasks')->value('done_at'));

        // A starší opis s tímtéž klíčem ji do čekání nevrátí.
        $this->stav(['klTasks' => [['id' => 'k1757000000000', 'name' => 'Zavolat na úřad', 'need' => 1]], '__odebrane' => ['klTasks' => []]])->assertOk();

        $this->assertSame(1, DB::table('wellbeing_tasks')->count());
        $this->assertSame(0, DB::table('wellbeing_tasks')->whereNull('done_at')->count());
    }

    /** Starý klíč v odebraných neodškrtne věc, která v seznamu je pod číslem. */
    public function test_stary_klic_v_odebranych_neodskrtne_vec_s_cislem(): void
    {
        $this->stav(['klTasks' => [['id' => 'k1757000000000', 'name' => 'Zavolat na úřad', 'need' => 1]]])->assertOk();
        $id = (int) DB::table('wellbeing_tasks')->value('id');

        $this->stav([
            'klTasks' => [['id' => $id, 'name' => 'Zavolat na úřad', 'need' => 1]],
            '__odebrane' => ['klTasks' => ['k1757000000000']],
        ])->assertOk();

        $this->assertNull(DB::table('wellbeing_tasks')->value('done_at'));
    }

    // ——— pomůcky ———

    private function stav(array $patch)
    {
        return $this->patchJson('/api/state', ['data' => $patch]);
    }
}
