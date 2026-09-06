<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Support\SpaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rozbory nad financemi: obálka pro sebe, vlastní inflace, sezónní fondy
 * a cena cesty.
 *
 * Žádná nová tabulka — všechno se počítá z transakcí, limitů a cílů, které
 * dvojice už má. Právě proto se sem nedostal zbytek rozborů z prototypu
 * (odhady proti skutečnosti, zrušená předplatná, cena odkladu): ty nikde
 * nevznikají a spočítat je z ničeho nejde.
 *
 * Co se spočítat nedá, se **neposílá** — obrazovka pak drží ukázku, a to je
 * pořád lepší než vymyšlené číslo, podle kterého se dvojice rozhoduje.
 */
class FinanceRozbory implements PoskytovatelObsahu
{
    /** Kolik měsíců zpátky kreslí obálka a vlastní inflace. */
    private const MESICU = 6;

    private const MESICE = [1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
        'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];

    public function skupina(): string
    {
        return 'rozbory';
    }

    public function uplne(): array
    {
        return [];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('transactions')) {
            return [];
        }

        return array_filter([
            'ENV' => $this->obalka($prostor),
            'INFL' => $this->inflace($prostor),
            'SEASON' => $this->fondy($prostor),
            'TRIPCOST' => $this->cenyCest($prostor),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Obálka jen pro sebe: `{ limit, months: [{ m, a, k }] }`.
     *
     * Jediná položka rozpočtu bez odůvodnění — proto se počítá z kategorie,
     * kterou si dvojice takhle pojmenovala, a ne z odhadu. Bez ní se neposílá
     * nic: vymyslet, kolik si kdo „smí vzít, aniž by se ptal", by bylo to
     * poslední, co by měl dělat server.
     *
     * @return array<string, mixed>|null
     */
    private function obalka(GallerySpace $prostor): ?array
    {
        $kategorie = $this->osobniKategorie($prostor);

        if (! $kategorie) {
            return null;
        }

        $dvojice = $this->dvojice($prostor);
        $od = CarbonImmutable::now()->startOfMonth()->subMonths(self::MESICU - 1);

        $pohyby = Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('category_id', $kategorie->id)
            ->where('occurred_at', '>=', $od)
            ->get(['occurred_at', 'amount_from', 'amount_to', 'payer_partner_id']);

        $mesice = [];

        for ($i = 0; $i < self::MESICU; $i++) {
            $mesic = $od->addMonths($i);

            $vMesici = $pohyby->filter(
                fn (Transaction $t) => CarbonImmutable::parse($t->occurred_at)->isSameMonth($mesic),
            );

            $mesice[] = [
                'm' => self::MESICE[$mesic->month],
                'a' => $this->soucet($vMesici, $dvojice[0]),
                'k' => $this->soucet($vMesici, $dvojice[1]),
            ];
        }

        return [
            'limit' => (int) ($this->limitKategorie($prostor, $kategorie->id) ?: 0),
            'months' => $mesice,
        ];
    }

    /**
     * Vlastní inflace: `{ name, y25, y26, qty, cat }`.
     *
     * Ne ta ze zpráv — tahle se počítá z toho, co dvojice doopravdy kupuje
     * pořád dokola. Bere se **medián**, ne průměr: jeden velký nákup by jinak
     * z rohlíků udělal luxusní zboží.
     *
     * @return list<array<string, mixed>>
     */
    private function inflace(GallerySpace $prostor): array
    {
        $letos = CarbonImmutable::now()->year;

        $pohyby = Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('type', '!=', 'income')
            ->where('occurred_at', '>=', CarbonImmutable::create($letos - 1, 1, 1))
            ->with('category:id,name')
            ->get();

        return $pohyby
            ->filter(fn (Transaction $t) => trim((string) $t->description) !== '')
            ->groupBy(fn (Transaction $t) => mb_strtolower(trim((string) $t->description)))
            ->map(function (Collection $stejne) use ($letos) {
                $rok = fn (int $r) => $stejne
                    ->filter(fn (Transaction $t) => CarbonImmutable::parse($t->occurred_at)->year === $r)
                    ->map(fn (Transaction $t) => abs((float) ($t->amount_from ?? $t->amount_to ?? 0)))
                    ->filter()
                    ->values();

                $loni = $rok($letos - 1);
                $ted = $rok($letos);

                // Bez obou roků není co porovnávat.
                if ($loni->isEmpty() || $ted->isEmpty()) {
                    return null;
                }

                $prvni = $stejne->first();

                return [
                    'name' => trim((string) $prvni->description),
                    'y25' => (int) round($loni->median()),
                    'y26' => (int) round($ted->median()),
                    'qty' => $ted->count(),
                    'cat' => mb_strtolower((string) ($prvni->category?->name ?? 'ostatní')),
                ];
            })
            ->filter()
            // Nejdřív to, co se opakuje nejčastěji — na tom je zdražení vidět.
            ->sortByDesc('qty')
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * Sezónní fondy: `{ id, name, months, target, saved, per, icon, note }`.
     *
     * @return list<array<string, mixed>>
     */
    private function fondy(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('budget_goals')) {
            return [];
        }

        $dnes = CarbonImmutable::now();

        return DB::table('budget_goals as c')
            ->join('budgets as r', 'r.id', '=', 'c.budget_id')
            ->where('r.gallery_space_id', $prostor->id)
            ->orderBy('c.sort_order')
            ->get(['c.uuid', 'c.name', 'c.target_amount', 'c.saved_amount', 'c.target_on', 'c.note'])
            ->map(function (object $c) use ($dnes) {
                $do = $c->target_on ? CarbonImmutable::parse($c->target_on) : null;
                // Nahoru: začatý měsíc se ještě počítá. Zaokrouhlení dolů by
                // z fondu udělalo vyšší měsíční částku, než kolik je potřeba.
                $zbyva = $do ? max(1, (int) ceil($dnes->diffInMonths($do))) : 12;
                $chybi = max(0, (float) $c->target_amount - (float) $c->saved_amount);

                return [
                    'id' => $c->uuid,
                    'name' => $c->name,
                    'months' => $do ? self::MESICE[$do->month].' '.$do->year : 'průběžně',
                    'target' => (int) $c->target_amount,
                    'saved' => (int) $c->saved_amount,
                    // Kolik měsíčně, aby to do termínu vyšlo — ne uložené číslo,
                    // které by po každém vkladu bylo o kus vedle.
                    'per' => (int) round($chybi / $zbyva),
                    'icon' => $this->ikonaFondu($c->name),
                    'note' => (string) ($c->note ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Cena cesty: `{ total, days, people, prev, prevPerDay, items }`.
     *
     * Porovnává se s **předchozí cestou**, ne s průměrem — „o tisíc na den víc
     * než ve Vídni" je věta, se kterou se dá něco dělat.
     *
     * @return array<string, array<string, mixed>>
     */
    private function cenyCest(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('trips') || ! Schema::hasTable('trip_expenses')) {
            return [];
        }

        $cesty = DB::table('trips')
            ->where('gallery_space_id', $prostor->id)
            ->whereDate('end_date', '<=', CarbonImmutable::now())
            ->orderByDesc('start_date')
            ->limit(12)
            ->get();

        if ($cesty->isEmpty()) {
            return [];
        }

        $utraty = DB::table('trip_expenses')
            ->whereIn('trip_id', $cesty->pluck('id'))
            ->where('state', '!=', 'planned')
            ->get()
            ->groupBy('trip_id');

        $lidi = max(2, $prostor->members()->count());
        $vysledek = [];
        $predchozi = null;

        // Odzadu, aby každá cesta znala tu předchozí.
        foreach ($cesty->reverse() as $c) {
            $moje = $utraty[$c->id] ?? collect();

            if ($moje->isEmpty()) {
                continue;
            }

            $od = CarbonImmutable::parse($c->start_date);
            $dnu = max(1, (int) $od->diffInDays(CarbonImmutable::parse($c->end_date)) + 1);
            $celkem = (int) $moje->sum('amount');

            $vysledek[$this->klic($c->name, $vysledek)] = [
                'total' => $celkem,
                'days' => $dnu,
                'people' => $lidi,
                'prev' => $predchozi['name'] ?? '',
                'prevPerDay' => $predchozi['perDay'] ?? 0,
                'items' => $moje
                    ->groupBy('category')
                    ->map(fn (Collection $co, $kategorie) => [
                        'name' => $this->kategorie((string) $kategorie),
                        'amount' => (int) $co->sum('amount'),
                        'note' => $this->pocet($co->count(), 'položka', 'položky', 'položek'),
                    ])
                    ->sortByDesc('amount')
                    ->values()
                    ->all(),
            ];

            $predchozi = ['name' => $c->name, 'perDay' => (int) round($celkem / $dnu)];
        }

        return $vysledek;
    }

    // ——— dílky ———

    /**
     * Kategorie, kterou si dvojice vede jako osobní obálku.
     *
     * Pozná se podle jména — vlastní příznak na to v aplikaci není a vymýšlet
     * ho jen kvůli jedné obrazovce by bylo horší než se zeptat názvu.
     */
    private function osobniKategorie(GallerySpace $prostor): ?object
    {
        if (! Schema::hasTable('finance_categories')) {
            return null;
        }

        return DB::table('finance_categories')
            ->where('gallery_space_id', $prostor->id)
            ->where(function ($q) {
                foreach (['obálka', 'obalka', 'osobní', 'osobni', 'kapesné', 'kapesne'] as $slovo) {
                    $q->orWhereRaw('LOWER(name) LIKE ?', ['%'.$slovo.'%']);
                }
            })
            ->first(['id', 'name']);
    }

    private function limitKategorie(GallerySpace $prostor, int $kategorie): float
    {
        if (! Schema::hasTable('budget_category_limits')) {
            return 0;
        }

        return (float) DB::table('budget_category_limits as l')
            ->join('budgets as r', 'r.id', '=', 'l.budget_id')
            ->where('r.gallery_space_id', $prostor->id)
            ->where('l.finance_category_id', $kategorie)
            ->orderByDesc('r.id')
            ->value('l.amount');
    }

    /**
     * Dvojice jako partneři plateb — zakladatel prostoru první.
     *
     * @return array<int, int|null>
     */
    private function dvojice(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('partners')) {
            return [null, null];
        }

        $lide = $prostor->members()
            ->orderByRaw('users.id = ? desc', [$prostor->owner_id])
            ->pluck('users.id');

        $partneri = DB::table('partners')
            ->where('gallery_space_id', $prostor->id)
            ->whereIn('user_id', $lide)
            ->pluck('id', 'user_id');

        return [$partneri[$lide[0] ?? 0] ?? null, $partneri[$lide[1] ?? 0] ?? null];
    }

    /** @param  Collection<int, Transaction>  $pohyby */
    private function soucet(Collection $pohyby, ?int $partner): int
    {
        if (! $partner) {
            return 0;
        }

        return (int) round($pohyby
            ->filter(fn (Transaction $t) => (int) $t->payer_partner_id === $partner)
            ->sum(fn (Transaction $t) => abs((float) ($t->amount_from ?? $t->amount_to ?? 0))));
    }

    // ——— formát ———

    /** @param  array<string, mixed>  $uz */
    private function klic(string $nazev, array $uz): string
    {
        $bez = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nazev);
        $zaklad = substr(strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $bez ?: $nazev)) ?: 'cesta', 0, 24);
        $klic = $zaklad;
        $poradi = 2;

        while (array_key_exists($klic, $uz)) {
            $klic = $zaklad.$poradi++;
        }

        return $klic;
    }

    private function kategorie(string $kategorie): string
    {
        return match ($kategorie) {
            'transport' => 'Doprava',
            'lodging', 'accommodation' => 'Ubytování',
            'food' => 'Jídlo venku',
            'groceries' => 'Nákupy potravin',
            'tickets', 'activities' => 'Vstupy a výlety',
            'shopping' => 'Nákupy',
            default => 'Ostatní',
        };
    }

    private function ikonaFondu(string $nazev): string
    {
        $n = mb_strtolower($nazev);

        return match (true) {
            str_contains($n, 'dovolen') || str_contains($n, 'cest') => 'ph-umbrella-simple',
            str_contains($n, 'vánoc') || str_contains($n, 'dárk') => 'ph-gift',
            str_contains($n, 'auto') || str_contains($n, 'pneu') => 'ph-car',
            str_contains($n, 'rezerv') => 'ph-shield-check',
            default => 'ph-piggy-bank',
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
}
