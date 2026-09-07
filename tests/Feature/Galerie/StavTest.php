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

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->maki = User::factory()->create(['name' => 'Makinka']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$this->prostor->id => ['role' => 'owner']]);
        $this->maki->gallerySpaces()->syncWithoutDetaching([$this->prostor->id => ['role' => 'editor']]);
    }

    public function test_prazdny_stav_se_zalozi_sam(): void
    {
        $this->actingAs($this->adri)->getJson('/api/state')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('rev', 0);
    }

    /**
     * Patch je částečný: co v něm není, zůstává.
     *
     * Klíče jsou schválně takové, které si stav vede sám. Ty, které patří
     * databázi (`rules`, `season`, `quarGone`, …), se cestou vyzvedávají
     * a ve stavu nezůstávají — na to jsou vlastní testy.
     */
    public function test_patch_sloucí_po_klicich(): void
    {
        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['favs' => ['a'], 'pins' => ['p1']]])
            ->assertOk()->assertJsonPath('rev', 1);

        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['favs' => ['a', 'b']]])
            ->assertOk()
            ->assertJsonPath('data.favs', ['a', 'b'])
            ->assertJsonPath('data.pins', ['p1'])
            ->assertJsonPath('rev', 2);
    }

    /**
     * Zápis do téhož klíče postavený na starší verzi se neaplikuje.
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
            ->assertJsonPath('strety', ['favs'])
            ->assertJsonPath('data.favs', ['nové']);

        $this->assertSame(['nové'], CoupleState::first()->toClientArray()['favs']);
    }

    /**
     * Změna něčeho jiného se ale zapíše.
     *
     * Dřív stačilo, aby druhý z dvojice mezitím změnil cokoliv: server vrátil
     * 409 a **celý patch zahodil**. Obrazovka se překreslila podle serveru
     * a to, co člověk mezitím napsal, zmizelo bez hlášky — jedno ze dvou
     * otevřených zařízení psalo do prázdna.
     */
    public function test_zmena_jineho_klice_se_zapise_i_na_starsi_revizi(): void
    {
        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['favs' => ['nové']]])->assertOk();

        $this->actingAs($this->maki)
            ->patchJson('/api/state', ['data' => ['pins' => ['moje']], 'rev' => 0])
            ->assertOk()
            ->assertJsonPath('data.pins', ['moje'])
            ->assertJsonPath('data.favs', ['nové'])
            ->assertJsonPath('strety', []);

        $stav = CoupleState::first()->toClientArray();

        $this->assertSame(['moje'], $stav['pins'], 'Zápis do jiného klíče nemá co ztratit.');
        $this->assertSame(['nové'], $stav['favs']);
    }

    /**
     * Z patche se zahodí jen to, oč se ti dva přetahují.
     *
     * Jedno kliknutí obvykle mění víc klíčů najednou. Zahodit kvůli jednomu
     * střetu i zbytek by znamenalo, že se ztratí věci, o které nikdo nestál.
     */
    public function test_ze_smiseneho_patche_se_zahodi_jen_stret(): void
    {
        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['favs' => ['nové']]])->assertOk();

        $this->actingAs($this->maki)
            ->patchJson('/api/state', ['data' => ['favs' => ['staré'], 'pins' => ['moje']], 'rev' => 0])
            ->assertOk()
            ->assertJsonPath('strety', ['favs'])
            ->assertJsonPath('data.favs', ['nové'])
            ->assertJsonPath('data.pins', ['moje']);
    }

    /** Bez čísla revize se nekontroluje nic — klient neřekl, na čem staví. */
    public function test_bez_revize_se_zapise_vse(): void
    {
        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['favs' => ['nové']]])->assertOk();

        $this->actingAs($this->maki)
            ->patchJson('/api/state', ['data' => ['favs' => ['přepsané']]])
            ->assertOk()
            ->assertJsonPath('data.favs', ['přepsané']);
    }

    /**
     * Heslo k trezoru se neuloží, ani když dorazí.
     *
     * Klient si hlídá, co na server neposílá, jenže `vaultPwd` v tom seznamu
     * chybí — při psaní se odešle. Spoléhat se na kázeň klienta nejde: mobilní
     * aplikace, starší verze i překlep v jednom seznamu jsou tři různé cesty,
     * jak sem heslo poslat.
     */
    public function test_hesla_a_kody_se_neukladaji(): void
    {
        $this->actingAs($this->adri)->patchJson('/api/state', [
            'data' => [
                'vaultPwd' => 'tajneheslo',
                'lockPin' => '240613',
                'gvPwd' => 'heslo pro hosty',
                'joy' => ['tohle uložit ano'],
            ],
            'rev' => 0,
        ])->assertOk();

        $ulozeno = CoupleState::first();
        $vse = json_encode([$ulozeno->data, $ulozeno->private], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('tajneheslo', $vse);
        $this->assertStringNotContainsString('240613', $vse);
        $this->assertStringNotContainsString('heslo pro hosty', $vse);
        $this->assertSame(['tohle uložit ano'], $ulozeno->data['joy'], 'Zbytek patche projít musí.');
    }

    /**
     * Prázdný stav je `{}`, ne `[]`.
     *
     * Klient si odpověď vezme jako svou lokální kopii a ukládá ji přes
     * `JSON.stringify`. To u pole zapíše jen číselné indexy, takže první uložení
     * v prohlížeči zahodí všechno, co do stavu mezitím přibylo — a stane se to
     * jen novému páru, tedy tam, kde si toho nikdo nevšimne.
     */
    public function test_prazdny_stav_je_objekt_ne_pole(): void
    {
        $telo = $this->actingAs($this->adri)->getJson('/api/state')->assertOk()->getContent();

        $this->assertStringContainsString('"data":{}', $telo);
        $this->assertStringNotContainsString('"data":[]', $telo);

        $smazano = $this->actingAs($this->adri)->deleteJson('/api/state')->assertOk()->getContent();

        $this->assertStringContainsString('"data":{}', $smazano);
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

    /**
     * Prázdný objekt se nesmí vrátit jako prázdné pole.
     *
     * PHP mezi `{}` a `[]` po `json_decode(..., true)` nerozliší a při
     * odeslání zpátky z obojího vyjde `[]`. Klient porovnává obsah přes
     * `JSON.stringify`, uviděl rozdíl, který sám nezpůsobil, a zapsal znovu —
     * server zase odpověděl polem. Aplikace při nečinnosti posílala kolem
     * čtyřiceti `PATCH /api/state` za minutu s pořád stejným obsahem, revize
     * stavu šla do desetitisíců, a na serveru to WAF po sto dvaceti
     * požadavcích za minutu vyhodnotil jako útok a zablokoval adresu, ze které
     * se dvojice dívala. Aplikace pak nešla načíst vůbec.
     */
    public function test_prazdny_objekt_zustane_objektem(): void
    {
        $this->actingAs($this->adri)
            ->patchJson('/api/state', ['data' => ['favs' => new \stdClass, 'sel' => []]])
            ->assertOk();

        $telo = $this->actingAs($this->adri)->getJson('/api/state')->assertOk()->getContent();

        $this->assertStringContainsString('"favs":{}', $telo, 'Prázdný objekt se vrátil jako pole.');
        // A pole zůstane polem — obojí platí, jinak se smyčka jen otočí.
        $this->assertStringContainsString('"sel":[]', $telo);
    }

    /**
     * Mapa s číselnými klíči taky.
     *
     * `{"0":"adrianovo"}` je v PHP totéž co `["adrianovo"]` a `json_encode`
     * z toho udělá pole. Oblíbené se takhle vracely v jiném tvaru, než v jakém
     * odešly, a spouštěly tutéž smyčku.
     */
    public function test_mapa_s_ciselnymi_klici_zustane_objektem(): void
    {
        // Přes `stdClass`, ne pole: `['0' => 'x']` by PHP odeslalo jako `["x"]`
        // a test by zkoušel něco jiného, než co posílá prohlížeč.
        $this->actingAs($this->adri)
            ->patchJson('/api/state', ['data' => ['favs' => json_decode('{"0":"adrianovo"}')]])
            ->assertOk();

        $telo = $this->actingAs($this->adri)->getJson('/api/state')->assertOk()->getContent();

        $this->assertStringContainsString('"favs":{"0":"adrianovo"}', $telo);
    }

    /** Kdo nepatří do žádného prostoru, nemá ani stav — a dozví se proč. */
    public function test_ucet_bez_prostoru_dostane_srozumitelnou_chybu(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/state')
            ->assertNotFound()
            ->assertJsonPath('message', 'Účet zatím nepatří do žádného společného prostoru. Založte ho nebo přijměte pozvánku.');
    }
}
