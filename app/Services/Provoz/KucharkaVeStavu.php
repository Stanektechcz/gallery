<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\PlannedMeal;
use App\Models\User;
use App\Services\Obsah\Kucharka;
use App\Support\Tabulky;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menu na týden z prototypu → plán jídel.
 *
 * Výběr receptu na den končil v `ckMenu` ve stavu prohlížeče. Nákupní seznam
 * se přitom počítá z `planned_meals`, takže do něj naplánované jídlo nikdy
 * nedorazilo, a mapa `{Pondělí: …}` bez data by příští pondělí ukazovala
 * pořád totéž. Zápis jde do plánu jídel na nejbližší takový den a klíč ze
 * stavu mizí — obrazovka pak menu čte ze serveru (`CKMENU`).
 *
 * Spravuje jen večeře mimo cesty: to je to, co obrazovka ukazuje. Snídaně,
 * jídla z cest a uvařená jídla nechává být.
 */
class KucharkaVeStavu
{
    public const SERVEROVE = ['ckMenu'];

    /** Pořadí dnů tak, jak je píše obrazovka — od pondělí. */
    private const DNY = ['Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota', 'Neděle'];

    public function __construct(private readonly Kucharka $kucharka) {}

    public function tykaSe(array $patch): bool
    {
        return array_key_exists('ckMenu', $patch);
    }

    public function bezKucharky(array $patch): array
    {
        return array_diff_key($patch, array_flip(self::SERVEROVE));
    }

    public function zpracuj(array $patch, GallerySpace $prostor, User $kdo): void
    {
        if (! Tabulky::je('planned_meals') || ! is_array($patch['ckMenu'] ?? null)) {
            return;
        }

        $menu = $patch['ckMenu'];
        $recepty = $this->kucharka->idPodleKlice($prostor);

        DB::transaction(function () use ($menu, $recepty, $prostor, $kdo) {
            foreach (self::DNY as $poradi => $den) {
                /*
                 * Jen dny, které prohlížeč poslal.
                 *
                 * Chybějící den se bral jako „uvolnit" a naplánovaná večeře se
                 * smazala: `{"ckMenu":{}}` vyprázdnilo týden a karta, ve které
                 * ještě nebyla středa od toho druhého, ji úpravou pondělí
                 * zahodila. Mapa v prohlížeči je kopie z doby načtení — co v ní
                 * chybí, se od „nic jsem neměnil" nepozná. Uvolnit den se dá
                 * jen výslovně: `''` nebo `null`.
                 */
                if (! array_key_exists($den, $menu)) {
                    continue;
                }

                // Nejbližší takový den od dneška — ne pondělí téhož týdne (viz `Kucharka::datumDne`).
                $datum = Kucharka::datumDne($poradi);
                $klic = is_string($menu[$den]) ? $menu[$den] : '';

                // Jiný typ než text nebo `null` (číslo, pole) není „uvolnit" — den se nechá být.
                if ($menu[$den] !== null && ! is_string($menu[$den])) {
                    continue;
                }
                $recept = $recepty[$klic] ?? null;

                // Neznámý klíč (recept mezitím smazaný) den neruší — jen se nezapíše.
                if ($klic !== '' && $recept === null) {
                    continue;
                }

                $stavajici = PlannedMeal::where('gallery_space_id', $prostor->id)
                    ->whereNull('trip_id')
                    ->where('meal_type', 'dinner')
                    /*
                     * Týž filtr jako při čtení (`Kucharka::menu()`).
                     *
                     * Zápis bral jen `planned` a `confirmed`, čtení všechno
                     * kromě `cancelled`. Uvařené jídlo (`cooked`) se tedy na
                     * obrazovce ukázalo, ale tady se nenašlo: výběr jiného
                     * receptu na ten den založil **druhý** řádek a vyčištění
                     * dne neudělalo nic. Den měl v databázi dvě večeře a plán
                     * z nich četl tu starší.
                     */
                    ->where('status', '!=', 'cancelled')
                    ->where('planned_for', '>=', $datum->startOfDay())
                    ->where('planned_for', '<', $datum->addDay()->startOfDay())
                    ->orderBy('planned_for')
                    ->first();

                if ($recept === null) {
                    /*
                     * Uvařené jídlo se nemaže.
                     *
                     * Je to záznam o tom, co dvojice opravdu jedla — plán na
                     * příští týden ho smazat nemá. Přepsat ho jiným receptem
                     * se smí (obrazovka ten den jako večeři ukazuje), ale
                     * „uvolnit den" znamená zahodit plán, ne historii.
                     */
                    if ($stavajici?->status !== 'cooked') {
                        $stavajici?->delete();
                    }

                    continue;
                }

                if ($stavajici !== null) {
                    if ((int) $stavajici->recipe_id !== $recept) {
                        $stavajici->update(['recipe_id' => $recept]);
                    }

                    continue;
                }

                PlannedMeal::create([
                    'uuid' => (string) Str::uuid(),
                    'gallery_space_id' => $prostor->id,
                    'recipe_id' => $recept,
                    'created_by' => $kdo->id,
                    'meal_type' => 'dinner',
                    'planned_for' => $datum->setTime(18, 0),
                    'servings' => (float) (DB::table('recipes')->where('id', $recept)->value('base_servings') ?: 2),
                    'status' => 'planned',
                ]);
            }
        });
    }
}
