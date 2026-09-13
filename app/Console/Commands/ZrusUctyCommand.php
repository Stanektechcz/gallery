<?php

namespace App\Console\Commands;

use App\Services\Auth\ZruseniUctu;
use Illuminate\Console\Command;

/**
 * Provede zrušení účtů, kterým uplynula čtrnáctidenní lhůta.
 *
 * Nastavení žádost jen zapisovalo; tohle je to „po čtrnácti dnech se smaže",
 * které slibovalo. Vlastníka galerie přeskočí — prostor by zůstal bez
 * někoho, kdo ho spravuje a platí.
 */
class ZrusUctyCommand extends Command
{
    protected $signature = 'gallery:zrus-ucty {--nanecisto : Jen vypsat, koho by se to týkalo}';

    protected $description = 'Zruší účty, kterým uplynula lhůta od žádosti o zrušení';

    public function handle(ZruseniUctu $zruseni): int
    {
        $ucty = $zruseni->splatne();

        if ($ucty->isEmpty()) {
            $this->line('Žádný účet nečeká na zrušení.');

            return self::SUCCESS;
        }

        foreach ($ucty as $ucet) {
            $popis = "#{$ucet->id} {$ucet->email} (žádost na {$zruseni->naplanovano($ucet)?->toDateString()})";

            if (($prekazka = $zruseni->prekazka($ucet)) !== null) {
                $this->warn("Přeskočeno {$popis}: {$prekazka}");

                continue;
            }

            if ($this->option('nanecisto')) {
                $this->line("Zrušil by se {$popis}");

                continue;
            }

            $pocty = $zruseni->proved($ucet);
            $this->info("Zrušen {$popis}: ".json_encode($pocty, JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
