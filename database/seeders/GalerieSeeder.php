<?php

namespace Database\Seeders;

use App\Models\CoupleState;
use App\Models\GallerySpace;
use Illuminate\Database\Seeder;

/**
 * Startovní stav prototypu Galerie.
 *
 * Zakládá **prázdný** záznam stavu pro každý prostor. Nic víc, a schválně.
 *
 * Scaffold prototypu tu měl nalít všech 180 kolekcí z `galerie-data.json`.
 * To by bylo špatně hned dvakrát. Za prvé: ta data jsou výchozí hodnoty klienta,
 * který si je nese v souboru — kdyby se jednou nakopírovaly do stavu páru, každá
 * pozdější změna výchozích hodnot by je minula a dvojice by navždy koukala na
 * čísla z podzimu 2026. Stav má obsahovat jen to, co lidi opravdu změnili; přesně
 * proto je zápis částečný PATCH. Za druhé: soubor obsahuje i heslo a PINy
 * v čitelné podobě. Zakládat podle nich účty by znamenalo dát celé aplikaci
 * heslo, které je v repozitáři — účty proto zakládá `GallerySpaceSeeder`
 * s náhodným heslem, jak to dělal vždycky.
 */
class GalerieSeeder extends Seeder
{
    public function run(): void
    {
        $prostory = GallerySpace::pluck('id');

        foreach ($prostory as $id) {
            CoupleState::firstOrCreate(
                ['couple_id' => $id],
                ['data' => [], 'private' => [], 'rev' => 0],
            );
        }

        $this->command?->info('Galerie: stav připraven pro '.$prostory->count().' prostor(y).');
    }
}
