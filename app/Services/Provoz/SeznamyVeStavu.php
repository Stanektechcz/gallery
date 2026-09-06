<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Seznamy `AL`, které obrazovka umí doplnit.
 *
 * `xRows` je společný sklad pro třicet dva seznamů napříč aplikací. Většina
 * z nich se do téhle chvíle ukládala jen do stavu páru: data sice přežila
 * zavření záložky, ale nedalo se na ně zeptat odjinud — nákupní seznam neviděla
 * připomínka a z nápadu na dárek nevznikl úkol.
 *
 * Tahle vrstva bere ty, které **mají v aplikaci vlastní tabulku**: nápady na
 * dárky, uložená randíčka, jízdenky a cestovní schránku. Ostatní klíče projdou
 * beze změny a zůstanou tam, kde byly.
 *
 * Pozor na společný klíč: `xRows` patří i deseti dalším obrazovkám, takže se
 * ze stavu vyhazují jen ty seznamy, které si vrstva bere — ne celý klíč.
 */
class SeznamyVeStavu
{
    /** Seznamy, které se ukládají do vlastní tabulky. */
    public const SEZNAMY = ['gifts', 'datesSaved', 'ticket', 'travelInbox'];

    public function tykaSe(array $patch): bool
    {
        return is_array($patch['xRows'] ?? null)
            && array_intersect(self::SEZNAMY, array_keys($patch['xRows'])) !== [];
    }

    /**
     * Patch bez seznamů, které si bere databáze.
     *
     * `xRows` se nevyhazuje celý — nákupní seznam ani nápady na fotky tabulku
     * nemají a zmizely by.
     *
     * @return array<string, mixed>
     */
    public function bezSeznamu(array $patch): array
    {
        if (! is_array($patch['xRows'] ?? null)) {
            return $patch;
        }

        $patch['xRows'] = array_diff_key($patch['xRows'], array_flip(self::SEZNAMY));

        if ($patch['xRows'] === []) {
            unset($patch['xRows']);
        }

        return $patch;
    }

    public function zpracuj(array $patch, GallerySpace $prostor, ?User $uzivatel): void
    {
        $seznamy = (array) ($patch['xRows'] ?? []);

        if (is_array($seznamy['gifts'] ?? null)) {
            $this->napady($seznamy['gifts'], $prostor, $uzivatel);
        }

        if (is_array($seznamy['datesSaved'] ?? null)) {
            $this->randicka($seznamy['datesSaved'], $prostor, $uzivatel);
        }

        if (is_array($seznamy['ticket'] ?? null)) {
            $this->jizdenky($seznamy['ticket'], $prostor, $uzivatel);
        }

        if (is_array($seznamy['travelInbox'] ?? null)) {
            $this->cestovniInbox($seznamy['travelInbox'], $prostor);
        }
    }

