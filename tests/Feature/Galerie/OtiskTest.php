<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use App\Models\WebauthnCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            ->postJson('/api/webauthn/register/options', ['heslo' => 'zadar2026'])
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

    /**
     * Otisk nahrazuje kód zámku, takže se zapíná tímtéž kódem.
     *
     * Registrace chtěla jen token. Obrazovka zámku pak po úspěšném
     * `navigator.credentials.create()` sama odemkla — a to projde každý, kdo
     * zná PIN zařízení (Windows Hello na společném počítači, PIN telefonu),
     * i když kód zámku nezná a server jeho pokusy zrovna blokuje.
     */
    public function test_s_kodem_zamku_chce_registrace_ten_kod(): void
    {
        $this->nastavKod('240613');
        $klient = $this->prihlasenyTokenem();

        $klient->postJson('/api/webauthn/register/options')
            ->assertStatus(422)
            ->assertJsonStructure(['chyba']);
        $this->assertFalse(Cache::has('galerie:webauthn:reg:'.$this->adri->id),
            'Bez důkazu nesmí vzniknout challenge — s ní by registrace prošla.');

        // Heslo nestačí, když kód existuje: byl by to druhý, slabší zámek.
        $klient->postJson('/api/webauthn/register/options', ['heslo' => 'zadar2026'])->assertStatus(422);

        $odpoved = $klient->postJson('/api/webauthn/register/options', ['kod' => '240613'])->assertOk();
        $this->assertNotEmpty($odpoved->json('challenge'));
    }

    /** Bez challenge z úspěšných voleb registrace neprojde ani s podepsanou odpovědí. */
    public function test_bez_dukazu_registrace_neulozi_klic(): void
    {
        $this->nastavKod('240613');
        $autentikator = new FalesnyAutentikator('localhost', 'http://localhost');

        $this->prihlasenyTokenem()->postJson('/api/webauthn/register/options', ['kod' => '999999'])
            ->assertStatus(422)
            ->assertJsonMissingPath('challenge');

        // Challenge, kterou si klient vymyslí sám — jako dřív `bioAsk` při selhání voleb.
        $vlastni = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->postJson('/api/webauthn/register', $autentikator->registrace($vlastni, 'podvrh'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('challenge');

        $this->assertSame(0, WebauthnCredential::count());
    }

    /**
     * Hádání kódu přes registraci otisku počítá týž `PokusyOvereni` jako odemykání.
     *
     * Jinak by se tudy dal hádat kód zámku bez omezení, a to i ve chvíli,
     * kdy ho obrazovka zámku kvůli třem chybám blokuje.
     */
    public function test_hadani_kodu_pri_registraci_otisku_se_zablokuje(): void
    {
        $this->nastavKod('240613');
        $klient = $this->prihlasenyTokenem();

        $klient->postJson('/api/webauthn/register/options', ['kod' => '111111'])->assertStatus(422);
        $klient->postJson('/api/webauthn/register/options', ['kod' => '222222'])->assertStatus(422);
        $klient->postJson('/api/webauthn/register/options', ['kod' => '333333'])->assertStatus(429);

        // Ani se správným kódem to během uzavření neprojde…
        $klient->postJson('/api/webauthn/register/options', ['kod' => '240613'])
            ->assertStatus(429)
            ->assertJsonPath('chyba', fn (string $chyba) => str_starts_with($chyba, 'Přístup je uzavřený.'));
        $this->assertFalse(Cache::has('galerie:webauthn:reg:'.$this->adri->id));

        // …a uzavření platí i pro obrazovku zámku — je to jedno počítadlo.
        $klient->postJson('/api/zamek/overit', ['kod' => '240613'])->assertStatus(429);

        $this->assertSame(3, DB::table('audit_logs')->where('action', 'webauthn.register.failed')->count(),
            'Každý neúspěšný pokus musí být v protokolu.');
    }

    /** Správný kód vynuluje počítadlo, stejně jako u odemykání. */
    public function test_spravny_kod_pri_registraci_vynuluje_pokusy(): void
    {
        $this->nastavKod('240613');
        $klient = $this->prihlasenyTokenem();

        $klient->postJson('/api/webauthn/register/options', ['kod' => '111111'])->assertStatus(422);
        $klient->postJson('/api/webauthn/register/options', ['kod' => '222222'])->assertStatus(422);
        $klient->postJson('/api/webauthn/register/options', ['kod' => '240613'])->assertOk();

        // Po vynulování zase tři pokusy, ne jeden.
        $klient->postJson('/api/webauthn/register/options', ['kod' => '111111'])->assertStatus(422);
    }

    /** Kdo kód zámku ještě nemá, prokáže se heslem — stejně jako při jeho nastavení. */
    public function test_bez_kodu_zamku_chce_registrace_heslo(): void
    {
        $klient = $this->prihlasenyTokenem();

        $klient->postJson('/api/webauthn/register/options')->assertStatus(422);
        $klient->postJson('/api/webauthn/register/options', ['heslo' => 'spatne-heslo'])
            ->assertStatus(422)
            ->assertJsonPath('chyba', 'Heslo do galerie nesouhlasí.');
        $this->assertFalse(Cache::has('galerie:webauthn:reg:'.$this->adri->id));

        $klient->postJson('/api/webauthn/register/options', ['heslo' => 'zadar2026'])->assertOk();
        $this->assertTrue(Cache::has('galerie:webauthn:reg:'.$this->adri->id));
    }

    /** Klíč, který zařízení už jednou poslalo, nezaloží druhý řádek. */
    public function test_opakovana_registrace_tehoz_klice_neprida_radek(): void
    {
        $autentikator = $this->zaregistruj('iPhone Adrian');

        $volby = $this->prihlasenyTokenem()->postJson('/api/webauthn/register/options', ['heslo' => 'zadar2026'])->assertOk();
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

    /** Token z otisku má stejnou klouzavou platnost jako přihlášení heslem. */
    public function test_token_z_otisku_ma_platnost(): void
    {
        $autentikator = $this->zaregistruj('iPhone Adrian');
        $this->flushHeaders();
        $this->freezeTime();

        $this->prihlas($autentikator)->assertOk();

        $token = $this->adri->tokens()->where('name', 'iPhone Adrian')->sole();
        $this->assertSame(now()->addDays(60)->getTimestamp(), $token->expires_at?->getTimestamp());
    }

    /**
     * Úklid prošlých tokenů otisk nesmaže.
     *
     * Otisk je na token vázaný jen číslem (`personal_access_token_id`, bez cizího
     * klíče). Když `gallery:uklid-prihlaseni` smaže přihlášení zařízení, které dva
     * měsíce nikdo neotevřel, otisk v telefonu dál přihlásí — přihlášení ho
     * hledá podle účtu a klíče, ne podle tokenu — a naváže se na nový token.
     */
    public function test_otisk_prezije_uklid_proslych_tokenu(): void
    {
        $autentikator = $this->zaregistruj('iPhone Adrian');
        $this->flushHeaders();
        $this->prihlas($autentikator)->assertOk();
        $stary = $this->adri->tokens()->where('name', 'iPhone Adrian')->sole()->id;

        $this->travel(63)->days();
        $this->artisan('gallery:uklid-prihlaseni')->assertSuccessful();

        $this->assertFalse($this->adri->tokens()->whereKey($stary)->exists(), 'Prošlý token měl úklid smazat.');
        $this->assertSame(1, WebauthnCredential::where('user_id', $this->adri->id)->count());

        $novy = $this->prihlas($autentikator)->assertOk()->json('token');

        $this->withHeader('Authorization', 'Bearer '.$novy)->getJson('/api/state')->assertOk();
        $this->assertSame(
            $this->adri->tokens()->where('name', 'iPhone Adrian')->sole()->id,
            WebauthnCredential::where('user_id', $this->adri->id)->sole()->personal_access_token_id,
        );
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
        $volby = $this->prihlasenyTokenem()->postJson('/api/webauthn/register/options', ['heslo' => 'zadar2026'])->assertOk();
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

    /**
     * Cizí účet si nepřivlastní klíč, který už někomu patří.
     *
     * Identifikátor klíče vydají volby přihlášení každému, kdo zná e-mail,
     * a softwarový autentikátor si identifikátor zvolí sám. Registrace
     * (`updateOrCreate` podle identifikátoru) pak řádek předala druhému účtu
     * i s jeho veřejným klíčem — původní majitel se otiskem už nepřihlásil.
     */
    public function test_ciziho_klice_se_registrace_nezmocni(): void
    {
        $autentikator = $this->zaregistruj();
        $this->flushHeaders();
        // Strážce si mezi požadavky testu pamatuje ověřeného uživatele — bez
        // tohohle by další požadavek s cizím tokenem běžel dál jako Adri.
        $this->app['auth']->forgetGuards();

        $cizi = User::factory()->create(['email' => 'cizi@vzpominky.test', 'password' => Hash::make('jinde2026')]);
        $jinde = GallerySpace::create(['name' => 'Jinde', 'owner_id' => $cizi->id]);
        $cizi->gallerySpaces()->syncWithoutDetaching([$jinde->id => ['role' => 'owner']]);
        $token = $this->postJson('/sanctum/token', [
            'email' => $cizi->email, 'password' => 'jinde2026', 'device_name' => 'telefon',
        ])->json('token');

        $volby = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/webauthn/register/options', ['heslo' => 'jinde2026'])->assertOk();
        $this->postJson('/api/webauthn/register', $autentikator->registrace($volby->json('challenge'), 'podvrh'))
            ->assertStatus(422);

        $this->assertSame($this->adri->id, WebauthnCredential::sole()->user_id, 'Klíč přešel na cizí účet.');
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->prihlas($autentikator)->assertOk();
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

        // Adri kód zámku nemá, takže se prokazuje heslem (`PotvrzeniZamkem`).
        $volby = $this->prihlasenyTokenem()->postJson('/api/webauthn/register/options', ['heslo' => 'zadar2026'])->assertOk();

        $this->postJson('/api/webauthn/register', $autentikator->registrace($volby->json('challenge'), $label))
            ->assertOk()
            ->assertJsonPath('registered', true);

        return $autentikator;
    }

    /** Kód zámku rovnou do účtu — průchod `/api/zamek` tu netestujeme. */
    private function nastavKod(string $kod): void
    {
        $this->adri->forceFill(['app_lock_pin' => $kod, 'app_lock_set_at' => now()])->save();
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
