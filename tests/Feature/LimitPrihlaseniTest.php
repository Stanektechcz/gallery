<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Vyčerpané přihlášení nezamkne obnovu hesla ani pozvánku.
 *
 * Limity bez předpony počítadla sdílí jeden klíč (adresa), bez ohledu
 * na cestu. Kdo zapomněl heslo a desetkrát ho zkusil, dostal 429 i na
 * „zapomenuté heslo" a na nastavení nového — tedy přesně tam, kam ho
 * obrazovka po neúspěchu posílá. Pozvánka otevřená z téže sítě taky.
 */
class LimitPrihlaseniTest extends TestCase
{
    use RefreshDatabase;

    public function test_vycerpane_prihlaseni_nezamkne_obnovu_hesla_ani_pozvanku(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->post('/login', ['email' => 'nikdo@vzpominky.test', 'password' => 'spatne-'.$i]);
        }

        // Stav přímo: `assertStatus` na přesměrování padá uvnitř vendoru.
        $this->assertSame(429, $this->post('/login', ['email' => 'nikdo@vzpominky.test', 'password' => 'x'])->status(),
            'Limit přihlášení sám o sobě platit musí.');

        $this->assertNotSame(429, $this->post('/forgot-password', ['email' => 'nikdo@vzpominky.test'])->status(),
            'Zapomenuté heslo sdílí počítadlo s přihlášením.');
        $this->assertNotSame(429, $this->post('/reset-password', [
            'token' => 'neplatny', 'email' => 'nikdo@vzpominky.test',
            'password' => 'noveHeslo123', 'password_confirmation' => 'noveHeslo123',
        ])->status(), 'Nastavení nového hesla sdílí počítadlo s přihlášením.');
        $this->assertNotSame(429, $this->post('/invite/neplatny-token', [])->status(),
            'Přijetí pozvánky sdílí počítadlo s přihlášením.');
    }

    /**
     * Náhledy v mřížce nevyčerpají druhý faktor.
     *
     * Podepsané náhledy mají limit 600 za minutu, druhý faktor 15 — a bez
     * předpony počítadla oba četly týž klíč (adresu). Stránka s dvaceti
     * dlaždicemi pak z téže sítě zamkla ověření kódu dřív, než ho kdo zadal.
     */
    public function test_nahledy_nevycerpaji_druhy_faktor(): void
    {
        $nahled = URL::temporarySignedRoute('galerie.media.thumb', now()->addHour(), ['uuid' => (string) Str::uuid()]);

        for ($i = 1; $i <= 20; $i++) {
            $this->get($nahled);
        }

        $this->assertNotSame(429, $this->post('/login/overeni', ['code' => '000000'])->status(),
            'Druhý faktor sdílí počítadlo s podepsanými náhledy.');
    }
}
