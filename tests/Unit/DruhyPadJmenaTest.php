<?php

namespace Tests\Unit;

use App\Services\Obsah\Sdileni;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Druhý pád jména v „3 fotky od Kláry".
 *
 * Pravidlo se dřív skládalo ze dvou tříd písmen a u čtyř běžných jmen z deseti
 * vycházel tvar, který v češtině není: „od Tomáša", „od Ondřeja", „od Míšy",
 * „od Soňy". Vypadá to jako tvrzení, jak se ten člověk jmenuje.
 *
 * Skloňovat všechno stejně nejde; co nesedí do vzoru, zůstává v prvním pádě —
 * „od Tomáš" je vidět jako nedokonalost, „od Tomáša" ne.
 */
class DruhyPadJmenaTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function jmena(): array
    {
        return [
            // Tvrdý ženský vzor.
            ['Klára', 'Kláry'],
            ['Makinka', 'Makinky'],
            ['Eva', 'Evy'],
            ['Anna', 'Anny'],
            // Měkký ženský vzor — `-i`, ne `-y`.
            ['Míša', 'Míši'],
            ['Káča', 'Káči'],
            // U ď/ť/ň nese měkkost až to `i`, takže se háček sundá.
            ['Soňa', 'Soni'],
            ['Naďa', 'Nadi'],
            ['Máťa', 'Máti'],
            // Ženská jména na `-e` se nemění.
            ['Marie', 'Marie'],
            ['Alice', 'Alice'],
            // Tvrdá souhláska → `-a`.
            ['Adam', 'Adama'],
            ['Petr', 'Petra'],
            ['Jakub', 'Jakuba'],
            ['Adrian', 'Adriana'],
            // Měkká souhláska → `-e`, ne `-a`.
            ['Tomáš', 'Tomáše'],
            ['Lukáš', 'Lukáše'],
            ['Ondřej', 'Ondřeje'],
            ['Matěj', 'Matěje'],
            // Co do vzoru nepatří, zůstává v prvním pádě.
            ['Jiří', 'Jiří'],
            ['', ''],
        ];
    }

    #[DataProvider('jmena')]
    public function test_jmeno_ve_druhem_pade(string $jmeno, string $ocekavano): void
    {
        $metoda = new ReflectionMethod(Sdileni::class, 'druhyPad');

        $this->assertSame($ocekavano, $metoda->invoke(new Sdileni, $jmeno));
    }
}
