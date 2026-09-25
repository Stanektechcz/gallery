<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Měny v dokumentu počítače.
 *
 * Hlavní měna je koruna, vedle ní eura a dolary. Součty se ukazují v korunách
 * s datem kurzu ECB, nikdy jako smíšené číslo; co kurz nemá, stojí zvlášť.
 * Dluhy mezi partnery se počítají v každé měně zvlášť a nesčítají se.
 * Ukázková data nové klíče nemají — každá obrazovka proto musí mít náhradu.
 */
class MenyVPocitaciTest extends TestCase
{
    private static function dokument(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/galerie/galerie-desktop.dc.html');
    }

    /** Tělo metody třídy `Component` od hlavičky po další člen na dvou mezerách. */
    private static function metoda(string $dokument, string $hlavicka): string
    {
        $zacatek = strpos($dokument, "\n  ".$hlavicka);
        self::assertNotFalse($zacatek, 'Metoda '.$hlavicka.' v dokumentu není.');

        preg_match('/\n  [A-Za-z_$][\w$]*\s*(?:\(|=)/', $dokument, $dalsi, PREG_OFFSET_CAPTURE, $zacatek + 3);

        return substr($dokument, $zacatek, ($dalsi[0][1] ?? strlen($dokument)) - $zacatek);
    }

    /** Skript komponenty (`data-dc-script`) bez obalu `<script>`. */
    private static function skript(string $dokument): string
    {
        $zacatek = strpos($dokument, 'data-dc-script');
        self::assertNotFalse($zacatek);
        $zacatek = strpos($dokument, ">\n", $zacatek) + 2;
        $konec = strpos($dokument, "\n</script>", $zacatek);
        self::assertNotFalse($konec);

        return substr($dokument, $zacatek, $konec - $zacatek);
    }

