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
        $this->assertSame('První byt', $odpoved->json('data.storyList.0.1'));
        $this->assertContains('storyList', $odpoved->json('docasne'));
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

        $this->assertSame(0, $odpoved->json('data.storyList.0.4'));
        $this->assertSame(0, $odpoved->json('data.storyList.0.5'));
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
