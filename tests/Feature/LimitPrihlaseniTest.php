<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
