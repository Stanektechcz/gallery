<?php

namespace App\Console\Commands;

use App\Models\CoupleState;
use App\Models\GallerySpace;
use App\Services\Provoz\MediaVeStavu;
use Illuminate\Console\Command;

/**
 * Jednorázové propsání úprav fotek ze stavu do databáze.
 *
 * Srdíčka, popisky, místa, data a štítky upravené v prototypu dřív zůstaly jen
 * ve společném stavu. Zápis je teď propisuje průběžně — ale jen to, co se od
 * minulého zápisu změnilo, takže starší úpravy by v databázi nikdy neskončily.
 * Tenhle příkaz je dožene jednou po nasazení.
 */
class UpravyZeStavuCommand extends Command
{
    protected $signature = 'gallery:upravy-ze-stavu';

    protected $description = 'Propíše oblíbené a úpravy fotek ze stavu prototypu do databáze';

    public function handle(MediaVeStavu $media): int
    {
        $pocet = 0;

        CoupleState::query()->each(function (CoupleState $stav) use ($media, &$pocet) {
            $prostor = GallerySpace::find($stav->couple_id);
            $data = $stav->toClientArray();

            if ($prostor === null || (! isset($data['favs']) && ! isset($data['edits']))) {
                return;
            }

            // Oblíbené patří člověku; ve stavu jsou společné, tak je dostane vlastník prostoru.
            $kdo = $prostor->owner ?? $prostor->members()->first();

            if ($kdo === null) {
                return;
            }

            $media->zpracuj(array_intersect_key($data, array_flip(['favs', 'edits'])), [], $prostor, $kdo);
            $pocet++;
        });

        $this->info('Propsáno z '.$pocet.' '.($pocet === 1 ? 'stavu' : 'stavů').'.');

        return self::SUCCESS;
    }
}
