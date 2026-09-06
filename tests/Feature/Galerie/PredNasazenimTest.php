<?php

namespace Tests\Feature\Galerie;

use Tests\TestCase;

/**
 * Kontrola před nasazením.
 *
 * `.env.example` má správné produkční hodnoty, jenže tím nikdo nezaručí, že
 * se na serveru použily. „Před nasazením zkontrolovat" je věta, na kterou se
 * jednou zapomene — a `APP_DEBUG=true` na veřejném serveru ukáže při první
 * chybě celý zásobník volání i připojovací údaje.
 */
class PredNasazenimTest extends TestCase
{
    /** Mimo produkci se nekontroluje — na vývoji jsou ty hodnoty správně jiné. */
    public function test_mimo_produkci_projde(): void
    {
        config(['app.env' => 'local', 'app.debug' => true]);

        $this->artisan('galerie:pred-nasazenim')
            ->expectsOutputToContain('přeskočeno')
            ->assertSuccessful();
    }

    /** Zapnuté ladění nasazení zastaví. */
    public function test_zapnute_ladeni_nasazeni_zastavi(): void
    {
        $this->produkce(['app.debug' => true]);

        $this->artisan('galerie:pred-nasazenim')
            ->expectsOutputToContain('APP_DEBUG=false')
            ->assertFailed();
    }

    /** Adresa bez HTTPS taky — podepsané odkazy by mířily na http. */
    public function test_adresa_bez_https_nasazeni_zastavi(): void
    {
        $this->produkce(['app.url' => 'http://gallery.stanektech.cz']);

        $this->artisan('galerie:pred-nasazenim')->assertFailed();
    }

    /** Fronta v režimu `sync` by nechala zpracování fotek běžet v požadavku. */
    public function test_synchronni_fronta_nasazeni_zastavi(): void
    {
        $this->produkce(['queue.default' => 'sync']);

        $this->artisan('galerie:pred-nasazenim')->assertFailed();
    }

    /** Se správným nastavením projde. */
    public function test_spravne_nastaveni_projde(): void
    {
        $this->produkce();

        $this->artisan('galerie:pred-nasazenim')->assertSuccessful();
    }

    /**
     * Nezašifrované sezení je varování, ne zastavení.
     *
     * Je to skutečná vada, ale ne taková, aby kvůli ní nešlo nasadit opravu
     * něčeho horšího.
     */
    public function test_nezasifrovane_sezeni_je_varovani(): void
    {
        $this->produkce(['session.encrypt' => false]);

        $this->artisan('galerie:pred-nasazenim')
            ->expectsOutputToContain('k rozmyšlení')
            ->assertSuccessful();
    }

    /** Produkční nastavení tak, jak ho popisuje `.env.example`. */
    private function produkce(array $navic = []): void
    {
        config(array_merge([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://gallery.stanektech.cz',
            'session.secure' => true,
            'session.encrypt' => true,
            'session.driver' => 'database',
            'queue.default' => 'database',
            'logging.channels.stack.level' => 'warning',
        ], $navic));

        // Odkaz na úložiště je varování, ne chyba — v testu na něm nezáleží.
        if (! file_exists(public_path('build/manifest.json'))) {
            $this->markTestSkipped('Bez sestaveného rozhraní se tahle kontrola nedá ověřit.');
        }
    }
}