    /**
     * Nové nápady na dárek.
     *
     * Zapisují se **jen přírůstky**. Obrazovka posílá celý seznam, ve kterém
     * jsou i řádky poskládané z příležitostí — a ty do `gift_ideas` nepatří:
     * jsou to rozpočty, ne nápady. Poznají se podle předpony, kterou jim dal
     * poskytovatel.
     *
     * @param  list<mixed>  $radky
     */
    private function napady(array $radky, GallerySpace $prostor, ?User $uzivatel): void
    {
        if (! Schema::hasTable('gift_ideas')) {
            return;
        }

        $znamé = DB::table('gift_ideas')
            ->where('gallery_space_id', $prostor->id)
            ->pluck('title')
            ->map(fn ($t) => mb_strtolower(trim((string) $t)))
            ->all();

        foreach ($radky as $r) {
            $nazev = $this->nazev($r);

            // Řádek příležitosti sem nepatří; nápady mají předponu.
            if ($nazev === '' || ! str_starts_with($nazev, 'Nápad: ')) {
                continue;
            }

            $nazev = trim(mb_substr($nazev, mb_strlen('Nápad: ')));

            if ($nazev === '' || in_array(mb_strtolower($nazev), $znamé, true)) {
                continue;
            }

            DB::table('gift_ideas')->insert([
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $prostor->id,
                'created_by' => $uzivatel?->id,
                'title' => mb_substr($nazev, 0, 180),
                'status' => 'idea',
                'created_from' => 'prototyp',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $znamé[] = mb_strtolower($nazev);
        }
    }

    /**
     * Uložené nápady na randíčko.
     *
     * @param  list<mixed>  $radky
     */
    private function randicka(array $radky, GallerySpace $prostor, ?User $uzivatel): void
    {
        if (! Schema::hasTable('couple_date_ideas')) {
            return;
        }

        $znamé = DB::table('couple_date_ideas')
            ->where('gallery_space_id', $prostor->id)
            ->pluck('title')
            ->map(fn ($t) => mb_strtolower(trim((string) $t)))
            ->all();

        foreach ($radky as $r) {
            $nazev = $this->nazev($r);

            if ($nazev === '' || in_array(mb_strtolower($nazev), $znamé, true)) {
                continue;
            }

            DB::table('couple_date_ideas')->insert([
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $prostor->id,
                'created_by' => $uzivatel?->id,
                // Klíč generování patří návrhům z generátoru; ruční zápis
                // ho nemá a prázdný řetězec je poctivější než vymyšlený.
                'generation_key' => '',
                'title' => mb_substr($nazev, 0, 180),
                'summary' => '',
                'theme' => '',
                'estimated_minutes' => 0,
                'parameters' => json_encode([]),
                'plan' => json_encode([]),
                'status' => 'saved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $znamé[] = mb_strtolower($nazev);
        }
    }

    /**
     * Uložené jízdenky a spojení.
     *
     * @param  list<mixed>  $radky
     */
    private function jizdenky(array $radky, GallerySpace $prostor, ?User $uzivatel): void
    {
        if (! Schema::hasTable('saved_transport_routes')) {
            return;
        }

        $znamé = DB::table('saved_transport_routes')
            ->where('gallery_space_id', $prostor->id)
            ->pluck('name')
            ->map(fn ($t) => mb_strtolower(trim((string) $t)))
            ->all();

        foreach ($radky as $r) {
            $nazev = $this->nazev($r);

            if ($nazev === '' || in_array(mb_strtolower($nazev), $znamé, true)) {
                continue;
            }

            /*
             * Trasa se z názvu **nehádá**.
             *
             * „Letenky BRQ – LIS" vypadá jako odkud kam, ale „Nová jízdenka"
             * taky — a rozdělit to podle pomlčky by z poloviny řádků udělalo
             * cestu, která nikam nevede. Odkud kam doplní člověk.
             */
            DB::table('saved_transport_routes')->insert([
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $prostor->id,
                'created_by' => $uzivatel?->id,
                'name' => mb_substr($nazev, 0, 180),
                'origin' => '',
                'destination' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $znamé[] = mb_strtolower($nazev);
        }
    }

    /**
     * Zařazené položky cestovní schránky.
     *
     * Nové se odsud nezakládají — do schránky se dostávají z e-mailu, chatu
     * a sdílení, ne psaním do seznamu. Zapisuje se jen to, co dvojice zařadila.
     *
     * @param  list<mixed>  $radky
     */
    private function cestovniInbox(array $radky, GallerySpace $prostor): void
    {
        if (! Schema::hasTable('travel_inbox_items')) {
            return;
        }

        $zarazene = [];

        foreach ($radky as $r) {
            $r = (array) $r;
            $nazev = $this->nazev($r);

            if ($nazev !== '' && ($r['g'] ?? null) === 'zařazeno') {
                $zarazene[] = $nazev;
            }
        }

        if ($zarazene === []) {
            return;
        }

        DB::table('travel_inbox_items')
            ->where('gallery_space_id', $prostor->id)
            ->whereIn('title', $zarazene)
            ->where('state', '!=', 'filed')
            ->update(['state' => 'filed', 'updated_at' => now()]);
    }

    /** Název řádku — obrazovka posílá objekty `{ t, m, g, id }`. */
    private function nazev(mixed $radek): string
    {
        $radek = (array) $radek;

        return trim((string) ($radek['t'] ?? $radek[0] ?? ''));
    }
}
