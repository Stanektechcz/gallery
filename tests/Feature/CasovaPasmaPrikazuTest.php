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

    /** @return array<string, string> vzor => co je na něm špatně */
    private function podezrele(): array
    {
        return [
            '/Carbon::today\(\)/' => 'Carbon::today() je půlnoc v UTC — patří sem Cas::dnes()',
            '/Carbon::now\(\)->between\(/' => 'okno spuštění se počítá v UTC — patří sem Cas::ted()',
        ];
    }
}
