<?php

namespace App\Services\Provoz;

use App\Models\CycleDay;
use App\Models\CycleSetting;
use App\Models\GallerySpace;
use App\Models\User;
use App\Models\WellbeingMood;
use App\Support\Tabulky;
use Carbon\CarbonImmutable;

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
    /**
     * Klíče, které patří databázi. Do stavu se neukládají.
     *
     * Nastavení cyklu (`cycShare` a spol.) je osobní — ve společném stavu by
     * volba jednoho přepsala obrazovku druhého, a hlavně by nic neřídila:
     * co partner uvidí, rozhoduje `cycle_settings.share_level`.
     */
    public const SERVEROVE = ['cycDays', 'klMood', 'cycShare', 'cycRemind', 'cycRemindDays', 'cycTrack'];

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
        if (! $kdo || ! Tabulky::je('cycle_days')) {
            return;
        }

        $this->zapisNastaveni($patch, $prostor, $kdo);

        if (is_array($patch['cycDays'] ?? null)) {
            $this->zapisDny($patch['cycDays'], $prostor, $kdo);
        }

        if (is_array($patch['klMood'] ?? null) && Tabulky::je('wellbeing_moods')) {
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
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $datum) || ! ($zapis === null || is_array($zapis))) {
                continue;
            }

            $den = CarbonImmutable::parse($datum)->startOfDay();

            /*
             * Smazaný den je smazaný, ne prázdný.
             *
             * Prototyp umí zápis odebrat („cycDeleteDay"); prázdný řádek by
             * v kalendáři zůstal jako tečka bez obsahu a kazil odhad. Obě
             * rozvržení mažou tak, že den nastaví na `null` — to se dřív
             * přeskočilo a smazaný den v databázi zůstal.
             */
            if ($zapis === null || ($zapis['deleted'] ?? false) === true) {
                CycleDay::where('gallery_space_id', $prostor->id)
                    ->where('user_id', $kdo->id)
                    ->whereDate('day', $den)
                    ->delete();

                continue;
            }

            /*
             * Předvyplněný odhad (další dny krvácení po prvním) není zápis.
             *
             * Obrazovka ho kreslí přerušovaně a slibuje, že do statistik
             * nevstupuje. Uložený by se po načtení vrátil jako skutečné
             * krvácení a posunul odhad příštího cyklu.
             */
            if (($zapis['predicted'] ?? false) === true) {
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
     * Komu se cyklus ukazuje, připomínka a sledování příznaků — jen za sebe.
     *
     * Volba „Nic / Jen termíny / Celý deník" se dřív uložila jen do stavu
     * obrazovky. Partner přitom viděl podle `cycle_settings`, takže obrazovka
     * mohla ukazovat „nic nesdílíte", zatímco databáze sdílela celý deník.
     */
    private function zapisNastaveni(array $patch, GallerySpace $prostor, User $kdo): void
    {
        if (! Tabulky::je('cycle_settings')) {
            return;
        }

        $zmeny = [];

        if (array_key_exists('cycShare', $patch)
            && in_array($patch['cycShare'], [CycleSetting::SHARE_NONE, CycleSetting::SHARE_DATES, CycleSetting::SHARE_FULL], true)) {
            $zmeny['share_level'] = $patch['cycShare'];
        }

        // `null` posílá klient jako „vynuť odeslání" před skutečnou hodnotou — nic nemění.
        if (is_bool($patch['cycRemind'] ?? null)) {
            $zmeny['remind_upcoming'] = (bool) $patch['cycRemind'];
        }

        if (array_key_exists('cycRemindDays', $patch) && is_numeric($patch['cycRemindDays'])) {
            $zmeny['remind_days_before'] = max(0, min(14, (int) $patch['cycRemindDays']));
        }

        if (is_bool($patch['cycTrack'] ?? null)) {
            $zmeny['track_symptoms'] = (bool) $patch['cycTrack'];
        }

        if ($zmeny === []) {
            return;
        }

        CycleSetting::updateOrCreate(
            ['user_id' => $kdo->id, 'gallery_space_id' => $prostor->id],
            $zmeny,
        );
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
