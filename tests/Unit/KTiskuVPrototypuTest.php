<?php

namespace Tests\Unit;

use App\Support\TrasyPrototypu;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Fotky „k tisku" v obou dokumentech prototypu.
 *
 * Dvojice chtěla fotku rychlou ikonou označit k tisku a pak všechny označené
 * stáhnout najednou do telefonu; stažené se zapíšou jako sada, kterou jde
 * stáhnout znovu. Ikona tiskárny je na dlaždici i v prohlížeči fotky na počítači
 * i na telefonu, záložka „K tisku" v Tisku a fotoknihách ukáže označené
 * a „Dříve staženo". Označení jde přes `/api/k-tisku`, nikdy přes sdílený stav
 * — ten se skládá z opisů obou zařízení a starší opis by označení přepsal.
 */
class KTiskuVPrototypuTest extends TestCase
{
    private const POCITAC = 'galerie-desktop.dc.html';

    private const TELEFON = 'galerie-mobil.dc.html';

    /** Metody, které mají oba dokumenty — každou právě jednou (druhá definice by tiše přepsala první). */
    private const METODY = [
        'tiskStav() {', 'tiskNaServeru() {', 'jeKTisku(p) {', 'prepniKTisku(p) {', 'kTiskuNacti() {',
        'kTiskuStahnout() {', 'kTiskuZnovu(sada) {', 'kTiskuDorucit(sada, soubory) {', 'kTiskuArchiv(sada) {',
        'kTiskuPriprav(sada, soubory, od) {', 'kTiskuUloz() {', 'kTiskuVals() {',
    ];

