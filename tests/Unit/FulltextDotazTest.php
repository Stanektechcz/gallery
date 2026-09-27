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

    public function test_nebo_dotaz_nema_povinna_slova(): void
    {
        $dotaz = (string) FulltextDotaz::zNeboDotazu('Chata Lysá xyzzy');

        $this->assertStringNotContainsString('+', $dotaz);
        // Kmen a bez diakritiky: „Chata" → `chat*`, „Lysá" → `lysa*` (po utržení by zbyly tři znaky).
        $this->assertSame('chat* lysa* xyzz*', $dotaz);
    }

    public function test_nebo_dotaz_bez_slov_vrati_null(): void
    {
        $this->assertNull(FulltextDotaz::zNeboDotazu('a b'));
    }

    public function test_kmen_utrhne_koncovku_jen_kdyz_zbydou_ctyri_znaky(): void
    {
        $this->assertSame('výlet', FulltextDotaz::kmen('výletech'));
        $this->assertSame('prah', FulltextDotaz::kmen('prahy'));
        // „hoře" by po utržení „e" měla jen tři znaky — zůstane celá.
        $this->assertSame('hoře', FulltextDotaz::kmen('hoře'));
        $this->assertSame('2025', FulltextDotaz::kmen('2025'));
        // Přídavná jména: „krásné" i „krásná" skončí u „krásn".
        $this->assertSame('krásn', FulltextDotaz::kmen('krásné'));
    }

    public function test_hledana_slova_jsou_bez_diakritiky_mala_a_zkracena(): void
    {
        $this->assertSame(['lyse', 'hore', 'vylet'], FulltextDotaz::hledanaSlova('LYSÉ hoře, výletech a'));
    }

    /** @return array<string, array{0: string}> */
    public static function znakyKtereSeSkladajiNaOperatory(): array
    {
        return [
            'modifikační apostrof' => ['rockʼnʼroll chata'],
            'modifikační uvozovky' => ['ʺchataʺ louka'],
            'zlomek' => ['½ chata 1½kg'],
            'ochranná známka' => ['Nikon™ chata'],
            'ligatura' => ['ﬁlm chata'],
            'emoji' => ['🏔️ chata 🎉 hory🌲'],
        ];
    }

    /**
     * Po složení bez diakritiky nesmí zbýt nic jiného než `[a-z0-9_]`.
     *
     * `Str::ascii` dělá z modifikačních písmen (`ʼ ʺ`) a z `½` apostrof,
     * uvozovky a lomítko — v booleovském režimu InnoDB je `"` operátor
     * a neuzavřená uvozovka shodí dotaz na 500.
     */
    #[DataProvider('znakyKtereSeSkladajiNaOperatory')]
    public function test_slozena_slova_nesou_jen_bezpecne_znaky(string $text): void
    {
        foreach (FulltextDotaz::hledanaSlova($text) as $slovo) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $slovo, "„{$text}“ → „{$slovo}“");
        }

        $this->assertContains('chat', FulltextDotaz::hledanaSlova($text));

        foreach ([FulltextDotaz::zNeboDotazu($text), FulltextDotaz::zeSlov(FulltextDotaz::hledanaSlova($text), true)] as $dotaz) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_+* ]+$/', (string) $dotaz, "„{$text}“ → „{$dotaz}“");
        }
    }

    public function test_booleovsky_dotaz_nenese_operator_z_modifikacnich_znaku(): void
    {
        // `zBooleovskeho()` diakritiku nechává (viz test výš) — ale žádný znak mimo písmena, číslice a `_`.
        $dotaz = (string) FulltextDotaz::zBooleovskeho('rockʼnʼroll ʺchataʺ ½ Nikon™ ﬁlm 🎉');

        $this->assertDoesNotMatchRegularExpression('/["\'\/()<>~@\-]/', $dotaz);
        $this->assertStringContainsString('+chata*', $dotaz);
    }

    public function test_slozeni_textu_bez_diakritiky(): void
    {
        $this->assertSame('chata na lyse hore', FulltextDotaz::slozit('Chata na Lysé hoře'));
    }
}
