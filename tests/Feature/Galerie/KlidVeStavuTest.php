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

    // ——— pomůcky ———

    private function stav(array $patch)
    {
        return $this->patchJson('/api/state', ['data' => $patch]);
    }
}
