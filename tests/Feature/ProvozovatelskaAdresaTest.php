<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Provoz\AdministraceZasahy;
use App\Support\Provozovatel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Adresa provozovatele se nedá zabrat.
 *
 * Provozovatele celé instalace pozná `User::isOperator()` jen podle e-mailu
 * z `gallery.operator_emails`. Že adresa opravdu patří tomu, kdo ji zadal,
 * nikdo neověřuje: změna v profilu chce jen vlastní heslo, registrace
 * a pozvánka vezmou jakoukoli adresu. Dokud žádný účet provozovatelskou
 * adresu nedrží, stačilo si ji nastavit — a `/admin` s účty všech, klíči
 * integrací a úlohami byl otevřený. SQLite navíc `unique` porovnává podle
 * velikosti písmen, takže `OP@…` prošlo i vedle existujícího `op@…`.
 */
class ProvozovatelskaAdresaTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();
        config(['gallery.operator_emails' => 'op@example.cz, druhy-op@example.cz']);

        $this->adri = User::factory()->create([
            'email' => 'adrian@vzpominky.test',
            'role' => 'owner',
            'is_active' => true,
            'password' => Hash::make('adrianovo-heslo'),
        ]);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);
    }

    public function test_adresa_provozovatele_se_pozna_bez_ohledu_na_velikost_a_mezery(): void
    {
        $this->assertTrue(Provozovatel::jeAdresa('op@example.cz'));
        $this->assertTrue(Provozovatel::jeAdresa('  OP@Example.CZ '));
        $this->assertTrue(Provozovatel::jeAdresa('Druhy-Op@example.cz'));
        $this->assertFalse(Provozovatel::jeAdresa('adrian@vzpominky.test'));
        $this->assertFalse(Provozovatel::jeAdresa(''));
        $this->assertFalse(Provozovatel::jeAdresa(null));
    }

    public function test_zmena_adresy_v_profilu_na_provozovatelskou_neprojde(): void
    {
        Sanctum::actingAs($this->adri);

        $this->patchJson('/api/v1/profil', [
            'name' => 'Adrian',
            'email' => 'OP@example.cz',
            'current_password' => 'adrianovo-heslo',
        ])->assertStatus(422)->assertJsonPath('errors.email.0', 'Tuhle adresu tu použít nejde.');

        $this->adri->refresh();
        $this->assertSame('adrian@vzpominky.test', $this->adri->email);
        $this->assertFalse($this->adri->isOperator());
    }

    /** I vedle existujícího provozovatele: SQLite `unique` by `OP@` a `op@` nerozlišil. */
    public function test_zmena_adresy_neprojde_ani_vedle_existujiciho_provozovatele(): void
    {
        User::factory()->create(['email' => 'op@example.cz']);
        Sanctum::actingAs($this->adri);

        $this->patchJson('/api/v1/profil', [
            'name' => 'Adrian',
            'email' => 'OP@EXAMPLE.CZ',
            'current_password' => 'adrianovo-heslo',
        ])->assertStatus(422);

        $this->assertFalse($this->adri->fresh()->isOperator());
    }

    public function test_provozovatel_si_vlastni_adresu_zmenit_smi(): void
    {
        $provoz = User::factory()->create([
            'email' => 'op@example.cz',
            'password' => Hash::make('provozni-heslo'),
        ]);
        Sanctum::actingAs($provoz);

        $this->patchJson('/api/v1/profil', [
            'name' => 'Provoz',
            'email' => 'druhy-op@example.cz',
            'current_password' => 'provozni-heslo',
        ])->assertOk();

        $this->assertSame('druhy-op@example.cz', $provoz->fresh()->email);
        $this->assertTrue($provoz->fresh()->isOperator());
    }

    public function test_registrace_na_provozovatelskou_adresu_neprojde(): void
    {
        config(['gallery.registration_open' => true]);

        $this->postJson('/registrace', [
            'name' => 'Útočník',
            'email' => 'Op@Example.cz',
            'space_name' => 'Moje',
            'password' => 'tajneheslo1',
            'password_confirmation' => 'tajneheslo1',
        ])->assertStatus(422)->assertJsonPath('errors.email.0', 'Tuhle adresu tu použít nejde.');

        $this->assertFalse(User::whereRaw('lower(email) = ?', ['op@example.cz'])->exists());
        $this->assertGuest();
    }

    public function test_pozvanka_z_galerie_na_provozovatelskou_adresu_neprojde(): void
    {
        Sanctum::actingAs($this->adri);

        $odpoved = $this->postJson('/api/admin/users', ['email' => 'OP@example.cz'])->assertStatus(422);

        $this->assertNull($odpoved->json('invite_url'));
        $this->assertFalse(User::whereRaw('lower(email) = ?', ['op@example.cz'])->exists());
    }

    /** Stojí to ve službě, ne jen v kontroleru — zvát umí i stavová cesta (`AdminVeStavu`). */
    public function test_sluzba_pozvanky_provozovatelskou_adresu_nezalozi(): void
    {
        $vysledek = app(AdministraceZasahy::class)->pozvi($this->prostor, $this->adri, ' op@EXAMPLE.cz ');

        $this->assertNull($vysledek);
        $this->assertFalse(User::whereRaw('lower(email) = ?', ['op@example.cz'])->exists());
    }

    public function test_pozvanka_ze_stareho_rozhrani_na_provozovatelskou_adresu_neprojde(): void
    {
        $this->actingAs($this->adri);

        $this->postJson('/admin/users/invite', [
            'name' => 'Útočník',
            'email' => 'OP@example.cz',
            'role' => 'admin',
        ])->assertStatus(422)->assertJsonPath('errors.email.0', 'Tuhle adresu tu použít nejde.');

        $this->assertFalse(User::whereRaw('lower(email) = ?', ['op@example.cz'])->exists());
    }
}
