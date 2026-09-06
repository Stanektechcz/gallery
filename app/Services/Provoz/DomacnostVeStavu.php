<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\HouseChore;
use App\Models\HouseChoreLogEntry;
use App\Models\HouseDue;
use App\Models\HouseInventoryItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Domácnost, která přišla jako změna stavu.
 *
 * Prototyp celou dělbu práce drží v komponentě: kliknutí na „hotovo" jen změní
 * pole ve stavu a to se pak uloží do `/api/state` jako všechno ostatní. Kdyby
 * to tak zůstalo i s tabulkami, byly by dvě pravdy — a druhá by se od první
 * po prvním kliknutí rozešla. **Tabulka, do které nikdo nepíše, je horší než
 * žádná tabulka.**
 *
 * Záměr se proto přečte, provede v databázi a ze stavu **vyhodí**. Při dalším
 * načtení si prototyp kolekce vezme z `/api/data/domacnost`, tedy ze skutečnosti.
 *
 * Zapisuje se jen to, co prototyp umí změnit: kdo má práci na starost, jestli
 * rotuje a na jaký den padá; nový záznam o odvedené práci; předání a vyřízení
 * lhůty i lhůta založená ze servisu; a uložení dokladu k věci v bytě.
 */
class DomacnostVeStavu
{
    /** Klíče, které patří databázi. Do stavu se neukládají. */
    public const SERVEROVE = ['chores', 'choreLog', 'dues', 'inv'];

    public function tykaSe(array $patch): bool
    {
        return array_intersect(self::SERVEROVE, array_keys($patch)) !== [];
    }

    /** @return array<string, mixed> patch bez klíčů, které si bere databáze */
    public function bezDomacnosti(array $patch): array
    {
        return array_diff_key($patch, array_flip(self::SERVEROVE));
    }

    public function zpracuj(array $patch, GallerySpace $prostor): void
    {
        if (! Schema::hasTable('house_chores')) {
            return;
        }

        $jmena = array_flip($prostor->members()->pluck('users.name', 'users.id')->all());

        // Nejdřív záznamy: z nich se pozná, kdy se která práce naposledy udělala.
        if (is_array($patch['choreLog'] ?? null)) {
            $this->zapisZaznamy($patch['choreLog'], $prostor, $jmena);
        }

        if (is_array($patch['chores'] ?? null)) {
            $this->zapisPrace($patch['chores'], $prostor, $jmena);
        }

        if (is_array($patch['dues'] ?? null)) {
            $this->zapisZavazky($patch['dues'], $prostor, $jmena);
        }

        if (is_array($patch['inv'] ?? null)) {
            $this->zapisByt($patch['inv'], $prostor);
        }
    }

    /**
     * Nový záznam o odvedené práci.
     *
     * Prototyp posílá celý seznam, ale přidat do něj umí jedinou věcí —
     * tlačítkem „mám hotovo", které vloží na začátek řádek s popiskem
     * „právě teď". Tenhle podpis je proto jediné, co se zapisuje: bez něj by
     * se při prvním kliknutí do historie propsalo i dvacet napsaných řádků
     * z ukázky, všechny s dnešním časem, a statistika posledního měsíce by
     * lhala hned v první vteřině.
     *
     * Skutečný okamžik vzniká tady — klient žádný neposílá.
     *
     * @param  array<int, mixed>  $radky
     * @param  array<string, int>  $jmena
     */
    private function zapisZaznamy(array $radky, GallerySpace $prostor, array $jmena): void
    {
        $zname = $this->podleId(HouseChoreLogEntry::where('gallery_space_id', $prostor->id)->get());
        $prvni = $radky[0] ?? null;

        if (is_array($prvni) && isset($prvni['id'])
            && ! $zname->has($prvni['id'])
            && ($prvni['when'] ?? '') === 'právě teď') {
            HouseChoreLogEntry::create([
                'client_id' => (string) $prvni['id'],
                'gallery_space_id' => $prostor->id,
                'house_chore_id' => HouseChore::where('gallery_space_id', $prostor->id)
                    ->where('name', $prvni['chore'] ?? '')->value('id'),
                'chore_name' => (string) ($prvni['chore'] ?? 'Práce'),
                'user_id' => $jmena[$prvni['who'] ?? ''] ?? null,
                'minutes' => (int) ($prvni['mins'] ?? 0),
                'done_at' => now(),
            ]);
        }

        /*
         * Vzato zpět.
         *
         * „Mám hotovo" má tlačítko zpět a to pošle seznam bez toho řádku.
         * Maže se jen to, co tudy vzniklo (`client_id`) — historie z jiných
         * zdrojů zůstává.
         */
        $prisly = collect($radky)->pluck('id')->filter()->map(fn ($i) => (string) $i)->all();

        HouseChoreLogEntry::where('gallery_space_id', $prostor->id)
            ->whereNotNull('client_id')
            ->whereNotIn('client_id', $prisly ?: [''])
            ->whereNotIn('uuid', $prisly ?: [''])
            ->delete();
    }

