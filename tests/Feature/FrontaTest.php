<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Fronta: kdy se úloha smí vzít znovu a co dělá plánovač.
 *
 * Obojí je konfigurace, kterou nikdo nevidí, dokud nezpůsobí škodu — a v obou
 * případech to byla škoda tichá.
 */
class FrontaTest extends TestCase
{
    /**
     * `retry_after` musí být delší než nejdelší úloha.
     *
     * Ovladač databáze bere úlohu za opuštěnou, jakmile leží `retry_after`
     * vteřin — a pustí ji znovu, i když první kopie pořád běží. Výchozích 90 s
     * bylo kratší než `$timeout` skoro každé úlohy: vývoz (3600 s) se tak
     * spouštěl každých devadesát vteřin znovu a všechny kopie psaly do téhož
     * ZIPu s `ZipArchive::OVERWRITE`.
     *
     * `.env.example` sice mělo `QUEUE_RETRY_AFTER=600`, jenže ten název nečte
     * nic — `config/queue.php` se ptá na `DB_QUEUE_RETRY_AFTER`.
     */
    public function test_retry_after_je_delsi_nez_nejdelsi_uloha(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');
        [$nejdelsi, $kde] = $this->nejdelsiTimeout();

        $this->assertGreaterThan($nejdelsi, $retryAfter,
            "Fronta si vezme úlohu znovu po {$retryAfter} s, ale {$kde} běží až {$nejdelsi} s — "
            .'dvě kopie téže úlohy si pak přepisují výsledek.');
    }

    /**
     * `queue:retry all` v plánovači maže důkazy o selhání.
     *
     * `RetryCommand` vynuluje počet pokusů a smaže řádek z `failed_jobs`.
     * Každých deset minut to znamená, že trvale padající úloha se točí
     * donekonečna (`--tries` ji nikdy neukončí) a `gallery:doctor`, který hlásí
     * „Failed jobs" od deseti výš, nemá co číst. Systém tím nemá kam hlásit,
     * že se něco nepovedlo.
     */
    public function test_planovac_nespousti_retry_all(): void
    {
        foreach (app(Schedule::class)->events() as $uloha) {
            $this->assertStringNotContainsString('queue:retry', (string) $uloha->command,
                'Opakování všeho naslepo smaže `failed_jobs` dřív, než si jich někdo všimne.');
        }
    }

    /**
     * Záchranná síť musí pokrývat fronty, do kterých se doopravdy zapisuje.
     *
     * `queue:work` bez `--queue=` bere jen `default`. Úlohy přitom chodí i na
     * `media`, `drive`, `high` a `heavy` — a přesně takový nedobraný zbytek byl
     * ten incident, kvůli kterému tahle úloha v plánovači vznikla.
     *
     * Dlouhé úlohy (`heavy`) mají od tohoto kola vlastní vyprazdňování
     * (`heavy-drain`), aby hodinový převod netáhl zámek hlavního běhu s sebou —
     * pokrytí se proto počítá přes **sjednocení** front všech `queue:work`
     * událostí v plánu, ne jen té první.
     */
    public function test_vyprazdnovani_fronty_pokryva_vsechny_fronty(): void
    {
        $behy = $this->drainy();

        $this->assertNotEmpty($behy, 'Bez vyprazdňování fronty leží úlohy, dokud si toho někdo nevšimne.');

        $pokryte = collect($behy)->flatMap(fn ($beh) => $beh['fronty'])->unique()->values()->all();

        foreach ($this->fronty() as $fronta) {
            $this->assertContains($fronta, $pokryte,
                "Do fronty `{$fronta}` se v kódu zapisuje, ale žádný `queue:work` v plánovači ji nevybírá.");
        }
    }

    /**
     * Zámek proti souběhu musí přežít nejdelší úlohu na frontě, kterou hlídá.
     *
     * Holé `withoutOverlapping()` u `queue-drain` přežilo hodinový převod
     * videa jen díky náhodě pořadí — `--max-time` řeže mezi úlohami, ne
     * uprostřed jedné, takže zámek (dřív 600 s) vypršel dávno předtím, než
     * dlouhá úloha doběhla. `schedule:finish` ho pak `forceRelease()`oval a
     * další běh naskočil vedle prvního, klidně šestkrát za sebou.
     *
     * Pro `--max-jobs=1` (bez `--max-time`) se počítá jen nejdelší `$timeout`
     * mezi úlohami na dané frontě, s rezervou; jinak `--max-time` plus
     * nejdelší `$timeout`.
     */
    public function test_zamek_vyprazdnovani_prezije_nejdelsi_ulohu(): void
    {
        $mapa = $this->frontaPodleUlohy();

        foreach ($this->drainy() as $beh) {
            $nejdelsi = 0;
            foreach ($beh['fronty'] as $fronta) {
                foreach ($mapa[$fronta] ?? [] as $timeout) {
                    $nejdelsi = max($nejdelsi, $timeout);
                }
            }

            if ($nejdelsi === 0) {
                continue;
            }

            $potreba = $beh['maxTime'] !== null ? $beh['maxTime'] + $nejdelsi : $nejdelsi;
            $zamekS = $beh['expiresAt'] * 60;

            $this->assertGreaterThanOrEqual($potreba, $zamekS,
                "`{$beh['nazev']}`: zámek drží {$zamekS} s, ale fronta {$beh['fronty'][0]} ".
                "potřebuje aspoň {$potreba} s (nejdelší úloha na ní běží {$nejdelsi} s) — ".
                'jinak `schedule:finish` uvolní zámek dřív, než úloha doběhne, a další běh naskočí vedle ní.');
        }
    }

