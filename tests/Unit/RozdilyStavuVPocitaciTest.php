<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Stav, který server zapisuje jen podle toho, co prohlížeč výslovně změnil.
 *
 * Počítač posílá sezónní obálky (`season`), přepínače (`sw`) i menu na týden
 * (`ckMenu`) vždycky celé. Opis ze staré karty tak přepisoval, co mezitím
 * změnil druhý. Server teď bere jen změněné položky (`__zmenene`) a u menu
 * jen dny, které přišly (den vymaže výslovné `''`); tady se hlídá, že je
 * dokument opravdu posílá.
 */
class RozdilyStavuVPocitaciTest extends TestCase
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

    public function test_sezonni_obalky_posilaji_zmenene(): void
    {
        $pocitac = self::dokument();

        preg_match("/const ZMENY = \[([^\]]*)\];/", $pocitac, $zmeny);
        $this->assertNotEmpty($zmeny, 'Seznam ZMENY se nenašel.');
        $this->assertStringContainsString("'season'", $zmeny[1]);
        // Základ změn jsou obálky ze serveru, jako u ostatních seznamů.
        $this->assertStringContainsString("season: typeof SEASON !== 'undefined' ? SEASON : null", $pocitac);
    }

    public function test_prepinace_posilaji_zmenene_a_neprepisuji_ostatni_rozdily(): void
    {
        $ulozit = self::metoda(self::dokument(), 'persistSave(');

        $this->assertStringContainsString("if ('sw' in patch) {", $ulozit);
        $this->assertStringContainsString('patch.__zmenene = Object.assign({}, patch.__zmenene || {}, { sw });', $ulozit);
        // Až po `odebraneRozdil` — ten `__zmenene` zakládá znovu.
        $this->assertGreaterThan(strpos($ulozit, 'this.odebraneRozdil(prev, patch);'), strpos($ulozit, "if ('sw' in patch) {"));
    }

    public function test_menu_uvolneny_den_posila_jako_prazdny(): void
    {
        $pocitac = self::dokument();
        $nastav = self::metoda($pocitac, 'nastavMenu(');

        $this->assertStringContainsString('nastavMenu(zmena, celyTyden) {', $nastav);
        // Den, který v novém menu chybí, ale byl v něm, jde jako `''`.
        $this->assertStringContainsString("Object.keys(pred).forEach(d => { if (!nove[d]) plna[d] = ''; });", $nastav);
        $this->assertStringContainsString("if (!(d in plna)) plna[d] = '';", $nastav);

        // „Uvolnit den" už den nemaže z mapy.
        $this->assertStringNotContainsString('if (v) next[d] = v; else delete next[d];', $pocitac);
        $this->assertStringContainsString("next[d] = v || '';", $pocitac);

        // Všechna vygenerovaná menu nahrazují celý týden.
        preg_match_all('/this\.nastavMenu\(\{ ckMenu: next(?:, appTab: \d)? \}(, true)?\);/', $pocitac, $zapisy);
        $this->assertCount(5, $zapisy[0], 'Zápisy menu se změnily — zkontrolujte, které nahrazují celý týden.');
        $this->assertSame(4, count(array_filter($zapisy[1])), 'Vygenerované menu musí poslat i volné dny.');

        // Ze stavu se uvolněné dny do menu nepočítají.
        $this->assertStringContainsString('Object.keys(m).forEach(d => { if (m[d]) bez[d] = m[d]; });', self::metoda($pocitac, 'menuOf('));
    }
}