    private static function soubor(string $cesta): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$cesta);
    }

    private static function dokument(string $nazev): string
    {
        return self::soubor('resources/galerie/'.$nazev);
    }

    /** Tělo metody třídy `Component` od hlavičky po další člen na dvou mezerách. */
    private static function metoda(string $dokument, string $hlavicka): string
    {
        $zacatek = strpos($dokument, "\n  ".$hlavicka);
        self::assertNotFalse($zacatek, 'Metoda '.$hlavicka.' v dokumentu není.');

        preg_match('/\n  [A-Za-z_$][\w$]*\s*(?:\(|=)/', $dokument, $dalsi, PREG_OFFSET_CAPTURE, $zacatek + 3);

        return substr($dokument, $zacatek, ($dalsi[0][1] ?? strlen($dokument)) - $zacatek);
    }

    public function test_metody_k_tisku_jsou_v_kazdem_dokumentu_prave_jednou(): void
    {
        foreach ([self::POCITAC, self::TELEFON] as $nazev) {
            $dokument = self::dokument($nazev);

            foreach (self::METODY as $hlavicka) {
                $this->assertSame(1, substr_count($dokument, "\n  ".$hlavicka), $nazev.': '.$hlavicka);
            }
        }

        $this->assertSame(1, substr_count(self::dokument(self::POCITAC), "\n  kTiskuChyba(e, vychozi) {"));
        $this->assertSame(1, substr_count(self::dokument(self::TELEFON), "\n  kTiskuFotka(id) {"));
    }

    public function test_ikona_tiskarny_je_na_dlazdici_i_v_prohlizeci(): void
    {
        $pocitac = self::dokument(self::POCITAC);
        $telefon = self::dokument(self::TELEFON);

        // Počítač: mřížka knihovny a album (dlaždice z `tile`), prohlížeč fotky.
        $this->assertSame(2, substr_count($pocitac, '<sc-if value="{{ p.tiskBtn }}"><button class="tisk-q {{ p.tiskCls }}" onClick="{{ p.tiskPrepni }}"'));
        $this->assertSame(1, substr_count($pocitac, 'onClick="{{ lbTiskPrepni }}"'));
        $this->assertStringContainsString('tiskPrepni: e => { if (e && e.stopPropagation) e.stopPropagation(); this.prepniKTisku(p); },', self::metoda($pocitac, 'tile = p => {'));
        $this->assertStringContainsString('lbTiskPrepni: () => { if (cur) this.prepniKTisku(cur); },', $pocitac);
        // Neoznačená ikona se ukáže pod myší; dotykem (iPad) je vidět pořád.
        $this->assertStringContainsString('[role="button"]:hover .tisk-q,.tisk-q:focus-visible{opacity:1}', $pocitac);
        $this->assertStringContainsString('@media (hover:none){.tisk-q{opacity:.85}}', $pocitac);

        // Telefon: tlačítko vedle dlaždice (tlačítko v tlačítku HTML nedovolí) a prohlížeč.
        $this->assertSame(1, substr_count($telefon, '<sc-if value="{{ t.tiskBtn }}"><button onClick="{{ t.tiskPrepni }}"'));
        $this->assertSame(1, substr_count($telefon, 'onClick="{{ lbTiskPrepni }}"'));
        $this->assertStringContainsString('tiskPrepni: () => this.prepniKTisku(p)', $telefon);
        $this->assertStringContainsString('lbTiskPrepni: () => { if (lbP) this.prepniKTisku(lbP); },', $telefon);

        foreach ([$pocitac, $telefon] as $dokument) {
            $this->assertStringContainsString('<i class="ph-duotone ph-printer"', $dokument);
        }
    }

    public function test_zalozka_k_tisku_ma_seznam_stazeni_a_historii(): void
    {
        $data = self::soubor('public/galerie-data.js');
        $this->assertStringContainsString("['Předtisková kontrola', 'print', 'prepress'], ['K tisku', 'print', 'kTisku']] },", $data,
            '„K tisku" je poslední záložka, ať se nepohne `appTab: 0`.');

        $pocitac = self::dokument(self::POCITAC);
        $this->assertSame(1, substr_count($pocitac, '<sc-if value="{{ printIsKTisku }}">'));
        $this->assertStringContainsString("if (key === 'kTisku') return { ...base, ...this.kTiskuVals() };", self::metoda($pocitac, 'printVals(key) {'));
        foreach (['onClick="{{ ktStahnout }}"', 'onClick="{{ ktUloz }}"', '<sc-for list="{{ ktSady }}" as="sd"', 'onClick="{{ sd.znovu }}"', 'onClick="{{ sd.zip }}"', 'Dříve staženo'] as $kus) {
            $this->assertStringContainsString($kus, $pocitac);
        }

        $telefon = self::dokument(self::TELEFON);
        $this->assertSame(1, substr_count($telefon, '<sc-if value="{{ all.isKTisku }}">'));
        $this->assertStringContainsString("isKTisku: key === 'kTisku', kt: key === 'kTisku' ? this.kTiskuVals() : {},", $telefon);
        // Bez tohohle by pod záložkou visel i prázdný stav obecného seznamu.
        $this->assertStringContainsString("|| isRtNext || isOffPwa || key === 'kTisku');", $telefon);
        foreach (['onClick="{{ all.kt.stahnout }}"', 'onClick="{{ all.kt.uloz }}"', '<sc-for list="{{ all.kt.sady }}" as="sd"', 'onClick="{{ sd.znovu }}"', 'Dříve staženo'] as $kus) {
            $this->assertStringContainsString($kus, $telefon);
        }
    }

    public function test_oznaceni_jde_pres_api_a_ne_pres_sdileny_stav(): void
    {
        foreach ([self::POCITAC, self::TELEFON] as $nazev) {
            $dokument = self::dokument($nazev);

            $prepni = self::metoda($dokument, 'prepniKTisku(p) {');
            $this->assertStringContainsString("api.del('k-tisku/oznacene/' + encodeURIComponent(p.id))", $prepni, $nazev);
            $this->assertStringContainsString("api.post('k-tisku/oznacene', { id: p.id })", $prepni, $nazev);
            $this->assertStringContainsString("window.GalerieApi.get('k-tisku')", self::metoda($dokument, 'kTiskuNacti() {'), $nazev);
            $this->assertStringContainsString("window.GalerieApi.post('k-tisku/sady', {})", self::metoda($dokument, 'kTiskuStahnout() {'), $nazev);
            $this->assertStringContainsString("window.GalerieApi.get('k-tisku/sady/' + encodeURIComponent(sada.id))", self::metoda($dokument, 'kTiskuZnovu(sada) {'), $nazev);
            $this->assertStringContainsString('api.download(sada.archiv, sada.zip)', self::metoda($dokument, 'kTiskuArchiv(sada) {'), $nazev);

            // Stav okna, ne stav dvojice: žádný `setState`, překreslí se `forceUpdate()`.
            foreach (['tiskStav() {', 'prepniKTisku(p) {', 'kTiskuNacti() {', 'kTiskuStahnout() {', 'kTiskuPriprav(sada, soubory, od) {', 'kTiskuUloz() {'] as $hlavicka) {
                $telo = self::metoda($dokument, $hlavicka);
                $this->assertStringNotContainsString('setState', $telo, $nazev.': '.$hlavicka);
            }
            $this->assertStringContainsString('this._tisk || (this._tisk = {', self::metoda($dokument, 'tiskStav() {'), $nazev);

            // Po chybě načtení se nezkouší při každém překreslení.
            $this->assertStringContainsString('const smiZnovu = !t.chyba || Date.now() - (t.chybaCas || 0) > 30000;', self::metoda($dokument, 'kTiskuVals() {'), $nazev);
        }
    }

    /** Hláška je zápis stavu — upozornění jen `silent`, žádná v cyklu ani při průběhu přípravy. */
    public function test_hlasky_k_tisku_jsou_tiche_a_ne_v_cyklu(): void
    {
        foreach ([self::POCITAC, self::TELEFON] as $nazev) {
            $dokument = self::dokument($nazev);

            foreach (self::METODY as $hlavicka) {
                $telo = self::metoda($dokument, $hlavicka);
                preg_match_all('/this\.toast\([^;]*;/', $telo, $hlasky);

                foreach ($hlasky[0] as $hlaska) {
                    $this->assertStringContainsString('silent: true', $hlaska, $nazev.': '.$hlavicka);
                }
            }

            $priprava = self::metoda($dokument, 'kTiskuPriprav(sada, soubory, od) {');
            $this->assertStringNotContainsString('this.toast(', $priprava, $nazev);
            $this->assertStringNotContainsString('setInterval', $priprava, $nazev);
        }
    }

    /**
     * Do telefonu přes sdílení souborů, jinak ZIP.
     *
     * Sdílení musí vyjít z ťuknutí: soubory se nejdřív připraví (po dvaceti),
     * teprve další tlačítko zavolá `navigator.share`. Zavřená nabídka není chyba.
     */
    public function test_ulozeni_do_telefonu_sdilenim_se_zalohou_zip(): void
    {
        $api = self::soubor('public/galerie-api.js');
        $this->assertSame(1, substr_count($api, '    soubor: function (path, jmeno, typ) {'));
        $this->assertSame(1, substr_count($api, '    umiSdiletSoubory: function (soubory) {'));
        $this->assertStringContainsString('navigator.canShare({ files: vzor })', $api);
        // ZIP sady jde mimo worker — v jeho paměti by ležel celý.
        $this->assertStringContainsString('if (/^\/api\/k-tisku\/sady\/[^/]+\/archiv$/.test(url.pathname)) return;', self::soubor('resources/galerie/sw.js'));

        foreach ([self::POCITAC, self::TELEFON] as $nazev) {
            $dokument = self::dokument($nazev);

            $dorucit = self::metoda($dokument, 'kTiskuDorucit(sada, soubory) {');
            $this->assertStringContainsString('api.umiSdiletSoubory()', $dorucit, $nazev);
            $this->assertStringContainsString('this.kTiskuArchiv(sada);', $dorucit, $nazev);

            $priprav = self::metoda($dokument, 'kTiskuPriprav(sada, soubory, od) {');
            $this->assertStringContainsString('soubory.slice(od, od + 20)', $priprav, $nazev);
            $this->assertStringContainsString('api.soubor(f.cesta, f.name, f.mime)', $priprav, $nazev);
            $this->assertStringNotContainsString('navigator.share(', $priprav, $nazev);

            $uloz = self::metoda($dokument, 'kTiskuUloz() {');
            $this->assertStringContainsString('navigator.share({ files: pr.pripraveno, title: pr.sada.nazev })', $uloz, $nazev);
            $this->assertStringContainsString("e.name === 'AbortError'", $uloz, $nazev);
            $this->assertStringContainsString("'/stazeno'", $uloz, $nazev);
        }
    }

    /**
     * Menu má vlastní záložku „Pro tisk" vedle „Tisk a fotoknihy".
     *
     * Otevírá stejnou záložku K tisku přímo (bez Návrhů, na kterých `appTab: 0`
     * u `x-tisk` visí), a bere ji z téhož katalogu — žádná druhá šablona ani
     * druhá kopie `kTiskuVals()`.
     */
    public function test_polozka_pro_tisk_je_v_menu_a_ma_vlastni_trasu(): void
    {
        $data = self::soubor('public/galerie-data.js');

        $this->assertSame(1, substr_count($data, "['pro-tisk', 'Pro tisk', 'ph-printer']"),
            '„Pro tisk" musí být v NAV_GROUPS právě jednou.');
        $this->assertSame(1, substr_count($data, "'pro-tisk': { g: 'Vzpomínky', title: 'Pro tisk',"),
            '„Pro tisk" musí mít vlastní záznam v katalogu APP.');
        $this->assertStringContainsString("tabs: [['K tisku', 'print', 'kTisku']] },", $data,
            'Jediná záložka „Pro tisk" musí vést na tutéž `printVals(\'kTisku\')`, ne na kopii.');

        $this->assertSame('pro-tisk', TrasyPrototypu::ADRESY['pro-tisk'] ?? null,
            'Trasa „pro-tisk" musí být v TrasyPrototypu::ADRESY, jinak neplatí obnovení stránky ani hlubší odkaz.');
        $this->assertSame('/galerie/pro-tisk', TrasyPrototypu::url('pro-tisk'));

        foreach ([self::POCITAC, self::TELEFON] as $nazev) {
            $dokument = self::dokument($nazev);

            // Odznak počítá totéž `kTiskuVals()` jako záložka „K tisku" — žádné druhé volání na server.
            $this->assertSame(1, substr_count($dokument, "'pro-tisk': this.kTiskuVals()"), $nazev);
        }

        $this->assertStringContainsString('ktPocet: n,', self::metoda($this->dokument(self::POCITAC), 'kTiskuVals() {'));
        $this->assertStringContainsString('pocet: n,', self::metoda($this->dokument(self::TELEFON), 'kTiskuVals() {'));
    }

    /** Skript komponenty obou dokumentů jde přeložit. */
    public function test_skripty_obou_dokumentu_jsou_platny_javascript(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node.js není na PATH — syntaxe dokumentů se nedá ověřit.');
        }

        foreach ([self::POCITAC, self::TELEFON] as $nazev) {
            $dokument = self::dokument($nazev);
            $zacatek = strpos($dokument, 'data-dc-script');
            $this->assertNotFalse($zacatek, $nazev);
            $zacatek = strpos($dokument, ">\n", $zacatek) + 2;
            $konec = strpos($dokument, "\n</script>", $zacatek);
            $this->assertNotFalse($konec, $nazev);

            $soubor = tempnam(sys_get_temp_dir(), 'ktisku').'.mjs';
            file_put_contents($soubor, substr($dokument, $zacatek, $konec - $zacatek));

            try {
                $proces = new Process([$node, '--check', $soubor]);
                $proces->run();
                $this->assertTrue($proces->isSuccessful(), $nazev.":\n".$proces->getErrorOutput());
            } finally {
                @unlink($soubor);
            }
        }
    }
}
