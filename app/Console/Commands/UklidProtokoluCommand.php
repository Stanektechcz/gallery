<?php

namespace App\Console\Commands;

use App\Models\ScheduledTaskRun;
use Illuminate\Console\Command;

/**
 * Zkracuje protokol běhů plánovaných úloh.
 *
 * `scheduled_task_runs` se od svého vzniku jen plnila. Sám tep plánovače je
 * řádek každou minutu, tedy přes půl milionu ročně; se zbytkem úloh je to
 * zhruba 1,8 milionu. A čte se z ní při každém otevření administrace.
 *
 * Jedna výjimka je tvrdá: **poslední běh každé úlohy zůstává vždycky**, ať je
 * jakkoli starý. Administrace z něj bere sloupec „naposledy" — kdyby ho úklid
 * smazal, úloha, která běží jednou za rok, by o sobě tvrdila „nikdy", a to je
 * horší informace než žádná.
 *
 * Maže se po dávkách přes `whereIn('id', …)`. `DELETE … LIMIT` umí MySQL,
 * ale ne SQLite, na které běží testy; tahle cesta je stejná na obou.
 */
class UklidProtokoluCommand extends Command
{
    protected $signature = 'gallery:uklid-protokol
        {--dni= : Kolik dní protokolu nechat (jinak z konfigurace)}
        {--nasucho : Jen spočítá, co by smazal}';

    protected $description = 'Zkrátí protokol běhů úloh a nechá poslední běh každé z nich';

    /** Kolik řádků smazat najednou. */
    private const DAVKA = 2000;

    public function handle(): int
    {
        $dni = (int) ($this->option('dni') ?: config('gallery.task_log_retention_days', 90));
        $hranice = now()->subDays($dni);
        $nasucho = (bool) $this->option('nasucho');

        // Poslední běh každé úlohy. Je jich tolik, kolik je úloh — desítky,
        // takže se vejdou do `whereIn` bez obav.
        $chranene = ScheduledTaskRun::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('task')
            ->pluck('id')
            ->all();

        $naRadu = ScheduledTaskRun::query()
            ->where('started_at', '<', $hranice)
            ->whereNotIn('id', $chranene);

        $kolik = (clone $naRadu)->count();

        if ($kolik === 0) {
            $this->info(sprintf('Protokol je v pořádku — nic staršího než %d dní k mazání není.', $dni));

            return self::SUCCESS;
        }

        if ($nasucho) {
            $this->line(sprintf(
                'Nasucho bych smazal %d běhů starších než %d dní; %d posledních běhů zůstává.',
                $kolik, $dni, count($chranene),
            ));

            return self::SUCCESS;
        }

        $smazano = 0;

        while (true) {
            $ids = (clone $naRadu)->orderBy('id')->limit(self::DAVKA)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $smazano += ScheduledTaskRun::query()->whereIn('id', $ids)->delete();
        }

        $this->info(sprintf(
            'Smazáno %d běhů starších než %d dní; %d posledních běhů zůstalo.',
            $smazano, $dni, count($chranene),
        ));

        return self::SUCCESS;
    }
}
