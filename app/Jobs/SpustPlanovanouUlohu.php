<?php

namespace App\Jobs;

use App\Models\ScheduledTaskRun;
use App\Services\Provoz\PlanovaneUlohy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ruční spuštění plánované úlohy z administrace.
 *
 * Do fronty, ne do požadavku: „Spustit teď" u noční zálohy by jinak drželo
 * prohlížeč otevřený několik minut a spadlo by to na časovém limitu serveru —
 * a úloha by přitom doběhla, takže by nikdo nevěděl, jestli proběhla.
 *
 * Spouští se **úloha z plánu**, ne příkaz z požadavku. Kdyby se posílal příkaz,
 * bylo by tlačítko v administraci cestou, jak přes API spustit cokoli.
 */
class SpustPlanovanouUlohu implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Delší běh je chyba úlohy, ne fronty. */
    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(private readonly string $nazev) {}

    public function handle(PlanovaneUlohy $ulohy): void
    {
        $uloha = $ulohy->najdi($this->nazev);

        if ($uloha === null) {
            return;
        }

        $zaznam = ScheduledTaskRun::create([
            'task' => $this->nazev,
            'command' => mb_substr((string) $uloha->command, 0, 512),
            'started_at' => now(),
            'state' => ScheduledTaskRun::BEZI,
            'manual' => true,
        ]);

        $zacatek = microtime(true);

        try {
            // `run()` obchází kalendář úlohy i pozastavení — o to při ručním
            // spuštění jde. Filtry `->when()` a `->withoutOverlapping()` platí dál.
            $uloha->run(app());

            $zaznam->update([
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $zacatek) * 1000),
                'state' => ($uloha->exitCode ?? 0) === 0 ? ScheduledTaskRun::HOTOVO : ScheduledTaskRun::CHYBA,
                'exit_code' => (int) ($uloha->exitCode ?? 0),
            ]);
        } catch (\Throwable $e) {
            $zaznam->update([
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $zacatek) * 1000),
                'state' => ScheduledTaskRun::CHYBA,
                'exit_code' => 1,
                'output' => mb_substr($e->getMessage(), 0, ScheduledTaskRun::VYPIS_MAX),
            ]);

            throw $e;
        }
    }
}
