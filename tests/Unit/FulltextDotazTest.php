<?php

namespace Tests\Unit;

use App\Support\FulltextDotaz;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Z textu vyhledávání vznikne dotaz, na kterém InnoDB nespadne.
 *
 * MySQL tu neběží, ověřuje se tvar: žádný operátor booleovského režimu
 * z uživatelského textu, žádné slovo kratší než tři znaky, `null` tam, kde
 * nezbylo nic (volající pak hledá přes `LIKE`).
 */
class FulltextDotazTest extends TestCase
{
    public function test_email_nenese_zavinac_ani_tecku(): void
    {
        // Dřív: `MATCH … AGAINST ('adri@seznam.cz' IN BOOLEAN MODE)` = chyba syntaxe.
        $this->assertSame('+adri* +seznam*', FulltextDotaz::zBooleovskeho('adri@seznam.cz'));
    }

    public function test_dvoupismenny_dotaz_vrati_null(): void
    {
        $this->assertNull(FulltextDotaz::zBooleovskeho('ok'));
        $this->assertNull(FulltextDotaz::zBooleovskeho('já a ty'));
    }

    public function test_ceska_diakritika_zustane_a_velka_pismena_se_sjednoti(): void
    {
        $this->assertSame('+žluťoučký* +kůň*', FulltextDotaz::zBooleovskeho('Žluťoučký KŮŇ'));
    }

    public function test_rozlozena_diakritika_se_slozi_a_slovo_nerozdeli(): void
    {
        if (! class_exists(\Normalizer::class)) {
            $this->markTestSkipped('Bez rozšíření intl se diakritika neskládá.');
        }

        // „Čáp" s háčkem a čárkou jako samostatnými znaky (NFD).
        $rozlozene = "C\u{030C}a\u{0301}p";

        $this->assertSame('+čáp*', FulltextDotaz::zBooleovskeho($rozlozene));
    }

    /** @return array<string, array{0: string}> */
    public static function operatory(): array
    {
        return [
            'plus a minus' => ['+moře -Chorvatsko'],
            'závorky a větší menší' => ['(moře) >Chorvatsko <léto'],
            'vlnovka a hvězdička' => ['~moře* Chorvatsko'],
            'neuzavřená uvozovka' => ['"moře Chorvatsko'],
            'zavináč' => ['@moře Chorvatsko'],
        ];
    }

    #[DataProvider('operatory')]
    public function test_operatory_z_textu_zmizi(string $text): void
    {
        $dotaz = (string) FulltextDotaz::zBooleovskeho($text);

        // Jediné operátory v dotazu jsou ty, které přidal pomocník: `+` na začátku, `*` na konci slova.
        foreach (explode(' ', $dotaz) as $slovo) {
            $this->assertMatchesRegularExpression('/^\+[\p{L}\p{M}\p{N}_]{3,}\*$/u', $slovo, "„{$text}“ → „{$dotaz}“");
        }

        $this->assertStringContainsString('+moře*', $dotaz);
        $this->assertStringContainsString('+chorvatsko*', $dotaz);
    }

    public function test_kratka_slova_a_stopslova_se_zahodi_zbytek_zustane(): void
    {
        $this->assertSame('+výlet* +brno*', FulltextDotaz::zBooleovskeho('výlet do Brno the'));
    }

    public function test_opakovane_slovo_jen_jednou(): void
    {
        $this->assertSame('+praha*', FulltextDotaz::zBooleovskeho('Praha praha PRAHA'));
    }

    public function test_jen_operatory_a_mezery_vrati_null(): void
    {
        $this->assertNull(FulltextDotaz::zBooleovskeho('+ - @ "" () ~ *'));
        $this->assertNull(FulltextDotaz::zBooleovskeho('   '));
    }

    public function test_neplatne_utf8_vrati_null(): void
    {
        $this->assertNull(FulltextDotaz::zBooleovskeho("\xC3\x28"));
    }
}
