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

    /**
     * Data z odpovědi na akci dojdou i do kopie telefonu.
     *
     * `GalerieObsahNavlec` dával `MOBIL` do `GalerieData`, kde ho telefon
     * nečte: nová domácí práce, cesta nebo album se na telefonu objevily až
     * po dalším načtení stránky.
     */
    public function test_odpoved_na_akci_plni_i_kopii_telefonu(): void
    {
        $hlavicka = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/galerie/hlavicka.blade.php');

        preg_match('/window\.GalerieObsahNavlec = function \(mapa, prazdne\) \{(.*?)\n  \};/s', $hlavicka, $telo);
        $this->assertNotEmpty($telo, 'GalerieObsahNavlec se v hlavičce nenašel.');
        $this->assertStringContainsString("if (klic === 'MOBIL') { Object.assign(mobil, mapa[klic]); doMobilu(); return; }", $telo[1]);
    }

    /** Dělba jde začít: nová práce z počítače i z telefonu, odebrání u řádku. */
    public function test_domaci_prace_jde_pridat_a_odebrat(): void
    {
        $pocitac = self::dokument('galerie-desktop.dc.html');
        $telefon = self::dokument('galerie-mobil.dc.html');

        $this->assertStringContainsString('onClick="{{ chAdd }}"', $pocitac);
        $this->assertStringContainsString("cesta: () => 'domacnost/prace'", $pocitac);
        $this->assertStringContainsString("window.GalerieApi.del('domacnost/prace/' + c.id)", $pocitac);
        $this->assertStringContainsString('onClick="{{ chAdd }}"', $telefon);
        $this->assertStringContainsString("window.GalerieApi.post('domacnost/prace', { nazev: a, jak_casto: jakCasto })", $telefon);

        // Rozhodnutí a sliby jdou zapsat i z telefonu; prázdný stav vysvětlí k čemu jsou.
        $this->assertStringContainsString('onClick="{{ dcAddDec }}"', $telefon);
        $this->assertStringContainsString('onClick="{{ dcAddProm }}"', $telefon);
        $this->assertStringContainsString("if (s.addKind === 'decision') {", $telefon);

        // Lhůty, rodina a byt jdou doplnit na obou rozvrženích.
        foreach (['dueAdd', 'famAdd', 'flatAdd'] as $akce) {
            $this->assertStringContainsString('onClick="{{ '.$akce.' }}"', $pocitac, $akce);
            $this->assertStringContainsString('onClick="{{ '.$akce.' }}"', $telefon, $akce);
        }
        $this->assertStringContainsString('if (f.stav) {', $pocitac);
    }

    /** Promítání na telefonu: skutečné fotky výběru a automatické listování. */
    public function test_promitani_na_telefonu(): void
    {
        $telefon = self::dokument('galerie-mobil.dc.html');
        $pocitac = self::dokument('galerie-desktop.dc.html');

        $this->assertStringContainsString('onClick="{{ all.gridPlay }}"', $telefon);
        $this->assertStringContainsString('gridPlay: () => this.promitej(gridList)', $telefon);
        $this->assertStringContainsString('onClick="{{ lbAutoStop }}"', $telefon);
        // Šedé čtverce bez fotky už mřížka nekreslí jako hotový výběr.
        $this->assertStringNotContainsString('<div style="aspect-ratio:1; border-radius:2px; background:var(--g-photo)"></div>', $telefon);
        // Časovač se při odchodu z aplikace zastaví.
        $this->assertStringContainsString("componentWillUnmount() {\n    this.zastavPromitani();", $telefon);

        $this->assertStringContainsString('this.startShow(this.fotkyVyberu(AGRID.show, all)', $pocitac);
    }

    /** Kdo je na fotce: „+ Osoba" v detailu fotky na obou rozvrženích. */
    public function test_osoby_jdou_oznacit_na_fotce(): void
    {
        $pocitac = self::dokument('galerie-desktop.dc.html');
        $telefon = self::dokument('galerie-mobil.dc.html');

        $this->assertStringContainsString('onClick="{{ lbStartPerson }}"', $pocitac);
        $this->assertStringContainsString('people: (cur.people || []).concat(znama || j)', $pocitac);
        $this->assertStringContainsString('onClick="{{ lbDetail.personAdd }}"', $telefon);
        $this->assertStringContainsString('{ people: seznam }', $telefon);
    }

    /** Oznámení ze serveru: zvonek na počítači, Domů na telefonu, přečtení na server. */
    public function test_oznameni_ze_serveru(): void
    {
        $pocitac = self::dokument('galerie-desktop.dc.html');
        $telefon = self::dokument('galerie-mobil.dc.html');

        $this->assertStringContainsString('((window.GalerieData || {}).OZNAMENI || []).forEach(o => {', $pocitac);
        $this->assertStringContainsString("'v1/notifications/read-all'", $pocitac);
        $this->assertStringContainsString('<sc-if value="{{ oznOn }}">', $telefon);
        $this->assertStringContainsString("'v1/notifications/read-all'", $telefon);
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

    /**
     * Travel inbox a jízdenky: akce jdou na server na obou rozvrženích.
     *
     * Zařazení, archivace i smazání měnily jen obrazovku; „Přidat jízdenku"
     * založilo řádek, který nešlo doplnit; na telefonu „hotovo" přeškrtlo řádek.
     */
    public function test_travel_inbox_a_jizdenky_na_serveru(): void
    {
        $pocitac = self::dokument('galerie-desktop.dc.html');
        $telefon = self::dokument('galerie-mobil.dc.html');

        $this->assertStringContainsString("api.patch('v1/calendar/inbox/' + r.klic, { state: 'assigned', trip_id: cesta.n })", $pocitac);
        $this->assertStringContainsString("api.patch('v1/calendar/inbox/' + r.klic, { state: 'archived' })", $pocitac);
        $this->assertStringContainsString("cesta: () => 'cesty/inbox'", $pocitac);
        $this->assertStringContainsString("cesta: () => 'cesty/jizdenky'", $pocitac);
        $this->assertStringContainsString("window.GalerieApi.del('cesty/jizdenky/' + r.klic)", $pocitac);
        $this->assertStringContainsString('<sc-if value="{{ t.canSave }}">', $pocitac);

        $this->assertStringContainsString("window.GalerieApi.post('cesty/inbox', { nazev: a, odkaz: b || null })", $telefon);
        $this->assertStringContainsString("window.GalerieApi.post('cesty/jizdenky', {", $telefon);
        $this->assertStringContainsString("window.GalerieApi.patch('v1/calendar/inbox/' + r.klic, { state: 'archived' })", $telefon);
        $this->assertStringContainsString("window.GalerieApi.del('cesty/jizdenky/' + id)", $telefon);
    }

    /** „Byli jsme" u místa jen na server — ne do místní kopie, která přebíjela databázi. */
    public function test_navstivena_mista_jen_na_serveru(): void
    {
        $pocitac = self::dokument('galerie-desktop.dc.html');
        $telefon = self::dokument('galerie-mobil.dc.html');

        $this->assertStringContainsString('if (this.mistaNaServeru() && p.title) { this.mistoNavstiveno(p.title, !vis); return; }', $pocitac);
        $this->assertStringContainsString('if (naServeru) { this.mistoNavstiveno(p.title, !vis); return; }', $pocitac);
        $this->assertStringContainsString('undo: () => this.mistoNavstiveno(nazev, !navstiveno)', $pocitac);
        $this->assertStringNotContainsString('}).catch(() => {});', substr($pocitac, strpos($pocitac, 'placeToggleVisited'), 1200));

        $this->assertStringContainsString('placeVisited(p) { const v = this.galerieNaServeru() ? undefined : this.state.places[p[0]];', $telefon);
        $this->assertStringContainsString('if (this.galerieNaServeru()) { this.mistoNavstiveno(pRow[0], !pVisited); return; }', $telefon);
        $this->assertStringContainsString("zadost = window.GalerieApi.post('mista', { nazev: a, zeme: b || null });", $telefon);
    }

    /** Prázdné stavy neslibují přepis hlasovek ani nahrávání PDF jízdenek. */
    public function test_prazdne_stavy_neslibuji_co_neumime(): void
    {
        $data = file_get_contents(dirname(__DIR__, 2).'/public/galerie-data.js');
        $telefon = self::dokument('galerie-mobil.dc.html');

        foreach ([$data, $telefon] as $zdroj) {
            $this->assertStringNotContainsString('přepis se pak najde v hledání', $zdroj);
            $this->assertStringNotContainsString('PDF a QR kódy se dají nahrát', $zdroj);
            $this->assertStringNotContainsString('se sem přesypou z e-mailu', $zdroj);
        }

        // Druhá záložka travel inboxu jsou jízdenky, ne „zařazené".
        $this->assertStringContainsString("['Jízdenky a trasy', 'list', 'ticket']", $data);
    }

    /** „Na příští týden" v Týdenním přehledu posune úkol na serveru, ne jen obrazovku. */
    public function test_tydenni_prehled_posouva_ukol_na_serveru(): void
    {
        $pocitac = self::dokument('galerie-desktop.dc.html');

        $this->assertStringContainsString("window.GalerieApi.post('ukoly/' + x[3] + '/pristi-tyden', {})", $pocitac);
        $this->assertStringContainsString('<sc-if value="{{ wkSlippedEmpty }}">', $pocitac);
        $this->assertStringContainsString('<sc-if value="{{ wkMomentsEmpty }}">', $pocitac);
        $this->assertStringNotContainsString("open: () => this.setState({ route: 'timeline' })", $pocitac);
    }

    /** Prohlížeč fotky: čas, datum, nahrání a album podle fotky, ne napsané. */
    public function test_prohlizec_fotky_neukazuje_vymyslene_udaje(): void
    {
        $pocitac = self::dokument('galerie-desktop.dc.html');

        $this->assertStringNotContainsString("dateVal: cur.day, timeVal: '06:42'", $pocitac);
        $this->assertStringContainsString("timeVal: cur.timeVal || (this.ukazka() ? '06:42' : '')", $pocitac);
        $this->assertStringContainsString("lbAlbum: cur && cur.album && cur.album !== 'Bez alba' ? cur.album : ''", $pocitac);
        $this->assertStringContainsString("'nahráno ' + (lbP.nahrano || lbP.day || '')", self::dokument('galerie-mobil.dc.html'));
    }

    /** Pravidla zařazování netvrdí, že import výpisů není; „Upravit" otevře platby obchodu. */
    public function test_pravidla_zarazovani_odpovidaji_importu(): void
    {
        $pocitac = self::dokument('galerie-desktop.dc.html');

        $this->assertStringNotContainsString('importu výpisů z banky, který galerie zatím nemá', $pocitac);
        $this->assertStringContainsString("this.setState({ route: 'x-transakce', appTab: 0, txQuery: r[0],", $pocitac);
        $this->assertStringContainsString('if (!q.split(/\\s+/).every(slovo => kde.includes(slovo))) return false;', $pocitac);
    }

    /** Úkol odškrtnutý na telefonu se uzavře na serveru, ne jen v `done` telefonu. */
    public function test_telefon_odskrtava_ukoly_na_serveru(): void
    {
        $telefon = self::dokument('galerie-mobil.dc.html');

        $this->assertStringContainsString('r[3] ? 1 : 0, r[4] || null]', $telefon);
        $this->assertStringContainsString("window.GalerieApi.patch('v1/todos/' + t[4], { completed: hotovo })", $telefon);
        $this->assertStringContainsString('if (this.galerieNaServeru() && t[4]) { this.ukolNaServeru(t, !on); return; }', $telefon);
        $this->assertStringNotContainsString("s.done['t' + grp[0] + i]", $telefon);
    }

    /**
     * Tlačítka, která hlásila hotovou věc a jen přepnula obrazovku (audit 17. kola).
     */
    public function test_tlacitka_neslibuji_co_neudelaji(): void
    {
        $pocitac = self::dokument('galerie-desktop.dc.html');

        foreach ([
            "' nabídnut jako podklad fotoknihy'",
            "' uložených vzpomínek nabídnuto jako podklad fotoknihy'",
            "'“ nabídnuto jako podklad kapitoly'",
            "'Založena kapitola z připnuté vzpomínky'",
            "'Téma přidáno k nedělní agendě · tři minuty'",
            'aplikace to připomene sama, nikdo si to nemusí pamatovat',
            "'Milník založen v Dárcích a nápadech'",
            "this.setState({ memLater: { ...prev, [m.id]: 'zítra ráno' } })",
            "m.date.indexOf('17.') === 0).slice(0, 2)",
        ] as $lez) {
            $this->assertStringNotContainsString($lez, $pocitac, $lez);
        }

        $this->assertStringContainsString("cesta: () => 'milniky'", $pocitac);
        $this->assertStringContainsString("api.post('v1/guest-uploads/' + id + '/' + akce, {})", $pocitac);
        $this->assertStringContainsString("window.GalerieApi.patch('v1/trips/' + dalsi.n, { budget: castka })", $pocitac);
        $this->assertStringContainsString("(this.state.nedTopics || []).forEach(t => push('Opakované téma'", $pocitac);
        $this->assertStringContainsString('this.setState({ decs: [zaznam].concat(decs)', $pocitac);
    }

    /** Komentáře u fotky přes API, ne ve sdíleném stavu; cizí nejde smazat. */
    public function test_komentare_k_fotce_na_serveru(): void
    {
        $pocitac = self::dokument('galerie-desktop.dc.html');
        $api = (string) file_get_contents(dirname(__DIR__, 2).'/public/galerie-api.js');

        $this->assertStringContainsString("window.GalerieApi.get('v1/media/' + id + '/comments')", $pocitac);
        $this->assertStringContainsString("window.GalerieApi.post('v1/media/' + id + '/comments', { body: txt })", $pocitac);
        $this->assertStringContainsString('<sc-if value="{{ c.canDel }}">', $pocitac);
        $this->assertStringContainsString('get: function (path) {', $api);
    }

    /** Archiv alb na serveru — a na telefonu z něj jde album vrátit. */
    public function test_archiv_alb_na_serveru(): void
    {
        $pocitac = self::dokument('galerie-desktop.dc.html');
        $telefon = self::dokument('galerie-mobil.dc.html');

        $this->assertStringContainsString("api.post('alba/' + album.id + '/archivovat', { archivovat: true })", $pocitac);
        $this->assertStringContainsString('<sc-if value="{{ albArchOn }}">', $pocitac);
        $this->assertStringContainsString('.filter(a => this.albumNaServeru(a) || !arch[a.id])', $pocitac);
        $this->assertStringContainsString("archive: (G.ALBUMS_ARCH || []).map(a => [a.name, a.count + ' · ' + a.when, 'archiv', a.id])", $telefon);
        $this->assertStringContainsString("window.GalerieApi.post('alba/' + id + '/archivovat', { archivovat: false })", $telefon);
    }

    /** Audit telefonu (18. kolo): co hlásilo uloženo a žilo jen do obnovení. */
    public function test_telefon_uklada_co_hlasi(): void
    {
        $telefon = self::dokument('galerie-mobil.dc.html');
        $pocitac = self::dokument('galerie-desktop.dc.html');

        $this->assertStringContainsString("'srSaved coolLimit whOk').split(' ')", $telefon);
        $this->assertStringContainsString("window.GalerieApi.post('kalendar/udalost', { nazev: a, datum: this.calVybrany(), cas: cas || null })", $telefon);
        $this->assertStringContainsString("window.GalerieApi.post('denik', { nadpis: a, text: b, datum: s.dayNoteIso, soukromy: true })", $telefon);
        $this->assertStringContainsString("if (ideas.length) xRows.gifts = ideas.map(x => ({ t: 'Nápad: ' + x.title", $telefon);
        $this->assertStringContainsString("if (kam === 'done' || odkud === 'done') { this.ukolNaServeru([it.t, '', '', 0, it.id], kam === 'done'); return true; }", $telefon);
        $this->assertStringContainsString("if (this.galerieNaServeru()) return [];\n    const t = this.dnes();", $telefon);
        $this->assertStringContainsString("window.GalerieApi.post('domacnost/spiz', { polozky })", $telefon);
        $this->assertStringContainsString("window.GalerieApi.post('domacnost/spiz', telo)", $pocitac);
        $this->assertStringContainsString("'mDgHide']; }", $telefon);

        // Dva rychlé zápisy různých seznamů (`xRows`) se ve frontě nepřepíšou.
        $api = (string) file_get_contents(dirname(__DIR__, 2).'/public/galerie-api.js');
        $this->assertStringContainsString("if (k === 'xRows' && pending[k] && typeof pending[k] === 'object'", $api);
    }

    /** Tisk u dvojice: místní návrhy neschovají fotoknihy ze serveru; ty se mažou na serveru. */
    public function test_tisk_spojuje_navrhy_s_fotoknihami(): void
    {
        $pocitac = self::dokument('galerie-desktop.dc.html');

        $this->assertStringContainsString('return s.pJobs.concat(serverove.filter(j => !skryte[j.id] && !s.pJobs.some(m => m.id === j.id)));', $pocitac);
        $this->assertStringContainsString("window.GalerieApi.del('v1/books/' + j.id)", $pocitac);
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