    /**
     * Dělba: kdo, rotace a den.
     *
     * Práce se z prototypu nedají zakládat ani mazat, takže se jen mění to,
     * co se změnit dá — neznámý identifikátor se přeskočí, místo aby vznikl
     * duplikát.
     *
     * @param  array<int, mixed>  $radky
     * @param  array<string, int>  $jmena
     */
    private function zapisPrace(array $radky, GallerySpace $prostor, array $jmena): void
    {
        $prace = $this->podleId(HouseChore::where('gallery_space_id', $prostor->id)->get());

        // První dotek: v databázi ještě nic není a na obrazovce je rozdělení,
        // se kterým dvojice právě pracovala. Tím se stává jejich — kdyby se
        // zahodilo, jejich klik by se po obnovení stránky ztratil.
        if ($prace->isEmpty()) {
            $prace = $this->prvniPrace($radky, $prostor, $jmena);
        }

        foreach ($radky as $r) {
            if (! is_array($r) || ! isset($r['id']) || ! $prace->has($r['id'])) {
                continue;
            }

            $p = $prace[$r['id']];

            $p->update([
                'assigned_to' => $jmena[$r['who'] ?? ''] ?? $p->assigned_to,
                'rotate' => (bool) ($r['rotate'] ?? $p->rotate),
                'day' => array_key_exists('day', $r) ? ($r['day'] ?: null) : $p->day,
            ]);
        }

        // A teď „naposledy" — ze záznamů, ne z popisku, který přišel s patchem.
        $posledni = HouseChoreLogEntry::where('gallery_space_id', $prostor->id)
            ->selectRaw('chore_name, MAX(done_at) AS kdy')
            ->groupBy('chore_name')
            ->pluck('kdy', 'chore_name');

        foreach ($prace->unique('id') as $p) {
            $kdy = $posledni[$p->name] ?? null;

            if ($kdy && (! $p->last_done_at || CarbonImmutable::parse($kdy)->gt($p->last_done_at))) {
                $p->update(['last_done_at' => $kdy]);
            }
        }
    }

    /**
     * Založení dělby při prvním doteku.
     *
     * Identifikátor z prototypu (`c1`) se schová do `client_id` a řádek dostane
     * vlastní uuid; klient ale poslal `c1`, takže se mapa vrací klíčovaná tím,
     * co přišlo — jinak by hned další krok téhož zápisu nic nenašel.
     *
     * @param  array<int, mixed>  $radky
     * @param  array<string, int>  $jmena
     * @return \Illuminate\Support\Collection<string, HouseChore>
     */
    private function prvniPrace(array $radky, GallerySpace $prostor, array $jmena): \Illuminate\Support\Collection
    {
        $zalozene = collect();

        foreach (array_values($radky) as $poradi => $r) {
            if (! is_array($r) || ! isset($r['id'], $r['name'])) {
                continue;
            }

            $zalozene[(string) $r['id']] = HouseChore::create([
                'client_id' => (string) $r['id'],
                'gallery_space_id' => $prostor->id,
                'name' => (string) $r['name'],
                'every' => (string) ($r['every'] ?? 'týdně'),
                'assigned_to' => $jmena[$r['who'] ?? ''] ?? null,
                'rotate' => (bool) ($r['rotate'] ?? true),
                'minutes' => (int) ($r['mins'] ?? 30),
                'day' => ($r['day'] ?? null) ?: null,
                'icon' => (string) ($r['icon'] ?? 'ph-broom'),
                'sort_order' => $poradi,
            ]);
        }

        return $zalozene;
    }

