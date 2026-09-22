<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\User;
use App\Support\Tabulky;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Nákupní seznam: odškrtnuté položky a položky napsané ručně.
 *
 * Seznam se počítá ze surovin naplánovaných jídel, takže se sám mění: přibude
 * jídlo, přibude surovina. Ukládat se proto smí jen to, co počítat nejde —
 * že tuhle věc už někdo koupil, a co někdo připsal sám.
 *
 * Klíč spočítané položky je týž jako v plánovači jídel
 * (`meal_shopping_states.item_key`, otisk z názvu a jednotky), aby se
 * odškrtnutí projevilo na obou obrazovkách. Kdyby se ukládal text řádku,
 * přidané jídlo by změnilo „Rajčata 1 kg" na „Rajčata 1,5 kg" a odškrtnutí
 * by tiše přestalo platit.
 *
 * Řádek s prázdným `calendar_event_id` i `trip_id` je ten společný — nepatří
 * k jedné večeři ani k jedné cestě, ale k příštím dvěma týdnům.
 *
 * Ručně připsané položky (`shopping_list_items`) se dřív zahazovaly spolu
 * s celým `xRows.shopping`: mléko na seznamu vydrželo do obnovení stránky
 * a druhý z dvojice ho neviděl nikdy.
 */
class NakupyVeStavu
{
    /** Předpona spočítané položky. */
    public const PREDPONA = 'shopping:';

    /** Předpona ručně připsané položky; za ní je uuid řádku. */
    public const VLASTNI = 'shopping-item:';

    /** Seznam, který tahle vrstva vlastní. */
    public const SEZNAMY = ['shopping'];

    public function tykaSe(array $patch): bool
    {
        if (array_key_exists('shopDel', $patch)) {
            return true;
        }

        if (array_key_exists('xRows', $patch)
            && array_key_exists('shopping', (array) $patch['xRows'])) {
            return true;
        }

        foreach (array_keys((array) ($patch['rowDone'] ?? [])) as $klic) {
            if ($this->patriSem((string) $klic)) {
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
        // Pokyn ke smazání se provedl; ve stavu by jen visel.
        unset($patch['shopDel']);

        if (is_array($patch['rowDone'] ?? null)) {
            $zbytek = array_filter(
                $patch['rowDone'],
                fn (string $klic) => ! $this->patriSem($klic),
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
        if (Tabulky::je('shopping_list_items')) {
            /*
             * Smazání jen výslovně (`shopDel`), ne podle toho, co v seznamu chybí.
             *
             * Klient posílá celý seznam, jak ho zná — a starší seznam z druhého
             * telefonu by jinak smazal položku, kterou mezitím připsal ten druhý.
             */
            $smazat = array_keys(array_filter((array) ($patch['shopDel'] ?? [])));

            if ($smazat !== []) {
                DB::table('shopping_list_items')
                    ->where('gallery_space_id', $prostor->id)
                    ->whereIn('uuid', array_map('strval', $smazat))
                    ->delete();
            }

            if (is_array($patch['xRows']['shopping'] ?? null)) {
                $this->vlastniPolozky($patch['xRows']['shopping'], $prostor, $uzivatel);
            }
        }

        foreach ((array) ($patch['rowDone'] ?? []) as $klic => $koupeno) {
            $klic = (string) $klic;

            if (str_starts_with($klic, self::VLASTNI)) {
                if (Tabulky::je('shopping_list_items')) {
                    DB::table('shopping_list_items')
                        ->where('gallery_space_id', $prostor->id)
                        ->where('uuid', substr($klic, strlen(self::VLASTNI)))
                        ->update([
                            'is_checked' => (bool) $koupeno,
                            'checked_by' => $koupeno ? $uzivatel?->id : null,
                            'updated_at' => now(),
                        ]);
                }

                continue;
            }

            if (! str_starts_with($klic, self::PREDPONA) || ! Tabulky::je('meal_shopping_states')) {
                continue;
            }

            $polozka = substr($klic, strlen(self::PREDPONA));

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

    /**
     * Nově připsané položky ze seznamu, který poslal klient.
     *
     * Spočítané řádky (`shopping:…`) i už uložené (`shopping-item:…`) se
     * přeskočí. Nový řádek (identifikátor mu dal klient) se založí, pokud už
     * stejně pojmenovaná položka na seznamu neleží: klient posílá celý seznam
     * při každé změně, dokud se znovu nenačte, a bez téhle kontroly by se mléko
     * zapsalo tolikrát, kolikrát se mezitím něco odškrtlo.
     *
     * @param  array<int, mixed>  $radky
     */
    private function vlastniPolozky(array $radky, GallerySpace $prostor, ?User $uzivatel): void
    {
        $nove = [];

        foreach ($radky as $radek) {
            if (! is_array($radek)) {
                continue;
            }

            $id = (string) ($radek['id'] ?? '');

            if (str_starts_with($id, self::PREDPONA) || str_starts_with($id, self::VLASTNI)) {
                continue;
            }

            $nazev = mb_substr(trim((string) ($radek['t'] ?? '')), 0, 200);

            if ($nazev !== '') {
                $nove[Str::lower($nazev)] = [$nazev, mb_substr(trim((string) ($radek['m'] ?? '')), 0, 200) ?: null];
            }
        }

        if ($nove === []) {
            return;
        }

        $uz = DB::table('shopping_list_items')
            ->where('gallery_space_id', $prostor->id)
            ->get(['id', 'title', 'is_checked'])
            ->keyBy(fn (object $p) => Str::lower($p->title));

        foreach ($nove as $klic => [$nazev, $detail]) {
            if (isset($uz[$klic])) {
                // Koupené mléko připsané znovu je zase potřeba koupit.
                if ($uz[$klic]->is_checked) {
                    DB::table('shopping_list_items')->where('id', $uz[$klic]->id)
                        ->update(['is_checked' => false, 'checked_by' => null, 'updated_at' => now()]);
                }

                continue;
            }

            DB::table('shopping_list_items')->insert([
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $prostor->id,
                'title' => $nazev,
                'detail' => $detail,
                'created_by' => $uzivatel?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function patriSem(string $klic): bool
    {
        return str_starts_with($klic, self::PREDPONA) || str_starts_with($klic, self::VLASTNI);
    }
}
