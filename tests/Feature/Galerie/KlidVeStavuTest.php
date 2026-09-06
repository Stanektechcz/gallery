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

    /** Mřížka se rozloží na buňky — a uloží se za toho, komu patří. */
    public function test_mapa_energie_se_ulozi_po_bunkach(): void
    {
        $odpoved = $this->stav(['klEn' => [
            'Adrian' => ['201', '000', '000', '000', '000', '000', '000'],
            'Makinka' => ['000', '000', '000', '000', '000', '000', '020'],
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
     * Napsaná odpověď dorazí dřív než potvrzení.
     *
     * Prototyp ukládá rozepsaný text hned, takže patch s „odeslat" už ho
     * nenese — dohledá se v tom, co ve stavu leží.
     */
    public function test_odpoved_z_drivejsiho_patche_se_najde(): void
    {
        $this->stav(['klAskMine' => 'Tři večery po deváté v práci.'])->assertOk();

        $this->assertSame(0, DB::table('wellbeing_answers')->count());

        $this->stav([
            'klAskDone' => true,
            'klAskQ' => 'Co z tohoto týdne bys nechtěl opakovat?',
        ])->assertOk();

        $radek = DB::table('wellbeing_answers')->first();

        $this->assertSame('Tři večery po deváté v práci.', $radek->answer);
        $this->assertSame('Co z tohoto týdne bys nechtěl opakovat?', $radek->question);
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

    // ——— pomůcky ———

    private function stav(array $patch)
    {
        return $this->patchJson('/api/state', ['data' => $patch]);
    }
}
