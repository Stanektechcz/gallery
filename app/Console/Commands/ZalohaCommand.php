<?php

namespace App\Console\Commands;

use App\Services\Provoz\ZalohaDatabaze;
use Illuminate\Console\Command;
use Throwable;

/**
 * Noční záloha databáze.
 *
 * Do `storage/app/private/zalohy` — mimo web, jen pro server. Drží se posledních
 * `GALLERY_BACKUP_KEEP` (14). Co v záloze je a jak se obnovuje, popisuje
 * `BACKUP_AND_RESTORE.md` a `App\Services\Provoz\ZalohaDatabaze`.
 */
class ZalohaCommand extends Command
{
    protected $signature = 'gallery:zaloha';

    protected $description = 'Záloha databáze (data; schéma postaví migrate) s úklidem starších záloh';

    public function handle(ZalohaDatabaze $zaloha): int
    {
        try {
            $vysledek = $zaloha->zalohuj();
        } catch (Throwable $e) {
            report($e);
            $this->error('Záloha selhala: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Záloha %s: %d tabulek, %d řádků, %s kB.',
            $vysledek['cesta'],
            count($vysledek['tabulky']),
            array_sum($vysledek['tabulky']),
            number_format($vysledek['bajtu'] / 1024, 0, ',', ' '),
        ));

        $smazane = $zaloha->uklid((int) config('gallery.backup_keep', 14));

        if ($smazane !== []) {
            $this->line('Starší zálohy smazány: '.count($smazane).'.');
        }

        return self::SUCCESS;
    }
}