    /**
     * Lhůty: předání, vyřízení a nová lhůta ze servisu.
     *
     * Co ze seznamu zmizelo, je vyřízené — prototyp „hotovo" dělá odebráním
     * řádku. Smazat se to nesmí: rok co rok se ptáme, kdy naposledy byla STK.
     *
     * @param  array<int, mixed>  $radky
     * @param  array<string, int>  $jmena
     */
    private function zapisZavazky(array $radky, GallerySpace $prostor, array $jmena): void
    {
        $vDatabazi = HouseDue::where('gallery_space_id', $prostor->id)
            ->whereNull('settled_at')
            ->get();

        $zavazky = $this->podleId($vDatabazi);

        $prisly = [];

        foreach ($radky as $r) {
            if (! is_array($r) || ! isset($r['id'])) {
                continue;
            }

            $prisly[] = (string) $r['id'];

            if ($zavazky->has($r['id'])) {
                $zavazky[$r['id']]->update([
                    'user_id' => $jmena[$r['who'] ?? ''] ?? $zavazky[$r['id']]->user_id,
                ]);

                continue;
            }

            $this->zalozZavazek($r, $prostor, $jmena);
        }

        // Vyřízené: byly v databázi, v seznamu už nejsou. Pozná se to podle
        // obou identifikátorů — v jednom sezení posílá klient ještě ten svůj.
        $vDatabazi
            ->reject(fn (HouseDue $z) => in_array($z->uuid, $prisly, true)
                || ($z->client_id && in_array($z->client_id, $prisly, true)))
            ->each(fn (HouseDue $z) => $z->update(['settled_at' => now()]));
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, int>  $jmena
     */
    private function zalozZavazek(array $r, GallerySpace $prostor, array $jmena): void
    {
        $kdy = $this->datum((string) ($r['date'] ?? ''));

        // Bez data by lhůta neměla smysl — a prototyp ji bez data neposílá.
        if (! $kdy) {
            return;
        }

        HouseDue::create([
            'client_id' => (string) $r['id'],
            'gallery_space_id' => $prostor->id,
            'what' => (string) ($r['what'] ?? 'Závazek'),
            'kind' => (string) ($r['kind'] ?? 'lhůta'),
            'due_on' => $kdy,
            'amount' => (int) ($r['amount'] ?? 0),
            'user_id' => $jmena[$r['who'] ?? ''] ?? null,
            'note' => $r['note'] ?? null,
            'delay_note' => $r['delayNote'] ?? null,
            'delay_cost' => isset($r['delayCost']) ? (int) $r['delayCost'] : null,
            'change_note' => $r['change'] ?? null,
        ]);
    }

    /**
     * Uložení dokladu k věci v bytě.
     *
     * @param  array<int, mixed>  $radky
     */
    private function zapisByt(array $radky, GallerySpace $prostor): void
    {
        $veci = $this->podleId(HouseInventoryItem::where('gallery_space_id', $prostor->id)->get());

        if ($veci->isEmpty()) {
            $veci = $this->prvniByt($radky, $prostor);
        }

        foreach ($radky as $r) {
            if (! is_array($r) || ! isset($r['id']) || ! $veci->has($r['id'])) {
                continue;
            }

            $veci[$r['id']]->update(['has_doc' => (bool) ($r['doc'] ?? false)]);
        }
    }

    /**
     * Založení bytu při prvním doteku.
     *
     * @param  array<int, mixed>  $radky
     * @return \Illuminate\Support\Collection<string, HouseInventoryItem>
     */
    private function prvniByt(array $radky, GallerySpace $prostor): \Illuminate\Support\Collection
    {
        $zalozene = collect();

        foreach ($radky as $r) {
            if (! is_array($r) || ! isset($r['id'], $r['name'])) {
                continue;
            }

            $zaruka = $this->mesic((string) ($r['warrantyTo'] ?? ''));

            $zalozene[(string) $r['id']] = HouseInventoryItem::create([
                'client_id' => (string) $r['id'],
                'gallery_space_id' => $prostor->id,
                'name' => (string) $r['name'],
                'subtitle' => $r['sub'] ?? null,
                'room' => $r['room'] ?? null,
                'bought_on' => $this->datum((string) ($r['bought'] ?? '')),
                'warranty_to' => $zaruka,
                'has_doc' => (bool) ($r['doc'] ?? false),
                'needs_service' => (bool) ($r['service'] ?? false),
                'service_next_on' => $this->datum((string) ($r['serviceNext'] ?? '')),
                'service_price' => isset($r['servicePrice']) ? (int) $r['servicePrice'] : null,
                'price' => isset($r['price']) ? (int) $r['price'] : null,
                'life_years' => isset($r['life']) ? (int) $r['life'] : null,
                'energy_per_year' => isset($r['energy']) ? (int) $r['energy'] : null,
                'upkeep_per_year' => isset($r['upkeep']) ? (int) $r['upkeep'] : null,
            ]);
        }

        return $zalozene;
    }

    /** „14. 10. 2026" zpátky na datum. Jiný tvar prototyp neposílá. */
    private function datum(string $text): ?CarbonImmutable
    {
        if (! preg_match('/(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/u', $text, $shoda)) {
            return null;
        }

        return CarbonImmutable::createFromDate((int) $shoda[3], (int) $shoda[2], (int) $shoda[1]);
    }

    /** „3/2026" — záruka se píše měsícem a rokem; bere se poslední den měsíce. */
    private function mesic(string $text): ?CarbonImmutable
    {
        if (! preg_match('~^(\d{1,2})/(\d{4})$~', trim($text), $shoda)) {
            return null;
        }

        return CarbonImmutable::createFromDate((int) $shoda[2], (int) $shoda[1], 1)->endOfMonth()->startOfDay();
    }

    /**
     * Řádky klíčované obojím identifikátorem.
     *
     * Server posílá uuid, ale prototyp si v běžícím sezení pamatuje ten svůj
     * (`c1`, `q3`) až do obnovení stránky. Bez druhého klíče by druhá změna
     * v témž sezení založila duplikát nebo tiše spadla pod stůl.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Support\Collection<int, T>  $radky
     * @return \Illuminate\Support\Collection<string, T>
     */
    private function podleId(\Illuminate\Support\Collection $radky): \Illuminate\Support\Collection
    {
        $mapa = collect();

        foreach ($radky as $r) {
            $mapa[$r->uuid] = $r;

            if ($r->client_id) {
                $mapa[$r->client_id] = $r;
            }
        }

        return $mapa;
    }
}
