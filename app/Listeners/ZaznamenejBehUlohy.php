<?php

namespace App\Listeners;

use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schema;

/**
 * Zapisuje, co plánovač skutečně udělal.
 *
 * Bez toho by administrace u každé úlohy ukazovala vymyšlený „poslední běh"
 * a incidenty by se nedozvěděl nikdo — chybující noční úloha je přesně ta,
 * o které se člověk jinak dozví až měsíc po tom, co přestala fungovat.
 *
 * Selhání zápisu nesmí shodit úlohu samotnou: záznam je poznámka o práci,
 * ne ta práce.
 */
class ZaznamenejBehUlohy
{
    public function zacal(ScheduledTaskStarting $udalost): void
    {
        $this->bezpecne(fn () => ScheduledTaskRun::create([
            'task' => $this->nazev($udalost->task),
            'command' => mb_substr((string) $udalost->task->command, 0, 512),
            'started_at' => now(),
            'state' => ScheduledTaskRun::BEZI,
        ]));
    }

    public function skoncil(ScheduledTaskFinished $udalost): void
    {
        $this->dopis($udalost->task, $udalost->runtime, (int) ($udalost->task->exitCode ?? 0));
    }

    public function selhal(ScheduledTaskFailed $udalost): void
    {
        $this->dopis($udalost->task, null, 1, $udalost->exception?->getMessage());
    }

    public function preskocen(ScheduledTaskSkipped $udalost): void
    {
        // Pozastavená úloha se do protokolu nezapisuje jako běh — jinak by
        // administrace hlásila „poslední běh dnes 3:00" u něčeho, co neběželo.
        $this->bezpecne(function () use ($udalost) {
            ScheduledTaskRun::where('task', $this->nazev($udalost->task))
                ->where('state', ScheduledTaskRun::BEZI)
                ->latest('id')
                ->first()
                ?->update(['state' => ScheduledTaskRun::PRESKOCENO, 'finished_at' => now()]);
        });
    }

    private function dopis(Event $uloha, ?float $trvani, int $kod, ?string $chyba = null): void
    {
        $this->bezpecne(function () use ($uloha, $trvani, $kod, $chyba) {
            $zaznam = ScheduledTaskRun::where('task', $this->nazev($uloha))
                ->where('state', ScheduledTaskRun::BEZI)
                ->latest('id')
                ->first();

            if ($zaznam === null) {
                return;
            }

            $zaznam->update([
                'finished_at' => now(),
                'duration_ms' => $trvani !== null
                    ? (int) round($trvani * 1000)
                    : (int) $zaznam->started_at->diffInMilliseconds(now()),
                'state' => $kod === 0 ? ScheduledTaskRun::HOTOVO : ScheduledTaskRun::CHYBA,
                'exit_code' => $kod,
                'output' => $chyba !== null ? mb_substr($chyba, 0, ScheduledTaskRun::VYPIS_MAX) : $this->vypis($uloha),
            ]);
        });
    }

    /** Jméno z `->name()`; bez něj aspoň příkaz, ať se úloha dá odlišit. */
    private function nazev(Event $uloha): string
    {
        return mb_substr((string) ($uloha->description ?: $uloha->getSummaryForDisplay()), 0, 191);
    }

    private function vypis(Event $uloha): ?string
    {
        $soubor = $uloha->output ?? null;

        if (! is_string($soubor) || $soubor === '' || ! is_file($soubor)) {
            return null;
        }

        return mb_substr((string) file_get_contents($soubor), -ScheduledTaskRun::VYPIS_MAX);
    }

    private function bezpecne(callable $co): void
    {
        // Migrace ještě neproběhla, databáze nedostupná — úloha běží dál.
        try {
            if (Schema::hasTable('scheduled_task_runs')) {
                $co();
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
