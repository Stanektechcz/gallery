<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Mazání po společném schválení v dokumentu počítače.
 *
 * Dvojice se dohodla: „Mazat fotky mohou jen po společném schválení pokud si
 * po vzájemném schválení nenastaví jinak." Server „Do koše" ve společném
 * režimu jen navrhne a v `ids` vrací jen to, co opravdu odešlo do koše.
 * Obrazovka proto nesmí fotku odebrat z knihovny podle toho, že na ni
 * klikla, nesmí hlásit „30 dní na vrácení" u návrhu a musí nabídnout, jak
 * návrh druhého schválit nebo odmítnout.
 */
class MazaniVPocitaciTest extends TestCase
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

    public function test_navrh_druheho_jde_schvalit_ponechat_i_stahnout(): void
    {
        $pocitac = self::dokument();

        $this->assertStringContainsString("'schvalit'", $pocitac);
        $this->assertStringContainsString("'ponechat'", $pocitac);
        // Obojí jde přes `kosVolej` (`kos/` + akce) a knihovna se pak obnoví kvůli označení na dlaždicích.
        $kos = self::metoda($pocitac, 'kosVolej(');
        $this->assertStringContainsString("api.post('kos/' + akce, telo)", $kos);
        $this->assertStringContainsString("window.GalerieObnovit('knihovna')", $kos);
        $this->assertStringContainsString('Stáhnout návrh', $pocitac);
    }

    public function test_do_kose_z_knihovny_odebira_jen_opravdu_vyhozene(): void
    {
        $pocitac = self::dokument();
        $doKose = self::metoda($pocitac, 'doKoseNaServeru(');

        $this->assertStringContainsString("api.post('media/do-kose', { ids })", $doKose);
        $this->assertStringContainsString('res.navrzeno', $doKose);
        $this->assertStringContainsString('res.uzNavrzeno', $doKose);
        $this->assertStringContainsString('res.zprava', $doKose);
        // Návrh se vrací stažením návrhu, ne voláním koše.
        $this->assertStringContainsString("api.post('kos/ponechat', { ids: navrzeno })", $doKose);
        $this->assertStringContainsString("api.post('kos/vratit', { ids: presunute })", $doKose);
    }

    public function test_prohlizec_fotky_rozlisuje_navrh_od_kose(): void
    {
        $pocitac = self::dokument();

        $zacatek = strpos($pocitac, 'trashCurrent: () => {');
        $this->assertNotFalse($zacatek);
        $telo = substr($pocitac, $zacatek, (int) strpos($pocitac, 'lbDownload:', $zacatek) - $zacatek);

        $this->assertStringContainsString('res.status', $telo);
        $this->assertStringContainsString("'trashed'", $telo);
        $this->assertStringContainsString('res.zprava', $telo);
        // Z knihovny jen to, co je v koši.
        $this->assertMatchesRegularExpression("/if \\(stav === 'trashed'\\) \\{\\s*this\\.odeberZKnihovny\\(id\\);/", $telo);
        $this->assertSame(1, substr_count($telo, 'odeberZKnihovny'), 'Navržená fotka zůstává v knihovně.');

        // Pruh v prohlížeči: druhý schvaluje nebo nechává, navrhující stahuje.
        $this->assertStringContainsString('<sc-if value="{{ lbNavrhOn }}">', $pocitac);
        $this->assertStringContainsString('{{ lbNavrhSmazat }}', $pocitac);
        $this->assertStringContainsString('{{ lbNavrhPonechat }}', $pocitac);
    }

    public function test_srovnani_posila_ostatni_jednim_pozadavkem(): void
    {
        $pocitac = self::dokument();

        $this->assertStringNotContainsString("Promise.all(others.map(o => api.del('media/'", $pocitac);
        $this->assertStringContainsString('this.doKoseNaServeru(others.map(o => o.id)', $pocitac);
    }

    public function test_duplicity_posilaji_viteze_ve_stejnem_zapisu(): void
    {
        $pocitac = self::dokument();

        // „Necháváme obě" = `*` spolu s uzavřením nálezu, jinak by server vybral vítěze sám.
        $this->assertStringContainsString("dupKeep: { ...keep, [g.id]: '*' }", $pocitac);
        $this->assertStringNotContainsString("this.setState({ dupDone: done.concat(g.id) });", $pocitac);
        // Sloučení: vítěz v témže zápisu jako `dupDone` (posílají se jen změněné klíče).
        $this->assertMatchesRegularExpression('/dupDone: done\.concat\(list\.map\(g => g\.id\)\), dupKeep: vitezove/', $pocitac);
    }

    public function test_karantena_ve_dvojici_neslibuje_kos(): void
    {
        $pocitac = self::dokument();
        $karantena = self::metoda($pocitac, 'quarVals(');

        $this->assertStringContainsString('this.muzuMazatSam()', $karantena);
        $this->assertStringNotContainsString('originál se odstraní za 7 dní', $karantena);
        $this->assertStringContainsString('this.muzuMazatSam()', self::metoda($pocitac, 'clnVals('));
    }

    public function test_dlazdice_a_kos_ukazuji_navrhy(): void
    {
        $pocitac = self::dokument();

        // Dlaždice: vlastní značka, ne `pending` (to je „zpracovává se").
        $this->assertStringContainsString('<sc-if value="{{ p.navrhSmazat }}">', $pocitac);
        $this->assertStringContainsString('ph-hourglass', $pocitac);
        $this->assertStringContainsString('title="{{ p.navrhTitle }}"', $pocitac);

        // Koš: sekce „Čeká na schválení" z `KE_SCHVALENI`, prázdná se neukazuje.
        $this->assertStringContainsString('KE_SCHVALENI', $pocitac);
        $this->assertStringContainsString('<sc-if value="{{ kschHas }}">', $pocitac);
        $this->assertStringContainsString('<sc-for list="{{ ksch }}" as="k"', $pocitac);
        $this->assertStringContainsString('Čeká na schválení', $pocitac);
    }

    public function test_pravidlo_mazani_se_meni_v_nastaveni(): void
    {
        $pocitac = self::dokument();
        $akce = self::metoda($pocitac, 'nastaveniAkce(');

        foreach (['mazani-navrh', 'mazani-potvrdit', 'mazani-zrusit', 'mazani-spolecne'] as $druh) {
            $this->assertStringContainsString("'".$druh."'", $akce, $druh);
        }

        $this->assertStringContainsString("'mazani/rezim'", $pocitac);
        $this->assertStringContainsString("'mazani/rezim/potvrdit'", $pocitac);
        $this->assertStringContainsString("'mazani/rezim/zrusit'", $pocitac);

        // Odmítnutí kódu (422/429 `{ chyba }`) se ukáže, ne „nepodařilo se".
        $this->assertStringContainsString('b.chyba', self::metoda($pocitac, 'mazaniRezim('));

        // Kód zámku (nebo heslo) jde jen do požadavku — dialog je v `acDlg`, který se nesdílí.
        $potvrzeni = self::metoda($pocitac, 'mazaniPotvrzeniVals(');
        $this->assertStringContainsString("{ kod: hodnota } : { heslo: hodnota }", $potvrzeni);
        $this->assertStringNotContainsString('localStorage', $potvrzeni);
        $this->assertStringNotContainsString('setVals', $potvrzeni);
        $this->assertStringContainsString("if (d.mode === 'mazani-potvrdit') return this.mazaniPotvrzeniVals(d);", $pocitac);
        $this->assertStringContainsString('acDlg: 1', $pocitac, '`acDlg` musí zůstat mimo sdílený stav.');
    }
}
