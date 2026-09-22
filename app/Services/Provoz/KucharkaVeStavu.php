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
                // Nejbližší takový den od dneška — ne pondělí téhož týdne (viz `Kucharka::datumDne`).
                $datum = Kucharka::datumDne($poradi);
                $klic = is_string($menu[$den] ?? null) ? $menu[$den] : '';
                $recept = $recepty[$klic] ?? null;

                // Neznámý klíč (recept mezitím smazaný) den neruší — jen se nezapíše.
                if ($klic !== '' && $recept === null) {
                    continue;
                }

                $stavajici = PlannedMeal::where('gallery_space_id', $prostor->id)
                    ->whereNull('trip_id')
                    ->where('meal_type', 'dinner')
                    ->whereIn('status', ['planned', 'confirmed'])
                    ->where('planned_for', '>=', $datum->startOfDay())
                    ->where('planned_for', '<', $datum->addDay()->startOfDay())
                    ->orderBy('planned_for')
                    ->first();

                if ($recept === null) {
                    $stavajici?->delete();

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
