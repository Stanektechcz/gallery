<?php

namespace Tests\Feature\Galerie;

use App\Http\Middleware\PreventRequestForgery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Zápis tokenem nesmí padat na ochraně proti CSRF.
 *
 * Klient prototypu i nativní klient z README se hlásí jen hlavičkou
 * `Authorization: Bearer` — cookie nemají a token proti CSRF taky ne. V prohlížeči
 * to procházelo díky `Sec-Fetch-Site`, mimo něj vracel `PATCH /api/state` 419.
 * Nejhůř to dopadalo u offline fronty: service worker přehrával zápis s uloženým
 * (a po vypršení sezení už neplatným) tokenem donekonečna.
 *
 * Testuje se přes middleware přímo. Skrz HTTP to nejde: ochrana se v testech sama
 * vypíná (`runningUnitTests`), takže by test prošel i s chybou.
 */
class ZapisTokenemTest extends TestCase
{
    use RefreshDatabase;

    public function test_platny_token_ochranu_obejde(): void
    {
        $token = User::factory()->create()->createToken('telefon')->plainTextToken;

        $this->assertTrue($this->vyjimka($this->pozadavek('Bearer '.$token)));
    }

    /**
     * Vymyšlený token výjimku nedostane.
     *
     * Jinak by stačilo poslat „Bearer cokoliv" a ochrana by zmizela pro každého,
     * kdo má v prohlížeči přihlášené sezení.
     */
    public function test_vymysleny_token_ochranu_neobejde(): void
    {
        $this->assertFalse($this->vyjimka($this->pozadavek('Bearer vymysleny')));
    }

    public function test_zruseny_token_ochranu_neobejde(): void
    {
        $uzivatel = User::factory()->create();
        $token = $uzivatel->createToken('telefon')->plainTextToken;
        $uzivatel->tokens()->delete();

        $this->assertFalse($this->vyjimka($this->pozadavek('Bearer '.$token)));
    }

    public function test_pozadavek_bez_hlavicky_ochranu_neobejde(): void
    {
        $this->assertFalse($this->vyjimka($this->pozadavek(null)));
    }

    /** Vydání tokenu proti heslu ochranu nepotřebuje — kdo ho volá, žádný nemá. */
    public function test_vydani_tokenu_je_v_seznamu_vyjimek(): void
    {
        $this->assertTrue($this->vyjimka(Request::create('/sanctum/token', 'POST')));
    }

    private function pozadavek(?string $autorizace): Request
    {
        $pozadavek = Request::create('/api/state', 'PATCH');

        if ($autorizace !== null) {
            $pozadavek->headers->set('Authorization', $autorizace);
        }

        return $pozadavek;
    }

    private function vyjimka(Request $pozadavek): bool
    {
        $middleware = new PreventRequestForgery($this->app, $this->app['encrypter']);

        $metoda = new \ReflectionMethod($middleware, 'inExceptArray');
        $metoda->setAccessible(true);

        return (bool) $metoda->invoke($middleware, $pozadavek);
    }
}
