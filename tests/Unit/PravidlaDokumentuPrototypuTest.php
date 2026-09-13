<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pravidla, která se v dokumentech prototypu snadno poruší a nepozná.
 *
 * Každé z nich se v září 2026 porušilo doopravdy — a přišlo se na to až
 * na druhém zařízení, v síťovém logu nebo v databázi. Prohlížečové průchody
 * žijí jen ve vývojovém prohlížeči; tohle je hlídá při každém spuštění testů.
 */
class PravidlaDokumentuPrototypuTest extends TestCase
{
    private static function dokument(string $nazev): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/galerie/'.$nazev);
    }

    /**
     * Šablonové adresy s předponou `sc-camel-`.
     *
     * Prohlížeč čte šablonu dřív, než ji běhové prostředí vyplní:
     * `src="{{ mapSrc }}"` stáhl doslova (404 na serveru při každém otevření)
     * a `points="{{ … }}"` hlásil chybu SVG.
     */
    public function test_sablonove_adresy_a_svg_nemaji_doslovnou_hodnotu(): void
    {
        foreach (['galerie-desktop.dc.html', 'galerie-mobil.dc.html'] as $nazev) {
            preg_match_all('/\s(src|poster|srcset|points|cx|cy|r|d|x1|x2|y1|y2)="\{\{/', self::dokument($nazev), $shody);

            $this->assertSame([], $shody[0], $nazev.': atribut se šablonou bez předpony sc-camel-.');
        }
    }

    /**
     * Hesla, kódy, nastavení zámku a identita zařízení nejdou do sdíleného stavu.
     *
     * Počítač ukládá do stavu dvojice všechno, co nemá v `persistSkip`.
     * Heslo z dialogu kódu tak leželo v databázi a „Zamknout při spuštění:
     * Vypnuto" jednoho vypnulo zámek druhému.
     */
    public function test_pocitac_nesdili_hesla_ani_nastaveni_zamku(): void
    {
        $dokument = self::dokument('galerie-desktop.dc.html');

        $this->assertMatchesRegularExpression('/this\._pSkip = \{(.*?)\n    \};/s', $dokument);
        preg_match('/this\._pSkip = \{(.*?)\n    \};/s', $dokument, $blok);

        $musi = [
            'vaultPwd', 'lockPwd', 'lockPin', 'gatePin', 'gvPwd', 'pinHeslo', 'pinStary', 'pinNovy', 'pinZnovu',
            'pinObnovovaci', 'obPin', 'shrPwd', 'lockCode', 'vaultLeft', 'vaultTries',
            'lockIdle', 'lockSecs', 'lockBio', 'lockStart', 'lockWho', 'lockTrusted',
            'klAskMine', 'klAskDone', 'vw', 'theme',
        ];

        foreach ($musi as $klic) {
            $this->assertMatchesRegularExpression('/\b'.$klic.': 1\b/', $blok[1], 'Klíč „'.$klic.'" musí být v persistSkip.');
        }
    }

    /** Telefon ukládá jen vyjmenované klíče — nastavení zámku mezi nimi být nesmí. */
    public function test_telefon_neuklada_nastaveni_zamku_do_stavu(): void
    {
        $dokument = self::dokument('galerie-mobil.dc.html');

        preg_match("/this\._pKeep = \{\};\s*\((.*?)\)\.split\(' '\)/s", $dokument, $blok);
        $this->assertNotEmpty($blok, 'Výčet ukládaných klíčů telefonu se nenašel.');

        preg_match_all("/'([^']*)'/", $blok[1], $casti);
        $klice = preg_split('/\s+/', trim(implode(' ', $casti[1])));

        foreach (['lockIdle', 'lockSecs', 'lockBio', 'lockStart', 'setVals', 'mVaultPwd', 'lockPwd', 'lockPin'] as $klic) {
            $this->assertNotContains($klic, $klice, 'Telefon nesmí ukládat „'.$klic.'" do společného stavu.');
        }
    }

    /** Hlasy a potvrzení „až kliknou oba" jsou u dvojice pod jménem, ne pod A/M. */
    public function test_hlasy_se_nezapisuji_pod_stranou(): void
    {
        $dokument = self::dokument('galerie-desktop.dc.html');

        $this->assertStringContainsString('const klic = this.ukazka() ? who : this.meWho();', $dokument);
        $this->assertStringContainsString('homeCapReady', $dokument);
        $this->assertStringContainsString('whOk', $dokument);
    }

    /**
     * Seznamy zapisované do tabulek posílají, co z nich prohlížeč odebral.
     *
     * Server mazal, co v odeslaném seznamu chybělo — a seznam je kopie
     * z doby načtení. Karta otevřená přes víkend tak smazala, co mezitím
     * přidal ten druhý; „Do trezoru" u zamčeného trezoru vrátilo celý trezor
     * do knihovny.
     */
    public function test_seznamy_posilaji_rozdil_ne_jen_celek(): void
    {
        foreach (['galerie-desktop.dc.html', 'galerie-mobil.dc.html'] as $nazev) {
            $dokument = self::dokument($nazev);

            $this->assertStringContainsString('this.odebraneRozdil(prev, patch);', $dokument, $nazev);
            $this->assertStringContainsString('patch.__odebrane = out;', $dokument, $nazev);
            $this->assertStringContainsString('patch.__zmenene = zmenene;', $dokument, $nazev);
        }

        // Rozdíl nepatří do stavu a změněné se ve frontě sčítají.
        $api = (string) file_get_contents(dirname(__DIR__, 2).'/public/galerie-api.js');
        $this->assertStringContainsString('if (ROZDIL.indexOf(k) >= 0) return;', $api);
        $this->assertStringContainsString("if (k === '__zmenene' && pending[k]", $api);

        $pocitac = self::dokument('galerie-desktop.dc.html');
        $this->assertStringContainsString("if ('evList' in patch || 'xBoard' in patch) this.planRozdil(prev, patch);", $pocitac);
        $this->assertStringContainsString('patch.vaultVyjmout =', $pocitac);
    }

    /**
     * Odmítnutý zápis stavu se neopakuje každé čtyři vteřiny.
     *
     * Prošlé přihlášení, odebraný přístup nebo příliš velký zápis dělaly
     * z otevřené karty smyčku `PATCH /api/state` (firewall už jednou adresu
     * zablokoval) a čekající zápis zastavil dotazy na změny toho druhého.
     */
    public function test_odmitnuty_zapis_stavu_se_neopakuje_dokola(): void
    {
        $api = (string) file_get_contents(dirname(__DIR__, 2).'/public/galerie-api.js');

        $this->assertStringNotContainsString('schedule(4000);', $api);
        $this->assertStringContainsString('prodleva = Math.min(prodleva ? prodleva * 2 : 4000, 120000);', $api);
        $this->assertStringContainsString('if (stav === 401 || stav === 403) {', $api);
        $this->assertStringContainsString('if (stav === 400 || stav === 413 || stav === 422) {', $api);
        $this->assertStringContainsString("ohlas('galerie-odhlaseno'", $api);

        foreach (['galerie-desktop.dc.html', 'galerie-mobil.dc.html'] as $nazev) {
            $dokument = self::dokument($nazev);

            $this->assertStringContainsString("window.addEventListener('galerie-odhlaseno', this._odhlaseno);", $dokument, $nazev);
            $this->assertStringContainsString("window.addEventListener('galerie-odmitnuto', this._odmitnuto);", $dokument, $nazev);
            $this->assertStringContainsString("window.addEventListener('galerie-stret', this._stret);", $dokument, $nazev);
        }
    }

    /** Koš v telefonu umí i trvale odstranit — dřív jen „Obnovit". */
    public function test_telefon_maze_z_kose_na_serveru(): void
    {
        $dokument = self::dokument('galerie-mobil.dc.html');

        $this->assertStringContainsString("api.post('kos/odstranit', { id })", $dokument);
        $this->assertStringContainsString('onClick="{{ t.purge }}"', $dokument);
        $this->assertStringContainsString('...this.kosSmazatTlacitko(r.id, r.name, () => this.kosOdstranNaServeru(r.id))', $dokument);
    }

    /** Ikony z cizího CDN s kontrolou integrity. */
    public function test_ikony_z_cdn_maji_kontrolu_integrity(): void
    {
        foreach (['galerie-desktop.dc.html', 'galerie-mobil.dc.html'] as $nazev) {
            preg_match_all('/<link[^>]+unpkg\.com[^>]*>/', self::dokument($nazev), $odkazy);

            $this->assertNotEmpty($odkazy[0], $nazev.': odkaz na ikony se nenašel.');

            foreach ($odkazy[0] as $odkaz) {
                $this->assertStringContainsString('integrity="sha384-', $odkaz, $nazev.': chybí SRI.');
                $this->assertStringContainsString('crossorigin="anonymous"', $odkaz);
            }
        }
    }

    /** Přihlášení se na kód druhého ověření ptá políčkem, ne dialogem prohlížeče. */
    public function test_dvoufazove_overeni_ma_policko(): void
    {
        foreach (['galerie-desktop.dc.html', 'galerie-mobil.dc.html'] as $nazev) {
            $dokument = self::dokument($nazev);

            $this->assertStringContainsString('{{ lockNeeds2fa }}', $dokument, $nazev);
            $this->assertStringContainsString('{ bezDotazu: true }', $dokument, $nazev);
        }
    }
}
