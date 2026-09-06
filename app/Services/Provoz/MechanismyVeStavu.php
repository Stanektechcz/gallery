<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Obsah\Mechanismy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Mechanismy pro dva, které přišly jako změna stavu.
 *
 * Vyrovnaná laskavost, odpuštěná věc, zrušené předplatné, kontakt s rodinou —
 * všechno to končilo v jednom JSON dokumentu. Nebylo to ztracené, ale nešlo se
 * na to zeptat: kolik laskavostí je nevyrovnaných, se z blobu nedozví ani
 * upozornění, ani týdenní přehled.
 */
class MechanismyVeStavu
{
    /** Klíče, které patří databázi. Do stavu se neukládají. */
    public const SERVEROVE = ['favList', 'forgList', 'antiList', 'mlLoad', 'fam', 'truths', 'pauseLog', 'pausePlan'];

    public function __construct(private readonly Mechanismy $obsah) {}

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
    public function bezMechanismu(array $patch): array
    {
        return array_diff_key($patch, array_flip(self::SERVEROVE));
    }

    /** @return array<string, mixed> */
    public function zpracuj(array $patch, GallerySpace $prostor, ?User $uzivatel): array
    {
        $lide = array_flip($prostor->members()->pluck('users.name', 'users.id')->all());

        if (array_key_exists('favList', $patch)) {
            $this->laskavosti((array) $patch['favList'], $prostor, $lide);
        }

        if (array_key_exists('forgList', $patch)) {
            $this->odpustene((array) $patch['forgList'], $prostor, $lide);
        }

        if (array_key_exists('antiList', $patch)) {
            $this->antiRozpocet((array) $patch['antiList'], $prostor);
        }

        if (array_key_exists('fam', $patch)) {
            $this->rodina((array) $patch['fam'], $prostor, $lide);
        }

        if (array_key_exists('truths', $patch)) {
            $this->pravdy((array) $patch['truths'], $prostor);
        }

        $obsah = $this->obsah->kolekce($prostor);

        return array_filter([
            'favList' => $obsah['FAV'] ?? [],
            'forgList' => $obsah['FORGIVEN'] ?? [],
            'antiList' => $obsah['ANTI'] ?? [],
            'fam' => $obsah['FAMILY'] ?? [],
            'truths' => $obsah['TRUTHS'] ?? [],
        ], fn ($v) => $v !== []);
    }

    /**
     * Účet laskavostí. Vyrovnat laskavost znamená přepnout příznak, ne smazat
     * řádek — účet je o tom, co se stalo, ne o tom, co ještě visí.
     *
     * @param  list<mixed>  $seznam
     * @param  array<string, int>  $lide
     */
    private function laskavosti(array $seznam, GallerySpace $prostor, array $lide): void
    {
        if (! Schema::hasTable('couple_favours')) {
            return;
        }

        $this->srovnej('couple_favours', $seznam, $prostor, function (array $l) use ($lide) {
            $co = trim((string) ($l['what'] ?? ''));

            return $co === '' ? null : [
                'from_user_id' => $lide[(string) ($l['from'] ?? '')] ?? null,
                'what' => $co,
                'happened_on' => $this->den($l['date'] ?? null),
                'weight' => max(1, min(5, (int) ($l['w'] ?? 1))),
                'is_settled' => (bool) ($l['settled'] ?? false),
            ];
        });
    }

    /**
     * @param  list<mixed>  $seznam
     * @param  array<string, int>  $lide
     */
    private function odpustene(array $seznam, GallerySpace $prostor, array $lide): void
    {
        if (! Schema::hasTable('couple_forgiven')) {
            return;
        }

        $this->srovnej('couple_forgiven', $seznam, $prostor, function (array $o) use ($lide) {
            $co = trim((string) ($o['what'] ?? ''));

            return $co === '' ? null : [
                'forgiven_by' => $lide[(string) ($o['by'] ?? '')] ?? null,
                'what' => $co,
                'happened_on' => $this->den($o['date'] ?? null),
                'tries' => max(0, (int) ($o['tries'] ?? 0)),
            ];
        });
    }

    /**
     * Anti-rozpočet. Měsíc je slovo, ne datum — ukládá se první den toho
     * měsíce v roce, ve kterém se to rozhodlo.
     *
     * @param  list<mixed>  $seznam
     */
    private function antiRozpocet(array $seznam, GallerySpace $prostor): void
    {
        if (! Schema::hasTable('couple_anti_budget')) {
            return;
        }

        $mesice = array_flip([1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
            'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec']);

        $this->srovnej('couple_anti_budget', $seznam, $prostor, function (array $a) use ($mesice) {
            $nazev = trim((string) ($a['name'] ?? ''));

            if ($nazev === '') {
                return null;
            }

            $mesic = $mesice[(string) ($a['month'] ?? '')] ?? CarbonImmutable::now()->month;

            return [
                'name' => $nazev,
                'kind' => $this->druhAnti((string) ($a['type'] ?? '')),
                'saved' => max(0, (int) ($a['saved'] ?? 0)),
                'decided_on' => CarbonImmutable::now()->setDate(CarbonImmutable::now()->year, $mesic, 1)->toDateString(),
                'came_back' => (bool) ($a['back'] ?? false),
            ];
        });
    }

