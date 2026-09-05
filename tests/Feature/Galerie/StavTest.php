<?php

namespace Tests\Feature\Galerie;

use App\Models\CoupleState;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stav prototypu — jeden JSON dokument na pár.
 *
 * Prototyp má přes dvě stě stavových klíčů a mění se každý den, takže server drží
 * jeden dokument a klient posílá jen to, co se změnilo. Testy míří na tři místa,
 * kde se to dá pokazit tak, že to vypadá funkčně:
 *
 *  - **sloučení po klíčích** — kdyby server patch nahradil celý dokument, dvě
 *    zařízení by si navzájem mazala změny a poznalo by se to až u dat, která
 *    zmizela bez stopy,
 *  - **konflikt na `rev`** — zápis z telefonu bez signálu dorazí i za pár dní,
 *  - **oddělení párů** — cizí stav se nesmí objevit ani omylem.
 */
class StavTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$this->prostor->id => ['role' => 'owner']]);
    }

    public function test_prazdny_stav_se_zalozi_sam(): void
    {
        $this->actingAs($this->adri)->getJson('/api/state')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('rev', 0);
    }

    /** Patch je částečný: co v něm není, zůstává. */
    public function test_patch_sloucí_po_klicich(): void
    {
        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['favs' => ['a'], 'rules' => ['r1']]])
            ->assertOk()->assertJsonPath('rev', 1);

        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['favs' => ['a', 'b']]])
            ->assertOk()
            ->assertJsonPath('data.favs', ['a', 'b'])
            ->assertJsonPath('data.rules', ['r1'])
            ->assertJsonPath('rev', 2);
    }

    /**
     * Zápis postavený na starší verzi se neaplikuje.
     *
     * Bez signálu leží patch ve frontě klidně dny. Kdyby se pak přepsal aktuální
     * stav, zmizely by změny, které mezitím udělal ten druhý — a nikdo by nevěděl,
     * kam se poděly.
     */
    public function test_starsi_rev_konci_konfliktem_a_vrati_aktualni_stav(): void
    {
        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['favs' => ['nové']]])->assertOk();

        $this->actingAs($this->adri)
            ->patchJson('/api/state', ['data' => ['favs' => ['staré']], 'rev' => 0])
            ->assertStatus(409)
            ->assertJsonPath('conflict', true)
            ->assertJsonPath('data.favs', ['nové']);

        $this->assertSame(['nové'], CoupleState::first()->toClientArray()['favs']);
    }

    /** Shodná verze projde — klient staví na tom, co server má. */
    public function test_shodny_rev_projde(): void
    {
        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['favs' => ['a']]])->assertOk();

        $this->actingAs($this->adri)
            ->patchJson('/api/state', ['data' => ['favs' => ['a', 'b']], 'rev' => 1])
            ->assertOk()->assertJsonPath('rev', 2);
    }

    /**
     * Citlivé klíče leží v šifrovaném sloupci, ale klient je dostane jako ostatní.
     *
     * Kdyby se lišil tvar odpovědi, musel by prototyp vědět, co je citlivé — a to
     * je rozhodnutí, které patří na server.
     */
    public function test_citlive_klice_jdou_do_sifrovaneho_sloupce(): void
    {
        $this->actingAs($this->adri)
            ->patchJson('/api/state', ['data' => ['kidsStance' => 'zatím ne', 'favs' => ['a']]])
            ->assertOk()
            ->assertJsonPath('data.kidsStance', 'zatím ne')
            ->assertJsonPath('data.favs', ['a']);

        $radek = \DB::table('couple_states')->first();

        $this->assertStringNotContainsString('zatím ne', $radek->data, 'Citlivý klíč nesmí ležet v otevřeném sloupci.');
        $this->assertStringContainsString('favs', $radek->data);
    }

    /** Oba partneři čtou a píší tentýž záznam — stav patří páru, ne člověku. */
    public function test_oba_partneri_vidi_tentyz_stav(): void
    {
        $makinka = User::factory()->create();
        $makinka->gallerySpaces()->syncWithoutDetaching([$this->prostor->id => ['role' => 'owner']]);

        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['favs' => ['spolecne']]])->assertOk();

        $this->actingAs($makinka)->getJson('/api/state')
            ->assertOk()->assertJsonPath('data.favs', ['spolecne']);

        $this->assertSame(1, CoupleState::count(), 'Na pár patří jeden záznam, ne jeden na člověka.');
    }

    /** Cizí pár nevidí nic z našeho stavu. */
    public function test_cizi_par_nas_stav_nevidi(): void
    {
        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['favs' => ['tajne']]])->assertOk();

        $cizi = User::factory()->create();
        $cizProstor = GallerySpace::create(['name' => 'Jiní', 'owner_id' => $cizi->id]);
        $cizi->gallerySpaces()->syncWithoutDetaching([$cizProstor->id => ['role' => 'owner']]);

        $this->actingAs($cizi)->getJson('/api/state')->assertOk()->assertJsonPath('data', []);
    }

    public function test_smazani_stav_vynuluje(): void
    {
        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['favs' => ['a']]])->assertOk();

        $this->actingAs($this->adri)->deleteJson('/api/state')->assertOk()->assertJsonPath('rev', 0);
        $this->actingAs($this->adri)->getJson('/api/state')->assertOk()->assertJsonPath('data', []);
    }

    public function test_bez_prihlaseni_stav_nedostane(): void
    {
        $this->getJson('/api/state')->assertUnauthorized();
    }

    /** Kdo nepatří do žádného prostoru, nemá ani stav — a dozví se proč. */
    public function test_ucet_bez_prostoru_dostane_srozumitelnou_chybu(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/state')
            ->assertNotFound()
            ->assertJsonPath('message', 'Účet zatím nepatří do žádného společného prostoru. Založte ho nebo přijměte pozvánku.');
    }
}
