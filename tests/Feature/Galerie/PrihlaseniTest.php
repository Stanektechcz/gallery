<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Přihlášení e-mailem a heslem → osobní token.
 *
 * Kód aplikace (šestimístný PIN) je druhý faktor **na zařízení**, ne autentizace
 * proti serveru — server rozhoduje jen o tomhle kroku. Testy proto hlídají, že
 * endpoint nic neprozradí a že jedno zařízení drží právě jeden token.
 */
class PrihlaseniTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create([
            'email' => 'adrian@vzpominky.test',
            'password' => Hash::make('zadar2026'),
        ]);

        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$prostor->id => ['role' => 'owner']]);
    }

    public function test_spravne_udaje_vrati_token(): void
    {
        $odpoved = $this->postJson('/sanctum/token', [
            'email' => 'adrian@vzpominky.test',
            'password' => 'zadar2026',
            'device_name' => 'telefon',
        ])->assertOk()->assertJsonPath('user.email', 'adrian@vzpominky.test');

        $this->assertNotEmpty($odpoved->json('token'));
        $this->assertSame(1, $this->adri->tokens()->count());
    }

    /**
     * Token opravdu otevře stav — jinak by test potvrzoval jen to, že se vrátil řetězec.
     */
    public function test_tokenem_jde_cist_stav(): void
    {
        $token = $this->postJson('/sanctum/token', [
            'email' => 'adrian@vzpominky.test', 'password' => 'zadar2026', 'device_name' => 'telefon',
        ])->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/state')->assertOk()->assertJsonPath('rev', 0);
    }

    /**
     * Neznámý e-mail a špatné heslo dávají tutéž hlášku.
     *
     * Rozdíl by prozradil, kdo v aplikaci je — a to je u aplikace pro dva lidi
     * podstatnější než kdekoli jinde.
     */
    public function test_neznamy_email_a_spatne_heslo_hlasi_totez(): void
    {
        $spatneHeslo = $this->postJson('/sanctum/token', [
            'email' => 'adrian@vzpominky.test', 'password' => 'vedle', 'device_name' => 'telefon',
        ])->assertStatus(422);

        $neznamy = $this->postJson('/sanctum/token', [
            'email' => 'nikdo@vzpominky.test', 'password' => 'zadar2026', 'device_name' => 'telefon',
        ])->assertStatus(422);

        $this->assertSame(
            $spatneHeslo->json('errors.email'),
            $neznamy->json('errors.email'),
            'Odlišná hláška by prozradila, které e-maily v aplikaci existují.',
        );
    }

    /** Jedno zařízení = jeden token. Nové přihlášení to staré zneplatní. */
    public function test_nove_prihlaseni_zrusi_stary_token_tehoz_zarizeni(): void
    {
        $prvni = $this->postJson('/sanctum/token', [
            'email' => 'adrian@vzpominky.test', 'password' => 'zadar2026', 'device_name' => 'telefon',
        ])->json('token');

        $this->postJson('/sanctum/token', [
            'email' => 'adrian@vzpominky.test', 'password' => 'zadar2026', 'device_name' => 'telefon',
        ])->assertOk();

        $this->assertSame(1, $this->adri->tokens()->count(), 'Staré tokeny téhož zařízení nemají zůstávat.');

        $this->withHeader('Authorization', 'Bearer '.$prvni)
            ->getJson('/api/state')->assertUnauthorized();
    }

    /** Dvě různá zařízení mají tokeny vedle sebe — telefon nevyhodí tablet. */
    public function test_ruzna_zarizeni_maji_vlastni_tokeny(): void
    {
        foreach (['telefon', 'tablet'] as $zarizeni) {
            $this->postJson('/sanctum/token', [
                'email' => 'adrian@vzpominky.test', 'password' => 'zadar2026', 'device_name' => $zarizeni,
            ])->assertOk();
        }

        $this->assertSame(2, $this->adri->tokens()->count());
    }

    public function test_odhlaseni_token_zneplatni(): void
    {
        $token = $this->postJson('/sanctum/token', [
            'email' => 'adrian@vzpominky.test', 'password' => 'zadar2026', 'device_name' => 'telefon',
        ])->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/logout')->assertOk()->assertJsonPath('status', 'signed-out');

        $this->assertSame(0, $this->adri->tokens()->count(), 'Token má z databáze zmizet.');

        // Testovací klient drží už jednou vyřešeného uživatele v paměti procesu;
        // skutečný požadavek přichází do čistého. Bez tohohle řádku by test
        // potvrzoval chování testovacího nástroje, ne aplikace.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/state')->assertUnauthorized();
    }

    public function test_chybejici_udaje_neprojdou(): void
    {
        $this->postJson('/sanctum/token', ['email' => 'adrian@vzpominky.test'])->assertStatus(422);
    }
}
