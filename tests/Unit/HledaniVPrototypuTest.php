<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Hledání v obou dokumentech prototypu a pravda o trezoru a cloudu.
 *
 * Dvojice chtěla, „ať je vyhledávání maximálně efektivní". Knihovna přitom
 * filtrovala jen 240 nejnovějších fotek v prohlížeči a celou frázi; telefon
 * větou nenašel ani jednu fotku a každé políčko „Hledat" bralo diakritiku
 * doslova. Hledá se proto na serveru (`GET /api/hledat`) — s prodlevou po
 * úhozu a se zahozením odpovědi na starší dotaz, jinak by rychlé psaní
 * vyčerpalo limit a pozdní odpověď přepsala novější. Místní filtry jdou
 * přes jediný pomocník `shoda()`.
 *
 * A „Fotky z trezoru nejdou na cloud": obrazovky to musí říkat, ne tvrdit,
 * že originály leží na Google účtu a galerie drží jen náhledy.
 */
class HledaniVPrototypuTest extends TestCase
{
    private const POCITAC = 'galerie-desktop.dc.html';

    private const TELEFON = 'galerie-mobil.dc.html';

    private static function dokument(string $nazev): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/galerie/'.$nazev);
    }

    /** Tělo metody třídy `Component` od hlavičky po další člen na dvou mezerách. */
    private static function metoda(string $dokument, string $hlavicka): string
    {
        $zacatek = strpos($dokument, "\n  ".$hlavicka);
        self::assertNotFalse($zacatek, 'Metoda '.$hlavicka.' v dokumentu není.');

        preg_match('/\n  [A-Za-z_$][\w$]*\s*(?:\(|=)/', $dokument, $dalsi, PREG_OFFSET_CAPTURE, $zacatek + 3);

        return substr($dokument, $zacatek, ($dalsi[0][1] ?? strlen($dokument)) - $zacatek);
    }

    public function test_oba_dokumenty_hledaji_fotky_na_serveru_s_prodlevou_a_poradim(): void
    {
        foreach ([self::POCITAC, self::TELEFON] as $nazev) {
            $dokument = self::dokument($nazev);

            $hledani = self::metoda($dokument, 'hledani() {');
            // Prodleva po úhozu: starý časovač se ruší, požadavek odchází až po 250 ms.
            $this->assertStringContainsString('clearTimeout(h.casovac);', $hledani, $nazev);
            $this->assertStringContainsString('h.casovac = setTimeout(() => this.hledaniNacti(seq, 1), 250);', $hledani, $nazev);
            $this->assertStringContainsString('h.seq++', $hledani, $nazev);
            // Krátký dotaz na server nejde.
            $this->assertStringContainsString('q.length < 2', $hledani, $nazev);

            $nacti = self::metoda($dokument, 'hledaniNacti(');
            $this->assertStringContainsString("window.GalerieApi.get('hledat?q=' + encodeURIComponent(h.q) + '&strana=' + strana + '&limit=60')", $nacti, $nazev);
            // Odpověď na starší dotaz se zahodí — v úspěchu i v chybě.
            $this->assertSame(2, substr_count($nacti, 'if (h.seq !== seq) return;'), $nazev.': odpověď na starší dotaz se musí zahodit.');
            // Chyba jen přepne zpátky na filtr v prohlížeči: žádná hláška, žádné opakování.
            $this->assertStringContainsString('h.chyba = true', $nacti, $nazev);
            $this->assertStringNotContainsString('this.toast(', $nacti, $nazev);
            $this->assertStringNotContainsString('setInterval', $nacti, $nazev);
            $this->assertStringContainsString("res.uroven === 'or'", $nacti, $nazev);

            // Výsledky patří jen tomuhle oknu, ne do stavu, který se ukládá dvojici.
            $this->assertStringContainsString('this._hl = {', $hledani, $nazev);
            $this->assertStringNotContainsString('setState', $hledani.$nacti, $nazev);

            $this->assertStringContainsString('Nic neobsahuje všechna slova — ukazuji nejbližší shody', $dokument, $nazev);
            $this->assertStringContainsString('hledaniDalsi() {', $dokument, $nazev);
        }
    }

    public function test_knihovna_pocitace_ukazuje_vysledky_serveru(): void
    {
        $pocitac = self::dokument(self::POCITAC);

        $visible = self::metoda($pocitac, 'visible() {');
        $this->assertStringContainsString('const hl = this.hledani();', $visible);
        $this->assertStringContainsString('(zeServeru || this.photos())', $visible);
        // Bez odpovědi serveru filtr v prohlížeči — po slovech a bez diakritiky, i popisek a lidé.
        $this->assertStringContainsString('this.shoda(this.hledaniText(p), q)', $visible);
        $this->assertStringNotContainsString('.toLowerCase().includes(q)', $visible);
        $text = self::metoda($pocitac, 'hledaniText(');
        foreach (['p.caption', 'p.people', 'p.dayLabel', 'p.dateShort'] as $pole) {
            $this->assertStringContainsString($pole, $text);
        }

        // Prohlížeč fotky otevře i fotku, která v načtené knihovně není, a listuje výsledky.
        $this->assertStringContainsString('if (! this.fotkaPodleId(id)) return;', self::metoda($pocitac, 'openLb('));
        $this->assertStringContainsString('const curZdroj = s.lb ? this.fotkaPodleId(s.lb) : null;', $pocitac);
        $this->assertStringContainsString('const ph = this.lbSeznam()', self::metoda($pocitac, 'step('));

        // Poznámka a další stránka v mřížce; globální hledání nabídne všechny výsledky.
        $this->assertStringContainsString('<sc-if value="{{ libHledaniPozn }}">', $pocitac);
        $this->assertStringContainsString('onClick="{{ libHledaniNacist }}"', $pocitac);
        $this->assertStringContainsString("libHledaniDalsiLabel: hlStav && hlStav.nacita && hlVysl ? 'Načítám…' : 'Načíst další'", $pocitac);
        $this->assertStringContainsString('Ukázat všechny výsledky v knihovně', $pocitac);
        $this->assertStringContainsString('<sc-if value="{{ srHledaniPozn }}">', $pocitac);
        // Hledaný text je filtr mřížky a jde odebrat.
        $this->assertStringContainsString("label: 'Hledání: „' + s.query.trim() + '“', remove: () => this.setState({ query: '' })", $pocitac);
    }

    public function test_telefon_ma_ve_vysledcich_fotky(): void
    {
        $telefon = self::dokument(self::TELEFON);

        $this->assertStringContainsString("const srHl = scopeOk('photos') && !nl.todo && !nl.agg ? this.hledani() : null;", $telefon);
        $this->assertStringContainsString('<sc-for list="{{ srFotky }}" as="f"', $telefon);
        $this->assertStringContainsString('onClick="{{ srFotkyNacist }}"', $telefon);
        // Klepnutí otevře prohlížeč nad výsledky; ten najde i fotku mimo načtenou knihovnu.
        $this->assertStringContainsString('tap: () => this.setState({ lb: p.id, lbList: srHlFotky.map(x => x.id), lbAuto: false })', $telefon);
        $this->assertStringContainsString('lib.find(p => p.id === id) || this.hledaniFotka(id)', $telefon);
        // Dlaždice ze serveru se převede na fotku telefonu (přehrání, den, místo).
        $prevod = self::metoda($telefon, 'hledaniNaTelefon(');
        foreach (['play: f.video', 'day: f.dayLabel', 'video: !!f.isVideo', 'full: f.full'] as $pole) {
            $this->assertStringContainsString($pole, $prevod);
        }
    }

    public function test_shoda_je_v_kazdem_dokumentu_jednou_a_bez_diakritiky(): void
    {
        foreach ([self::POCITAC, self::TELEFON] as $nazev) {
            $dokument = self::dokument($nazev);

            $this->assertSame(1, substr_count($dokument, "\n  shoda(text, q) {"), $nazev.': pomocník shoda() má být právě jeden.');
            $shoda = self::metoda($dokument, 'shoda(text, q) {');
            // Rozložit na písmeno + značku (NFD) a značky zahodit: „Západ" → „zapad".
            $this->assertStringContainsString(".normalize('NFD').replace(/\\p{M}/gu, '').toLowerCase()", $shoda, $nazev);
            $this->assertStringContainsString('slova.every(w => kde.indexOf(w) >= 0)', $shoda, $nazev);
        }
    }

    public function test_filtry_pocitace_jdou_pres_shodu(): void
    {
        $pocitac = self::dokument(self::POCITAC);

        foreach ([
            'this.shoda([r.name, r.cat, r.account, r.note], q)',      // transakce
            'this.shoda([d.title, d.text], q)',                        // deník
            'this.shoda(m.text, q)',                                   // zprávy
            'this.shoda([title, where], q)',                           // cesty
            'this.shoda([PLACES[k].title, PLACES[k].city, PLACES[k].kind], q)', // místa
            'this.shoda([r.t, r.m], xq)',                              // řádky sekcí
            'const hit = txt => this.shoda(txt, q);',                  // globální hledání: deník, zprávy, výdaje
            'this.shoda(a.name, s.query)',                             // globální hledání: alba
            "return this.shoda(vals.map(v => v == null ? '' : v), this.nl().resid || '');", // srTextHit
        ] as $vyraz) {
            $this->assertStringContainsString($vyraz, $pocitac);
        }
        // Recepty: seznam i počet v záhlaví stejně.
        $this->assertSame(2, substr_count($pocitac, 'this.shoda([RECIPES[k].title, RECIPES[k].kind, RECIPES[k].source], q)'));

        foreach ([
            "(d.title + ' ' + d.text).toLowerCase()",
            "RECIPES[k].source).toLowerCase()",
            "(m.text || '').toLowerCase().indexOf(q)",
            "(title + ' ' + where).toLowerCase()",
            "PLACES[k].kind).toLowerCase()",
            "(r.t + ' ' + r.m).toLowerCase()",
            'x.label.toLowerCase().includes(s.query.toLowerCase())',
        ] as $stary) {
            $this->assertStringNotContainsString($stary, $pocitac);
        }
    }

    public function test_filtry_telefonu_jdou_pres_shodu(): void
    {
        $telefon = self::dokument(self::TELEFON);

        $this->assertStringContainsString('src.filter(r => this.shoda([r.t, r.m], q))', $telefon);       // seznamy „Vše"
        $this->assertStringContainsString('return this.shoda([r.title, r.meta], q);', $telefon);        // sekce
        $this->assertStringContainsString('const hit = txt => this.shoda(txt, q);', $telefon);          // hledání větou
        $this->assertStringNotContainsString("(r.t + ' ' + r.m).toLowerCase().indexOf(q)", $telefon);
        $this->assertStringNotContainsString("(r.title + ' ' + r.meta).toLowerCase().indexOf(q)", $telefon);
    }

    /**
     * „Letos" a „loni" se počítají z dnešního data, ne z napsaného roku.
     *
     * Telefon měl „loni" napevno jako 2025 a „letos" v ukázce jako 2026 —
     * od ledna by hledal o rok vedle.
     */
    public function test_letos_a_loni_podle_dnesniho_data(): void
    {
        foreach ([self::POCITAC => 'nlParse(raw) {', self::TELEFON => 'mNlParse(raw) {'] as $nazev => $hlavicka) {
            $parser = self::metoda(self::dokument($nazev), $hlavicka);

            preg_match_all('/^.*\((?:letos|loni)\|.*$/m', $parser, $vetve);
            $this->assertCount(2, $vetve[0], $nazev.': větev pro „letos" i „loni".');

            foreach ($vetve[0] as $radek) {
                $this->assertDoesNotMatchRegularExpression('/(?<![\d\\\\])(?:19|20)\d\d(?!\d)/', $radek, $nazev.': napsaný rok ve větvi „'.trim($radek).'"');
                $this->assertStringContainsString('this.dnes().getFullYear()', $radek, $nazev);
            }
            $this->assertStringContainsString('year = String(this.dnes().getFullYear() - 1);', $parser, $nazev);
        }
    }

    public function test_trezor_a_cloud_rikaji_pravdu(): void
    {
        foreach ([self::POCITAC, self::TELEFON] as $nazev) {
            $this->assertStringContainsString('Fotky v trezoru se do cloudu nekopírují — zůstávají jen na serveru.', self::dokument($nazev), $nazev);
        }

        $pocitac = self::dokument(self::POCITAC);
        $this->assertStringNotContainsString('Galerie si u sebe drží jen náhledy', $pocitac);
        $this->assertStringNotContainsString('Originály fotek a videí leží na Google účtu', $pocitac);
        $this->assertStringNotContainsString('Originály na Google Drivu', $pocitac);
        $this->assertStringContainsString('Položky v trezoru se do cloudu nekopírují.', self::metoda($pocitac, 'diskVals('));

        // Prohlížeč fotky: podle trezoru, ne „bezpečně uložen" u všeho.
        $this->assertStringNotContainsString('Originál je bezpečně uložen', $pocitac);
        $this->assertStringContainsString('{{ lbUlozeniText }}', $pocitac);
        $this->assertStringContainsString("lbUlozeniText: curVTrezoru ? 'V trezoru — jen na serveru, do cloudu se nekopíruje' : 'Originál je uložený na serveru'", $pocitac);
        $this->assertStringContainsString('const curVTrezoru = !!(cur && this.vaultIds().includes(cur.id));', $pocitac);
    }
}
