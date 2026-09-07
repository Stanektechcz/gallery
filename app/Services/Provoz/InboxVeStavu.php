<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Akční inbox: co dvojice vyřešila a co odložila.
 *
 * Řádky inboxu se nikde neukládají — počítají se z toho, co v aplikaci chybí.
 * Rozhodnutí o nich se ale ukládat musí, a nedělo se to: „Vyřešit" jen
 * přeškrtlo řádek v prohlížeči, po obnovení stránky byl zpátky a záložky
 * „Odloženo" a „Hotovo" vedle toho kreslily tři napsané řádky z ukázky.
 *
 * Klíč nese sám řádek (`inbox:fotky-bez-data`), ne jeho pořadí ani text.
 * Kdyby se ukládal text, přibyla by jedna fotka bez data, popisek by se
 * změnil z „12 fotek" na „13 fotek" a odložení by tiše přestalo platit.
 *
 * Odložení má vždycky datum. Bez něj by z „teď to neřeš" bylo smazání
 * a dvojice by se o té věci už nikdy nedozvěděla.
 */
class InboxVeStavu
{
    /** Předpona, podle které se pozná klíč, který patří sem. */
    public const PREDPONA = 'inbox:';

    /** Na jak dlouho se odkládá. Týden — v prototypu je to „odloženo o týden". */
    private const DNI = 7;

    /**
     * Seznamy, které tahle vrstva vlastní.
     *
     * `inbox` je mezi nimi, i když se do něj nezapisuje. Posílá se k rozhodnutí
     * jen proto, aby k němu server znal text řádku — a když zůstal ležet ve
     * stavu, obrazovka ho pak kreslila z něj místo ze serveru. Vyřešený řádek
     * se tak vracel na místo a rozhodnutí vypadalo, že se neuložilo.
     */
    public const SEZNAMY = ['inbox', 'snoozed', 'inboxDone'];

    /** Mapy, ve kterých hledá své klíče. */
    private const MAPY = ['rowDone', 'inboxSt'];

    public function tykaSe(array $patch): bool
    {
        if (array_key_exists('xRows', $patch)
            && array_intersect(self::SEZNAMY, array_keys((array) $patch['xRows'])) !== []) {
            return true;
        }

        foreach (self::MAPY as $mapa) {
            foreach (array_keys((array) ($patch[$mapa] ?? [])) as $klic) {
                if (str_starts_with((string) $klic, self::PREDPONA)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Patch bez toho, co si bere databáze.
     *
     * `rowDone` i `xRows` patří ještě deseti dalším obrazovkám, takže se
     * z nich vybírají jen klíče s předponou — vyhodit je celé by znamenalo
     * smazat nákupní seznam kvůli jedné vyřešené fotce bez data.
     *
     * @return array<string, mixed>
     */
    public function bezInboxu(array $patch): array
    {
        foreach (self::MAPY as $mapa) {
            if (! is_array($patch[$mapa] ?? null)) {
                continue;
            }

            $zbytek = array_filter(
                $patch[$mapa],
                fn (string $klic) => ! str_starts_with($klic, self::PREDPONA),
                ARRAY_FILTER_USE_KEY,
            );

            if ($zbytek === []) {
                unset($patch[$mapa]);
            } else {
                $patch[$mapa] = $zbytek;
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
        if (! Schema::hasTable('inbox_states')) {
            return;
        }

        $nazvy = $this->nazvy($patch);

        foreach ((array) ($patch['rowDone'] ?? []) as $klic => $hotovo) {
            if (! str_starts_with((string) $klic, self::PREDPONA)) {
                continue;
            }

            $hotovo
                ? $this->uloz($prostor, (string) $klic, 'done', $nazvy, $uzivatel)
                : $this->zrus($prostor, (string) $klic);
        }

        /*
         * Odložení chodí z úzkého rozvržení jako `inboxSt[klíč] = 'snooze'`.
         *
         * `act` znamená „vrať to zpátky mezi ostatní" — tedy zahodit
         * rozhodnutí, ne uložit další.
         */
        foreach ((array) ($patch['inboxSt'] ?? []) as $klic => $stav) {
            if (! str_starts_with((string) $klic, self::PREDPONA)) {
                continue;
            }

            match ((string) $stav) {
                'snooze' => $this->uloz($prostor, (string) $klic, 'snoozed', $nazvy, $uzivatel),
                'done' => $this->uloz($prostor, (string) $klic, 'done', $nazvy, $uzivatel),
                default => $this->zrus($prostor, (string) $klic),
            };
        }
    }

    /**
     * Text řádku, jak ho v tu chvíli obrazovka ukazovala.
     *
     * Ukládá se s rozhodnutím, protože po vyřešení se ten řádek přestane
     * počítat — a seznam „Hotovo" by pak neměl co napsat.
     *
     * @return array<string, string>
     */
    private function nazvy(array $patch): array
    {
        $nazvy = [];

        foreach ((array) ($patch['xRows']['inbox'] ?? []) as $radek) {
            $klic = is_array($radek) ? ($radek['klic'] ?? $radek['id'] ?? null) : null;
            $text = is_array($radek) ? ($radek['t'] ?? null) : null;

            if (is_string($klic) && is_string($text) && str_starts_with($klic, self::PREDPONA)) {
                $nazvy[$klic] = $text;
            }
        }

        return $nazvy;
    }

    /** @param  array<string, string>  $nazvy */
    private function uloz(GallerySpace $prostor, string $klic, string $stav, array $nazvy, ?User $uzivatel): void
    {
        $drive = DB::table('inbox_states')
            ->where('gallery_space_id', $prostor->id)
            ->where('item_key', $klic)
            ->first();

        DB::table('inbox_states')->updateOrInsert(
            ['gallery_space_id' => $prostor->id, 'item_key' => $klic],
            [
                'state' => $stav,
                // Název z patche, jinak ten uložený, jinak aspoň klíč — prázdný
                // řádek v seznamu „Hotovo" nikomu nic neřekne.
                'title' => $nazvy[$klic] ?? $drive?->title ?? $this->zKlice($klic),
                'snoozed_until' => $stav === 'snoozed' ? now()->addDays(self::DNI)->toDateString() : null,
                'resolved_at' => $stav === 'done' ? now() : null,
                'by_user_id' => $uzivatel?->id,
                'created_at' => $drive->created_at ?? now(),
                'updated_at' => now(),
            ],
        );
    }

    private function zrus(GallerySpace $prostor, string $klic): void
    {
        DB::table('inbox_states')
            ->where('gallery_space_id', $prostor->id)
            ->where('item_key', $klic)
            ->delete();
    }

    /** Nouzový popisek z klíče: `inbox:fotky-bez-data` → `Fotky bez data`. */
    private function zKlice(string $klic): string
    {
        $text = str_replace('-', ' ', substr($klic, strlen(self::PREDPONA)));

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
