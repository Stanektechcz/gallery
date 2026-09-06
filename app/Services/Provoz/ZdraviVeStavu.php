<?php

namespace App\Services\Provoz;

use App\Models\CycleDay;
use App\Models\GallerySpace;
use App\Models\User;
use App\Models\WellbeingMood;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Cyklus a nálada, které přišly jako změna stavu.
 *
 * Kalendář cyklu má v aplikaci vlastní modul i tabulku, takže tenhle zápis není
 * o tom vyrobit nový domov — je o to, aby zápis z prototypu skončil tam, kde se
 * na něj ptá zbytek aplikace. Bez něj by dvojice měla dva kalendáře: jeden
 * v prohlížeči a druhý v databázi.
 *
 * **Zapisuje se jen pod přihlášeného člověka.** Cyklus je soukromý zápis; cizí
 * den by nikdo neměl umět přepsat ani omylem.
 */
class ZdraviVeStavu
{
    /** Klíče, které patří databázi. Do stavu se neukládají. */
    public const SERVEROVE = ['cycDays', 'klMood'];

    public function tykaSe(array $patch): bool
    {
        return array_intersect(self::SERVEROVE, array_keys($patch)) !== [];
    }

    /** @return array<string, mixed> patch bez klíčů, které si bere databáze */
    public function bezZdravi(array $patch): array
    {
        return array_diff_key($patch, array_flip(self::SERVEROVE));
    }

    public function zpracuj(array $patch, GallerySpace $prostor, ?User $kdo): void
    {
        if (! $kdo || ! Schema::hasTable('cycle_days')) {
            return;
        }

        if (is_array($patch['cycDays'] ?? null)) {
            $this->zapisDny($patch['cycDays'], $prostor, $kdo);
        }

        if (is_array($patch['klMood'] ?? null) && Schema::hasTable('wellbeing_moods')) {
            $this->zapisNalady($patch['klMood'], $prostor, $kdo);
        }
    }

    /**
     * Zapsané dny cyklu.
     *
     * Klient posílá mapu `datum => zápis`, ale jen ty dny, které sám změnil —
     * `cycDays()` v prototypu skládá základ ze serveru a tenhle přepis nad něj.
     *
     * @param  array<string, mixed>  $dny
     */
    private function zapisDny(array $dny, GallerySpace $prostor, User $kdo): void
    {
        foreach ($dny as $datum => $zapis) {
            if (! is_array($zapis) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $datum)) {
                continue;
            }

            $den = CarbonImmutable::parse($datum)->startOfDay();

            /*
             * Smazaný den je smazaný, ne prázdný.
             *
             * Prototyp umí zápis odebrat („cycDeleteDay"); prázdný řádek by
             * v kalendáři zůstal jako tečka bez obsahu a kazil odhad.
             */
            if (($zapis['deleted'] ?? false) === true) {
                CycleDay::where('gallery_space_id', $prostor->id)
                    ->where('user_id', $kdo->id)
                    ->whereDate('day', $den)
                    ->delete();

                continue;
            }

            CycleDay::updateOrCreate(
                [
                    'gallery_space_id' => $prostor->id,
                    'user_id' => $kdo->id,
                    'day' => $den,
                ],
                [
                    'flow' => in_array($zapis['flow'] ?? 'none', CycleDay::FLOWS, true) ? $zapis['flow'] : 'none',
                    'symptoms' => array_values(array_filter((array) ($zapis['symptoms'] ?? []))),
                    'moods' => array_values(array_filter((array) ($zapis['moods'] ?? []))),
                    'pain' => isset($zapis['pain']) && $zapis['pain'] !== null ? (int) $zapis['pain'] : null,
                    'temperature' => isset($zapis['temp']) && $zapis['temp'] !== null ? (float) $zapis['temp'] : null,
                    'note' => $zapis['note'] ?? null,
                    'is_cycle_start' => (bool) ($zapis['start'] ?? false),
                ],
            );
        }
    }

    /**
     * Nálada dne.
     *
     * Prototyp drží mapu `jméno => [čtrnáct hodnot]`, kde poslední je dnešek.
     * Zapisuje se jen řádek přihlášeného člověka — cizí náladu za nikoho
     * vyplňovat nelze.
     *
     * @param  array<string, mixed>  $nalady
     */
    private function zapisNalady(array $nalady, GallerySpace $prostor, User $kdo): void
    {
        $moje = $nalady[$kdo->name] ?? null;

        if (! is_array($moje) || ! $moje) {
            return;
        }

        $dnes = CarbonImmutable::now()->startOfDay();
        $pocet = count($moje);

        foreach (array_values($moje) as $i => $hodnota) {
            if ($hodnota === null || $hodnota === '') {
                continue;
            }

            $hodnota = (int) $hodnota;

            if ($hodnota < 1 || $hodnota > 5) {
                continue;
            }

            WellbeingMood::updateOrCreate(
                [
                    'gallery_space_id' => $prostor->id,
                    'user_id' => $kdo->id,
                    // Poslední hodnota patří dnešku, předchozí dnům před ním.
                    'day' => $dnes->subDays($pocet - 1 - $i),
                ],
                ['value' => $hodnota],
            );
        }
    }
}