    private static function node(): string
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            self::markTestSkipped('Node.js není na PATH — syntaxe počítače se nedá ověřit.');
        }

        return $node;
    }

    public function test_skript_pocitace_je_platny_javascript(): void
    {
        $soubor = tempnam(sys_get_temp_dir(), 'pocitac').'.mjs';
        file_put_contents($soubor, self::skript(self::dokument()));

        try {
            $proces = new Process([self::node(), '--check', $soubor]);
            $proces->run();

            $this->assertTrue($proces->isSuccessful(), "Skript počítače nejde přeložit:\n".$proces->getErrorOutput());
        } finally {
            @unlink($soubor);
        }
    }

    public function test_pomocnici_men_jsou_v_dokumentu_prave_jednou(): void
    {
        $pocitac = self::dokument();

        foreach (['znakMeny(', 'vMene(', 'poMenachText(', 'hlavniMena(', 'menyVolby('] as $hlavicka) {
            // Druhá definice by tiše přepsala první.
            $this->assertSame(1, substr_count($pocitac, "\n  ".$hlavicka), $hlavicka.' je v dokumentu víckrát nebo vůbec.');
        }
    }

    /**
     * Pomocníci měn opravdu počítají, jak mají.
     *
     * Metody se vytáhnou z dokumentu a pustí v Node nad objektem se stejnými
     * jmény — tělo metody třídy je platná metoda objektového literálu.
     */
    public function test_pomocnici_men_pocitaji_spravne(): void
    {
        $pocitac = self::dokument();
        $metody = array_map(
            fn (string $h) => trim(self::metoda($pocitac, $h)),
            ['kc(', 'znakMeny(', 'vMene(', 'poMenachText(', 'hlavniMena(', 'menyVolby('],
        );

        $js = "globalThis.window = { GalerieData: { MENA: 'Kč' } };\n"
            ."globalThis.FIN = {}; globalThis.P60 = {};\n"
            ."const o = {\n".implode("\n,\n", $metody)."\n};\n"
            ."const nbsp = s => s.replace(/\\s/g, ' ');\n"
            ."const out = {\n"
            ."  znaky: [o.znakMeny('CZK'), o.znakMeny('eur'), o.znakMeny('USD'), o.znakMeny('GBP'), o.znakMeny('CHF'), o.znakMeny('')],\n"
            ."  czk: nbsp(o.vMene(1500, 'CZK')), eur: nbsp(o.vMene(12.4, 'EUR')), znak: nbsp(o.vMene(100, '€')), bez: nbsp(o.vMene(2500)),\n"
            ."  rozpis: nbsp(o.poMenachText({ EUR: 12, USD: 0, CZK: 500 })),\n"
            ."  hlavniBez: o.hlavniMena(),\n"
            ."  meny: o.menyVolby().map(x => x.value), popisek: o.menyVolby()[1].label,\n"
            ."};\n"
            ."globalThis.FIN = { souhrn: { mena: 'CZK' } };\n"
            ."out.hlavni = o.hlavniMena();\n"
            ."window.GalerieData.MENY = ['EUR', 'CZK', 'usd', 'x'];\n"
            ."out.menyServer = o.menyVolby().map(x => x.value);\n"
            ."process.stdout.write(JSON.stringify(out));\n";

        $soubor = tempnam(sys_get_temp_dir(), 'meny').'.mjs';
        file_put_contents($soubor, $js);

        try {
            $proces = new Process([self::node(), $soubor]);
            $proces->run();
            $this->assertTrue($proces->isSuccessful(), $proces->getErrorOutput());
            $v = json_decode($proces->getOutput(), true);
        } finally {
            @unlink($soubor);
        }

        $this->assertSame(['Kč', '€', '$', '£', 'CHF', 'Kč'], $v['znaky']);
        $this->assertSame('1 500 Kč', $v['czk']);
        $this->assertSame('12,40 €', $v['eur']);
        $this->assertSame('100 €', $v['znak']);
        // Bez měny (ukázková data) se píše jako dřív přes `kc()`.
        $this->assertSame('2 500 Kč', $v['bez']);
        // Nulové měny se vynechají; měny se nesčítají do jednoho čísla.
        $this->assertSame('12 € · 500 Kč', $v['rozpis']);
        $this->assertSame('CZK', $v['hlavniBez']);
        $this->assertSame('CZK', $v['hlavni']);
        // Bez `MENY` (ukázka, dvojice bez financí) tři měny; CZK vždy první.
        $this->assertSame(['CZK', 'EUR', 'USD'], $v['meny']);
        $this->assertSame('EUR · €', $v['popisek']);
        $this->assertSame(['CZK', 'EUR', 'USD'], $v['menyServer']);
    }

    public function test_finance_berou_souhrn_ze_serveru_a_ucty_svou_menu(): void
    {
        $pocitac = self::dokument();
        $fin = self::metoda($pocitac, 'finVals(');

        $this->assertStringContainsString('FIN.souhrn', $fin);
        $this->assertStringContainsString('souhrn.texty', $fin);
        $this->assertStringContainsString('popisek', $fin);
        // Každý účet ve své měně: hotový text [11], jinak částka [2] se znakem kódu [9].
        $this->assertStringContainsString('a[11] || this.vMene(a[2] || 0, a[9])', $fin);
        $this->assertStringContainsString('this.vMene(u[2], u[8])', $fin);
        $this->assertStringContainsString('finRate', $fin);
        $this->assertStringContainsString('{{ finRate }}', $pocitac);

        $ucet = self::metoda($pocitac, 'acctVals(');
        $this->assertStringContainsString('u[11] || this.vMene(u[2] || 0, u[9])', $ucet);
    }

    public function test_transakce_scitaji_hlavni_menu_a_bez_kurzu_zvlast(): void
    {
        $pocitac = self::dokument();
        $tx = self::metoda($pocitac, 'txVals(');

        $this->assertStringContainsString('.hl', $tx);
        $this->assertStringContainsString('bez kurzu', $tx);
        $this->assertStringContainsString('sectiHl', $tx);
        $this->assertStringContainsString('castkaRadku', $tx);
        $this->assertStringContainsString('.znak', $tx);
        $this->assertStringContainsString('{{ txIncomeNote }}', $pocitac);
        $this->assertStringContainsString('{{ txSpendNote }}', $pocitac);
    }

    public function test_dluhy_mezi_partnery_zustavaji_po_menach(): void
    {
        $pocitac = self::dokument();
        $bud = self::metoda($pocitac, 'budgetVals(');

        $this->assertStringContainsString('BUD.paidPoMenach', $bud);
        $this->assertStringContainsString('BUD.paid ||', $bud);
        $this->assertStringContainsString('dluhVMene', $bud);
        $this->assertStringContainsString('setlOther', $bud);
        $this->assertStringContainsString('setlHasOther', $bud);
        $this->assertStringContainsString('{{ setlOther }}', $pocitac);
        // `paidPrepocet` je jen popisek — nikdy se z něj nepočítá, kdo komu dluží.
        $this->assertStringNotContainsString('paidPrepocet', $bud);
        // Vyrovnání se zapisuje jen v hlavní měně.
        $this->assertStringContainsString("{ castka: owe, od, komu }", $bud);
    }

    public function test_rozpocet_pise_ve_sve_mene_a_ukazuje_poznamky(): void
    {
        $pocitac = self::dokument();
        $bud = self::metoda($pocitac, 'budgetVals(');

        $this->assertStringContainsString('const bk = n => this.vMene(n, BUD.mena)', $bud);
        $this->assertStringContainsString('c[7].text', $bud);
        $this->assertStringContainsString('BUD.mimoMenu', $bud);
        $this->assertStringContainsString('monthsPoznamka', $bud);
        $this->assertStringContainsString('poznamka', $bud);
        foreach (['{{ budMenaNote }}', '{{ budMonthsNote }}', '{{ budYearNote }}', '{{ c.menaNote }}'] as $vazba) {
            $this->assertStringContainsString($vazba, $pocitac);
        }
    }

    public function test_cesta_scita_jen_svou_menu(): void
    {
        $pocitac = self::dokument();
        $now = self::metoda($pocitac, 'nowVals(');

        $this->assertStringContainsString('r[4]', $now);
        $this->assertStringContainsString('vMeneCesty', $now);
        $this->assertStringContainsString('T.znak || T.mena', $now);
        $this->assertStringContainsString('spentOther', $now);
        $this->assertStringContainsString('spentMain', $now);
        $this->assertStringContainsString('{{ nowOther }}', $pocitac);
        // Dlaždice na přehledu zbytek fondu také ve měně cesty.
        $this->assertStringContainsString('this.vMene(T.fund - spentAll, T.znak || T.mena)', $pocitac);
    }

    public function test_rozbory_pisou_ve_mene_radku_a_ukazuji_kurz(): void
    {
        $pocitac = self::dokument();

        $p60 = self::metoda($pocitac, 'p60Vals(');
        $this->assertStringContainsString('P60.poznamka || P60.popisek', $p60);
        // Obě větve (s daty i bez) nesou poznámku.
        $this->assertSame(2, substr_count($p60, 'p60Rate: p60Rate, p60HasRate: !!p60Rate'));
        $this->assertStringContainsString('{{ p60Rate }}', $pocitac);

        $hz = self::metoda($pocitac, 'horizonVals(');
        $this->assertStringContainsString('h.mena', $hz);
        $this->assertStringContainsString('skupina.slice()', $hz);
        $this->assertStringNotContainsString('this.kc(', $hz);

        $cs = self::metoda($pocitac, 'costVals(');
        $this->assertStringContainsString('this.vMene(Math.round(r[1] / div), r[5])', $cs);
        $this->assertStringContainsString('rate: r[6]', $cs);
        $this->assertStringContainsString('{{ r.rate }}', $pocitac);

        $kal = self::metoda($pocitac, 'kalibVals(');
        $this->assertStringContainsString('this.vMene(e.est, e.mena)', $kal);
        $this->assertStringContainsString('e.popisek', $kal);

        $env = self::metoda($pocitac, 'envVals(');
        $this->assertStringContainsString('this.vMene(n, ENV.mena)', $env);
        $this->assertStringContainsString('ENV.popisek', $env);
        $this->assertStringContainsString('{{ envRate }}', $pocitac);

        $tc = self::metoda($pocitac, 'tripCostVals(');
        $this->assertStringContainsString('this.vMene(v, c.mena)', $tc);
        $this->assertStringContainsString('c.popisek', $tc);
        $this->assertStringNotContainsString('this.kc(', $tc);
        $this->assertStringContainsString('{{ tcRate }}', $pocitac);
    }

    public function test_investice_scitaji_jen_prepoctene_zustatky(): void
    {
        $inv = self::metoda(self::dokument(), 'investVals(');

        // Koruny kurzem ECB (`u[10]`); bez kurzu (`null`) mimo součet a zvlášť ve své měně.
        $this->assertStringContainsString("const bezKurzu = !!u[9] && u[10] === null", $inv);
        $this->assertStringContainsString("typeof u[10] === 'number' ? u[10] : (u[2] || 0)", $inv);
        $this->assertStringContainsString('const list = vse.filter(p => !p.bezKurzu)', $inv);
        $this->assertStringContainsString("'Bez kurzu, nezapočteno: '", $inv);
        $this->assertStringContainsString('invRateNote', $inv);
        $this->assertStringNotContainsString('val: u[2] || 0', $inv);
        $this->assertStringContainsString('{{ invRateNote }}', self::dokument());
    }

    public function test_rozpocet_dalsi_cesty_jen_ve_stejne_mene(): void
    {
        $tc = self::metoda(self::dokument(), 'tripCostVals(');

        $this->assertStringContainsString('const menaTeto = c.mena || null, menaDalsi = dalsi.mena || null;', $tc);
        $this->assertStringContainsString('if (!(menaTeto && menaDalsi && menaTeto === menaDalsi))', $tc);
        // Kontrola měny je před zápisem rozpočtu, ne až po něm.
        $this->assertLessThan(strpos($tc, "patch('v1/trips/'"), strpos($tc, 'menaTeto === menaDalsi'));
    }

    public function test_ucet_i_cesta_nabizi_vyber_meny(): void
    {
        $pocitac = self::dokument();

        $formulare = self::metoda($pocitac, 'zapForm(');
        $this->assertStringContainsString("d: 'Měna'", $formulare);
        $this->assertStringContainsString('dVolby: true', $formulare);
        $this->assertStringContainsString("(d || 'CZK')", $formulare);

        $this->assertStringContainsString('xaDOpts: zapF && zapF.dVolby ? this.menyVolby() : []', $pocitac);
        $this->assertStringContainsString("xaDSel: s.xaD || 'CZK'", $pocitac);
        $this->assertStringContainsString('<select class="input" value="{{ xaDSel }}" onChange="{{ xaSetD }}"', $pocitac);

        $cesta = self::metoda($pocitac, 'tripDlgVals(');
        $this->assertStringContainsString("trMena: d.mena || 'CZK'", $cesta);
        $this->assertStringContainsString('trMeny: this.menyVolby()', $cesta);
        $this->assertStringNotContainsString("currency: 'CZK'", $cesta);
        $this->assertStringContainsString('<select class="input" value="{{ trMena }}" onChange="{{ trSetMena }}"', $pocitac);
    }
}
