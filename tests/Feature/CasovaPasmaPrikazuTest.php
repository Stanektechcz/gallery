<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Příkazy počítají čas v pásmu dvojice, ne v UTC.
 *
 * `config('app.timezone')` je UTC a `display_timezone` Europe/Prague. Příkazy
 * ale braly `Carbon::now()` a `Carbon::today()` rovnou, takže okna, ve kterých
 * se smějí spustit, ležela v létě o dvě hodiny jinde, než co slibuje jejich
 * vlastní popis: „dopoledne" vycházelo na 10–12 h a večerní souhrn na 21–23 h.
 * `App\Support\Cas` kvůli tomu vznikl a v `app/Console/Commands` se nepoužíval
 * ani jednou.
 */
class CasovaPasmaPrikazuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Okna spuštění se počítají přes `Cas`, ne přes holý `Carbon`.
     *
     * Statická kontrola, protože jinak by se muselo pro každý příkaz zvlášť
     * stavět celé okolí — a chyba je přesně v tom, **čím** se ten čas bere.
     */
    public function test_prikazy_neberou_cas_v_utc(): void
    {
        $prehresky = [];

        foreach (File::allFiles(app_path('Console/Commands')) as $soubor) {
            $kod = (string) $soubor->getContents();

            foreach ($this->podezrele() as $vzor => $proc) {
                if (preg_match($vzor, $kod)) {
                    $prehresky[] = '  '.$soubor->getFilename().': '.$proc;
                }
            }
        }

        $this->assertSame([], $prehresky,
            "Čas dvojice se bere přes `App\\Support\\Cas`:\n".implode("\n", $prehresky));
    }

    /**
     * Plánovač spouští v pásmu dvojice.
     *
     * Bez `->timezone()` je „v sedm ráno" sedmá hodina UTC, tedy devátá
     * v Praze. Úlohy bez pevné hodiny (každou minutu, hodinově) pásmo
     * nepotřebují — spouštějí se tak jako tak pořád.
     */
    public function test_planovac_spousti_v_pasmu_dvojice(): void
    {
        $kod = (string) file_get_contents(base_path('routes/console.php'));

        preg_match_all("/Schedule::command\((.*?)->name\('([a-z-]+)'\)/s", $kod, $shody, PREG_SET_ORDER);

        $bezPasma = [];

        foreach ($shody as $shoda) {
            if (! preg_match('/->(?:dailyAt|twiceDaily|weeklyOn|monthlyOn)\(/', $shoda[1])) {
                continue;
            }

            if (! str_contains($shoda[1], '->timezone(')) {
                $bezPasma[] = '  '.$shoda[2];
            }
        }

        $this->assertSame([], $bezPasma,
            "Úloha s pevnou hodinou musí mít `->timezone()`:\n".implode("\n", $bezPasma));
    }

    /**
     * Obrazovky, zápisy ze stavu a kontrolery galerie počítají „dnes" jako dvojice.
     *
     * Mezi půlnocí a druhou ráno (v zimě do jedné) je v UTC ještě včerejšek.
     * Dnešní nálada se tak ukládala ke včerejšku a přepsala ho, kuchařka
     * i domácnost v pondělí v noci ukazovaly minulý týden a první noc v měsíci
     * se útrata počítala za ten minulý.
     *
     * Kromě kódu galerie se kontrolují i sdílené soubory, které z něj vedou —
     * modely a automatizace. Starší API mimo galerii zůstává, jak je (viz
     * dokument postupu, kolo 2ae).
     */
    public function test_obrazovky_a_stav_neberou_den_v_utc(): void
    {
        $soubory = collect([
            app_path('Services/Obsah'),
            app_path('Services/Provoz'),
            app_path('Services/Moments'),
            app_path('Http/Controllers/Api/Galerie'),
            app_path('Models'),
        ])->flatMap(fn (string $slozka) => File::allFiles($slozka))
            ->push(new \SplFileInfo(app_path('Services/Automation/AutomationEngine.php')));

        $prehresky = [];

        foreach ($soubory as $soubor) {
            foreach ($this->radkySPrehreskem((string) file_get_contents($soubor->getPathname())) as $radek => $proc) {
                $prehresky[] = '  '.str_replace(app_path().DIRECTORY_SEPARATOR, '', $soubor->getPathname()).":{$radek}: {$proc}";
            }
        }

        $this->assertSame([], $prehresky,
            "Den, týden, měsíc a rok dvojice se berou přes `App\\Support\\Cas`:\n".implode("\n", $prehresky));
    }

    /**
     * `strftime()` je funkce SQLite — na MySQL (produkce) spadne pokaždé.
     *
     * `Services/Obsah/Knihovna.php` z toho stejného důvodu počítá rok v PHP,
     * ne v SQL, a `Http/Controllers/Api/*Controller.php` na to větví podle
     * `getDriverName()`. Kontrola proto povolí jen řádek, který zmiňuje
     * SQLite (větvení nebo komentář k němu) vedle `strftime(` — jinde jde
     * o dotaz, který na MySQL vždy vyhodí chybu.
     */
    public function test_strftime_je_jen_v_radcich_ktere_vetvi_podle_driveru(): void
    {
        $prehresky = [];

        foreach (File::allFiles(app_path()) as $soubor) {
            $radky = preg_split('/\R/', (string) file_get_contents($soubor->getPathname()));

            foreach ($radky as $i => $radek) {
                if (! str_contains($radek, 'strftime(')) {
                    continue;
                }

                // Větvení i komentář k němu smí zmiňovat SQLite o řádek vedle
                // (`$driver === 'sqlite' ? … : …` na jednom řádku, nebo
                // dvouřádkový komentář vysvětlující proč se v PHP nepoužívá).
                $okoli = implode(' ', array_slice($radky, max(0, $i - 1), 3));

                if (stripos($okoli, 'sqlite') === false) {
                    $prehresky[] = '  '.str_replace(app_path().DIRECTORY_SEPARATOR, '', $soubor->getPathname()).':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $prehresky,
            "strftime() je SQLite-only a na MySQL v produkci spadne; větvit podle getDriverName() nebo použít whereMonth()/whereDay():\n".implode("\n", $prehresky));
    }

    /**
     * Řádky, na kterých se den bere v UTC.
     *
     * @return array<int, string> číslo řádku => co je na něm špatně
     */
    private function radkySPrehreskem(string $kod): array
    {
        $nalezy = [];

        foreach (preg_split('/\R/', $kod) as $i => $radek) {
            foreach ($this->podezrele() as $vzor => $proc) {
                if (preg_match($vzor, $radek)) {
                    $nalezy[$i + 1] = $proc;
                    break;
                }
            }
        }

        return $nalezy;
    }

    /** @return array<string, string> vzor => co je na něm špatně */
    private function podezrele(): array
    {
        // Holé `now()` — ne `->now()`, `::now()` ani `$now()`.
        $ted = '(?:Carbon(?:Immutable)?::now\(\)|(?<![\w>:$])now\(\))';

        return [
            '/Carbon(?:Immutable)?::today\(\)|(?<![\w>:$])today\(\)/' => 'today() je půlnoc v UTC — patří sem Cas::dnes()',
            '/'.$ted.'->(?:startOf|endOf)(?:Day|Week|Month|Year)\(/' => 'začátek dne, týdne, měsíce či roku v UTC — patří sem Cas::dnes()',
            '/'.$ted.'->(?:toDateString\(\)|format\(\'Y-m-d)/' => 'dnešní datum v UTC — patří sem Cas::dnes()',
            '/'.$ted.'->(?:year|month|day|dayOfWeek)\b/' => 'rok, měsíc či den v UTC — patří sem Cas::ted()',
            '/Carbon::now\(\)->between\(/' => 'okno spuštění se počítá v UTC — patří sem Cas::ted()',
        ];
    }
}
