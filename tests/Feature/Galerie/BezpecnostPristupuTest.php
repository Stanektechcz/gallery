<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Auth\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kdo smí do galerie a s čím.
 *
 * Každý test tu hlídá díru, která v aplikaci opravdu byla: o vstupu rozhodovalo
 * jen heslo, klíč „jen čtení" zapisoval, host viděl deník a finance, tokeny
 * neměly platnost a soubor z knihovny se dal otevřít jako stránka.
 */
class BezpecnostPristupuTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['email' => 'adrian@vzpominky.test', 'password' => Hash::make('zadar2026')]);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$this->prostor->id => ['role' => 'owner']]);
    }

    /**
     * Uložená odpověď se nepodá požadavku s jiným tokenem.
     *
     * `Vary` měl jen `X-Inertia`: po odhlášení jednoho a přihlášení druhého
     * na témž počítači prohlížeč do půl minuty podával obsah toho prvního.
     */
    public function test_pamet_prohlizece_rozlisuje_token(): void
    {
        $token = $this->adri->createToken('telefon')->plainTextToken;

        foreach (['/api/data?skupiny=system', '/api/storage'] as $adresa) {
            $vary = implode(', ', $this->withToken($token)->getJson($adresa)->assertOk()->baseResponse->getVary());

            $this->assertStringContainsString('Authorization', $vary, $adresa);
            $this->assertStringContainsString('X-Inertia', $vary, $adresa);
        }
    }

    // ——— přihlášení ———

    /** Odebraný přístup se nedá obejít novým přihlášením. */
    public function test_ucet_s_odebranym_pristupem_token_nedostane(): void
    {
        $makinka = $this->clen('editor', ['is_active' => false]);

        $this->postJson('/sanctum/token', $this->udaje($makinka))
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Tenhle účet do galerie přístup nemá. Obnovit ho může vlastník galerie.');

        $this->assertSame(0, $makinka->tokens()->count());
    }

    /** Token vydaný před odebráním přístupu přestane fungovat hned. */
    public function test_stary_token_po_odebrani_pristupu_neprojde(): void
    {
        $makinka = $this->clen('editor');
        $token = $makinka->createToken('telefon')->plainTextToken;

        $makinka->forceFill(['is_active' => false])->save();

        $this->withToken($token)->getJson('/api/state')->assertForbidden();
    }

    public function test_host_token_nedostane_a_do_api_nesmi(): void
    {
        $host = $this->clen('viewer');

        $this->postJson('/sanctum/token', $this->udaje($host))
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Tenhle účet je host galerie — vidí jen odkazy, které mu někdo pošle.');

        $token = $host->createToken('telefon')->plainTextToken;
        $this->withToken($token)->getJson('/api/data/denik')->assertForbidden();
        $this->withToken($token)->patchJson('/api/state', ['data' => ['x' => 1]])->assertForbidden();
    }

    /** Vlastník projde, i kdyby mu v členství zůstala výchozí role sloupce. */
    public function test_vlastnik_s_vychozi_roli_clenstvi_projde(): void
    {
        $this->prostor->members()->updateExistingPivot($this->adri->id, ['role' => 'viewer']);

        $this->postJson('/sanctum/token', $this->udaje($this->adri))->assertOk();
    }

    /** Zapnuté dvoufázové ověření platí i pro přihlášení aplikace. */
    public function test_dvoufazove_overeni_plati_i_pro_token(): void
    {
        $totp = app(TotpService::class);
        $tajemstvi = $totp->generateSecret();
        $this->adri->forceFill([
            'two_factor_secret' => $tajemstvi,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [Hash::make('OBNOVA-1234')],
        ])->save();

        $this->postJson('/sanctum/token', $this->udaje($this->adri))
            ->assertStatus(422)
            ->assertJsonPath('two_factor', true);
        $this->assertSame(0, $this->adri->tokens()->count(), 'Samotné heslo token vydat nesmí.');

        $this->postJson('/sanctum/token', $this->udaje($this->adri) + ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('two_factor', true);

        $kod = $totp->at($tajemstvi, intdiv(time(), 30));
        $this->postJson('/sanctum/token', $this->udaje($this->adri) + ['code' => $kod])->assertOk();

        // Obnovovací kód projde jednou, podruhé už ne.
        $this->postJson('/sanctum/token', $this->udaje($this->adri, 'druhé') + ['code' => 'OBNOVA-1234'])->assertOk();
        $this->postJson('/sanctum/token', $this->udaje($this->adri, 'třetí') + ['code' => 'OBNOVA-1234'])->assertStatus(422);
    }

    public function test_neuspesne_prihlaseni_se_zapise_do_protokolu(): void
    {
        $this->postJson('/sanctum/token', ['email' => 'adrian@vzpominky.test', 'password' => 'vedle', 'device_name' => 'telefon'])
            ->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login.failed', 'subject_id' => $this->adri->id]);
    }

    // ——— klíče ———

    /** Klíč „jen čtení" z administrace čte, ale nezapíše. */
    public function test_klic_jen_pro_cteni_nezapisuje(): void
    {
        $klic = $this->adri->createToken('záloha', ['read'])->plainTextToken;

        $this->withToken($klic)->getJson('/api/state')->assertOk();
        $this->withToken($klic)->patchJson('/api/state', ['data' => ['x' => 1]])
            ->assertForbidden()
            ->assertJsonPath('message', 'Tenhle klíč k API je jen pro čtení.');
        $this->withToken($klic)->postJson('/api/media/do-kose', ['ids' => ['cokoli']])->assertForbidden();
    }

    /** Klíč nepoužitý déle než 90 dní přestane platit; používaný platí dál. */
    public function test_necinny_token_prestane_platit(): void
    {
        $stary = $this->adri->createToken('ztracený telefon');
        $stary->accessToken->forceFill(['last_used_at' => now()->subDays(91), 'created_at' => now()->subYear()])->save();

        $cerstvy = $this->adri->createToken('telefon');
        $cerstvy->accessToken->forceFill(['last_used_at' => now()->subDays(10), 'created_at' => now()->subYear()])->save();

        $this->withToken($stary->plainTextToken)->getJson('/api/state')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($cerstvy->plainTextToken)->getJson('/api/state')->assertOk();
    }

    // ——— stav ———

    public function test_prilis_velky_zapis_stavu_neprojde(): void
    {
        $token = $this->adri->createToken('telefon')->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/state', ['data' => ['balast' => str_repeat('x', 1_100_000)]])
            ->assertStatus(413);
    }

    /** Celý společný stav smaže jen vlastník. */
    public function test_smazat_stav_smi_jen_vlastnik(): void
    {
        $makinka = $this->clen('editor');

        $this->withToken($makinka->createToken('telefon')->plainTextToken)->deleteJson('/api/state')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->adri->createToken('telefon')->plainTextToken)->deleteJson('/api/state')->assertOk();
    }

    // ——— soubory ———

    /** HTML převlečené za RAW z fotoaparátu se do knihovny nedostane. */
    public function test_html_s_priponou_raw_neprojde(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();

        $this->withToken($this->adri->createToken('telefon')->plainTextToken)
            ->post('/api/media', ['file' => UploadedFile::fake()->createWithContent(
                'IMG_0001.cr2',
                '<!doctype html><html><body><script>alert(localStorage.getItem("galerie.token"))</script></body></html>',
            )], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, MediaItem::withoutGlobalScopes()->count());
    }

    /** Originál jde s CSP `sandbox` — ani propašovaný skript by se nespustil. */
    public function test_original_ma_hlavicky_bez_skriptu(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();

        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xDB\x00\x43\x00".str_repeat("\x08", 64)."\xFF\xD9";
        $token = $this->adri->createToken('telefon')->plainTextToken;

        $id = $this->withToken($token)
            ->post('/api/media', ['file' => UploadedFile::fake()->createWithContent('vylet.jpg', $jpeg)], ['Accept' => 'application/json'])
            ->assertCreated()->json('id');

        $odpoved = $this->withToken($token)->get('/api/media/'.$id.'/raw')->assertOk();

        $this->assertStringContainsString('sandbox', (string) $odpoved->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $odpoved->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('image/', (string) $odpoved->headers->get('Content-Type'));
    }

    // ——— limity ———

    /**
     * Nahrávání nevyčerpá limit zbytku aplikace.
     *
     * Skupina médií (600/min) a zbytek API (120/min) sdílely počítadlo, takže
     * video nahrané po 130 částech shodilo stav a data na 429.
     */
    public function test_nahravani_nevycerpa_limit_aplikace(): void
    {
        $token = $this->adri->createToken('telefon')->plainTextToken;

        for ($i = 0; $i < 125; $i++) {
            $this->withToken($token)->deleteJson('/api/media/neexistuje-'.$i);
        }

        $this->withToken($token)->getJson('/api/state')->assertOk();
    }

    // ——— mapa ———

    /**
     * `mapa.html` nevěří cizím zprávám a jméno místa nevkládá jako HTML.
     *
     * Cizí stránka ji mohla otevřít přes `window.open`, poslat body se jménem
     * `<img onerror=…>` a spustit skript na adrese galerie.
     */
    public function test_mapa_prijima_zpravy_jen_od_galerie(): void
    {
        $mapa = (string) file_get_contents(public_path('mapa.html'));

        $this->assertStringContainsString('e.origin !== location.origin', $mapa);
        $this->assertStringNotContainsString('bindTooltip(p.name', $mapa);
    }

    // ——— migrace ———

    /** Druhý z dvojice s výchozí rolí `viewer` dostane správce, třetí člen ne. */
    public function test_partner_s_vychozi_roli_je_po_migraci_spravce(): void
    {
        $makinka = User::factory()->create();
        DB::table('gallery_space_user')->insert(['gallery_space_id' => $this->prostor->id, 'user_id' => $makinka->id, 'created_at' => now(), 'updated_at' => now()]);

        (require database_path('migrations/2026_09_13_140000_partner_dvojice_neni_host.php'))->up();

        $this->assertSame('editor', DB::table('gallery_space_user')->where('user_id', $makinka->id)->value('role'));

        $host = $this->clen('viewer');
        DB::table('gallery_space_user')->where('user_id', $makinka->id)->update(['role' => 'viewer']);

        (require database_path('migrations/2026_09_13_140000_partner_dvojice_neni_host.php'))->up();

        $this->assertSame('viewer', DB::table('gallery_space_user')->where('user_id', $makinka->id)->value('role'), 'U tří členů o roli rozhodl člověk.');
        $this->assertSame('viewer', DB::table('gallery_space_user')->where('user_id', $host->id)->value('role'));
    }

    /**
     * Host v jedné galerii a vlastník vlastní se do cizí nedostane.
     *
     * O roli rozhodoval `PristupDoGalerie::proc()` nad neseřazeným
     * `gallerySpaces()->first()`, kdežto požadavek pak běžel v prostoru
     * z `UrcujePar::parId()` — tedy ve výchozím, jinak nejstarším. Kdo byl
     * v jedné galerii host a ve své vlastní vlastník, prošel kontrolou podle
     * té svojí a sáhl si na fotky, stav i administraci té cizí.
     */
    public function test_host_s_vlastni_galerii_se_do_cizi_nedostane(): void
    {
        $host = $this->clen('viewer');
        $vlastni = GallerySpace::create(['name' => 'Moje vlastní', 'owner_id' => $host->id, 'is_default' => true]);
        $vlastni->members()->syncWithoutDetaching([$host->id => ['role' => 'owner']]);
        // Cizí galerie je výchozí a starší — tedy ta, kterou vybere `parId()`.
        DB::table('gallery_spaces')->where('id', $this->prostor->id)->update(['is_default' => true]);

        $token = $this->postJson('/api/sanctum/token', $this->udaje($host))->json('token');

        $this->assertNull($token, 'Host s vlastní galerií nesmí dostat token do cizí.');

        Sanctum::actingAs($host);

        foreach (['/api/state', '/api/admin', '/api/data/knihovna'] as $cesta) {
            $this->getJson($cesta)->assertForbidden();
        }

        $this->postJson('/api/kos/vyprazdnit')->assertForbidden();
    }

    /**
     * Přehled administrace (e-maily, klíče, protokol) není pro každého člena.
     *
     * Jediná metoda administrace, které chyběla kontrola — a přitom má
     * nejcitlivější odpověď.
     */
    public function test_prehled_administrace_jen_pro_spravce(): void
    {
        $host = $this->clen('viewer');

        Sanctum::actingAs($host);
        $this->getJson('/api/admin')->assertForbidden();

        Sanctum::actingAs($this->adri);
        $this->getJson('/api/admin')->assertOk();
    }

    // ——— pomocné ———

    private function clen(string $role, array $atributy = []): User
    {
        $user = User::factory()->create(['password' => Hash::make('heslo-heslo')] + $atributy);
        $this->prostor->members()->syncWithoutDetaching([$user->id => ['role' => $role]]);

        return $user;
    }

    private function udaje(User $user, string $zarizeni = 'telefon'): array
    {
        return [
            'email' => $user->email,
            'password' => $user->id === $this->adri->id ? 'zadar2026' : 'heslo-heslo',
            'device_name' => $zarizeni,
        ];
    }
}
