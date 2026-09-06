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
 * Kapitola, nouzový přístup a papírový list se opravdu uloží.
 *
 * U nouzového přístupu je to nejcitlivější: „soukromé zápisy v deníku se
 * nouzově neodemknou" je rozhodnutí, které musí platit i na druhém zařízení.
 */
class PribehVeStavuTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    /** Nová kapitola vznikne v tabulce, ne ve stavu. */
    public function test_nova_kapitola_vznikne_v_tabulce(): void
    {
        $odpoved = $this->stav(['storyList' => [
            ['c-nova', 'První byt', '2018', 'hotovo', 0, 0, 'Třicet čtyři metrů.'],
        ]])->assertOk();

        $radek = DB::table('couple_story_chapters')->first();

        $this->assertSame('První byt', $radek->title);
        $this->assertSame('2018', $radek->year);
        $this->assertSame('done', $radek->status);
        $this->assertSame('Třicet čtyři metrů.', $radek->body);

        $this->assertArrayNotHasKey('storyList', (array) $this->getJson('/api/state')->assertOk()->json('data'));
        $this->assertSame('První byt', $odpoved->json('data.storyList.0.title'));
        $this->assertContains('storyList', $odpoved->json('docasne'));
    }

    /**
     * Obrazovka posílá objekty, ne pole — a musí je i dostat.
     *
     * `storyOf()` v prototypu vrací `state.storyList` rovnou a kreslí z něj
     * `c.title`. Když se četla jen pozice v poli, přišla kapitola bez názvu —
     * a řádek bez názvu se přeskakuje, takže po první úpravě zmizely
     * z tabulky **všechny** kapitoly.
     */
    public function test_kapitola_v_tvaru_obrazovky_se_ulozi(): void
    {
        $odpoved = $this->stav(['storyList' => [
            ['id' => 'c-nova', 'title' => 'První byt', 'year' => '2018',
                'status' => 'hotovo', 'photoN' => 0, 'n' => 0, 'text' => 'Třicet čtyři metrů.'],
        ]])->assertOk();

        $this->assertSame('První byt', DB::table('couple_story_chapters')->value('title'));
        $this->assertSame('done', DB::table('couple_story_chapters')->value('status'));
        $this->assertSame('První byt', $odpoved->json('data.storyList.0.title'));
    }

    /** A úprava jedné kapitoly nesmí smazat ostatní. */
    public function test_uprava_z_obrazovky_nesmaze_ostatni(): void
    {
        $this->stav(['storyList' => [
            ['id' => 'a', 'title' => 'První', 'year' => '2016', 'status' => 'hotovo', 'text' => ''],
            ['id' => 'b', 'title' => 'Druhá', 'year' => '2018', 'status' => 'návrh', 'text' => ''],
        ]])->assertOk();

        $seznam = DB::table('couple_story_chapters')->orderBy('sort_order')->get(['uuid', 'title'])
            ->map(fn ($k) => ['id' => $k->uuid, 'title' => $k->title, 'year' => '2016', 'status' => 'hotovo', 'text' => ''])
            ->all();
        $seznam[0]['title'] = 'Přejmenovaná';

        $this->stav(['storyList' => $seznam])->assertOk();

        $this->assertSame(['Přejmenovaná', 'Druhá'], DB::table('couple_story_chapters')->orderBy('sort_order')->pluck('title')->all());
    }

    /**
     * Počty fotek a zápisů se z prohlížeče nepřebírají.
     *
     * Aplikace je počítá sama; číslo z prohlížeče je jen jeho stará kopie.
     */
    public function test_pocty_se_z_prohlizece_neberou(): void
    {
        $odpoved = $this->stav(['storyList' => [
            ['c-nova', 'Kapitola', '2018', 'píše se', 999, 999, 'Text.'],
        ]])->assertOk();

        $this->assertSame(0, $odpoved->json('data.storyList.0.photoN'));
        $this->assertSame(0, $odpoved->json('data.storyList.0.n'));
    }

    /**
     * Milník na ose se uloží do tabulky, ne do prohlížeče.
     *
     * „Milník přidán na osu" platilo do zavření záložky: `couple_story_milestones`
     * se četla a nikdo do ní nepsal.
     */
    public function test_milnik_vznikne_v_tabulce(): void
    {
        $this->stav(['storyList' => [
            ['id' => 'c1', 'title' => 'Začátek', 'year' => '2016', 'status' => 'hotovo', 'text' => ''],
        ]])->assertOk();

        $kapitola = DB::table('couple_story_chapters')->value('uuid');

        $odpoved = $this->stav(['msList' => [
            ['id' => 'm-n1', 'y' => '2016', 'date' => '14. května 2016', 'title' => 'Poprvé jsme se potkali',
                'note' => 'Svatba u Kláry.', 'icon' => 'ph-sparkle', 'ch' => $kapitola, 'iso' => '2016-05-14'],
        ]])->assertOk();

        $radek = DB::table('couple_story_milestones')->sole();

        $this->assertSame('Poprvé jsme se potkali', $radek->title);
        $this->assertStringStartsWith('2016-05-14', (string) $radek->happened_on);
        $this->assertNotNull($radek->chapter_id, 'Milník patří do kapitoly, kterou obrazovka vybrala.');

        $this->assertSame('14. května 2016', $odpoved->json('data.msList.0.date'));
        $this->assertSame('2016-05-14', $odpoved->json('data.msList.0.iso'));
    }

    /**
     * Bez `iso` se datum přečte z toho, co je napsané.
     *
     * Starší klient a ruční požadavek posílají jen text.
     */
    public function test_datum_se_precte_i_z_napsaneho_textu(): void
    {
        $this->stav(['msList' => [
            ['id' => 'm1', 'y' => '2018', 'date' => '11. listopadu 2018', 'title' => 'Nastěhovali jsme se', 'note' => '', 'icon' => '', 'ch' => ''],
            ['id' => 'm2', 'y' => '2019', 'date' => '3. 6. 2019', 'title' => 'Itálie vlakem', 'note' => '', 'icon' => '', 'ch' => ''],
            ['id' => 'm3', 'y' => '2021', 'date' => '17. dubna', 'title' => 'Rok cestování', 'note' => '', 'icon' => '', 'ch' => ''],
        ]])->assertOk();

        $dny = DB::table('couple_story_milestones')->orderBy('happened_on')->pluck('happened_on')
            ->map(fn ($d) => substr((string) $d, 0, 10))->all();

        // Rok z vedlejšího pole platí, jen když ho v textu není.
        $this->assertSame(['2018-11-11', '2019-06-03', '2021-04-17'], $dny);
        $this->assertSame('ph-sparkle', DB::table('couple_story_milestones')->value('icon'));
    }

    /**
     * Milník s nečitelným datem se nezaloží.
     *
     * Dosadit za něj první leden by znamenalo postavit ho na ose jinam,
     * než se stal — a nikdo by se to nedozvěděl.
     */
    public function test_milnik_bez_citelneho_data_nevznikne(): void
    {
        $this->stav(['msList' => [
            ['id' => 'm1', 'y' => '2020', 'date' => 'někdy na jaře', 'title' => 'Nečitelné', 'note' => '', 'icon' => '', 'ch' => ''],
        ]])->assertOk();

        $this->assertSame(0, DB::table('couple_story_milestones')->count());
    }

    /** Smazaný milník zmizí i z tabulky. */
    public function test_smazany_milnik_zmizi(): void
    {
        $this->stav(['msList' => [
            ['id' => 'm1', 'y' => '2018', 'date' => '11. listopadu 2018', 'title' => 'Zůstává', 'note' => '', 'icon' => '', 'ch' => ''],
            ['id' => 'm2', 'y' => '2019', 'date' => '3. června 2019', 'title' => 'Mizí', 'note' => '', 'icon' => '', 'ch' => ''],
        ]])->assertOk();

        $uuid = DB::table('couple_story_milestones')->where('title', 'Zůstává')->value('uuid');

        $this->stav(['msList' => [
            ['id' => $uuid, 'y' => '2018', 'date' => '11. listopadu 2018', 'title' => 'Zůstává', 'note' => '', 'icon' => '', 'ch' => ''],
        ]])->assertOk();

        $this->assertSame(['Zůstává'], DB::table('couple_story_milestones')->pluck('title')->all());
    }

    /** Milníky se do stavu neukládají — mají tabulku. */
    public function test_milniky_nezustanou_ve_stavu(): void
    {
        $this->stav(['msList' => [
            ['id' => 'm1', 'y' => '2018', 'date' => '11. listopadu 2018', 'title' => 'Nastěhovali jsme se', 'note' => '', 'icon' => '', 'ch' => ''],
        ]])->assertOk();

        $this->assertArrayNotHasKey('msList', (array) $this->getJson('/api/state')->assertOk()->json('data'));
    }

    /** Co v seznamu není, dvojice smazala. */
    public function test_smazana_kapitola_zmizi(): void
    {
        $this->stav(['storyList' => [['a', 'Zůstává', '2016', 'hotovo', 0, 0, '']]])->assertOk();
        $uuid = DB::table('couple_story_chapters')->value('uuid');

        $this->stav(['storyList' => [
            [$uuid, 'Zůstává', '2016', 'hotovo', 0, 0, ''],
            ['b', 'Nová', '2018', 'píše se', 0, 0, ''],
        ]])->assertOk();

        $this->assertSame(2, DB::table('couple_story_chapters')->count());

        $this->stav(['storyList' => [[$uuid, 'Zůstává', '2016', 'hotovo', 0, 0, '']]])->assertOk();

        $this->assertSame(['Zůstává'], DB::table('couple_story_chapters')->pluck('title')->all());
    }

    /**
     * Vypnutí sdílení v nouzi se zapíše — a zůstane po tom stopa.
     *
     * Kdo co komu otevřel se musí dát zjistit i za rok.
     */
    public function test_zmena_nouzoveho_pristupu_se_zapise_do_protokolu(): void
    {
        DB::table('emergency_access_items')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'label' => 'Soukromé zápisy v deníku',
            'note' => 'To, co jsme si nechali pro sebe',
            'is_shared' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $uuid = DB::table('emergency_access_items')->value('uuid');

        $this->stav(['emItems' => [
            ['id' => $uuid, 'label' => 'Soukromé zápisy v deníku', 'note' => 'To, co jsme si nechali pro sebe', 'on' => false],
        ]])->assertOk();

        $this->assertFalse((bool) DB::table('emergency_access_items')->value('is_shared'));

        $zapis = DB::table('emergency_access_log')->first();

        $this->assertStringContainsString('Adrian', $zapis->text);
        $this->assertStringContainsString('vypnul(a) sdílení', $zapis->text);
        $this->assertStringContainsString('Soukromé zápisy', $zapis->text);
    }

    /** Beze změny se do protokolu nic nepíše. */
    public function test_beze_zmeny_zadny_zapis(): void
    {
        DB::table('emergency_access_items')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'label' => 'Pojistky',
            'is_shared' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $uuid = DB::table('emergency_access_items')->value('uuid');

        $this->stav(['emItems' => [['id' => $uuid, 'label' => 'Pojistky', 'note' => '', 'on' => true]]])->assertOk();

        $this->assertSame(0, DB::table('emergency_access_log')->count());
    }

    /** Papírový list se uloží i s tím, co na něj dvojice napsala. */
    public function test_papirovy_list_se_ulozi(): void
    {
        $this->stav(['paper' => [
            ['id' => 'a1', 'label' => 'Kde je hlavní heslo', 'value' => 'Obálka u rodičů.', 'on' => true, 'changed' => true],
        ]])->assertOk();

        $radek = DB::table('paper_backup_rows')->first();

        $this->assertSame('Kde je hlavní heslo', $radek->label);
        $this->assertSame('Obálka u rodičů.', $radek->value);
        $this->assertTrue((bool) $radek->is_done);
        $this->assertTrue((bool) $radek->changed);
    }

    /** Řádek bez popisku není řádek. */
    public function test_radek_bez_popisku_se_neulozi(): void
    {
        $this->stav(['paper' => [['id' => 'a1', 'label' => '  ', 'value' => 'Něco', 'on' => true]]])->assertOk();

        $this->assertSame(0, DB::table('paper_backup_rows')->count());
    }

    /** Příběh druhého páru se odsud změnit nedá. */
    public function test_cizi_kapitola_zustane(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        DB::table('couple_story_chapters')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $ciziProstor->id,
            'title' => 'Cizí kapitola',
            'status' => 'draft',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->stav(['storyList' => []])->assertOk();

        $this->assertSame(1, DB::table('couple_story_chapters')->count());
    }

    // ——— pomůcky ———

    private function stav(array $patch)
    {
        return $this->patchJson('/api/state', ['data' => $patch]);
    }
}
