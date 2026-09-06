<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Obsah\Pravidla;
use App\Services\Obsah\SlovnikPravidel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Pravidla, která přišla jako změna stavu.
 *
 * Prototyp si seznam pravidel drží v komponentě: `rulesAll()` vrací
 * `state.rules || RULEDEF`. Stačilo tedy jedno přepnutí vypínače a stav
 * **navždy zastínil** skutečná pravidla ze serveru — obrazovka od té chvíle
 * ukazovala kopii z prohlížeče, nová pravidla nikde nedoběhla a ta skutečná
 * z ní zmizela.
 *
 * Zápis se proto provede v `automation_rules` a klíč se ze stavu vyhodí.
 * V odpovědi se vrací seznam spočítaný znovu z databáze, aby obrazovka
 * neblikla — ale **neukládá se**: jediná pravda je tabulka.
 */
class PravidlaVeStavu
{
    /** Klíče, které patří databázi. Do stavu se neukládají. */
    public const SERVEROVE = ['rules', 'ruleLog'];

    public function __construct(
        private readonly SlovnikPravidel $slovnik,
        private readonly Pravidla $obsah,
    ) {}

    public function tykaSe(array $patch): bool
    {
        return array_key_exists('rules', $patch) || array_key_exists('ruleLog', $patch);
    }

    /** @return array<string, mixed> */
    public function bezPravidel(array $patch): array
    {
        return array_diff_key($patch, array_flip(self::SERVEROVE));
    }

    /**
     * Provede zápis a vrátí skutečnost — seznam pravidel a historii běhů.
     *
     * @return array<string, mixed>
     */
    public function zpracuj(array $patch, GallerySpace $prostor, ?User $uzivatel): array
    {
        if (! Schema::hasTable('automation_rules')) {
            return [];
        }

        if (array_key_exists('rules', $patch)) {
            $this->uloz((array) $patch['rules'], $prostor, $uzivatel);
        }

        /*
         * Historie se z prohlížeče nepřebírá vůbec.
         *
         * Prototyp do ní při „Spustit teď" zapisoval větu „Do albumu přidáno
         * 6 fotek", aniž by se cokoli přidalo. Zápis o něčem, co se nestalo,
         * je horší než prázdná historie.
         */
        $skutecnost = $this->obsah->kolekce($prostor);

        return array_filter([
            'rules' => $skutecnost['RULEDEF'] ?? [],
            'ruleLog' => $skutecnost['RULOG'] ?? [],
        ], fn ($v) => $v !== []);
    }

    /**
     * @param  list<mixed>  $pravidla  seznam v tom tvaru, ve kterém ho prototyp drží
     */
    private function uloz(array $pravidla, GallerySpace $prostor, ?User $uzivatel): void
    {
        $stavajici = DB::table('automation_rules')
            ->where('gallery_space_id', $prostor->id)
            ->get(['id', 'uuid'])
            ->keyBy('uuid');

        $jmena = $prostor->members()->pluck('users.id', 'users.name')->all();
        $zustavaji = [];

        foreach ($pravidla as $p) {
            $p = (array) $p;
            $nazev = trim((string) ($p['name'] ?? ''));

            if ($nazev === '') {
                continue;
            }

            $spoustec = $this->slovnik->spoustecDovnitr((string) ($p['trig'] ?? 'week'));
            $akce = $this->slovnik->akceDovnitr((string) ($p['act'] ?? 'notify'));

            $radek = [
                'name' => $nazev,
                'trigger' => $spoustec,
                'conditions' => json_encode($this->podminky($spoustec, (string) ($p['targ'] ?? '')), JSON_UNESCAPED_UNICODE),
                'action' => $akce,
                'action_config' => json_encode(['title' => (string) ($p['aarg'] ?? '')], JSON_UNESCAPED_UNICODE),
                'is_enabled' => ($p['on'] ?? true) !== false,
                'updated_at' => now(),
            ];

            // Identifikátor z prototypu („r1", „r1757…") není uuid — pravidlo
            // založené na obrazovce se pozná podle toho, že takové uuid nemáme.
            $uuid = (string) ($p['id'] ?? '');
            $znamy = $stavajici[$uuid] ?? null;

            if ($znamy) {
                DB::table('automation_rules')->where('id', $znamy->id)->update($radek);
                $zustavaji[] = $znamy->id;

                continue;
            }

            $zustavaji[] = DB::table('automation_rules')->insertGetId($radek + [
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $prostor->id,
                // „oba" nemá jednoho autora; zapíše se ten, kdo pravidlo uložil.
                'created_by' => $jmena[(string) ($p['who'] ?? '')] ?? $uzivatel?->id,
                'run_count' => 0,
                'created_at' => now(),
            ]);
        }

        /*
         * Co v seznamu není, dvojice smazala.
         *
         * Prototyp posílá celý seznam, takže smazání se pozná jen tímhle
         * rozdílem. Běhy zůstávají — historie o pravidle, které už není, je
         * pořád historie toho, co se stalo.
         */
        DB::table('automation_rules')
            ->where('gallery_space_id', $prostor->id)
            ->when($zustavaji !== [], fn ($q) => $q->whereNotIn('id', $zustavaji))
            ->delete();
    }

    /**
     * Cíl pravidla jako podmínka motoru.
     *
     * @return list<array<string, mixed>>
     */
    private function podminky(string $spoustec, string $cil): array
    {
        $pole = $this->slovnik->pole($spoustec);

        if ($pole === null || $cil === '') {
            return [];
        }

        return [[
            'field' => $pole,
            'operator' => is_numeric($cil) ? 'greater_than' : 'contains',
            'value' => is_numeric($cil) ? (float) $cil : $cil,
        ]];
    }
}
