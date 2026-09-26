<?php

namespace Tests\Feature\Galerie;

use Illuminate\Support\Facades\File;
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
     * Odkaz `public/storage` je teď opačné pravidlo: nesmí existovat vůbec.
     *
     * `deploy.sh` ho po nasazení maže — vydával by originály fotek (i z trezoru)
     * bez přihlášení, mimo `/files`, kde se ověřuje podpis nebo členství.
     */
    public function test_existujici_odkaz_na_uloziste_nasazeni_zastavi(): void
    {
        $this->produkce();

        // Všechno na disku „existuje" — včetně toho, co existovat nesmí.
        File::shouldReceive('exists')->andReturn(true);

        $this->artisan('galerie:pred-nasazenim')
            ->expectsOutputToContain('Odkaz na úložiště')
            ->assertFailed();
    }

    /** `cache.default = array` by ztratilo omezení pokusů i stav trezoru s každým požadavkem. */
    public function test_mezipamet_array_nasazeni_zastavi(): void
    {
        $this->produkce(['cache.default' => 'array']);

        $this->artisan('galerie:pred-nasazenim')
            ->expectsOutputToContain('Mezipaměť mimo')
            ->assertFailed();
    }

    /** Chybějící MAIL_MAILER (nebo `array`/`log`) je jen varování. */
    public function test_mailer_bez_dorucovani_je_varovani(): void
    {
        $this->produkce(['mail.default' => 'array']);

        $this->artisan('galerie:pred-nasazenim')
            ->expectsOutputToContain('k rozmyšlení')
            ->assertSuccessful();
    }

    /** Chybějící VAPID klíče je jen varování — push je doplněk, ne nutnost. */
    public function test_chybejici_vapid_je_varovani(): void
    {
        $this->produkce();
        config(['push.public_key' => null, 'push.private_key' => null, 'push.subject' => null]);

        $this->artisan('galerie:pred-nasazenim')
            ->expectsOutputToContain('k rozmyšlení')
            ->assertSuccessful();
    }

    /** Jiný jazyk než čeština je jen varování. */
    public function test_jiny_jazyk_je_varovani(): void
    {
        $this->produkce(['app.locale' => 'en']);

        $this->artisan('galerie:pred-nasazenim')
            ->expectsOutputToContain('k rozmyšlení')
            ->assertSuccessful();
    }

    /** Chybějící e-mail vlastníka je jen varování. */
    public function test_chybejici_email_vlastnika_je_varovani(): void
    {
        $this->produkce();
        config(['gallery.owner_email' => '']);

        $this->artisan('galerie:pred-nasazenim')
            ->expectsOutputToContain('k rozmyšlení')
            ->assertSuccessful();
    }

    /** Otevřená registrace je jen varování. */
    public function test_otevrena_registrace_je_varovani(): void
    {
        $this->produkce(['gallery.registration_open' => true]);

        $this->artisan('galerie:pred-nasazenim')
            ->expectsOutputToContain('k rozmyšlení')
            ->assertSuccessful();
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
        // `public/storage` u testovacího frameworku nikdy neexistuje, takže rovnou
        // splňuje invertované pravidlo; kdyby vývojář odkaz omylem vytvořil (např.
        // `storage:link` na lokále, kde sdílí `public/` s aplikací), test by na to
        // spolehlivě upadl místo tichého projetí.
        config(array_merge([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://gallery.stanektech.cz',
            'app.locale' => 'cs',
            'session.secure' => true,
            'session.encrypt' => true,
            'session.driver' => 'database',
            'queue.default' => 'database',
            'logging.channels.stack.level' => 'warning',
            // Testovací prostředí (phpunit.xml) má `CACHE_STORE=array` a
            // `MAIL_MAILER=array` — pohodlné pro testy, ale přesně to, co má
            // tahle kontrola na produkci najít. Produkční výchozí hodnoty se tu
            // nastavují výslovně, aby „správné nastavení projde" opravdu
            // zůstalo správné, i když přibude další pravidlo.
            'cache.default' => 'database',
        ], $navic));

        if (! file_exists(public_path('build/manifest.json'))) {
            $this->markTestSkipped('Bez sestaveného rozhraní se tahle kontrola nedá ověřit.');
        }
    }
}
