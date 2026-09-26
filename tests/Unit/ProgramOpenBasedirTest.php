<?php

namespace Tests\Unit;

use App\Support\Program;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `Program::lzeSpustit()` pod `open_basedir` webového serveru.
 *
 * aaPanel dává `open_basedir` do `.user.ini` stránky, takže platí jen ve
 * FPM. `is_executable('/usr/bin/ffmpeg')` tam vyhodilo varování → výjimku
 * a video nahrané z prohlížeče dostalo místo plakátu náhradní obrázek.
 * `open_basedir` za běhu povolit nejde, testuje se proto čistá funkce
 * nad textem nastavení.
 */
class ProgramOpenBasedirTest extends TestCase
{
    /** @return array<string, array{string, string, string, bool, bool}> */
    public static function pripady(): array
    {
        $posix = '/www/wwwroot/galerie/:/tmp/';

        return [
            'bez omezení' => ['/usr/bin/ffmpeg', '', ':', false, false],
            'jen mezery a oddělovače' => ['/usr/bin/ffmpeg', ' : ', ':', false, false],
            'binárka mimo seznam' => ['/usr/bin/ffmpeg', $posix, ':', false, true],
            'soubor uvnitř povoleného adresáře' => ['/www/wwwroot/galerie/bin/exiftool', $posix, ':', false, false],
            'sám povolený adresář' => ['/tmp', $posix, ':', false, false],
            'položka je adresář, ne předpona' => ['/www/wwwroot/galerie2/bin/ffmpeg', '/www/wwwroot/galerie', ':', false, true],
            'vylezení přes ..' => ['/tmp/../usr/bin/ffmpeg', $posix, ':', false, true],
            'zdvojená lomítka' => ['//tmp//nastroje/ffmpeg', $posix, ':', false, false],
            'kořen povoluje všechno' => ['/usr/bin/ffmpeg', '/', ':', false, false],
            'POSIX rozlišuje velikost písmen' => ['/TMP/ffmpeg', $posix, ':', false, true],
            'Windows: uvnitř, jiná lomítka i velikost' => ['C:\\PHP\\php.exe', 'c:/php;D:\\data', ';', true, false],
            'Windows: mimo' => ['C:\\Windows\\ffmpeg.exe', 'C:\\php;D:\\data', ';', true, true],
            'Windows: jiný disk' => ['E:\\php\\php.exe', 'C:\\php', ';', true, true],
        ];
    }

    #[DataProvider('pripady')]
    public function test_mimo_open_basedir(string $cesta, string $nastaveni, string $oddelovac, bool $windows, bool $ocekavano): void
    {
        $this->assertSame($ocekavano, Program::mimoOpenBasedir($cesta, $nastaveni, $oddelovac, $windows));
    }

    public function test_relativni_polozka_se_bere_od_pracovniho_adresare(): void
    {
        $windows = DIRECTORY_SEPARATOR === '\\';
        $uvnitr = getcwd().DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'nastroj';

        $this->assertFalse(Program::mimoOpenBasedir($uvnitr, '.', PATH_SEPARATOR, $windows));
        $this->assertTrue(Program::mimoOpenBasedir(dirname(getcwd()).DIRECTORY_SEPARATOR.'jinde', '.', PATH_SEPARATOR, $windows));
    }

    /** Bez `open_basedir` (CLI, testy) beze změny: rozhoduje `is_executable()`. */
    public function test_bez_omezeni_rozhoduje_is_executable(): void
    {
        $this->assertSame('', (string) ini_get('open_basedir'), 'Test předpokládá běh bez open_basedir.');

        $this->assertTrue(Program::lzeSpustit(PHP_BINARY));
        $this->assertFalse(Program::lzeSpustit(sys_get_temp_dir().'/neexistujici-ffmpeg-'.uniqid()));
    }
}
