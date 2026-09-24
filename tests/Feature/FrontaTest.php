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
     * `media`, `drive` a `high` — a přesně takový nedobraný zbytek byl ten
     * incident, kvůli kterému tahle úloha v plánovači vznikla.
     */
    public function test_vyprazdnovani_fronty_pokryva_vsechny_fronty(): void
    {
        $drain = collect(app(Schedule::class)->events())
            ->first(fn ($uloha) => str_contains((string) $uloha->command, 'queue:work'));

        $this->assertNotNull($drain, 'Bez vyprazdňování fronty leží úlohy, dokud si toho někdo nevšimne.');

        foreach ($this->fronty() as $fronta) {
            $this->assertStringContainsString($fronta, (string) $drain->command,
                "Do fronty `{$fronta}` se v kódu zapisuje, ale plánovač ji nevybírá.");
        }
    }

    /** @return list<string> fronty, na které se v aplikaci opravdu odesílá */
    private function fronty(): array
    {
        $nalezene = ['default'];

        foreach (File::allFiles(app_path()) as $soubor) {
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
