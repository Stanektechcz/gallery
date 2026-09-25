<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Měny v dokumentu telefonu.
 *
 * Dvojice se rozhodla: hlavní měna je koruna, vedle ní eura a dolary. Součty
 * se ukazují v korunách s datem kurzu ECB, nikdy jako smíšené číslo; co kurz
 * nemá, stojí zvlášť („+ 12 € v útratách bez kurzu"). Telefon nese vlastní
 * kopii dat i metod, takže co platí na počítači, se tu hlídá znovu.
 */
class MenyVTelefonuTest extends TestCase
{
    private static function dokument(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/galerie/galerie-mobil.dc.html');
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
        $konec = strrpos($dokument, "\n</script>");

        return substr($dokument, $zacatek, $konec - $zacatek);
    }

    private static function node(): string
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            self::markTestSkipped('Node.js není na PATH — syntaxe telefonu se nedá ověřit.');
        }

        return $node;
    }

    public function test_skript_telefonu_je_platny_javascript(): void
    {
        $soubor = tempnam(sys_get_temp_dir(), 'mobil').'.mjs';
        file_put_contents($soubor, self::skript(self::dokument()));

        try {
            $proces = new Process([self::node(), '--check', $soubor]);
            $proces->run();

            $this->assertTrue($proces->isSuccessful(), "Skript telefonu nejde přeložit:\n".$proces->getErrorOutput());
        } finally {
            @unlink($soubor);
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
        $telefon = self::dokument();
        $metody = array_map(
            fn (string $h) => trim(self::metoda($telefon, $h)),
            ['kc(', 'znakMeny(', 'castka(', 'poMenachText(', 'menyNabidka(', 'txSoucet(', 'txCastka('],
        );

        $js = "globalThis.window = { GalerieData: { MENA: 'Kč' } };\n"
            // Čárka na vlastním řádku: tělo může končit komentářem k další metodě.
            ."const o = {\n".implode("\n,\n", $metody)."\n};\n"
            ."const nbsp = s => s.replace(/\\s/g, ' ');\n"
            ."const out = {\n"
            ."  czk: o.znakMeny('CZK'), eur: o.znakMeny('eur'), usd: o.znakMeny('USD'), gbp: o.znakMeny('GBP'), chf: o.znakMeny('CHF'), prazdny: o.znakMeny(''),\n"
            ."  kc: nbsp(o.kc(1500)), castkaEur: nbsp(o.castka(1234.4, '€')), castkaBez: nbsp(o.castka(undefined)),\n"
            ."  rozpis: nbsp(o.poMenachText({ EUR: 12, USD: 0, CZK: -500 })),\n"
            ."  meny: o.menyNabidka(),\n"
            ."  soucet: o.txSoucet([\n"
            ."    { amount: -500, hl: -500, znak: 'Kč', mena: 'CZK', cizi: false },\n"
            ."    { amount: -12.4, hl: -310, znak: '€', mena: 'EUR', cizi: true },\n"
            ."    { amount: -20, hl: null, znak: '$', mena: 'USD', cizi: true },\n"
            ."    { amount: -300, hl: -300, znak: 'Kč', mena: null, cizi: false }\n"
            ."  ]),\n"
            ."  radek: nbsp(o.txCastka({ amount: -12.4, znak: '€' })),\n"
            ."};\n"
            ."window.GalerieData.MENY = ['CZK', 'EUR'];\n"
            ."out.menyServer = o.menyNabidka();\n"
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

        $this->assertSame(['Kč', '€', '$', '£', 'CHF', 'Kč'], [$v['czk'], $v['eur'], $v['usd'], $v['gbp'], $v['chf'], $v['prazdny']]);
        $this->assertSame('1 500 Kč', $v['kc']);
        $this->assertSame('1 234 €', $v['castkaEur']);
        $this->assertSame('0 Kč', $v['castkaBez']);
        $this->assertSame('12 € · 500 Kč', $v['rozpis']);
        // Bez `MENY` (prázdná dvojice) nabídka padá na tři měny; se serverem bere jeho.
        $this->assertSame(['CZK', 'EUR', 'USD'], $v['meny']);
        $this->assertSame(['CZK', 'EUR'], $v['menyServer']);
        // Koruny + přepočtená eura dohromady, dolary bez kurzu zvlášť — nikdy smíšené číslo.
        $this->assertSame(1110, $v['soucet']['sum']);
        $this->assertSame(['USD' => 20], $v['soucet']['bez']);
        $this->assertSame(1, $v['soucet']['cizi']);
        $this->assertSame('− 12 €', $v['radek']);
    }

    public function test_hlavni_mena_zustava_v_kc_a_pomocnik_je_jednou(): void
    {
        $telefon = self::dokument();

        $this->assertStringContainsString("kc(n) { return Math.round(n).toLocaleString('cs-CZ') + ' ' + ((window.GalerieData || {}).MENA || 'Kč'); }", $telefon);

        foreach (['znakMeny', 'castka', 'poMenachText', 'menyNabidka', 'txSoucet', 'txCastka'] as $jmeno) {
            $this->assertSame(1, preg_match_all('/\n  '.$jmeno.'\(/', $telefon), $jmeno.' má být v dokumentu právě jednou.');
        }
    }

    /** Platby: měna ze serveru (`TX[i][7]`) jde do telefonní kopie a součty jdou z `hl`. */
    public function test_mesicni_soucty_a_rozpocet_scitaji_hl(): void
    {
        $telefon = self::dokument();

        $this->assertStringContainsString("m.mena ? { mena: m.mena, znak: m.znak, hl: m.hl === undefined ? t[4] : m.hl } : null", $telefon);
        $this->assertStringContainsString("nahrad(CATS, ((G.BUD || {}).cats || []).map(c => [c[0], c[1] || 0, c[2] || 0, (c[7] && c[7].text) || '']));", $telefon);

        $vse = self::metoda($telefon, 'txAll(');
        $this->assertStringContainsString('const m = t[9] || null;', $vse);
        // Ukázka a řádky zapsané v telefonu: hlavní měna, `hl` = částka.
        $this->assertStringContainsString('hl: m ? m.hl : t[4]', $vse);

        $soucet = self::metoda($telefon, 'txSoucet(');
        $this->assertStringContainsString('t.hl === null', $soucet);
        $this->assertStringContainsString('r.sum += Math.abs(t.hl);', $soucet);

        $this->assertStringContainsString('const spentOf = c => this.txSoucet(txs.filter(t => t.cat === c && t.amount < 0 && inMonth(t))).sum;', $telefon);
        $this->assertStringContainsString('const incomeS = this.txSoucet(txs.filter(t => t.amount > 0 && inMonth(t)));', $telefon);
        $this->assertStringContainsString('const spendS = this.txSoucet(txs.filter(t => t.amount < 0 && inMonth(t)));', $telefon);
        $this->assertStringContainsString('const budgetPct = budgetZaklad > 0 ? Math.round(budgetSpent / budgetZaklad * 100) : 0;', $telefon);
        $this->assertStringContainsString("' v útratách bez kurzu'", $telefon);
        $this->assertStringContainsString("' v příjmech bez kurzu'", $telefon);
        // Staré sčítání `amount` napříč měnami je pryč.
        $this->assertStringNotContainsString('txs.filter(t => t.amount > 0 && inMonth(t)).reduce((a, t) => a + t.amount, 0)', $telefon);
        $this->assertStringNotContainsString('pTxs.reduce((a, t) => a + (t.amount < 0 ? Math.abs(t.amount) : 0), 0)', $telefon);
        $this->assertStringNotContainsString('dTxs.reduce((a, t) => a + (t.amount < 0 ? Math.abs(t.amount) : 0), 0)', $telefon);

        // Částka řádku s vlastním znakem — seznam, detail, místo, den.
        $this->assertGreaterThanOrEqual(4, substr_count($telefon, 'this.txCastka('));
        $this->assertStringNotContainsString("this.kc(Math.abs(t.amount))", $telefon);
        $this->assertStringNotContainsString("this.kc(Math.abs(txOpen.amount))", $telefon);
        $this->assertStringContainsString("kc: this.castka(t[4], (t[7] || {}).znak)", $telefon);

        // Poznámky pod čísly a pod kategoriemi rozpočtu jsou v šabloně i v hodnotách.
        $this->assertStringContainsString('<sc-if value="{{ finMenyOn }}">', $telefon);
        $this->assertStringContainsString('{{ finMenyNote }}', $telefon);
        $this->assertStringContainsString('finMenyOn: !!finMenyNote,', $telefon);
        $this->assertStringContainsString('<sc-if value="{{ b.hasNote }}">', $telefon);
        $this->assertStringContainsString("note: c[3] || '', hasNote: !!c[3],", $telefon);
        $this->assertStringContainsString('<sc-if value="{{ finRozpMimoOn }}">', $telefon);
        $this->assertStringContainsString('finRozpMimoOn: !!', $telefon);
    }

    /** Vlastní čísla rozpočtu se píšou jeho znakem (`BUD.mena`), ne hlavním. */
    public function test_rozpocet_v_jine_mene_ma_svuj_znak(): void
    {
        $telefon = self::dokument();

        $this->assertStringContainsString('const budZnak = (finGD.BUD || {}).mena || hlavniZnak;', $telefon);
        $this->assertStringContainsString('const budSpentOf = c => budCizi ? ((CATS.find(x => x[0] === c) || [])[2] || 0) : spentOf(c);', $telefon);
        $this->assertStringContainsString("title: this.castka(budgetSpent, budZnak) + ' z ' + this.castka(budgetTotal, budZnak),", $telefon);
        $this->assertStringContainsString("meta: c[1] ? this.castka(sp, budZnak) + ' z ' + this.castka(c[1], budZnak)", $telefon);
        $this->assertStringContainsString("aria: m[0] + ' ' + this.castka(v, budZnak),", $telefon);
        $this->assertStringContainsString('value: this.castka(c.sp, budZnak),', $telefon);
        $this->assertStringContainsString('finGD.BUD.monthsPoznamka', $telefon);
    }

    /** Běžící cesta v měně cesty; ostatní měny a korunový souhrn malým řádkem. */
    public function test_bezici_cesta_pise_znak_cesty(): void
    {
        $telefon = self::dokument();

        $this->assertStringContainsString('fund: this.castka(NOWTRIP.fund, NOWTRIP.znak), spent: this.castka(NOWTRIP.spent, NOWTRIP.znak), today: this.castka(NOWTRIP.todaySpent, NOWTRIP.znak),', $telefon);
        $this->assertStringNotContainsString('this.kc(NOWTRIP.', $telefon);
        $this->assertStringContainsString('this.poMenachText(NOWTRIP.spentOther)', $telefon);
        $this->assertStringContainsString('NOWTRIP.spentMain', $telefon);
        $this->assertStringContainsString("kc: this.castka(r[1], r[4] ? this.znakMeny(r[4]) : NOWTRIP.znak)", $telefon);
        $this->assertStringContainsString('<sc-if value="{{ all.now.jinaOn }}">', $telefon);
        $this->assertStringContainsString('{{ all.now.jina }}', $telefon);

        // Třetí prvek „Utraceno" (kurz a rozpis) se u cesty ukáže.
        $this->assertStringContainsString("note: x[2] || '', hasNote: !!x[2]", $telefon);
        $this->assertStringContainsString('<sc-if value="{{ s.hasNote }}">', $telefon);
    }

    /** Rozbory: částky svou měnou, poznámky k přepočtu vidět. */
    public function test_rozbory_s_menou_a_poznamkou(): void
    {
        $telefon = self::dokument();

        $this->assertStringContainsString('const costZnak = c => c[5] ? this.znakMeny(c[5]) : undefined;', $telefon);
        $this->assertStringContainsString("const costNote = c => [c[3], c[6]].filter(Boolean).join(' · ');", $telefon);
        $this->assertStringContainsString("note: [r[3], r[6]].filter(Boolean).join(' · ')", self::metoda($telefon, 'costVals('));
        $this->assertStringContainsString('const envZnak = ENV.mena ? this.znakMeny(ENV.mena) : undefined;', $telefon);
        $this->assertStringContainsString('limit: this.castka(ENV.limit, envZnak),', $telefon);
        $this->assertStringContainsString('csSeasonNote: csSeasonNote, csSeasonNoteOn: !!csSeasonNote,', $telefon);
        $this->assertStringContainsString("csSeasonNote: '', csSeasonNoteOn: false,", $telefon);
        $this->assertStringContainsString('<sc-if value="{{ all.csSeasonNoteOn }}">', $telefon);
        $this->assertStringContainsString('y25: this.castka(x.y25, zn), y26: this.castka(x.y26, zn),', $telefon);
        // Roční výdaje pro rozpočet překvapení jen v hlavní měně.
        $this->assertStringContainsString('.filter(x => !x[4] || this.znakMeny(x[4]) === hlZnak)', $telefon);
        // Účet ve výběru výpisu se zůstatkem ve své měně.
        $this->assertStringContainsString("(a[11] || this.kc(a[2] || 0))", $telefon);
    }

    /** Nová cesta: měna z nabídky, výchozí CZK — ne napevno. */
    public function test_nova_cesta_nabizi_meny(): void
    {
        $telefon = self::dokument();
        $formular = self::metoda($telefon, 'novaCestaVals(');

        $this->assertStringNotContainsString("currency: 'CZK'", $telefon);
        $this->assertStringContainsString('currency: mena, status', $formular);
        $this->assertStringContainsString('const nabidka = this.menyNabidka();', $formular);
        $this->assertStringContainsString("(nabidka.indexOf('CZK') >= 0 ? 'CZK' : nabidka[0])", $formular);
        $this->assertStringContainsString('pick: () => set({ mena: k })', $formular);
        $this->assertStringContainsString("mTrip: { nazev: '', kam: '', od: '', do: '', rozpocet: '', mena: 'CZK' }", $telefon);
        $this->assertStringContainsString('<sc-for list="{{ ntMeny }}" as="m"', $telefon);
        $this->assertStringContainsString('Rozpočet ({{ ntMenaZnak }})', $telefon);
        $this->assertStringNotContainsString('<label>Rozpočet (Kč)</label>', $telefon);
    }
}
