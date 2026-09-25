<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rámcové hlášky (validace, přihlášení) česky.
 *
 * `config/app.php` mělo `APP_LOCALE` s výchozí hodnotou `en` a repozitář
 * neměl žádnou složku `lang/` — Laravel proto vracel anglické hlášky
 * i uživateli, který se snažil přihlásit do české aplikace (`sanctum/token`
 * vrací 422 s `message` přímo z frameworku, viz `TokenController::store`).
 */
class CeskeHlaskyTest extends TestCase
{
    use RefreshDatabase;

    public function test_chybejici_heslo_hlasi_cesky_nazev_pole(): void
    {
        $odpoved = $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/sanctum/token', [
                'email' => 'adri@example.test',
                'device_name' => 'Telefon',
            ]);

        $odpoved->assertStatus(422)
            ->assertJson(['message' => 'Pole heslo je povinné.']);
    }

    public function test_chybejici_email_hlasi_cesky_nazev_pole(): void
    {
        $odpoved = $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/sanctum/token', [
                'password' => 'tajneheslo123',
                'device_name' => 'Telefon',
            ]);

        $odpoved->assertStatus(422)
            ->assertJson(['message' => 'Pole e-mail je povinné.']);
    }

    public function test_jedna_dalsi_chyba_ma_cesky_jednotny_tvar(): void
    {
        // Chybí e-mail i název zařízení, heslo je vyplněné → 2 chyby celkem,
        // po odseknutí té první zbývá přesně jedna.
        $odpoved = $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/sanctum/token', [
                'password' => 'tajneheslo123',
            ]);

        $odpoved->assertStatus(422)
            ->assertJson(['message' => 'Pole e-mail je povinné. (a ještě 1 další chyba)']);
    }

    public function test_vice_dalsich_chyb_ma_cesky_mnozny_tvar(): void
    {
        // Prázdný požadavek → chybí e-mail, heslo i název zařízení (3 chyby),
        // po odseknutí první zbývají 2.
        $odpoved = $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/sanctum/token', []);

        $odpoved->assertStatus(422)
            ->assertJson(['message' => 'Pole e-mail je povinné. (a další chyby: 2)']);
    }
}
