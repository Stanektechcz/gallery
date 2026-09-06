<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Obsah\Darky;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Přání, nápady a chystané dárky, které přišly jako změna stavu.
 *
 * Všechny tři obrazovky čtou `state.wishes || GIFT_WISHES` (a stejně tak
 * `ideas` a `buys`), takže první napsané přání zastínilo celou sekci: druhý
 * z dvojice o něm nevěděl, po zavření záložky zmizelo a skutečné dárky
 * z obrazovky vypadly.
 *
 * Celá sekce přitom stojí na jedné věci: **druhý nesmí vidět, co se pro něj
 * chystá.** Zápis to drží stejně jako čtení — nákup dostane
 * `private_to_user_id` toho, kdo ho pořizuje.
 */
class DarkyVeStavu
{
    /** Klíče, které patří databázi. Do stavu se neukládají. */
    public const SERVEROVE = ['wishes', 'ideas', 'buys'];

    public function __construct(private readonly Darky $obsah) {}

    public function tykaSe(array $patch): bool
    {
        foreach (self::SERVEROVE as $klic) {
            if (array_key_exists($klic, $patch)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function bezDarku(array $patch): array
    {
        return array_diff_key($patch, array_flip(self::SERVEROVE));
    }

    /**
     * Zapíše a vrátí sekci tak, jak ji zná server.
     *
     * @return array<string, mixed>
     */
    public function zpracuj(array $patch, GallerySpace $prostor, ?User $uzivatel): array
    {
        if ($uzivatel === null || ! Schema::hasTable('gift_ideas')) {
            return [];
        }

        $jmena = $prostor->members()->pluck('users.id', 'users.name')->all();
        $zustavaji = [];
        $tykaSe = [];

        foreach (['wishes' => 'prani', 'ideas' => 'napad', 'buys' => 'nakup'] as $klic => $druh) {
            if (! array_key_exists($klic, $patch)) {
                continue;
            }

            $tykaSe[] = $druh;

            foreach ((array) $patch[$klic] as $polozka) {
                $id = $this->uloz((array) $polozka, $druh, $prostor, $uzivatel, $jmena);

                if ($id !== null) {
                    $zustavaji[] = $id;
                }
            }
        }

        $this->smazChybejici($zustavaji, $tykaSe, $prostor, $uzivatel);

        $obsah = $this->obsah->kolekce($prostor);

        return array_filter([
            'wishes' => $obsah['GIFT_WISHES'] ?? [],
            'ideas' => $obsah['GIFT_IDEAS'] ?? [],
            'buys' => $obsah['GIFT_BUYS'] ?? [],
        ], fn ($v) => $v !== []);
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, int>  $jmena
     * @return int|null id řádku, který má zůstat
     */
    private function uloz(array $p, string $druh, GallerySpace $prostor, User $uzivatel, array $jmena): ?int
    {
        $nazev = trim((string) ($p['title'] ?? $p['what'] ?? ''));

        if ($nazev === '') {
            return null;
        }

        $radek = [
            'title' => $nazev,
            'budget' => (int) ($p['price'] ?? 0),
            'source_url' => (string) ($p['note'] ?? $p['text'] ?? $p['where'] ?? ''),
            'status' => $this->stav($p, $druh),
            'occasion' => (string) ($p['occasion'] ?? ''),
            'updated_at' => now(),
        ];

        /*
         * Přání si píše člověk sám za sebe a je veřejné — o to jde. Nákup je
         * naopak soukromý toho, kdo ho pořizuje; prozrazený dárek se nedá
         * vzít zpět.
         */
        $pojmenovany = $jmena[(string) ($p['who'] ?? $p['owner'] ?? '')] ?? null;
        $autor = $pojmenovany ?? $uzivatel->id;

        if (Schema::hasColumn('gift_ideas', 'private_to_user_id')) {
            $radek['private_to_user_id'] = $druh === 'nakup' ? $autor : null;
        }

        // Přání i nákup svého člověka jmenují — a ten se může změnit, když se
        // nápad povýší na přání toho druhého. Nápad nikoho nejmenuje, tam
        // autor zůstává, jak byl.
        if ($pojmenovany !== null) {
            $radek['created_by'] = $pojmenovany;
        }

        $existujici = DB::table('gift_ideas')
            ->where('gallery_space_id', $prostor->id)
            ->where('uuid', (string) ($p['id'] ?? ''))
            ->value('id');

        if ($existujici) {
            DB::table('gift_ideas')->where('id', $existujici)->update($radek);

            return (int) $existujici;
        }

        return (int) DB::table('gift_ideas')->insertGetId($radek + [
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'created_by' => $autor,
            'currency' => 'CZK',
            'created_at' => now(),
        ]);
    }

    /**
     * Co v seznamu není, dvojice smazala.
     *
     * **Jen z toho, co ten člověk vidí.** Nákup, který si druhý schoval sám
     * pro sebe, v jeho seznamu není — a smazat cizí schovaný dárek proto, že
     * o něm ten první neví, by bylo to nejhorší, co tahle vrstva může udělat.
     *
     * @param  list<int>  $zustavaji
     * @param  list<string>  $druhy
     */
    private function smazChybejici(array $zustavaji, array $druhy, GallerySpace $prostor, User $uzivatel): void
    {
        if ($druhy === []) {
            return;
        }

        $stavy = [];

        foreach ($druhy as $druh) {
            $stavy = array_merge($stavy, match ($druh) {
                'prani' => ['wish', 'wanted', 'prani'],
                'napad' => ['idea', ''],
                default => ['reserved', 'planned', 'bought', 'purchased', 'wrapped', 'given', 'done'],
            });
        }

        DB::table('gift_ideas')
            ->where('gallery_space_id', $prostor->id)
            ->whereIn('status', $stavy)
            ->when(
                Schema::hasColumn('gift_ideas', 'private_to_user_id'),
                fn ($q) => $q->where(
                    fn ($v) => $v->whereNull('private_to_user_id')->orWhere('private_to_user_id', $uzivatel->id),
                ),
            )
            ->when($zustavaji !== [], fn ($q) => $q->whereNotIn('id', $zustavaji))
            ->delete();
    }

    /** @param  array<string, mixed>  $p */
    private function stav(array $p, string $druh): string
    {
        if ($druh === 'prani') {
            return 'wish';
        }

        if ($druh === 'napad') {
            return 'idea';
        }

        return match ((string) ($p['status'] ?? 'reserved')) {
            'bought' => 'bought',
            'wrapped' => 'wrapped',
            'given' => 'given',
            default => 'reserved',
        };
    }
}
