<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Auth\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dvoufázové ověření — dvě drobnosti, které z něj dělaly méně, než slibuje.
 *
 * Obnovovací kódy vznikají velkými písmeny (`ABCDE-12345`) a porovnávaly se
 * přesně, takže opsaný malými písmeny neprošel. A kód z aplikace platí ±30 s:
 * bez paměti posledního přijatého kroku šel tentýž kód použít znovu — třeba
 * tím, kdo ho odkoukal přes rameno.
 */
class DruhyFaktorPresneTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private TotpService $totp;

    private string $tajemstvi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['email' => 'adrian@vzpominky.test', 'password' => Hash::make('zadar2026-heslo')]);
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$prostor->id => ['role' => 'owner']]);

        $this->totp = app(TotpService::class);
        $this->tajemstvi = $this->totp->generateSecret();
    }

    public function test_obnovovaci_kod_projde_i_malymi_pismeny_a_bez_pomlcky(): void
    {
        $kody = $this->totp->recoveryCodes(3);
        $this->zapni($kody);

        $this->prihlas(' '.strtolower($kody[0]).' ', 'prvni')->assertOk();
        $this->prihlas(str_replace('-', ' ', strtolower($kody[1])), 'druhe')->assertOk();
        $this->prihlas(str_replace('-', '', $kody[2]), 'treti')->assertOk();

        $this->assertSame([], (array) $this->adri->fresh()->two_factor_recovery_codes, 'Každý kód se spotřebuje.');

        // Spotřebovaný kód neprojde ani v jiném zápisu.
        $this->prihlas($kody[0], 'ctvrte')->assertStatus(422);
    }

    public function test_stejny_kod_z_aplikace_podruhe_neprojde(): void
    {
        $this->zapni();
        $kod = $this->totp->at($this->tajemstvi, intdiv(time(), 30));

        $this->prihlas($kod, 'prvni')->assertOk();
        $this->prihlas($kod, 'druhe')->assertStatus(422)->assertJsonPath('two_factor', true);

        $this->assertSame(1, $this->adri->tokens()->count());
    }

    /** Starší krok z okna ±30 s po novějším taky ne — jinak by se přehrával předchozí kód. */
    public function test_starsi_kod_po_novejsim_neprojde(): void
    {
        $this->zapni();
        $krok = intdiv(time(), 30);

        $this->prihlas($this->totp->at($this->tajemstvi, $krok), 'prvni')->assertOk();
        $this->prihlas($this->totp->at($this->tajemstvi, $krok - 1), 'druhe')->assertStatus(422);
    }

    /** Kód, kterým se ověření zapnulo, už k přihlášení nestačí. */
    public function test_kod_z_potvrzeni_se_neda_pouzit_k_prihlaseni(): void
    {
        Sanctum::actingAs($this->adri);

        $tajny = $this->postJson('/api/v1/ucet/2fa', ['current_password' => 'zadar2026-heslo'])->assertOk()->json('secret');
        $kod = $this->totp->at($tajny, intdiv(time(), 30));
        $this->postJson('/api/v1/ucet/2fa/potvrdit', ['code' => $kod])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->prihlas($kod, 'telefon')->assertStatus(422);
    }

    /** @param  list<string>  $obnovovaci */
    private function zapni(array $obnovovaci = []): void
    {
        $this->adri->forceFill([
            'two_factor_secret' => $this->tajemstvi,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(fn (string $k) => Hash::make($k), $obnovovaci),
        ])->save();
    }

    private function prihlas(string $kod, string $zarizeni)
    {
        return $this->postJson('/sanctum/token', [
            'email' => 'adrian@vzpominky.test',
            'password' => 'zadar2026-heslo',
            'device_name' => $zarizeni,
            'code' => $kod,
        ]);
    }
}
