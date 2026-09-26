<?php

namespace App\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Spuštění externího programu (ffmpeg, ffprobe, exiftool) bez shellu.
 *
 * Na serveru jsou `shell_exec` i `exec` v php.ini vypnuté, `proc_open` ne.
 * Volání přes shell tak nevrátilo nic a nic nehlásilo: video zůstalo bez
 * náhledu a kopie pro prohlížeč, fotka bez EXIF, v logu ani slovo. Process
 * jde přes `proc_open` a pole argumentů předá programu přímo — bez
 * `escapeshellarg`, bez `2>/dev/null` a bez `| grep`.
 *
 * Každé volání nese vlastní strop. Výchozích 60 s z Laravelu je na převod
 * videa z telefonu málo a na zaseknutý exiftool moc. Vypršení je obyčejné
 * selhání: zapíše se do logu i s koncem chybového výstupu a volající dostane
 * `null` jako při nenulovém návratovém kódu — úloha kvůli tomu nespadne.
 */
final class Program
{
    /** Kolik znaků z konce chybového výstupu dát do logu. ffmpeg píše chybu až na konec. */
    private const KONEC_CHYBY = 800;

    /**
     * @param  list<string>  $prikaz  program a jeho argumenty, každý zvlášť
     * @param  int  $vterin  nejdelší doba běhu
     * @param  string  $popis  co se spouští — pro záznam v logu
     * @param  array<string, mixed>  $kontext  co přidat do záznamu (id média, cesta…)
     */
    public static function spust(array $prikaz, int $vterin, string $popis, array $kontext = []): ?ProcessResult
    {
        try {
            $vysledek = Process::timeout($vterin)->run($prikaz);
        } catch (ProcessTimedOutException $e) {
            Log::warning("{$popis}: nedoběhl do {$vterin} s", $kontext + [
                'stderr' => self::konec(fn () => $e->result->errorOutput()),
            ]);

            return null;
        } catch (Throwable $e) {
            // Program nejde vůbec spustit (vypnutý `proc_open`, špatná cesta
            // na Windows…). Pro volajícího totéž, co pád programu.
            Log::warning("{$popis}: nešel spustit", $kontext + ['error' => $e->getMessage()]);

            return null;
        }

        if (! $vysledek->successful()) {
            Log::warning("{$popis}: skončil s kódem {$vysledek->exitCode()}", $kontext + [
                'exit_code' => $vysledek->exitCode(),
                'stderr' => self::konec(fn () => $vysledek->errorOutput()),
            ]);

            return null;
        }

        return $vysledek;
    }

    /**
     * Dá se program na téhle cestě spustit — pokud se to vůbec dá zjistit?
     *
     * Webový server má v `.user.ini` stránky (aaPanel) `open_basedir`, CLI
     * ne. `is_executable('/usr/bin/ffmpeg')` pak ve FPM jen vypíše varování,
     * Laravel z něj udělá `ErrorException` — a každé video nahrané přes
     * prohlížeč dostalo místo plakátu náhradní obrázek, i když ffmpeg
     * na serveru je. `proc_open` přitom `open_basedir` nehlídá.
     *
     * Cesta mimo `open_basedir` se proto nezkoumá: rozhodne až spuštění
     * a jeho selhání zapíše `spust()` do logu. Jinak (CLI, testy, server
     * bez omezení) se chová jako dřív — `is_executable()`.
     */
    public static function lzeSpustit(string $cesta): bool
    {
        if (self::mimoOpenBasedir($cesta, (string) ini_get('open_basedir'))) {
            return true;
        }

        return @is_executable($cesta);
    }

    /**
     * Leží cesta mimo všechny adresáře z `open_basedir`?
     *
     * Čistá funkce nad textem — `open_basedir` za běhu povolit nejde, takže
     * test dostane seznam parametrem. Položka je adresář, ne předpona
     * (od PHP 5.3.4): `/var/www` nepokrývá `/var/www2`. Relativní položky
     * (typicky `.`) se berou od pracovního adresáře, na Windows se nerozlišuje
     * velikost písmen ani lomítka.
     *
     * @param  string  $openBasedir  hodnota `ini_get('open_basedir')`; prázdná = bez omezení
     */
    public static function mimoOpenBasedir(
        string $cesta,
        string $openBasedir,
        string $oddelovac = PATH_SEPARATOR,
        bool $windows = DIRECTORY_SEPARATOR === '\\',
    ): bool {
        $polozky = array_filter(array_map('trim', explode($oddelovac, $openBasedir)), fn ($p) => $p !== '');
        if ($polozky === []) {
            return false;
        }

        $cil = self::normalizuj($cesta, $windows);
        foreach ($polozky as $polozka) {
            $adresar = rtrim(self::normalizuj($polozka, $windows), '/');
            if ($adresar === '' || $cil === $adresar || str_starts_with($cil, $adresar.'/')) {
                return false;
            }
        }

        return true;
    }

    /** Absolutní cesta s `/`, bez `.` a `..`, bez zdvojených lomítek — jen textově, disk se nečte. */
    private static function normalizuj(string $cesta, bool $windows): string
    {
        $cesta = str_replace('\\', '/', $cesta);
        $absolutni = str_starts_with($cesta, '/') || preg_match('~^[A-Za-z]:/~', $cesta);
        if (! $absolutni) {
            $cesta = str_replace('\\', '/', (string) getcwd()).'/'.$cesta;
        }

        preg_match('~^([A-Za-z]:)?/*~', $cesta, $koren);
        $pocatek = $koren[1] ?? '';
        $casti = [];
        foreach (explode('/', substr($cesta, strlen($koren[0]))) as $cast) {
            if ($cast === '' || $cast === '.') {
                continue;
            }
            if ($cast === '..') {
                array_pop($casti);

                continue;
            }
            $casti[] = $cast;
        }

        $vysledek = $pocatek.'/'.implode('/', $casti);

        return $windows ? strtolower($vysledek) : $vysledek;
    }

    /** Konec chybového výstupu; proces, který se ani nerozběhl, žádný nemá. */
    private static function konec(callable $vystup): string
    {
        try {
            $text = trim((string) $vystup());
        } catch (Throwable) {
            return '';
        }

        return mb_strlen($text) > self::KONEC_CHYBY ? '…'.mb_substr($text, -self::KONEC_CHYBY) : $text;
    }
}
