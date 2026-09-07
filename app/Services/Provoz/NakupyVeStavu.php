<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Odškrtnutá položka nákupního seznamu.
 *
 * Seznam se počítá ze surovin naplánovaných jídel, takže se sám mění: přibude
 * jídlo, přibude surovina. Ukládat se proto smí jen to, co počítat nejde —
 * že tuhle věc už někdo koupil.
 *
 * Klíč je týž jako v plánovači jídel (`meal_shopping_states.item_key`, otisk
 * z názvu a jednotky), aby se odškrtnutí projevilo na obou obrazovkách. Kdyby
 * se ukládal text řádku, přidané jídlo by změnilo „Rajčata 1 kg" na „Rajčata
 * 1,5 kg" a odškrtnutí by tiše přestalo platit.
 *
 * Řádek s prázdným `calendar_event_id` i `trip_id` je ten společný — nepatří
 * k jedné večeři ani k jedné cestě, ale k příštím dvěma týdnům.
 */
class NakupyVeStavu
{
    /** Předpona, podle které se pozná klíč, který patří sem. */
    public const PREDPONA = 'shopping:';

    /** Seznam, který tahle vrstva vlastní. */
    public const SEZNAMY = ['shopping'];

    public function tykaSe(array $patch): bool
    {
        if (array_key_exists('xRows', $patch)
            && array_key_exists('shopping', (array) $patch['xRows'])) {
            return true;
        }

        foreach (array_keys((array) ($patch['rowDone'] ?? [])) as $klic) {
            if (str_starts_with((string) $klic, self::PREDPONA)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Patch bez toho, co si bere databáze.
     *
     * `rowDone` patří ještě deseti dalším obrazovkám, takže se z něj vybírají
     * jen klíče s předponou — vyhodit ho celý by znamenalo zapomenout, které
     * filmy jsou zhlédnuté.
     *
     * @return array<string, mixed>
     */
    public function bezNakupu(array $patch): array
    {
        if (is_array($patch['rowDone'] ?? null)) {
            $zbytek = array_filter(
                $patch['rowDone'],
                fn (string $klic) => ! str_starts_with($klic, self::PREDPONA),
                ARRAY_FILTER_USE_KEY,
            );

            if ($zbytek === []) {
                unset($patch['rowDone']);
            } else {
                $patch['rowDone'] = $zbytek;
            }
        }

        if (is_array($patch['xRows'] ?? null)) {
            $zbytek = array_diff_key($patch['xRows'], array_flip(self::SEZNAMY));

            if ($zbytek === []) {
                unset($patch['xRows']);
            } else {
                $patch['xRows'] = $zbytek;
            }
        }

        return $patch;
    }

    public function zpracuj(array $patch, GallerySpace $prostor, ?User $uzivatel): void
    {
        if (! Schema::hasTable('meal_shopping_states')) {
            return;
        }

        foreach ((array) ($patch['rowDone'] ?? []) as $klic => $koupeno) {
            if (! str_starts_with((string) $klic, self::PREDPONA)) {
                continue;
            }

            $polozka = substr((string) $klic, strlen(self::PREDPONA));

            DB::table('meal_shopping_states')->updateOrInsert(
                [
                    'gallery_space_id' => $prostor->id,
                    'item_key' => $polozka,
                    'calendar_event_id' => null,
                    'trip_id' => null,
                ],
                [
                    'is_checked' => (bool) $koupeno,
                    // Kdo to koupil, se zapisuje jen při odškrtnutí. Po vrácení
                    // zpátky by to bylo jméno u věci, kterou nikdo nekoupil.
                    'checked_by' => $koupeno ? $uzivatel?->id : null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }
}
