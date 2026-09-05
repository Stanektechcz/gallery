<?php

namespace Tests\Feature\Galerie;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Definice mechanismů ze serveru.
 *
 * Klient si je dnes nese v souboru a ze serveru je jen **přepisuje**. Proto tu
 * záleží hlavně na tom, co se stane, když data chybí: prázdná odpověď by
 * v aplikaci smazala všechny mechanismy najednou.
 */
class MechanismyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
    }

    public function test_vraci_vsech_24_klicu(): void
    {
        $odpoved = $this->getJson('/api/mechanisms')->assertOk();

        $this->assertCount(24, $odpoved->json('data'));
        $this->assertNotEmpty($odpoved->json('data.MECH_LABELS'));
        $this->assertNotEmpty($odpoved->json('rev'), 'Bez otisku nepozná klient, že se data změnila.');
    }

    /** Chybějící soubor hlásí 503, ne prázdno — klient si pak nechá vlastní data. */
    public function test_chybejici_soubor_hlasi_503(): void
    {
        config(['galerie.mechanisms_path' => storage_path('nic/mechanismy.json')]);

        $this->getJson('/api/mechanisms')
            ->assertStatus(503)
            ->assertJsonMissingPath('data');
    }

    public function test_poskozeny_soubor_hlasi_chybu(): void
    {
        $cesta = storage_path('framework/testing/mechanismy-rozbite.json');
        @mkdir(dirname($cesta), 0755, true);
        file_put_contents($cesta, 'tohle není JSON');
        config(['galerie.mechanisms_path' => $cesta]);

        try {
            $this->getJson('/api/mechanisms')->assertStatus(500)->assertJsonMissingPath('data');
        } finally {
            @unlink($cesta);
        }
    }

    /** Podruhé se nepřenáší nic — stránka se tím načte rychleji. */
    public function test_nezmenena_data_vrati_304(): void
    {
        $etag = $this->getJson('/api/mechanisms')->assertOk()->headers->get('ETag');

        $this->assertNotEmpty($etag);

        $this->withHeader('If-None-Match', $etag)
            ->getJson('/api/mechanisms')
            ->assertStatus(304);
    }

    /** Odpověď chodí přes přihlášený kanál a do sdílené proxy nepatří. */
    public function test_odpoved_se_neuklada_do_sdilene_cache(): void
    {
        $hlavicka = $this->getJson('/api/mechanisms')->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $hlavicka);
        $this->assertStringNotContainsString('public', $hlavicka);
    }

    public function test_bez_prihlaseni_neprojde(): void
    {
        $this->app['auth']->forgetGuards();
        auth()->guard('sanctum')->forgetUser();

        $this->getJson('/api/mechanisms')->assertUnauthorized();
    }
}
