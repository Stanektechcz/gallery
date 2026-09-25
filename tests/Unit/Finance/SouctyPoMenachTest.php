<?php

namespace Tests\Unit\Finance;

use App\Services\Finance\SouctyPoMenach;
use PHPUnit\Framework\TestCase;

/**
 * `SouctyPoMenach` je čistá aritmetika a text — bez databáze, bez kurzů.
 *
 * Testy kryjí přesně to, co šest obrazovek dřív mělo každá napsané zvlášť:
 * normalizaci klíče měny, sečtení řádků, zaokrouhlení a filtr šumu, přesun
 * měny na první místo a spojení částek do věty i s dovětkem o tom, co se
 * nevešlo.
 */
class SouctyPoMenachTest extends TestCase
{
    public function test_klic_prazdnou_menu_nahradi_vychozi(): void
    {
        $this->assertSame('CZK', SouctyPoMenach::klic('', 'CZK'));
        $this->assertSame('CZK', SouctyPoMenach::klic(null, 'CZK'));
    }

    public function test_klic_velka_pismena_bez_mezer(): void
    {
        $this->assertSame('EUR', SouctyPoMenach::klic(' eur ', 'CZK'));
    }

    public function test_klic_nesmyslny_kod_necha_jak_je(): void
    {
        // O platnosti kódu nerozhoduje `klic()` — to je práce `Meny::kod()` u volajícího.
        $this->assertSame('XY', SouctyPoMenach::klic('xy', 'CZK'));
    }

    public function test_secti_seskupi_podle_klice_a_scita_castky(): void
    {
        $radky = [
            (object) ['mena' => 'eur', 'castka' => 100.0],
            (object) ['mena' => 'EUR', 'castka' => 50.0],
            (object) ['mena' => '', 'castka' => 20.0],
        ];

        $soucty = SouctyPoMenach::secti($radky, fn ($r) => $r->mena, fn ($r) => $r->castka, 'CZK');

        $this->assertSame(['EUR' => 150.0, 'CZK' => 20.0], $soucty);
    }

    public function test_secti_necha_castku_upravit_volajicim(): void
    {
        // Finance::poMenach() sčítá `abs(amount_from)` — výdaj je vždycky kladný.
        $radky = [
            (object) ['mena' => 'CZK', 'castka' => -100.0],
        ];

        $soucty = SouctyPoMenach::secti($radky, fn ($r) => $r->mena, fn ($r) => abs($r->castka), 'CZK');

        $this->assertSame(['CZK' => 100.0], $soucty);
    }

    public function test_zaokrouhli_na_halere(): void
    {
        $this->assertSame(['CZK' => 12.35], SouctyPoMenach::zaokrouhli(['CZK' => 12.346]));
    }

    public function test_odfiltruj_nulove_zahodi_jen_sum(): void
    {
        $vysledek = SouctyPoMenach::odfiltrujNulove(['CZK' => 0.001, 'EUR' => 5.0, 'USD' => -0.5]);

        $this->assertSame(['EUR' => 5.0, 'USD' => -0.5], $vysledek);
    }

    public function test_presun_na_zacatek_zachova_poradi_ostatnich(): void
    {
        $vysledek = SouctyPoMenach::presunNaZacatek(['EUR' => 10.0, 'CZK' => 20.0, 'USD' => 5.0], 'CZK');

        $this->assertSame(['CZK' => 20.0, 'EUR' => 10.0, 'USD' => 5.0], $vysledek);
    }

    public function test_presun_na_zacatek_bez_klice_nezmeni_nic(): void
    {
        $puvodni = ['EUR' => 10.0, 'USD' => 5.0];

        $this->assertSame($puvodni, SouctyPoMenach::presunNaZacatek($puvodni, 'CZK'));
    }

    public function test_spoj_poskladi_castky_pres_format_a_oddelovac(): void
    {
        $format = fn (float $c, string $m) => number_format($c, 0).' '.$m;

        $vysledek = SouctyPoMenach::spoj(['CZK' => 1000.0, 'EUR' => 100.0], ' · ', $format);

        $this->assertSame('1,000 CZK · 100 EUR', $vysledek);
    }

    public function test_spoj_prazdneho_pole_je_prazdny_retezec(): void
    {
        $this->assertSame('', SouctyPoMenach::spoj([], ' + ', fn ($c, $m) => "{$c} {$m}"));
    }

    public function test_poznamka_s_plusem_a_priponou_nezapocteno(): void
    {
        $format = fn (float $c, string $m) => number_format($c, 0).' '.$m;

        $vysledek = SouctyPoMenach::poznamka(['EUR' => 40.0], $format, ' + ', '+', ' nezapočteno');

        $this->assertSame('+40 EUR nezapočteno', $vysledek);
    }

    public function test_poznamka_se_slovem_stranou(): void
    {
        $format = fn (float $c, string $m) => number_format($c, 0).' '.$m;

        $vysledek = SouctyPoMenach::poznamka(['CZK' => 500.0], $format, pripona: ' stranou');

        $this->assertSame('500 CZK stranou', $vysledek);
    }

    public function test_poznamka_z_prazdnych_castek_je_prazdny_retezec(): void
    {
        $vysledek = SouctyPoMenach::poznamka([], fn ($c, $m) => "{$c} {$m}", predpona: '+', pripona: ' nezapočteno');

        $this->assertSame('', $vysledek);
    }

    public function test_castka_pevna_mezera_pouziva_nezalomitelnou_mezeru_v_tisicich(): void
    {
        $vysledek = SouctyPoMenach::castkaPevnaMezera(12345.0, 'CZK');

        $this->assertSame("12\u{00A0}345 Kč", $vysledek);
    }

    public function test_castka_pevna_mezera_bez_meny_je_koruna(): void
    {
        $this->assertSame('100 Kč', SouctyPoMenach::castkaPevnaMezera(100.0, null));
    }
}
