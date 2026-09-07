<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Zámek aplikace: kód, který patří jednomu člověku.
 *
 * Šestimístný kód se porovnával v prohlížeči s konstantou `LOCKPIN`
 * z `galerie-data.js` — ze souboru, který server podá komukoli. Kódy obou
 * partnerů tak byly veřejné a obrazovka je navíc sama vypisovala v nápovědě
 * nad klávesnicí.
 *
 * Tyhle testy hlídají tři věci: že kód ověřuje server, že ho nikdo kromě
 * majitele nezná — ani partner, ani odpověď —, a že jde zapomenout.
 */
class ZamekTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian', 'password' => Hash::make('heslo-adriana')]);
        $this->maki = User::factory()->create(['name' => 'Makinka', 'password' => Hash::make('heslo-makinky')]);

        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    /** Bez nastaveného kódu se obrazovka nemá o co opřít. */
    public function test_bez_kodu_se_to_pozna(): void
    {
        $this->getJson('/api/zamek')->assertOk()->assertJson(['nastaveno' => false, 'zmeneno' => null]);

        // A ověřovat se nedá nic — 409, ne „kód nesouhlasí".
        $this->postJson('/api/zamek/overit', ['kod' => '240613'])->assertStatus(409);
    }

    /** První kód se zakládá heslem do galerie. */
    public function test_prvni_kod_chce_heslo(): void
    {
        $this->postJson('/api/zamek', ['kod' => '240613'])
            ->assertStatus(422)->assertJson(['chyba' => 'Heslo do galerie nesouhlasí.']);

        $telo = $this->postJson('/api/zamek', ['kod' => '240613', 'heslo' => 'heslo-adriana'])
            ->assertOk()->assertJson(['nastaveno' => true])->json();

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $telo['obnovovaci']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'app_lock.set')->count());
    }

    /**
     * Kód se v databázi neuloží tak, jak ho člověk napsal.
     *
     * Bez toho by ho z databáze přečetl každý, kdo se k ní dostane — a byl by
     * to týž kód, jakým se odemyká telefon.
     */
    public function test_kod_je_v_databazi_jen_jako_has(): void
    {
        $this->postJson('/api/zamek', ['kod' => '240613', 'heslo' => 'heslo-adriana'])->assertOk();

        $ulozeny = (string) DB::table('users')->where('id', $this->adri->id)->value('app_lock_pin');

        $this->assertNotSame('240613', $ulozeny);
        $this->assertTrue(Hash::check('240613', $ulozeny));
    }

    /** Odemyká server, ne prohlížeč. */
    public function test_spravny_kod_odemkne_a_spatny_ne(): void
    {
        $this->postJson('/api/zamek', ['kod' => '240613', 'heslo' => 'heslo-adriana'])->assertOk();

        $this->postJson('/api/zamek/overit', ['kod' => '000000'])
            ->assertStatus(422)->assertJsonPath('chyba', 'Kód nesouhlasí — zbývají 2 pokusy.');

        $this->postJson('/api/zamek/overit', ['kod' => '240613'])
            ->assertOk()->assertJson(['odemceno' => true]);

        $this->assertSame(1, DB::table('audit_logs')->where('action', 'app_lock.failed')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'app_lock.open')->count());
    }

    /** Tři chyby po sobě odemykání na půl minuty uzavřou. */
    public function test_tri_chyby_uzavrou_odemykani(): void
    {
        $this->postJson('/api/zamek', ['kod' => '240613', 'heslo' => 'heslo-adriana'])->assertOk();

        $this->postJson('/api/zamek/overit', ['kod' => '111111'])->assertStatus(422);
        $this->postJson('/api/zamek/overit', ['kod' => '222222'])->assertStatus(422);
        $this->postJson('/api/zamek/overit', ['kod' => '333333'])->assertStatus(429);

        // A správný kód v té chvíli taky ne — jinak by uzavření nic neznamenalo.
        $this->postJson('/api/zamek/overit', ['kod' => '240613'])->assertStatus(429);
    }

    /** Změna kódu chce ten starý, ne heslo. */
    public function test_zmena_chce_stary_kod(): void
    {
        $this->postJson('/api/zamek', ['kod' => '240613', 'heslo' => 'heslo-adriana'])->assertOk();

        $this->postJson('/api/zamek', ['kod' => '190522', 'heslo' => 'heslo-adriana'])
            ->assertStatus(422)->assertJson(['chyba' => 'Starý kód nesouhlasí.']);

        $this->postJson('/api/zamek', ['kod' => '190522', 'stary' => '240613'])->assertOk();
        $this->postJson('/api/zamek/overit', ['kod' => '190522'])->assertOk();
    }

    /**
     * Kód jednoho není kód druhého.
     *
     * `LOCKPIN` byl objekt `{ A: …, M: … }` a obrazovka podle vybraného
     * profilu sáhla do toho druhého — takže kód partnera byl po ruce.
     */
    public function test_kod_partnera_neotevre_muj_zamek(): void
    {
        $this->postJson('/api/zamek', ['kod' => '240613', 'heslo' => 'heslo-adriana'])->assertOk();

        Sanctum::actingAs($this->maki);

        // Makinka svůj kód nemá, i když ho Adrian má.
        $this->getJson('/api/zamek')->assertOk()->assertJson(['nastaveno' => false]);
        $this->postJson('/api/zamek/overit', ['kod' => '240613'])->assertStatus(409);

        $this->postJson('/api/zamek', ['kod' => '190522', 'heslo' => 'heslo-makinky'])->assertOk();
        $this->postJson('/api/zamek/overit', ['kod' => '240613'])->assertStatus(422);
        $this->postJson('/api/zamek/overit', ['kod' => '190522'])->assertOk();
    }

    /** Heslo partnera cizí kód nezaloží. */
    public function test_cizim_heslem_kod_nezalozim(): void
    {
        $this->postJson('/api/zamek', ['kod' => '240613', 'heslo' => 'heslo-makinky'])
            ->assertStatus(422);

        $this->assertNull(DB::table('users')->where('id', $this->adri->id)->value('app_lock_pin'));
    }

    /** Obnovovací kód otevře aplikaci a zapomenutý kód zahodí. */
    public function test_obnovovaci_kod_zahodi_zapomenuty(): void
    {
        $obnovovaci = $this->postJson('/api/zamek', ['kod' => '240613', 'heslo' => 'heslo-adriana'])
            ->assertOk()->json('obnovovaci');

        $this->postJson('/api/zamek/obnovit', ['kod' => 'XXXX-XXXX-XXXX'])->assertStatus(422);

        $this->postJson('/api/zamek/obnovit', ['kod' => $obnovovaci])
            ->assertOk()->assertJson(['odemceno' => true, 'nastaveno' => false]);

        // Kód je pryč a nový se zakládá zase heslem.
        $this->postJson('/api/zamek/overit', ['kod' => '240613'])->assertStatus(409);
        $this->postJson('/api/zamek', ['kod' => '111222', 'heslo' => 'heslo-adriana'])->assertOk();
    }

    /** Obnovovací kód platí jednou. */
    public function test_obnovovaci_kod_plati_jednou(): void
    {
        $obnovovaci = $this->postJson('/api/zamek', ['kod' => '240613', 'heslo' => 'heslo-adriana'])
            ->assertOk()->json('obnovovaci');

        $this->postJson('/api/zamek/obnovit', ['kod' => $obnovovaci])->assertOk();
        $this->postJson('/api/zamek/obnovit', ['kod' => $obnovovaci])->assertStatus(422);
    }

    /** Mezery a malá písmena v obnovovacím kódu se odpouštějí — opisuje se z papíru. */
    public function test_obnovovaci_kod_snese_prepis_z_papiru(): void
    {
        $obnovovaci = $this->postJson('/api/zamek', ['kod' => '240613', 'heslo' => 'heslo-adriana'])
            ->assertOk()->json('obnovovaci');

        $rucne = strtolower(str_replace('-', ' ', $obnovovaci));

        $this->postJson('/api/zamek/obnovit', ['kod' => $rucne])->assertOk();
    }

    /**
     * Běžné klepání po aplikaci nesmí vyčerpat limit na odemykání.
     *
     * `ThrottleRequests` si bez předpony klíčuje pokusy jen podle uživatele
     * a adresy, takže všechny cesty ve skupině sdílely **jedno počítadlo**:
     * šest načtení stavu spolklo limit 5/min na obnovovací kód a člověk
     * dostal 429 jen proto, že si prohlížel fotky.
     */
    public function test_prohlizeni_nevycerpa_limit_na_obnovu(): void
    {
        $obnovovaci = $this->postJson('/api/zamek', ['kod' => '240613', 'heslo' => 'heslo-adriana'])
            ->assertOk()->json('obnovovaci');

        foreach (range(1, 8) as $ignored) {
            $this->getJson('/api/state')->assertOk();
        }

        $this->postJson('/api/zamek/obnovit', ['kod' => $obnovovaci])->assertOk();
    }

    /**
     * O kódu se ven neposílá nic než to, že existuje.
     *
     * Ani haš: obrazovka ho nepotřebuje a co jednou dorazí do prohlížeče,
     * zůstane v jeho historii.
     */
    public function test_kod_se_do_obrazovky_neposila(): void
    {
        $this->postJson('/api/zamek', ['kod' => '240613', 'heslo' => 'heslo-adriana'])->assertOk();

        $data = $this->getJson('/api/data/system')->assertOk()->json('data');

        $this->assertTrue($data['ZAMEK']['nastaveno']);
        $this->assertSame(now()->toDateString(), $data['ZAMEK']['zmeneno']);
        $this->assertStringNotContainsString('240613', json_encode($data));
        $this->assertStringNotContainsString('$2y$', json_encode($data));
        $this->assertArrayNotHasKey('LOCKPIN', $data);
    }
}
