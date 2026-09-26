<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private const TELEFON = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';

    private const POCITAC = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

    public function test_public_health_response_has_browser_security_headers(): void
    {
        $this->get('/health/live')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /**
     * Prototyp pustí jen své vlastní inline skripty.
     *
     * Přihlašovací token leží v `localStorage`. S `'unsafe-inline'` by jediný
     * `<script>`, který by do stránky propašovala chyba v escapování, token
     * přečetl a poslal pryč. Politika proto vyjmenuje otisky přesně těch bloků,
     * které dokument opravdu nese — spočítané tady nezávisle na aplikaci.
     */
    public function test_prototyp_na_pocitaci_povoli_jen_sve_inline_skripty(): void
    {
        $this->overPolitikuPrototypu($this->withHeader('User-Agent', self::POCITAC)->get('/')->assertOk(), 2);
    }

    /** Telefonní dokument má navíc inline registraci service workera v `<helmet>`. */
    public function test_prototyp_na_telefonu_povoli_jen_sve_inline_skripty(): void
    {
        $this->overPolitikuPrototypu($this->withHeader('User-Agent', self::TELEFON)->get('/')->assertOk(), 3);
    }

    /** Obrazovka s vlastní adresou jde přes tentýž kontroler — a musí mít tutéž ochranu. */
    public function test_obrazovka_s_vlastni_adresou_ma_politiku_prototypu(): void
    {
        $this->overPolitikuPrototypu($this->withHeader('User-Agent', self::POCITAC)->get('/galerie/alba')->assertOk(), 2);
    }

    /**
     * Otisk místo nonce: stejný dokument, stejný `ETag` i stejná politika.
     *
     * Nonce by se měnil s každým požadavkem a s ním tělo — druhé stažení
     * dokumentu (`fetch(location.href)` v runtime) by pak nikdy nedostalo 304.
     */
    public function test_stejny_dokument_ma_stejny_etag_i_politiku(): void
    {
        $prvni = $this->get('/')->assertOk();
        $druhy = $this->get('/')->assertOk();

        $this->assertNotEmpty($prvni->headers->get('ETag'));
        $this->assertSame($prvni->headers->get('ETag'), $druhy->headers->get('ETag'));
        $this->assertSame(
            $prvni->headers->get('Content-Security-Policy'),
            $druhy->headers->get('Content-Security-Policy'),
        );
    }

    /**
     * I odpověď 304 nese politiku prototypu.
     *
     * Prohlížeč si u 304 přepíše uložené hlavičky těmi novými. Kdyby 304 dostala
     * společnou politiku, uložený dokument by se dál spouštěl s `'unsafe-inline'`.
     */
    public function test_odpoved_304_nese_politiku_prototypu(): void
    {
        $prvni = $this->get('/')->assertOk();

        $druha = $this->withHeader('If-None-Match', (string) $prvni->headers->get('ETag'))->get('/')->assertStatus(304);

        $this->assertSame(
            $prvni->headers->get('Content-Security-Policy'),
            $druha->headers->get('Content-Security-Policy'),
        );
    }

    /** Ostatní stránky (Inertia, API, zdraví) zůstávají u společné politiky. */
    public function test_ostatni_stranky_maji_spolecnou_politiku(): void
    {
        foreach (['/health/live', '/login'] as $adresa) {
            $politika = (string) $this->get($adresa)->headers->get('Content-Security-Policy');

            $this->assertSame(
                ["'self'", "'unsafe-inline'", "'unsafe-eval'", 'https://unpkg.com'],
                $this->direktiva($politika, 'script-src'),
                $adresa.' má mít společnou politiku.',
            );
        }
    }

    /**
     * Skripty v `<helmet>` přežijí přepis v runtime beze změny.
     *
     * Runtime po startu šablonu přepíše (`encodeCase` v `support.js`: camelCase
     * atributy na `sc-camel-…`, `<helmet>` a tabulkové značky na `sc-…`) a skripty
     * z `<helmet>` z ní vloží do stránky **znovu** s přepsaným textem. Otisk
     * v politice je spočítaný z původního textu — kdyby přepis skript změnil
     * (stačí řádek `  mojeHodnota = 1`), druhé vložení by prohlížeč zablokoval.
     */
    public function test_skripty_v_helmet_prezijou_prepis_runtime(): void
    {
        foreach (['galerie-desktop.dc.html', 'galerie-mobil.dc.html'] as $soubor) {
            $dokument = (string) file_get_contents(resource_path('galerie/'.$soubor));

            $this->assertSame(1, preg_match('#<helmet>(.*?)</helmet>#is', $dokument, $helmet), $soubor.' nemá <helmet>.');

            foreach ($this->inlineSkripty($helmet[1]) as $skript) {
                $this->assertSame($skript, $this->prepisRuntime($skript),
                    $soubor.': runtime by skript v <helmet> přepsal a jeho otisk by neseděl.');
            }
        }
    }

    private function overPolitikuPrototypu(TestResponse $odpoved, int $nejmeneSkriptu): void
    {
        $politika = (string) $odpoved->headers->get('Content-Security-Policy');
        $skripty = $this->direktiva($politika, 'script-src');

        $this->assertNotContains("'unsafe-inline'", $skripty, 'Prototyp nesmí pouštět libovolný inline skript.');
        $this->assertContains("'self'", $skripty);
        // Babel a `new Function` v support.js — bez `eval` se aplikace nespustí.
        $this->assertContains("'unsafe-eval'", $skripty);

        /*
         * Z unpkg jen soubory, které runtime opravdu stahuje — ne celý host.
         * S celým hostem by vložené `<script src="https://unpkg.com/<cizí balík>">`
         * doběhlo a přečetlo token z `localStorage`. Adresy se berou přímo ze
         * `support.js`, takže nová verze Reactu bez úpravy politiky test shodí.
         */
        $this->assertNotContains('https://unpkg.com', $skripty, 'Celý unpkg je pro prototyp moc široký.');
        $zUnpkg = array_values(array_filter($skripty, fn (string $zdroj) => str_starts_with($zdroj, 'https://unpkg.com')));
        sort($zUnpkg);
        $this->assertSame($this->adresyRuntime(), $zUnpkg);

        $ocekavane = array_values(array_unique(array_map(
            fn (string $skript) => "'sha256-".base64_encode(hash('sha256', $skript, true))."'",
            $this->inlineSkripty((string) $odpoved->getContent()),
        )));
        $vHlavicce = array_values(array_filter($skripty, fn (string $zdroj) => str_starts_with($zdroj, "'sha256-")));

        $this->assertGreaterThanOrEqual($nejmeneSkriptu, count($ocekavane), 'Dokument má mít své inline skripty.');
        sort($ocekavane);
        sort($vHlavicce);
        $this->assertSame($ocekavane, $vHlavicce, 'Otisky v politice musí sedět přesně na inline skripty dokumentu.');

        // Styly zůstávají, jak byly — runtime skládá CSS do `<style>` a atributů.
        $this->assertSame(
            ["'self'", "'unsafe-inline'", 'https://unpkg.com', 'https://fonts.googleapis.com'],
            $this->direktiva($politika, 'style-src'),
        );
        $this->assertSame(["'none'"], $this->direktiva($politika, 'object-src'));
    }

    /**
     * Obsah spustitelných inline skriptů tak, jak ho vidí prohlížeč.
     *
     * Jednodušší čtení než v aplikaci, schválně jiné: dokument se rozseká podle
     * `</script>` a v každém kusu je skript za první otevírací značkou. Typ jen
     * z krátkého seznamu, konce řádků po parseru HTML. (Líný regulární výraz
     * přes celý dokument na dvou megabajtech narazí na limit PCRE.)
     *
     * @return list<string>
     */
    private function inlineSkripty(string $html): array
    {
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $kusy = preg_split('#</script>#i', $html) ?: [];
        array_pop($kusy); // za posledním `</script>` už žádný skript není

        $skripty = [];

        foreach ($kusy as $kus) {
            $this->assertSame(1, preg_match('#<script\b([^>]*)>#i', $kus, $otevreni, PREG_OFFSET_CAPTURE));
            $atributy = $otevreni[1][0];
            $obsah = substr($kus, $otevreni[0][1] + strlen($otevreni[0][0]));
            if (preg_match('/\ssrc\s*=/i', ' '.$atributy)) {
                continue;
            }

            $typ = preg_match('/\stype\s*=\s*["\']?([^"\'\s>]*)/i', ' '.$atributy, $t) ? strtolower($t[1]) : '';

            if (in_array($typ, ['', 'text/javascript', 'application/javascript', 'module'], true)) {
                $skripty[] = $obsah;
            }
        }

        return $skripty;
    }

    /** Přepis šablony z `encodeCase` v `public/support.js`, jen části, které sahají na text. */
    private function prepisRuntime(string $text): string
    {
        $text = (string) preg_replace('/<(x-import|dc-import)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)\/>/i', '<$1$2></$1>', $text);
        $text = (string) preg_replace('/<helmet(\s|>)/i', '<sc-helmet$1', $text);
        $text = (string) preg_replace('/<\/helmet\s*>/i', '</sc-helmet>', $text);
        $text = (string) preg_replace_callback('/(\s)([a-z]+[A-Z][A-Za-z0-9]*)(\s*=)/',
            fn ($m) => $m[1].'sc-camel-'.strtolower((string) preg_replace('/([A-Z])/', '-$1', $m[2])).$m[3], $text);

        foreach (['select', 'table', 'tbody', 'thead', 'tfoot', 'tr', 'td', 'th', 'caption'] as $znacka) {
            $text = (string) preg_replace('/(<\/?)'.$znacka.'(?=[\s>])/i', '$1sc-raw-'.$znacka, $text);
        }

        return $text;
    }

    /**
     * Skripty, které si runtime stahuje z CDN (`src/cdn.ts` v `support.js`).
     *
     * @return list<string>
     */
    private function adresyRuntime(): array
    {
        $runtime = (string) file_get_contents(public_path('support.js'));

        preg_match_all('/var (?:REACT|REACT_DOM|BABEL)_URL = "([^"]+)";/', $runtime, $shody);

        $this->assertCount(3, $shody[1], 'support.js má stahovat React, ReactDOM a Babel.');
        $adresy = $shody[1];
        sort($adresy);

        return $adresy;
    }

    /** @return list<string> */
    private function direktiva(string $politika, string $jmeno): array
    {
        foreach (explode(';', $politika) as $cast) {
            $slova = preg_split('/\s+/', trim($cast)) ?: [];

            if (($slova[0] ?? null) === $jmeno) {
                return array_slice($slova, 1);
            }
        }

        $this->fail('Politika nemá direktivu '.$jmeno.': '.$politika);
    }
}
