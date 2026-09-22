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
        // Hromadné nahrávání se bez přihlášení zastaví, nezkouší každý soubor dvakrát.
        $this->assertStringContainsString('var zbyle = [polozka].concat(fronta.splice(0, fronta.length));', $api);

        foreach (['galerie-desktop.dc.html', 'galerie-mobil.dc.html'] as $nazev) {
            $dokument = self::dokument($nazev);

            $this->assertStringContainsString("window.addEventListener('galerie-odhlaseno', this._odhlaseno);", $dokument, $nazev);
            $this->assertStringContainsString("window.addEventListener('galerie-odmitnuto', this._odmitnuto);", $dokument, $nazev);
            $this->assertStringContainsString("window.addEventListener('galerie-stret', this._stret);", $dokument, $nazev);
        }
    }

    /** Přihlášení odkazuje na zapomenuté heslo a po obnovení řekne, že je nové. */
    public function test_prihlaseni_ma_cestu_k_zapomenutemu_heslu(): void
    {
        foreach (['galerie-desktop.dc.html', 'galerie-mobil.dc.html'] as $nazev) {
            $dokument = self::dokument($nazev);

            $this->assertStringContainsString('<a href="/forgot-password"', $dokument, $nazev);
            $this->assertStringContainsString('{{ lockInfoText }}', $dokument, $nazev);
            $this->assertStringContainsString('/[?&]heslo=zmeneno(&|$)/.test(location.search)', $dokument, $nazev);
            $this->assertStringNotContainsString('<span>Přihlášen <strong', $dokument, $nazev);
        }

        // Informace z přihlášení nepatří do sdíleného stavu.
        $this->assertMatchesRegularExpression('/this\._pSkip = \{.*?\blockInfo: 1\b/s', self::dokument('galerie-desktop.dc.html'));
    }

    /**
     * Telefon má vlastní Zprávy a počítač maže zprávu doopravdy.
     *
     * Telefon ukazoval hovor jen jako náhled bez psaní; počítač „mazal" jen
     * v místním seznamu a hlásil „smazána u obou".
     */
    public function test_zpravy_na_telefonu_a_mazani_na_serveru(): void
    {
        $telefon = self::dokument('galerie-mobil.dc.html');
        $this->assertStringContainsString('data-screen-label="Mobil — Zprávy"', $telefon);
        $this->assertStringContainsString("'x-zpravy': 'chat'", $telefon);
        $this->assertStringContainsString("window.GalerieApi.post('v1/chat', { body: t })", $telefon);
        $this->assertStringContainsString("api.post('v1/chat', { voice_note:", $telefon);
        $this->assertStringContainsString("window.GalerieApi.del('v1/chat/' + m.id)", $telefon);

        $pocitac = self::dokument('galerie-desktop.dc.html');
        $this->assertStringContainsString("api.del('v1/chat/' + m.id)", $pocitac);
        $this->assertStringContainsString('<sc-if value="{{ m.canDel }}">', $pocitac);
    }

    /**
     * Cesta se zakládá na serveru z obou rozvržení; vzkazy hostů jdou spravovat
     * i z telefonu; prázdné stavy neslibují, co aplikace nedělá.
     */
    public function test_cesty_vzkazy_hostu_a_poctive_prazdne_stavy(): void
    {
        $pocitac = self::dokument('galerie-desktop.dc.html');
        $telefon = self::dokument('galerie-mobil.dc.html');

        $this->assertStringContainsString("window.GalerieApi.post('v1/trips', {", $pocitac);
        $this->assertStringContainsString('type="date" value="{{ trOd }}"', $pocitac);
        $this->assertStringContainsString("window.GalerieApi.post('v1/trips', {", $telefon);
        $this->assertStringContainsString('data-screen-label="Mobil — Nová cesta"', $telefon);
        $this->assertStringContainsString('{{ tripEmpty }}', $telefon);

        // Bod programu cesty z telefonu na server — dřív „zatím jen na počítači".
        $this->assertStringContainsString("api.post('cesty/' + cesta.n + '/program', { den: s.addDen || 0, nazev: a, cas: b || null })", $telefon);
        $this->assertStringNotContainsString('Do itineráře zatím přidáte jen na počítači', $telefon);
        // „Splněno" u bodu ze serveru se ukládá k bodu, ne do jednoho telefonu.
        $this->assertStringContainsString("window.GalerieApi.post('cesty/program/' + it[3] + '/hotovo', { hotovo: !on })", $telefon);

        $this->assertStringContainsString("window.GalerieApi.patch('vzkazy-hostu/' + c.id, { skryty: !c.hidden })", $telefon);
        $this->assertStringContainsString("window.GalerieApi.del('vzkazy-hostu/' + c.id)", $telefon);

        // Album z telefonu jde přejmenovat a stáhnout (dřív jen sdílet a smazat).
        $this->assertStringContainsString("window.GalerieApi.patch('alba/' + a.id, { nazev })", $telefon);
        $this->assertStringContainsString("window.GalerieApi.download('alba/' + a.id + '/archiv'", $telefon);
        // Odznaky a „den cesty" v menu Více ze skutečných dat, ne čísla z ukázky.
        $this->assertStringNotContainsString("'x-inbox': 12 - resolvedIn", $telefon);
        $this->assertStringNotContainsString("meta: 'den 5 z 8'", $telefon);

        $data = (string) file_get_contents(dirname(__DIR__, 2).'/public/galerie-data.js');
        foreach ([$telefon, $data] as $zdroj) {
            $this->assertStringNotContainsString('Rozpoznávání běží', $zdroj);
            $this->assertStringNotContainsString('Kontrola běží každou noc', $zdroj);
        }
    }

    /**
     * Odznaky a čísla na obrazovkách ze skutečných dat a ve správném tvaru.
     *
     * Hlavička stránky brala odznak z katalogu (`appPage.badge`: „2 návrhy",
     * „3 na dnes", „4 aktivní"), panel kreslil „Vzpomínky 0" a telefon
     * hlásil „zbývá v rozpočtu" i u záporného zůstatku.
     */
    public function test_odznaky_a_cisla_bez_ukazky(): void
    {
        $pocitac = self::dokument('galerie-desktop.dc.html');
        $telefon = self::dokument('galerie-mobil.dc.html');

        $this->assertStringNotContainsString('(appPage.badge || null)', $pocitac);
        $this->assertStringContainsString('this.odznakHlavicky(R)', $pocitac);
        $this->assertStringNotContainsString('String(this.memTodayCount())]', $pocitac);
        $this->assertStringNotContainsString("' položek v knihovně · '", $pocitac);
        $this->assertStringNotContainsString("R === 'x-inbox' ? ' položek'", $pocitac);

        $this->assertStringNotContainsString("meta: 'zbývá v rozpočtu', color: 'var(--g-ok)'", $telefon);
        $this->assertStringNotContainsString("value: String(lib.length), meta: 'položek v knihovně'", $telefon);
    }

    /** Výpis z banky jde nahrát u účtu a klient ho posílá jako soubor. */
    public function test_vypis_z_banky_se_nahrava_u_uctu(): void
    {
        $pocitac = self::dokument('galerie-desktop.dc.html');
        $api = (string) file_get_contents(dirname(__DIR__, 2).'/public/galerie-api.js');
        $data = (string) file_get_contents(dirname(__DIR__, 2).'/public/galerie-data.js');

        $this->assertStringContainsString('onClick="{{ a.importVypis }}"', $pocitac);
        $this->assertStringContainsString('importVypis: () => this.nahratVypis(a[8], a[0])', $pocitac);
        $this->assertStringContainsString("fetch(base + '/finance/import'", $api);
        $this->assertStringContainsString("telo.append('vypis', soubor", $api);
        $this->assertStringNotContainsString('Import z Revolutu ústí sem.', $data);

        // Telefon: výpis se stahuje právě tam (aplikace banky), nahrát jde z Financí.
        $telefon = self::dokument('galerie-mobil.dc.html');
        $this->assertStringContainsString('onClick="{{ txImport }}"', $telefon);
        $this->assertStringContainsString('api.nahrajVypis(soubor, ucet[8])', $telefon);
        $this->assertStringContainsString('<sc-if value="{{ sheetIsVypis }}">', $telefon);
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
