<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use App\Models\WebauthnCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Fixtures\Galerie\FalesnyAutentikator;
use Tests\TestCase;

/**
 * Odemknutí otiskem prstu nebo obličejem.
 *
 * Testy pracují se skutečně podepsanými odpověďmi (viz [FalesnyAutentikator]) —
 * kdyby se odpovědi jen vymýšlely, potvrzovaly by, že se do databáze uloží
 * řetězec, ne že server pozná podvrh. Právě proto tu jsou i tři testy toho,
 * co projít **nemá**: zkopírovaný klíč, vypršelá challenge a cizí origin.
 */
class OtiskTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    protected function setUp(): void
    {
        parent::setUp();

        // Doména a origin natvrdo — jinak by test záležel na tom, co má vývojář v .env.
        config([
            'galerie.rp_id' => 'localhost',
            'galerie.rp_name' => 'Naše vzpomínky',
            'galerie.rp_origins' => ['http://localhost'],
        ]);

        $this->adri = User::factory()->create([
            'email' => 'adrian@vzpominky.test',
            'password' => Hash::make('zadar2026'),
        ]);

        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$prostor->id => ['role' => 'owner']]);
    }

    // ——— registrace ———

    public function test_registrace_ulozi_klic_prihlasenemu_cloveku(): void
    {
        $autentikator = $this->zaregistruj('iPhone Adrian');

        $klic = WebauthnCredential::sole();

        $this->assertSame($this->adri->id, $klic->user_id);
        $this->assertSame($autentikator->idKlice(), $klic->credential_id);
        $this->assertSame('iPhone Adrian', $klic->label);
        $this->assertNotEmpty($klic->public_key, 'Bez veřejného klíče by se podpis neměl čím ověřit.');
        $this->assertNotNull($klic->last_used_at);
    }

    public function test_volby_registrace_nesou_challenge_a_ucet(): void
    {
        $odpoved = $this->prihlasenyTokenem()
            ->postJson('/api/webauthn/register/options')
            ->assertOk()
            ->assertJsonPath('rp.id', 'localhost')
            ->assertJsonPath('user.name', 'adrian@vzpominky.test');

        $this->assertNotEmpty($odpoved->json('challenge'));
        $this->assertSame(
            32,
            strlen($this->zB64u($odpoved->json('challenge'))),
            'Kratší challenge než 32 bajtů se dá uhodnout.',
        );
    }

    /** Bez tokenu by si otisk k účtu připojil kdokoli, kdo zná e-mail. */
    public function test_registrace_bez_prihlaseni_neprojde(): void
    {
        $this->postJson('/api/webauthn/register/options')->assertUnauthorized();
        $this->postJson('/api/webauthn/register', [])->assertUnauthorized();
    }

    /** Klíč, který zařízení už jednou poslalo, nezaloží druhý řádek. */
    public function test_opakovana_registrace_tehoz_klice_neprida_radek(): void
    {
        $autentikator = $this->zaregistruj('iPhone Adrian');

        $volby = $this->prihlasenyTokenem()->postJson('/api/webauthn/register/options')->assertOk();
        $this->postJson('/api/webauthn/register', $autentikator->registrace($volby->json('challenge'), 'iPhone Adrian'))
            ->assertOk();

        $this->assertSame(1, WebauthnCredential::count());
    }

    // ——— přihlášení ———

    public function test_otiskem_jde_ziskat_token_a_precist_stav(): void
    {
        $autentikator = $this->zaregistruj('iPhone Adrian');
        $this->flushHeaders();

        $volby = $this->postJson('/api/webauthn/login/options', ['email' => 'adrian@vzpominky.test'])
            ->assertOk()
            ->assertJsonCount(1, 'allowCredentials');

        $odpoved = $this->postJson('/api/webauthn/login', $autentikator->prihlaseni($volby->json('challenge')))
            ->assertOk()
            ->assertJsonPath('user.id', $this->adri->id);

        $this->assertNotEmpty($odpoved->json('token'));

        // Token musí opravdu otevřít stav — jinak test potvrzuje jen návrat řetězce.
        $this->withHeader('Authorization', 'Bearer '.$odpoved->json('token'))
            ->getJson('/api/state')->assertOk();
    }

    /** Úspěšné přihlášení posune počítadlo podpisů, aby šlo poznat přehrání. */
    public function test_prihlaseni_posune_pocitadlo(): void
    {
        $autentikator = $this->zaregistruj();
        $this->flushHeaders();

        $pred = WebauthnCredential::sole()->sign_count;
        $this->prihlas($autentikator)->assertOk();

        $this->assertGreaterThan($pred, WebauthnCredential::sole()->fresh()->sign_count);
    }

    /**
     * Zkopírovaný klíč přehrává starší počítadlo — a neprojde.
     *
     * Tohle je jediná obrana proti klonu autentikátoru: podpis i challenge sedí,
     * poznat se to dá jen podle toho, že číslo podpisu nepřibývá.
     */
    public function test_klesajici_pocitadlo_neprojde(): void
    {
        $autentikator = $this->zaregistruj();
        $this->flushHeaders();

        $this->prihlas($autentikator, 9)->assertOk();
        $this->assertSame(9, WebauthnCredential::sole()->fresh()->sign_count);

        $this->prihlas($autentikator, 4)
            ->assertStatus(422)
            ->assertJsonPath('errors.response.0', 'Otisk se nepodařilo ověřit — zadejte kód.');

        $this->assertSame(9, WebauthnCredential::sole()->fresh()->sign_count, 'Neúspěšný pokus nesmí počítadlo srazit.');
    }

    /**
     * Podvržený podpis neprojde.
     *
     * Bez tohohle testu by všechny kladné testy prošly i tehdy, kdyby se podpis
     * vůbec neověřoval — stačilo by poslat správnou challenge. Odpověď je jinak
     * úplně v pořádku, změněný je jediný bajt podpisu.
     */
    public function test_podvrzeny_podpis_neprojde(): void
    {
        $autentikator = $this->zaregistruj();
        $this->flushHeaders();

        $volby = $this->postJson('/api/webauthn/login/options', ['email' => 'adrian@vzpominky.test'])->assertOk();
        $telo = $autentikator->prihlaseni($volby->json('challenge'));

        /*
         * Mění se **bajt podpisu**, ne znak base64.
         *
         * Poslední znak base64 nese jen část bitů posledního bajtu, takže jeho
         * změna se občas do dekódovaných dat vůbec nepromítne — test by pak
         * jednou za čas prošel s platným podpisem. Poslední bajt DER podpisu
         * je nejnižší bajt čísla s: nikdy to není značka ani délka.
         */
        $podpis = $this->zB64u($telo['response']['signature']);
        $podpis[-1] = chr(ord($podpis[-1]) ^ 0xFF);
        $telo['response']['signature'] = rtrim(strtr(base64_encode($podpis), '+/', '-_'), '=');

        $this->postJson('/api/webauthn/login', $telo)
            ->assertStatus(422)
            ->assertJsonMissingPath('token');
    }

    /** Jedno zařízení = jeden token, stejně jako u přihlášení heslem. */
    public function test_druhy_otisk_tehoz_zarizeni_zrusi_stary_token(): void
    {
        $autentikator = $this->zaregistruj('iPhone Adrian');
        $this->flushHeaders();

        $prvni = $this->prihlas($autentikator)->assertOk()->json('token');
        $this->prihlas($autentikator)->assertOk();

        $this->assertSame(1, $this->adri->tokens()->where('name', 'iPhone Adrian')->count());

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$prvni)->getJson('/api/state')->assertUnauthorized();
    }

    public function test_vyprsela_challenge_neprojde(): void
    {
        $autentikator = $this->zaregistruj();
        $this->flushHeaders();

        $volby = $this->postJson('/api/webauthn/login/options', ['email' => 'adrian@vzpominky.test'])->assertOk();

        $this->travel(config('galerie.webauthn_challenge_ttl') + 1)->seconds();

        $this->postJson('/api/webauthn/login', $autentikator->prihlaseni($volby->json('challenge')))
            ->assertStatus(422)
            ->assertJsonValidationErrors('challenge');
    }

    /** Challenge platí jednou. Druhý pokus s toutéž odpovědí končí. */
    public function test_challenge_jde_pouzit_jen_jednou(): void
    {
        $autentikator = $this->zaregistruj();
        $this->flushHeaders();

        $volby = $this->postJson('/api/webauthn/login/options', ['email' => 'adrian@vzpominky.test'])->assertOk();
        $telo = $autentikator->prihlaseni($volby->json('challenge'));

        $this->postJson('/api/webauthn/login', $telo)->assertOk();
        $this->postJson('/api/webauthn/login', $telo)->assertStatus(422);
    }

    /**
     * Neznámý e-mail vypadá stejně jako účet bez klíče.
     *
     * Rozdíl v odpovědi by prozradil, kdo v aplikaci je — u aplikace pro dva
     * lidi je to citlivější než jinde.
     */
    public function test_volby_prihlaseni_neprozradi_existenci_uctu(): void
    {
        $neznamy = $this->postJson('/api/webauthn/login/options', ['email' => 'nikdo@vzpominky.test'])->assertOk();
        $bezKlice = $this->postJson('/api/webauthn/login/options', ['email' => 'adrian@vzpominky.test'])->assertOk();

        $this->assertSame(
            array_keys($neznamy->json()),
            array_keys($bezKlice->json()),
            'Odlišný tvar odpovědi by prozradil, které e-maily v aplikaci existují.',
        );
        $this->assertSame([], $neznamy->json('allowCredentials'));
        $this->assertSame([], $bezKlice->json('allowCredentials'));
    }

    /** Podepsaná odpověď na neexistující účet nesmí vydat token. */
    public function test_otisk_na_neznamy_email_nevyda_token(): void
    {
        $autentikator = $this->zaregistruj();
        $this->flushHeaders();

        $volby = $this->postJson('/api/webauthn/login/options', ['email' => 'nikdo@vzpominky.test'])->assertOk();

        $this->postJson('/api/webauthn/login', $autentikator->prihlaseni($volby->json('challenge')))
            ->assertStatus(422)
            ->assertJsonMissingPath('token');
    }

    /** Cizí zařízení se správnou challenge, ale bez registrace, neprojde. */
    public function test_neregistrovany_klic_neprojde(): void
    {
        $this->zaregistruj();
        $this->flushHeaders();

        $cizi = new FalesnyAutentikator('localhost', 'http://localhost');

        $this->prihlas($cizi)->assertStatus(422);
    }

    /**
     * Odpověď z jiné domény neprojde.
     *
     * Tohle je obrana proti podvrženému webu: klíč sice existuje, ale zařízení
     * ho podepsalo pro cizí origin, a ten je součástí podepsaných dat.
     */
    public function test_odpoved_z_ciziho_originu_neprojde(): void
    {
        $volby = $this->prihlasenyTokenem()->postJson('/api/webauthn/register/options')->assertOk();
        $podvrh = new FalesnyAutentikator('localhost', 'https://vzpominky.podvod.test');

        $this->postJson('/api/webauthn/register', $podvrh->registrace($volby->json('challenge')))
            ->assertStatus(422)
            // Konkrétní hláška hlídá, že odpověď selhala na kontrole originu,
            // a ne dřív — třeba na tom, že se nedala přečíst.
            ->assertJsonPath('errors.response.0', 'Klíč se nepodařilo ověřit — zkuste to znovu.');

        $this->assertSame(0, WebauthnCredential::count());
    }

    /** Klíč patří účtu. Druhý člověk ho k přihlášení nepoužije. */
    public function test_klic_jednoho_cloveka_neprihlasi_druheho(): void
    {
        $autentikator = $this->zaregistruj();
        $this->flushHeaders();

        $makinka = User::factory()->create(['email' => 'makinka@vzpominky.test']);

        $volby = $this->postJson('/api/webauthn/login/options', ['email' => $makinka->email])->assertOk();

        $this->postJson('/api/webauthn/login', $autentikator->prihlaseni($volby->json('challenge')))
            ->assertStatus(422);
    }

    // ——— pomocné ———

    /** Přihlásí Adriho heslem a vrátí testovacího klienta s jeho tokenem. */
    private function prihlasenyTokenem(): static
    {
        $token = $this->postJson('/sanctum/token', [
            'email' => 'adrian@vzpominky.test',
            'password' => 'zadar2026',
            'device_name' => 'telefon',
        ])->json('token');

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    /** Projde celou registrací otisku a vrátí zařízení, které klíč drží. */
    private function zaregistruj(string $label = 'iPhone Adrian'): FalesnyAutentikator
    {
        $autentikator = new FalesnyAutentikator('localhost', 'http://localhost');

        $volby = $this->prihlasenyTokenem()->postJson('/api/webauthn/register/options')->assertOk();

        $this->postJson('/api/webauthn/register', $autentikator->registrace($volby->json('challenge'), $label))
            ->assertOk()
            ->assertJsonPath('registered', true);

        return $autentikator;
    }

    private function prihlas(FalesnyAutentikator $autentikator, ?int $pocitadlo = null)
    {
        $volby = $this->postJson('/api/webauthn/login/options', ['email' => 'adrian@vzpominky.test'])->assertOk();

        return $this->postJson('/api/webauthn/login', $autentikator->prihlaseni($volby->json('challenge'), $pocitadlo));
    }

    private function zB64u(string $hodnota): string
    {
        return (string) base64_decode(strtr($hodnota, '-_', '+/'), true);
    }
}
