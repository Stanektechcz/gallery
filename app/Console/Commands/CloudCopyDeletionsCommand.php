<?php

namespace App\Console\Commands;

use App\Jobs\Media\RemoveCloudCopy;
use App\Models\CloudCopyDeletion;
use Illuminate\Console\Command;

/**
 * Mazání kopií v cloudu po trvalém smazání — přehled a ruční opakování.
 *
 * Úloha `RemoveCloudCopy` to po posledním pokusu vzdá (výpadek na celý den,
 * odvolaný token). Když se cloud vzpamatuje nebo se znovu připojí, nemá kdo
 * mazání spustit znovu — doktor to jen ohlásí. Tenhle příkaz je ta druhá půlka.
 *
 * Výpis nese jen počty podle cloudu a stavu. Záznamy jména souborů nemají
 * (trezor) a výstup příkazu končí v logu.
 *
 * Připojení se při opakování nepřehazuje: záznam, jehož spojení někdo odpojil
 * a smazal, selže znovu s vysvětlením. Nové připojení může být jiný účet
 * a „nenalezeno" by tam kopii vykázalo jako smazanou, i když leží v původním.
 * Obnovené připojení (znovu přihlášený účet) si řádek ponechá a projde.
 */
class CloudCopyDeletionsCommand extends Command
{
    protected $signature = 'gallery:cloud-mazani
        {--znovu : Selhaná a den visící mazání vrátí do fronty a zkusí znovu}';

    protected $description = 'Přehled mazání kopií v cloudu po trvalém smazání; s --znovu je zkusí znovu';

    public function handle(): int
    {
        $this->prehled();

        if (! $this->option('znovu')) {
            $this->line('Zkusit znovu selhaná a visící: php artisan gallery:cloud-mazani --znovu');

            return self::SUCCESS;
        }

        [$zarazeno, $nezarazeno] = $this->znovu();

        $this->info("Znovu zařazeno: {$zarazeno}");

        if ($nezarazeno > 0) {
            $this->warn("Nepodařilo se zařadit: {$nezarazeno} — viz log (fronta nejede?).");
        }

        return self::SUCCESS;
    }

    private function prehled(): void
    {
        $radky = CloudCopyDeletion::query()
            ->selectRaw('provider, status, count(*) as pocet')
            ->groupBy('provider', 'status')
            ->orderBy('provider')
            ->orderBy('status')
            ->get();

        if ($radky->isEmpty()) {
            $this->info('Žádná mazání kopií v cloudu.');

            return;
        }

        foreach ($radky as $radek) {
            $this->line(sprintf('  %-13s %-8s %d', $radek->provider, $radek->status, $radek->pocet));
        }

        $visi = CloudCopyDeletion::query()->visi()->count();
        if ($visi > 0) {
            $this->line("  Z čekajících visí déle než den: {$visi}");
        }
    }

    /**
     * Selhané a visící zpět na `pending` a do fronty.
     *
     * `attempts` od nuly — nové kolo pokusů. `last_error` zůstává jako
     * historie: úloha ho při úspěchu smaže a při nové chybě přepíše, a do té
     * doby je z něj vidět, proč se to opakuje. Uložení posune `updated_at`,
     * takže vrácený záznam hned zase „nevisí".
     *
     * Dvojí zařazení nevadí: úloha pracuje jen s čekajícím záznamem a druhý
     * pokus o smazané dostane „nenalezeno", což je úspěch.
     *
     * @return array{int, int} zařazeno, nezařazeno
     */
    private function znovu(): array
    {
        $zarazeno = 0;
        $nezarazeno = 0;

        CloudCopyDeletion::query()
            ->where(fn ($q) => $q->where('status', CloudCopyDeletion::STATUS_FAILED)->orWhere(fn ($v) => $v->visi()))
            ->lazyById()
            ->each(function (CloudCopyDeletion $zaznam) use (&$zarazeno, &$nezarazeno) {
                $zaznam->forceFill(['status' => CloudCopyDeletion::STATUS_PENDING, 'attempts' => 0])->touch();

                RemoveCloudCopy::zaradBezpecne($zaznam->id) ? $zarazeno++ : $nezarazeno++;
            });

        return [$zarazeno, $nezarazeno];
    }
}