    /**
     * Všechny `queue:work` události v plánu s jejich frontami, `--max-time`
     * a platností zámku (`withoutOverlapping`) v minutách.
     *
     * @return list<array{nazev: string, fronty: list<string>, maxTime: ?int, expiresAt: int}>
     */
    private function drainy(): array
    {
        return collect(app(Schedule::class)->events())
            ->filter(fn ($uloha) => str_contains((string) $uloha->command, 'queue:work'))
            ->map(function ($uloha) {
                preg_match("/--queue=(\S+)/", (string) $uloha->command, $q);
                preg_match('/--max-time=(\d+)/', (string) $uloha->command, $mt);

                return [
                    'nazev' => (string) ($uloha->description ?: $uloha->command),
                    'fronty' => $q ? explode(',', $q[1]) : ['default'],
                    'maxTime' => $mt ? (int) $mt[1] : null,
                    'expiresAt' => (int) $uloha->expiresAt,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Kterou frontu má která úloha — z `ClassName::dispatch(...)->onQueue('x')`
     * a z vlastního `$this->onQueue('x')` na třídě úlohy (např. v konstruktoru),
     * spárováno s jejím `$timeout`.
     *
     * @return array<string, list<int>> fronta => seznam `$timeout` úloh na ní
     */
    private function frontaPodleUlohy(): array
    {
        $timeouty = [];
        foreach (File::allFiles(app_path('Jobs')) as $soubor) {
            if (preg_match('/\$timeout\s*=\s*(\d+)/', $soubor->getContents(), $shoda)) {
                $timeouty[$soubor->getFilenameWithoutExtension()] = (int) $shoda[1];
            }
        }

        $mapa = [];

        // Úloha si frontu nastavuje sama (`$this->onQueue('x')`), ne až na místě odeslání.
        foreach (File::allFiles(app_path('Jobs')) as $soubor) {
            if (preg_match("/\\\$this->onQueue\('([a-z]+)'\)/", $soubor->getContents(), $shoda)
                && isset($timeouty[$soubor->getFilenameWithoutExtension()])) {
                $mapa[$shoda[1]][] = $timeouty[$soubor->getFilenameWithoutExtension()];
            }
        }

        // `SomeJob::dispatch(...)->onQueue('x')` kdekoli v aplikaci.
        foreach (File::allFiles(app_path()) as $soubor) {
            preg_match_all("/(\w+)::dispatch\([^;]*?\)->onQueue\('([a-z]+)'\)/s", $soubor->getContents(), $shody, PREG_SET_ORDER);
            foreach ($shody as $shoda) {
                if (isset($timeouty[$shoda[1]])) {
                    $mapa[$shoda[2]][] = $timeouty[$shoda[1]];
                }
            }
        }

        return $mapa;
    }

    /** @return list<string> fronty, na které se v aplikaci opravdu odesílá */
    private function fronty(): array
    {
        $nalezene = ['default'];

        foreach (File::allFiles(app_path()) as $soubor) {
            // Pokrývá `Job::dispatch(...)->onQueue('x')` i vlastní `$this->onQueue('x')`.
            preg_match_all("/onQueue\('([a-z]+)'\)/", $soubor->getContents(), $shody);
            $nalezene = array_merge($nalezene, $shody[1]);
        }

        return array_values(array_unique($nalezene));
    }

    /** @return array{int, string} nejdelší `$timeout` mezi úlohami a čí je */
    private function nejdelsiTimeout(): array
    {
        $nejdelsi = 0;
        $kde = 'žádná úloha';

        foreach (File::allFiles(app_path('Jobs')) as $soubor) {
            if (! preg_match('/\$timeout\s*=\s*(\d+)/', $soubor->getContents(), $shoda)) {
                continue;
            }

            if ((int) $shoda[1] > $nejdelsi) {
                $nejdelsi = (int) $shoda[1];
                $kde = $soubor->getFilename();
            }
        }

        return [$nejdelsi, $kde];
    }
}
