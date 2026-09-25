<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Provoz\AdministraceZasahy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\Fixtures\Galerie\FalesnyAutentikator;
use Tests\TestCase;

/**
 * Otisky a „zapamatovat si mě" po odhlášení ostatních a po obnově hesla.
 *
 * Otisk (WebAuthn) vydává nový token z jakéhokoli uloženého klíče. Kdo si
 * jednou k účtu připojil vlastní otisk, dostal se po „Odhlásit ostatní" i po
 * obnově hesla hned zpátky. Stejně tak cookie „zapamatovat si mě" ze starého
 * rozhraní: `remember_token` se měnil jen při obnově hesla.
 */
class OtiskPoOdhlaseniTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'galerie.rp_id' => 'localhost',
            'galerie.rp_name' => 'Naše vzpomínky',
            'galerie.rp_origins' => ['http://localhost'],
        ]);

        $this->adri = User::factory()->create([
            'email' => 'adrian@vzpominky.test',
            'password' => Hash::make('zadar2026-heslo'),
            'remember_token' => 'stary-remember-token',
        ]);

        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);
    }

    public function test_obnova_hesla_smaze_vsechny_otisky(): void
    {
        $telefon = $this->adri->createToken('telefon');
        $this->otisk('otisk-telefonu', $telefon->accessToken->id);
        $this->otisk('otisk-bez-tokenu', null);

        $this->post('/reset-password', [
            'token' => Password::broker()->createToken($this->adri),
            'email' => 'adrian@vzpominky.test',
            'password' => 'nove-heslo-456',
            'password_confirmation' => 'nove-heslo-456',
        ])->assertRedirect('/?heslo=zmeneno');

        $this->assertSame(0, WebauthnCredential::where('user_id', $this->adri->id)->count(),
            'Otisk, který si k účtu připojil kdokoli jiný, musí obnovou hesla přestat platit.');
    }

    public function test_odhlaseni_ostatnich_necha_jen_otisk_tohoto_zarizeni(): void
    {
        $tady = $this->adri->createToken('telefon');
        $jinde = $this->adri->createToken('tablet');
        $this->otisk('otisk-tady', $tady->accessToken->id);
        $this->otisk('otisk-jinde', $jinde->accessToken->id);
        $this->otisk('otisk-bez-tokenu', null);

        $cizi = User::factory()->create();
        $ciziToken = $cizi->createToken('jeho telefon');
        $this->otisk('otisk-partnera', $ciziToken->accessToken->id, $cizi);

        $this->withHeader('Authorization', 'Bearer '.$tady->plainTextToken)
            ->postJson('/api/zamek/odhlasit-ostatni')
            ->assertOk();

        $this->assertSame(['otisk-tady'], WebauthnCredential::where('user_id', $this->adri->id)->pluck('credential_id')->all());
        $this->assertSame(1, WebauthnCredential::where('user_id', $cizi->id)->count(), 'Otisk partnera se nemění.');
    }

    public function test_odhlaseni_ostatnich_zmeni_remember_token(): void
    {
        $tady = $this->adri->createToken('telefon');

        $this->withHeader('Authorization', 'Bearer '.$tady->plainTextToken)
            ->postJson('/api/zamek/odhlasit-ostatni')
            ->assertOk();

        $this->assertNotSame('stary-remember-token', $this->adri->fresh()->remember_token,
            'Cookie „zapamatovat si mě" z jiného prohlížeče nesmí odhlášení přežít.');
    }

    /** Staré rozhraní ruší všechny klíče aplikace — a tím i všechny otisky. */
    public function test_odhlaseni_ostatnich_ve_starem_rozhrani_smaze_otisky_i_remember(): void
    {
        $telefon = $this->adri->createToken('telefon');
        $this->otisk('otisk-telefonu', $telefon->accessToken->id);

        $this->actingAs($this->adri)
            ->postJson('/settings/security/sessions/revoke-others')
            ->assertOk();

        $this->assertSame(0, WebauthnCredential::where('user_id', $this->adri->id)->count());
        $this->assertNotSame('stary-remember-token', $this->adri->fresh()->remember_token);
    }

    public function test_odebrany_pristup_smaze_otisky_i_remember(): void
    {
        $makinka = User::factory()->create(['remember_token' => 'jeji-remember']);
        $this->prostor->members()->syncWithoutDetaching([$makinka->id => ['role' => 'editor']]);
        $this->otisk('otisk-makinky', $makinka->createToken('telefon')->accessToken->id, $makinka);

        $this->assertTrue(app(AdministraceZasahy::class)->nastavPristup($this->prostor, $this->adri, $makinka->id, false));

        $this->assertSame(0, WebauthnCredential::where('user_id', $makinka->id)->count());
        $this->assertNotSame('jeji-remember', $makinka->fresh()->remember_token);
    }

    /** Registrace i přihlášení otiskem si pamatují token, ke kterému otisk patří. */
    public function test_otisk_patri_tokenu_ktery_ho_zaregistroval_a_pak_tomu_z_prihlaseni(): void
    {
        $autentikator = new FalesnyAutentikator('localhost', 'http://localhost');

        $telefon = $this->postJson('/sanctum/token', [
            'email' => 'adrian@vzpominky.test',
            'password' => 'zadar2026-heslo',
            'device_name' => 'telefon',
        ])->assertOk()->json('token');

        $volby = $this->withHeader('Authorization', 'Bearer '.$telefon)->postJson('/api/webauthn/register/options')->assertOk();
        $this->postJson('/api/webauthn/register', $autentikator->registrace($volby->json('challenge'), 'iPhone Adrian'))->assertOk();

        $idTelefonu = $this->adri->tokens()->where('name', 'telefon')->sole()->id;
        $this->assertSame($idTelefonu, (int) WebauthnCredential::sole()->personal_access_token_id);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $volby = $this->postJson('/api/webauthn/login/options', ['email' => 'adrian@vzpominky.test'])->assertOk();
        $novy = $this->postJson('/api/webauthn/login', $autentikator->prihlaseni($volby->json('challenge')))->assertOk()->json('token');

        $idNoveho = $this->adri->tokens()->where('name', 'iPhone Adrian')->sole()->id;
        $this->assertSame($idNoveho, (int) WebauthnCredential::sole()->fresh()->personal_access_token_id);

        // Zařízení přihlášené otiskem odhlásí ostatní — a svůj otisk si nechá.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$novy)->postJson('/api/zamek/odhlasit-ostatni')->assertOk();

        $this->assertSame(1, WebauthnCredential::count());
    }

    private function otisk(string $id, ?int $token, ?User $komu = null): void
    {
        DB::table('webauthn_credentials')->insert([
            'user_id' => ($komu ?? $this->adri)->id,
            'credential_id' => $id,
            'public_key' => Str::random(40),
            'sign_count' => 0,
            'transports' => json_encode(['internal']),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'label' => $id,
            'personal_access_token_id' => $token,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
