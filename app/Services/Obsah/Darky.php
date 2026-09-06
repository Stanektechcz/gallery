<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dárky a přání ve tvaru, ve kterém je kreslí prototyp.
 *
 * Celá sekce stojí na jedné věci: **druhý nesmí vidět, co se pro něj chystá.**
 * Aplikace to řeší sloupcem `private_to_user_id` a poskytovatel ho drží —
 * nákup se posílá jen tomu, kdo ho pořizuje. Kdyby se poslal oběma, byla by to
 * nejhorší možná chyba téhle obrazovky: prozrazený dárek se nedá vzít zpět.
 *
 * Přání jsou naopak veřejná — o to jde, aby je druhý viděl.
 */
class Darky implements PoskytovatelObsahu
{
    public function skupina(): string
    {
        return 'darky';
    }

    public function uplne(): array
    {
        return [];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('gift_ideas')) {
            return [];
        }

        $jmena = $prostor->members()->pluck('users.name', 'users.id')->all();
        $polozky = $this->polozky($prostor);

        $prilezitosti = $this->prilezitosti($prostor);

        return array_filter([
            'GIFT_WISHES' => $this->prani($polozky, $jmena),
            'GIFT_BUYS' => $this->nakupy($polozky, $jmena),
            'GIFT_IDEAS' => $napady = $this->napady($polozky, $jmena),
            'GIFT_OCC' => $prilezitosti,
            // Obrazovka „Milníky a výročí“ kreslí totéž jako seznam.
            'AL' => ($seznam = $this->seznam($prilezitosti, $napady)) ? ['gifts' => $seznam] : [],
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Nápady a příležitosti jako `[název, popis, štítek]`.
     *
     * Seznam u milníků se bral z `galerie-data.js`: stálo v něm „Narozeniny
     * Makinka" a „poznámka od Adriana" bez ohledu na to, kdo dvojici tvoří
     * a co si zapsala.
     *
     * Odpočet „za 42 dní" se počítá teď — uložený by den po výročí tvrdil,
     * že je za týden.
     *
     * @param  list<array<string, mixed>>  $prilezitosti
     * @param  list<array<string, mixed>>  $napady
     * @return list<array<int, ?string>>
     */
    private function seznam(array $prilezitosti, array $napady): array
    {
        $radky = [];

        foreach ($prilezitosti as $p) {
            $dni = (int) $p['days'];

            $radky[] = [
                (string) $p['name'],
                trim(implode(' · ', array_filter([
                    (string) $p['when'],
                    $dni > 0 ? 'za '.$this->pocet($dni, 'den', 'dny', 'dní') : ($dni === 0 ? 'dnes' : null),
                ]))),
                $dni >= 0 && $dni <= 60 ? 'blíží se' : null,
            ];
        }

        foreach ($napady as $n) {
            $radky[] = [
                'Nápad: '.$n['title'],
                trim(implode(' · ', array_filter([
                    $n['forWhom'] ? 'pro '.$n['forWhom'] : null,
                    $n['price'] ? $this->castka((int) $n['price']) : null,
                    $n['source'] ?: null,
                ]))) ?: 'bez poznámky',
                'nápad',
            ];
        }

        return $radky;
    }

    /**
     * Dárky, které přihlášený člověk **smí vidět**.
     *
     * @return Collection<int, object>
     */
    private function polozky(GallerySpace $prostor): Collection
    {
        $ja = auth()->id();
        $maSoukromi = Schema::hasColumn('gift_ideas', 'private_to_user_id');

        return DB::table('gift_ideas')
            ->where('gallery_space_id', $prostor->id)
            ->when($maSoukromi, fn ($q) => $q->where(
                fn ($v) => $v->whereNull('private_to_user_id')->orWhere('private_to_user_id', $ja),
            ))
            ->orderByDesc('created_at')
            ->limit(80)
            ->get();
    }

    /**
     * Přání: `{ id, who, title, price, size, note, added }`.
     *
     * Přání si píše člověk sám za sebe, takže `who` je jeho jméno — a je
     * veřejné. O to jde: druhý má vědět, co si přeju.
     *
     * @param  Collection<int, object>  $polozky
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function prani(Collection $polozky, array $jmena): array
    {
        return $polozky
            ->filter(fn (object $d) => $this->stav($d) === 'prani')
            ->map(fn (object $d) => [
                'id' => $d->uuid,
                'who' => $jmena[$d->created_by] ?? '—',
                'title' => $d->title,
                'price' => (int) ($d->budget ?? 0),
                // Velký dárek je ten, na kterém se dvojice musí domluvit.
                'size' => (float) ($d->budget ?? 0) >= 2000 ? 'big' : 'small',
                'note' => (string) ($d->source_url ?? ''),
                'added' => CarbonImmutable::parse($d->created_at)->format('j. n. Y'),
            ])
            ->values()
            ->all();
    }

    /**
     * Chystané dárky: `{ id, owner, forWhom, what, price, occasion, status, where, expensed }`.
     *
     * @param  Collection<int, object>  $polozky
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function nakupy(Collection $polozky, array $jmena): array
    {
        return $polozky
            ->filter(fn (object $d) => in_array($this->stav($d), ['reserved', 'bought', 'wrapped', 'given'], true))
            ->map(fn (object $d) => [
                'id' => $d->uuid,
                'owner' => $jmena[$d->created_by] ?? '—',
                'forWhom' => $this->proKoho($d, $jmena),
                'what' => $d->title,
                'price' => (int) ($d->budget ?? 0),
                'occasion' => (string) ($d->occasion ?? ''),
                'status' => $this->stav($d),
                // Kde dárek leží, si píše ten, kdo ho schoval.
                'where' => (string) ($d->source_url ?? ''),
                'expensed' => $this->stav($d) === 'given',
            ])
            ->values()
            ->all();
    }

    /**
     * Nápady: `{ id, title, price, forWhom, text, source, icon }`.
     *
     * @param  Collection<int, object>  $polozky
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function napady(Collection $polozky, array $jmena): array
    {
        return $polozky
            ->filter(fn (object $d) => $this->stav($d) === 'idea')
            ->map(fn (object $d) => [
                'id' => $d->uuid,
                'title' => $d->title,
                'price' => (int) ($d->budget ?? 0),
                'forWhom' => $this->proKoho($d, $jmena),
                'text' => (string) ($d->source_url ?? ''),
                'source' => $this->odkud($d),
                'icon' => $this->ikona((string) ($d->created_from ?? '')),
            ])
            ->values()
            ->all();
    }

    /**
     * Příležitosti: `{ name, name2, when, days, budget, note }`.
     *
     * `days` se počítá teď — uložený odpočet by den po Vánocích tvrdil, že jsou
     * za týden.
     *
     * @return list<array<string, mixed>>
     */
    private function prilezitosti(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('gift_budgets')) {
            return [];
        }

        $dnes = CarbonImmutable::now()->startOfDay();

        return DB::table('gift_budgets')
            ->where('gallery_space_id', $prostor->id)
            ->orderBy('budget_year')
            ->get()
            ->map(function (object $r) use ($dnes) {
                $kdy = $this->terminPrilezitosti($r);

                return [
                    'name' => $r->title,
                    // Druhé jméno je to, pod kterým se příležitost píše u dárku.
                    'name2' => (string) ($r->occasion ?: $r->title),
                    'when' => $kdy ? $this->denCesky($kdy) : (string) $r->budget_year,
                    'days' => $kdy ? (int) $dnes->diffInDays($kdy, false) : 0,
                    'budget' => (int) $r->planned_amount,
                    'note' => '',
                ];
            })
            ->values()
            ->all();
    }

    private function terminPrilezitosti(object $r): ?CarbonImmutable
    {
        foreach (['due_date', 'occasion_date'] as $sloupec) {
            if (isset($r->{$sloupec}) && $r->{$sloupec}) {
                return CarbonImmutable::parse($r->{$sloupec});
            }
        }

        return null;
    }

    // ——— formát ———

    private function stav(object $d): string
    {
        $stav = (string) ($d->status ?? 'idea');

        // `wish` a `idea` prototyp odlišuje: přání si píše obdarovaný,
        // nápad si všiml ten druhý.
        return match ($stav) {
            'wish', 'wanted', 'prani' => 'prani',
            'reserved', 'planned' => 'reserved',
            'bought', 'purchased' => 'bought',
            'wrapped' => 'wrapped',
            'given', 'done' => 'given',
            default => 'idea',
        };
    }

    /** @param  array<int, string>  $jmena */
    private function proKoho(object $d, array $jmena): string
    {
        if (! isset($d->person_id) || ! $d->person_id || ! Schema::hasTable('people')) {
            // Bez určené osoby je to pro toho druhého z dvojice — dárek sám
            // sobě se v téhle sekci nevede.
            return collect($jmena)->reject(fn ($j, $id) => (int) $id === (int) $d->created_by)->first() ?? '—';
        }

        return (string) (DB::table('people')->where('id', $d->person_id)->value('name') ?? '—');
    }

    private function odkud(object $d): string
    {
        $zdroj = match ((string) ($d->created_from ?? '')) {
            'chat' => 'Zprávy',
            'diary', 'journal' => 'Deník',
            'voice' => 'Hlasovky',
            'dating' => 'Randíčka',
            'assistant' => 'Asistent',
            default => 'Zapsáno ručně',
        };

        return $zdroj.' · '.CarbonImmutable::parse($d->created_at)->format('j. n. Y');
    }

    private function ikona(string $odkud): string
    {
        return match ($odkud) {
            'chat' => 'ph-chat-circle',
            'diary', 'journal' => 'ph-notebook',
            'voice' => 'ph-microphone',
            'dating' => 'ph-confetti',
            'assistant' => 'ph-sparkle',
            default => 'ph-gift',
        };
    }

    private function pocet(int $kolik, string $jeden, string $dva, string $pet): string
    {
        return $kolik.' '.match (true) {
            $kolik === 1 => $jeden,
            $kolik >= 2 && $kolik <= 4 => $dva,
            default => $pet,
        };
    }

    /** Částka s měnou a mezerou po tisících — tak, jak ji píše zbytek aplikace. */
    private function castka(int $kolik): string
    {
        return number_format($kolik, 0, ',', ' ').' Kč';
    }

    private function denCesky(CarbonImmutable $den): string
    {
        $mesice = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
            'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

        return $den->day.'. '.$mesice[$den->month].' '.$den->year;
    }
}
