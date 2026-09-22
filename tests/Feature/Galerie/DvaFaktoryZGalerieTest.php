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
 * Dvoufázové přihlášení z nastavení galerie.
 *
 * Řádek „Dvoufázové přihlášení" ukazoval jen „vypnuto" a zapnout se dalo
 * jedině ve starém rozhraní. Teď je to tlačítko, které projde
 * `/api/v1/ucet/2fa` — heslo, klíč do aplikace, kód, záchranné kódy.
 */
class DvaFaktoryZGalerieTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian', 'password' => Hash::make('spravne-heslo-123')]);
        $maki = User::factory()->create(['name' => 'Makinka']);
        GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id])
            ->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner'], $maki->id => ['role' => 'editor']]);

        Sanctum::actingAs($this->adri);
    }

    public function test_zapnuti_a_vypnuti_celou_cestou(): void
    {
        $this->postJson('/api/v1/ucet/2fa', ['current_password' => 'spatne'])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $tajny = $this->postJson('/api/v1/ucet/2fa', ['current_password' => 'spravne-heslo-123'])->assertOk()->json('secret');
        $this->assertNotEmpty($tajny);
        $this->assertNull($this->adri->fresh()->two_factor_confirmed_at, 'Před potvrzením kódem se nic nezapíná.');

        $this->postJson('/api/v1/ucet/2fa/potvrdit', ['code' => '000000'])->assertUnprocessable();

        $totp = app(TotpService::class);
        $kod = $totp->at($tajny, intdiv(time(), 30));
        $kody = $this->postJson('/api/v1/ucet/2fa/potvrdit', ['code' => $kod])->assertOk()->json('recovery_codes');
        $this->assertCount(8, $kody);
        $this->assertNotNull($this->adri->fresh()->two_factor_confirmed_at);

        // Nastavení to teď řekne a nabídne opak.
        $radek = collect($this->getJson('/api/data/system')->json('data.SETROWS.profil.rows'))->keyBy(0)['Dvoufázové přihlášení'];
        $this->assertSame('Vypnout ověření', $radek[2]);
        $this->assertStringStartsWith('Zapnuto', $radek[1]);

        // Heslo jde v těle požadavku, ne v adrese.
        $this->deleteJson('/api/v1/ucet/2fa', ['current_password' => 'spatne'])->assertUnprocessable();
        $this->deleteJson('/api/v1/ucet/2fa', ['current_password' => 'spravne-heslo-123'])->assertOk();
        $this->assertNull($this->adri->fresh()->two_factor_confirmed_at);
        $this->assertNull($this->adri->fresh()->two_factor_secret);
    }

    /** Heslo se nedá přes přihlášené sezení zkoušet donekonečna. */
    public function test_pokusy_o_heslo_maji_strop(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/ucet/2fa', ['current_password' => 'spatne-'.$i])->assertUnprocessable();
        }

        $this->postJson('/api/v1/ucet/2fa', ['current_password' => 'spravne-heslo-123'])->assertStatus(429);
        // Stejné počítadlo drží i změnu hesla.
        $this->putJson('/api/v1/profil/heslo', ['current_password' => 'x', 'password' => 'nove-heslo-12345', 'password_confirmation' => 'nove-heslo-12345'])->assertStatus(429);
    }
}