    /**
     * Rotace kontaktu s rodinou.
     *
     * `last` je věta („před 4 dny"), ne datum — z ní se datum nedopočítává:
     * kdo se ozval, ví server z toho, co mu přišlo předtím, a přepsat to
     * přibližným výpočtem by rotaci posunulo při každém načtení.
     *
     * @param  list<mixed>  $seznam
     * @param  array<string, int>  $lide
     */
    private function rodina(array $seznam, GallerySpace $prostor, array $lide): void
    {
        if (! Schema::hasTable('couple_family_contacts')) {
            return;
        }

        $znamé = DB::table('couple_family_contacts')
            ->where('gallery_space_id', $prostor->id)
            ->get()
            ->keyBy('uuid');

        $zustavaji = [];

        foreach (array_values($seznam) as $poradi => $r) {
            $r = (array) $r;
            $jmeno = trim((string) ($r['name'] ?? ''));

            if ($jmeno === '') {
                continue;
            }

            $uuid = (string) ($r['id'] ?? '');
            $puvodni = $znamé[$uuid] ?? null;
            $kdo = $lide[(string) ($r['lastWho'] ?? '')] ?? null;

            $radek = [
                'name' => $jmeno,
                'side_user_id' => $lide[(string) ($r['side'] ?? '')] ?? null,
                'every_days' => max(1, (int) ($r['every'] ?? 7)),
                'note' => (string) ($r['note'] ?? ''),
                'sort_order' => $poradi,
                'updated_at' => now(),
            ];

            /*
             * „Ozvali jsme se" je jediná změna, která posouvá datum.
             *
             * Pozná se podle toho, že se změnil ten, kdo byl poslední —
             * jinak by každé načtení obrazovky vypadalo jako nový kontakt.
             */
            if ($puvodni !== null && $kdo !== null && (int) $puvodni->last_contact_by !== $kdo) {
                $radek['last_contact_on'] = CarbonImmutable::now()->toDateString();
                $radek['last_contact_by'] = $kdo;
            }

            if ($puvodni !== null) {
                DB::table('couple_family_contacts')->where('id', $puvodni->id)->update($radek);
                $zustavaji[] = $puvodni->id;

                continue;
            }

            $zustavaji[] = DB::table('couple_family_contacts')->insertGetId($radek + [
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $prostor->id,
                'last_contact_on' => $kdo ? CarbonImmutable::now()->toDateString() : null,
                'last_contact_by' => $kdo,
                'created_at' => now(),
            ]);
        }

        DB::table('couple_family_contacts')
            ->where('gallery_space_id', $prostor->id)
            ->when($zustavaji !== [], fn ($q) => $q->whereNotIn('id', $zustavaji))
            ->delete();
    }

    /**
     * Dvě pravdy o jedné události — obě verze vedle sebe, žádná nevyhrává.
     *
     * @param  list<mixed>  $seznam
     */
    private function pravdy(array $seznam, GallerySpace $prostor): void
    {
        if (! Schema::hasTable('couple_truths')) {
            return;
        }

        [$prvni, $druhy] = array_pad(array_keys($prostor->members()->pluck('users.name', 'users.id')->all()), 2, null);

        $this->srovnej('couple_truths', $seznam, $prostor, function (array $p) use ($prvni, $druhy) {
            $nadpis = trim((string) ($p['title'] ?? ''));

            return $nadpis === '' ? null : [
                'title' => $nadpis,
                'context' => (string) ($p['when'] ?? ''),
                'first_user_id' => $prvni,
                'first_version' => (string) ($p['a'] ?? ''),
                'second_user_id' => $druhy,
                'second_version' => (string) ($p['m'] ?? ''),
            ];
        });
    }

    /**
     * Srovná seznam z obrazovky s tabulkou: co je, přepíše; co není, smaže.
     *
     * @param  list<mixed>  $seznam
     * @param  callable(array<string, mixed>): (array<string, mixed>|null)  $prevod
     */
    private function srovnej(string $tabulka, array $seznam, GallerySpace $prostor, callable $prevod): void
    {
        $znamé = DB::table($tabulka)
            ->where('gallery_space_id', $prostor->id)
            ->pluck('id', 'uuid');

        $zustavaji = [];

        foreach ($seznam as $polozka) {
            $polozka = (array) $polozka;
            $radek = $prevod($polozka);

            if ($radek === null) {
                continue;
            }

            $uuid = (string) ($polozka['id'] ?? '');

            if (isset($znamé[$uuid])) {
                DB::table($tabulka)->where('id', $znamé[$uuid])->update($radek + ['updated_at' => now()]);
                $zustavaji[] = $znamé[$uuid];

                continue;
            }

            $zustavaji[] = DB::table($tabulka)->insertGetId($radek + [
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $prostor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table($tabulka)
            ->where('gallery_space_id', $prostor->id)
            ->when($zustavaji !== [], fn ($q) => $q->whereNotIn('id', $zustavaji))
            ->delete();
    }

    private function den(mixed $hodnota): string
    {
        $datum = trim((string) $hodnota);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)
            ? $datum
            : CarbonImmutable::now()->toDateString();
    }

    private function druhAnti(string $druh): string
    {
        return match ($druh) {
            'předplatné' => 'predplatne',
            'věc' => 'vec',
            'služba' => 'sluzba',
            default => 'jine',
        };
    }
}
