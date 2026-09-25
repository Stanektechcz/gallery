<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Mazání po společném schválení v dokumentu telefonu.
 *
 * Dvojice se dohodla: „Mazat fotky mohou jen po společném schválení pokud si
 * po vzájemném schválení nenastaví jinak." Telefon nese vlastní kopii dat
 * i metod — co platí na počítači (`MazaniVPocitaciTest`), se tu musí napsat
 * znovu. Server „Do koše" ve společném režimu jen navrhne a v `ids` vrací
 * jen to, co opravdu odešlo do koše.
 */
class MazaniVTelefonuTest extends TestCase
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

    public function test_do_kose_odebira_jen_opravdu_vyhozene(): void
    {
        $doKose = self::metoda(self::dokument(), 'doKoseNaServeru(');

        $this->assertStringContainsString("api.post('media/do-kose', { ids })", $doKose);
        $this->assertStringContainsString('r.navrzeno', $doKose);
        $this->assertStringContainsString('r.uzNavrzeno', $doKose);
        $this->assertStringContainsString('r.zprava', $doKose);
        // Z knihovny telefonu jen `ids` — navržené v ní zůstávají.
        $this->assertStringContainsString('presunute.indexOf(p.id) < 0', $doKose);
        // Návrh se vrací stažením návrhu, vyhozené vrácením z koše.
        $this->assertStringContainsString("this.navrhVyrid('ponechat', navrzene)", $doKose);
        $this->assertStringContainsString('this.zKoseNaServeru(presunute)', $doKose);
        // Žádné „v koši · 30 dní" napsané v telefonu — u návrhu by lhalo.
        $this->assertStringNotContainsString("' v koši · 30 dní na vrácení'", $doKose);
    }

    public function test_navrh_jde_schvalit_ponechat_i_stahnout(): void
    {
        $telefon = self::dokument();
        $vyrid = self::metoda($telefon, 'navrhVyrid(');

        $this->assertStringContainsString("'kos/schvalit'", $vyrid);
        $this->assertStringContainsString("'kos/ponechat'", $vyrid);
        $this->assertStringContainsString('window.GalerieObsahNavlec(res.data, res.prazdne)', $vyrid);
        $this->assertStringContainsString("window.GalerieObnovit('knihovna')", $vyrid);

        // Prohlížeč fotky: pruh s návrhem, druhý schvaluje nebo nechává, navrhující stahuje.
        $this->assertStringContainsString('<sc-if value="{{ lbNavrhOn }}">', $telefon);
        $this->assertStringContainsString('{{ lbNavrhText }}', $telefon);
        $this->assertStringContainsString("label: 'Schválit smazání'", $telefon);
        $this->assertStringContainsString("label: 'Ponechat'", $telefon);
        $this->assertStringContainsString("label: 'Stáhnout návrh'", $telefon);
    }

    public function test_slouceni_duplicit_posila_viteze_ve_stejnem_zapisu(): void
    {
        $telefon = self::dokument();

        // Prázdné `dupKeep` server bere jako „nevím" a nesloučí nic.
        $this->assertStringNotContainsString('dupKeep: {} ', $telefon);
        $this->assertStringNotContainsString('dupKeep: {}}', $telefon);
        $this->assertStringNotContainsString('dupKeep: {})', $telefon);
        $this->assertMatchesRegularExpression('/dupDone: hotove\.concat\(\[g\.id\]\), dupKeep: \{ \.\.\.keep, \[g\.id\]: vitez \}/', $telefon);
        $this->assertStringContainsString('(g.items || []).find(i => i.best)', $telefon);
        // Ve dvojici jsou ostatní kopie jen návrh — hláška nesmí slibovat koš.
        $this->assertStringContainsString('this.muzuMazatSam()', self::metoda($telefon, 'secRowsOf('));
    }

    public function test_dlazdice_ukazuji_navrh(): void
    {
        $telefon = self::dokument();

        // Každá dlaždice se srdíčkem má i značku návrhu.
        $srdicek = substr_count($telefon, '<sc-if value="{{ t.fav }}">');
        $this->assertGreaterThanOrEqual(5, $srdicek);
        $this->assertSame($srdicek, substr_count($telefon, '<sc-if value="{{ t.navrhSmazat }}"><i class="ph-duotone ph-hourglass"'));

        // Objekty dlaždic značku z `PHOTOS` opravdu nesou (chybějící pole = prázdno bez chyby).
        $this->assertSame($srdicek, substr_count($telefon, 'navrhSmazat: !!p.navrhSmazat'));
    }

    public function test_kos_ukazuje_co_ceka_na_schvaleni(): void
    {
        $telefon = self::dokument();

        $this->assertStringContainsString('(window.GalerieData || {}).KE_SCHVALENI', $telefon);
        $this->assertStringContainsString('<sc-if value="{{ kschHas }}">', $telefon);
        $this->assertStringContainsString('<sc-for list="{{ ksch }}" as="k"', $telefon);
        $this->assertStringContainsString('Čeká na schválení', $telefon);
        $this->assertStringContainsString('onClick="{{ k.schvalit }}"', $telefon);
        $this->assertStringContainsString('onClick="{{ k.ponechat }}"', $telefon);
    }

    /**
     * Přepínače formulářů (`sw`) jdou s tím, na co se klepnulo.
     *
     * Server přepne jen id z `__zmenene.sw`; bez něj bere celou mapu — a stará
     * kopie v telefonu by vrátila přepínače, které mezitím změnil druhý.
     */
    public function test_prepinace_posilaji_jen_zmenene(): void
    {
        $ulozeni = self::metoda(self::dokument(), 'persistSave(');

        $this->assertStringContainsString("if ('sw' in patch)", $ulozeni);
        // Připojit k tomu, co sestavil `odebraneRozdil`, ne to přepsat.
        $this->assertStringContainsString('patch.__zmenene = Object.assign({}, patch.__zmenene || {}, { sw: prepnute });', $ulozeni);
        // Klíč za klíčem proti předchozímu stavu — i „Vrátit" pošle jen jeden přepínač.
        $this->assertStringContainsString('Object.keys(Object.assign({}, pred, ted)).filter(id => pred[id] !== ted[id])', $ulozeni);
    }

    /** Den z menu se na serveru maže jen výslovným `''`, ne vynecháním klíče. */
    public function test_menu_nemaze_den_vynechanim_klice(): void
    {
        $telefon = self::dokument();

        preg_match_all('/save\(\{ ckMenu: ([^\n]*)/', $telefon, $zapisy);
        $this->assertNotEmpty($zapisy[1]);

        foreach ($zapisy[1] as $zapis) {
            if (str_starts_with($zapis, 'menu }')) {
                // Kuchařka skládá menu o řádek výš — týmž sloučením přes CKMENU.
                $this->assertStringContainsString('const menu = Object.assign({}, (window.GalerieData || {}).CKMENU || {}, { [den]: rk });', $telefon);

                continue;
            }

            $this->assertStringContainsString('CKMENU || {}, { [den]: rk }', $zapis, 'ckMenu se má skládat přes CKMENU: '.$zapis);
        }

        $this->assertDoesNotMatchRegularExpression('/delete [A-Za-z_.]*(?:menu|Menu|CKMENU)\[/', $telefon);
    }

    public function test_pravidlo_mazani_se_meni_v_nastaveni(): void
    {
        $telefon = self::dokument();
        $akce = self::metoda($telefon, 'nastaveniAkce(');

        foreach (['mazani-navrh', 'mazani-potvrdit', 'mazani-zrusit', 'mazani-spolecne'] as $druh) {
            $this->assertStringContainsString("'".$druh."'", $akce, $druh);
        }

        $this->assertStringContainsString("'mazani/rezim'", $telefon);
        $this->assertStringContainsString("'mazani/rezim/potvrdit'", $telefon);
        $this->assertStringContainsString("'mazani/rezim/zrusit'", $telefon);

        // Kód zámku (nebo heslo) jde jen do požadavku — dialog je v `acDlg`, který se nesdílí ani neukládá.
        $potvrzeni = self::metoda($telefon, 'mazaniPotvrzeniVals(');
        $this->assertStringContainsString('b.chyba', $potvrzeni);
        $this->assertStringNotContainsString('localStorage', $potvrzeni);
        $this->assertStringNotContainsString('api.save', $potvrzeni);
        $this->assertStringNotContainsString('acDlg', self::metoda($telefon, 'persistKey('));
    }
}
