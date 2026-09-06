<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cesty a místa ve tvaru, ve kterém je kreslí prototyp.
 *
 * Aplikace má celý cestovní modul — cesty, dny, program, útraty, balení,
 * doklady, deník i místa s návštěvami a poznámkami. Prototyp z toho neukazoval
 * nic: tři napsané cesty do Lisabonu, na Brač a do Beskyd a osm míst, o kterých
 * dvojice nikdy nerozhodla.
 *
 * `TRIPS`, `PLACES` a jejich rejstříky se posílají **celé**: nechat vedle
 * skutečné cesty ukázkovou znamená nabízet dvojici výlet, který si nikdy
 * nenaplánovala.
 */
class Cesty implements PoskytovatelObsahu
{
    private const MESICE = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
        'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

    private const DNY = ['Neděle', 'Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota'];

    public function skupina(): string
    {
        return 'cesty';
    }

    public function uplne(): array
    {
        return ['TRIPS', 'TRIP_BY_TITLE', 'PLACES', 'PLACE_BY_TITLE'];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        $cesty = Schema::hasTable('trips') ? $this->cesty($prostor) : [];
        $mista = Schema::hasTable('places') ? $this->mista($prostor) : [];

        return array_filter([
            'TRIPS' => $cesty,
            'TRIP_BY_TITLE' => $this->rejstrik($cesty),
            'NOWTRIP' => $this->prave($prostor, $cesty),
            'PLACES' => $mista,
            'PLACE_BY_TITLE' => $this->rejstrik($mista),
            // Telefon kreslí cesty z mnohem menšího tvaru a drží si ho stranou.
            'MOBIL' => $cesty ? ['TRIPS' => $this->proTelefon($cesty)] : [],
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Cesty klíčované slugem, jak je prototyp adresuje (`TRIPS.lisabon`).
     *
     * @return array<string, array<string, mixed>>
     */
    private function cesty(GallerySpace $prostor): array
    {
        $cesty = DB::table('trips')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('start_date')
            ->limit(20)
            ->get();

        if ($cesty->isEmpty()) {
            return [];
        }

        $id = $cesty->pluck('id');
        $dny = $this->dny($id);
        // `$dny` je seskupené po cestách, takže identifikátory dnů leží o patro níž.
        $program = $this->program($dny->flatten(1)->pluck('id'));
        $utraty = $this->utraty($id);
        $limity = $this->limity($id);
        $baleni = $this->baleni($id, $prostor);
        $doklady = $this->doklady($id);
        $denik = $this->denik($id);

        $dnes = CarbonImmutable::now()->startOfDay();
        $vysledek = [];

        foreach ($cesty as $c) {
            $od = CarbonImmutable::parse($c->start_date)->startOfDay();
            $do = CarbonImmutable::parse($c->end_date)->startOfDay();
            $delka = (int) $od->diffInDays($do) + 1;
            $mistaCesty = ($dny[$c->id] ?? collect())
                ->flatMap(fn ($d) => ($program[$d->id] ?? collect())->pluck('place_name'))
                ->filter()->unique()->take(3);

            $klic = $this->klic($c->name, $vysledek);

            $vysledek[$klic] = [
                'title' => $c->name,
                'when' => $this->rozsah($od, $do),
                'where' => $mistaCesty->implode(' · '),
                'tag' => match (true) {
                    $dnes->lt($od) => 'plánujeme',
                    $dnes->gt($do) => 'byli jsme',
                    default => 'jsme tam',
                },
                'n' => (int) $c->id,
                'past' => $dnes->gt($do),
                // Prototyp z toho dělá dotaz do knihovny, ne číslo: pruh fotek
                // pod cestou vzniká hledáním.
                'photoMatch' => $mistaCesty->first() ?: $c->name,
                'desc' => (string) ($c->description ?? ''),
                'stats' => $this->cisla($c, $od, $do, $delka, $dnes, $utraty[$c->id] ?? collect(), $limity[$c->id] ?? collect(), $dny[$c->id] ?? collect(), $program),
                'days' => $this->dnyCesty($dny[$c->id] ?? collect(), $program, $od),
                'budget' => $this->rozpocet($limity[$c->id] ?? collect(), $utraty[$c->id] ?? collect()),
                'budgetTitle' => 'Rozpočet cesty',
                'docs' => $doklady[$c->id] ?? [],
                'pack' => $baleni[$c->id] ?? [],
                'diary' => ($denik[$c->id] ?? null) ?: null,
            ];
        }

        return $vysledek;
    }

    /**
     * Čísla nad cestou. Které to jsou, závisí na tom, kdy cesta je: před
     * odjezdem zajímá odpočet, uprostřed dnešní den, po ní útrata.
     *
     * @return list<array<int, string>>
     */
    private function cisla(object $c, CarbonImmutable $od, CarbonImmutable $do, int $delka, CarbonImmutable $dnes, Collection $utraty, Collection $limity, Collection $dny, Collection $program): array
    {
        $polozek = $dny->sum(fn ($d) => ($program[$d->id] ?? collect())->count());
        $plan = (int) $limity->sum('amount');
        $utraceno = (int) $utraty->where('state', '!=', 'planned')->sum('amount');

        return array_values(array_filter([
            ['Délka', $this->pocet($delka, 'den', 'dny', 'dní')],
            match (true) {
                $dnes->lt($od) => ['Odjezd za', $this->pocet((int) $dnes->diffInDays($od), 'den', 'dny', 'dní')],
                $dnes->gt($do) => ['Bylo', 'před '.$this->pocet((int) $do->diffInDays($dnes), 'dnem', 'dny', 'dny')],
                default => ['Dnes', 'den '.((int) $od->diffInDays($dnes) + 1)],
            },
            $plan ? ['Rozpočet', $this->koruny($plan)] : null,
            $utraceno ? ['Utraceno', $this->koruny($utraceno)] : null,
            $polozek ? ['Položek v plánu', (string) $polozek] : null,
        ]));
    }

    /**
     * Program po dnech: `['Den 1', 'Pondělí 12. 4.', podtitul, [[čas, ikona, co, poznámka, stav], …]]`.
     *
     * @return list<array<int, mixed>>
     */
    private function dnyCesty(Collection $dny, Collection $program, CarbonImmutable $od): array
    {
        return $dny->values()->map(function ($d, $i) use ($program, $od) {
            $den = CarbonImmutable::parse($d->date);

            return [
                'Den '.((int) $od->diffInDays($den) + 1),
                self::DNY[$den->dayOfWeek].' '.$den->format('j. n.'),
                (string) ($d->title ?? ''),
                ($program[$d->id] ?? collect())->map(fn ($a) => [
                    $a->starts_at ? CarbonImmutable::parse($a->starts_at)->format('G:i') : '—',
                    $this->ikona((string) $a->type),
                    $a->title,
                    (string) ($a->description ?? $a->place_name ?? ''),
                    $this->stav((string) $a->status),
                ])->values()->all(),
            ];
        })->all();
    }

    /**
     * Rozpočet: `[kategorie, 'X z Y Kč', procenta, příznak]`.
     *
     * Příznak 2 znamená „jen plán" — v téhle kategorii se zatím nic neutratilo
     * a pruh se nemá kreslit jako naplněný.
     *
     * @return list<array<int, mixed>>
     */
    private function rozpocet(Collection $limity, Collection $utraty): array
    {
        $podleKategorie = $utraty->where('state', '!=', 'planned')->groupBy('category');

        return $limity->map(function ($l) use ($podleKategorie) {
            $limit = (int) $l->amount;
            $utraceno = (int) ($podleKategorie[$l->category] ?? collect())->sum('amount');

            return [
                $this->kategorie((string) $l->category),
                $utraceno
                    ? $this->cislo($utraceno).' z '.$this->koruny($limit)
                    : 'plán '.$this->koruny($limit),
                $limit ? min(100, (int) round($utraceno / $limit * 100)) : 0,
                $utraceno ? 0 : 2,
            ];
        })->values()->all();
    }

    /**
     * Cesta, která právě běží.
     *
     * Bez počasí a západu slunce — ty aplikace odnikud nebere a vymyslet je
     * by znamenalo tvrdit dvojici na cestě něco o obloze nad nimi.
     *
     * @param  array<string, array<string, mixed>>  $cesty
     * @return array<string, mixed>|null
     */
    private function prave(GallerySpace $prostor, array $cesty): ?array
    {
        $dnes = CarbonImmutable::now()->startOfDay();

        $ted = DB::table('trips')
            ->where('gallery_space_id', $prostor->id)
            ->whereDate('start_date', '<=', $dnes)
            ->whereDate('end_date', '>=', $dnes)
            ->orderBy('start_date')
            ->first();

        if (! $ted) {
            return null;
        }

        $od = CarbonImmutable::parse($ted->start_date)->startOfDay();
        $do = CarbonImmutable::parse($ted->end_date)->startOfDay();
        $klic = collect($cesty)->search(fn (array $c) => $c['title'] === $ted->name) ?: 'cesta';
        $zaznam = $cesty[$klic] ?? [];

        $den = DB::table('trip_days')->where('trip_id', $ted->id)->whereDate('date', $dnes)->first();
        $program = $den
            ? DB::table('trip_activities')->where('trip_day_id', $den->id)->orderBy('starts_at')->orderBy('sort_order')->get()
            : collect();

        $utraty = DB::table('trip_expenses')->where('trip_id', $ted->id)->where('state', '!=', 'planned')->get();

        return [
            'id' => $klic,
            'title' => $ted->name,
            'where' => $zaznam['where'] ?? '',
            'day' => (int) $od->diffInDays($dnes) + 1,
            'days' => (int) $od->diffInDays($do) + 1,
            'when' => self::DNY[$dnes->dayOfWeek].' '.$dnes->day.'. '.self::MESICE[$dnes->month].' '.$dnes->year,
            'fund' => (int) DB::table('trip_budget_limits')->where('trip_id', $ted->id)->sum('amount'),
            'spent' => (int) $utraty->sum('amount'),
            'todaySpent' => (int) $utraty
                ->filter(fn ($u) => $u->occurred_at && CarbonImmutable::parse($u->occurred_at)->isSameDay($dnes))
                ->sum('amount'),
            'plan' => $program->map(fn ($a) => [
                $a->starts_at ? CarbonImmutable::parse($a->starts_at)->format('G:i') : '—',
                $this->ikona((string) $a->type),
                $a->title,
                (string) ($a->description ?? $a->place_name ?? ''),
                $this->stav((string) $a->status),
            ])->values()->all(),
            'spendRows' => $utraty
                ->filter(fn ($u) => $u->occurred_at && CarbonImmutable::parse($u->occurred_at)->isSameDay($dnes))
                ->map(fn ($u) => [
                    $u->title,
                    (int) $u->amount,
                    $this->kategorie((string) $u->category),
                    CarbonImmutable::parse($u->occurred_at)->format('G:i'),
                ])->values()->all(),
            'diary' => $this->denikCesty($ted->id, $od),
            'tripEntry' => [
                'title' => $ted->name,
                'when' => $this->rozsah($od, $do),
                'where' => $zaznam['where'] ?? '',
                'tag' => 'jsme tam',
            ],
        ];
    }

    /**
     * Místa klíčovaná slugem, jak je prototyp adresuje (`PLACES.alma`).
     *
     * @return array<string, array<string, mixed>>
     */
    private function mista(GallerySpace $prostor): array
    {
        if (! Schema::hasColumn('places', 'gallery_space_id')) {
            return [];
        }

        $mista = DB::table('places')
            ->where('gallery_space_id', $prostor->id)
            ->orderBy('name')
            ->limit(60)
            ->get();

        if ($mista->isEmpty()) {
            return [];
        }

        $id = $mista->pluck('id');
        $navstevy = $this->navstevy($id, $prostor);
        $poznamky = $this->poznamky($id, $prostor);
        $vysledek = [];

        foreach ($mista as $m) {
            $klic = $this->klic($m->name, $vysledek);
            $kdy = $navstevy[$m->id] ?? collect();

            $vysledek[$klic] = [
                'title' => $m->name,
                'kind' => (string) ($m->district ?: ($m->region ?: 'Místo')),
                'city' => trim(implode(', ', array_filter([$m->city, $m->district]))) ?: (string) ($m->country ?? ''),
                'tag' => $this->znacka((string) ($m->lifecycle_status ?? 'idea'), $kdy->isNotEmpty()),
                // Dotaz do knihovny, ne počet: pruh fotek vzniká hledáním.
                'photoMatch' => $m->name,
                'desc' => (string) ($m->description ?? ''),
                'facts' => array_values(array_filter([
                    $m->address ? ['Adresa', $m->address] : null,
                    $m->price_level ? ['Cenová hladina', ['nízká', 'střední', 'vyšší', 'vysoká'][$m->price_level - 1] ?? 'střední'] : null,
                    $m->estimated_visit_minutes ? ['Zdrží', $m->estimated_visit_minutes.' minut'] : null,
                    $m->personal_rating ? ['Naše hodnocení', $m->personal_rating.' z 5'] : null,
                    ['Přidáno', CarbonImmutable::parse($m->created_at)->format('j. n. Y')],
                ])),
                'practical' => array_values(array_filter([
                    $m->address ? ['ph-map-pin', 'Adresa', $m->address] : null,
                    $m->latitude ? ['ph-compass', 'Poloha', round((float) $m->latitude, 4).', '.round((float) $m->longitude, 4)] : null,
                    $m->opens_early ? ['ph-sun-horizon', 'Otevírá brzy', 'ano'] : null,
                    $m->is_rain_friendly ? ['ph-cloud-rain', 'Když prší', 'dá se'] : null,
                    $m->next_time_note ? ['ph-note-pencil', 'Příště', $m->next_time_note] : null,
                ])),
                'visits' => $kdy->values()->all(),
                'notes' => $poznamky[$m->id] ?? [],
                // Odkazy jinam skládá prototyp z vlastních obrazovek; server
                // o nich nic neví a vymýšlet je by znamenalo slibovat cestu,
                // která nikam nevede.
                'related' => [],
            ];
        }

        return $vysledek;
    }

    /**
     * Návštěvy místa: `[datum, název, text, útrata, značka]`.
     *
     * @return array<int, Collection<int, array<int, mixed>>>
     */
    private function navstevy(Collection $id, GallerySpace $prostor): array
    {
        if (! Schema::hasTable('place_plans')) {
            return [];
        }

        return DB::table('place_plans')
            ->where('gallery_space_id', $prostor->id)
            ->whereIn('place_id', $id)
            ->whereNotNull('visited_on')
            ->orderByDesc('visited_on')
            ->get()
            ->groupBy('place_id')
            ->map(fn (Collection $r) => $r->map(fn ($p) => [
                CarbonImmutable::parse($p->visited_on)->format('j. n. Y'),
                'Byli jsme tam',
                (string) ($p->notes ?? ''),
                null,
                'byli jsme',
            ]))
            ->all();
    }

    /**
     * Poznámky k místu: `[datum, nadpis, text, odkud]`.
     *
     * @return array<int, list<array<int, string>>>
     */
    private function poznamky(Collection $id, GallerySpace $prostor): array
    {
        if (! Schema::hasTable('place_notes')) {
            return [];
        }

        return DB::table('place_notes')
            ->where('gallery_space_id', $prostor->id)
            ->whereIn('place_id', $id)
            // Osobní poznámka patří jednomu člověku; sdílené místo ukazuje
            // jen to, co si dvojice napsala společně.
            ->where('visibility', 'shared')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('place_id')
            ->map(fn (Collection $r) => $r->map(fn ($p) => [
                CarbonImmutable::parse($p->created_at)->format('j. n. Y'),
                'Poznámka',
                (string) $p->content,
                'z místa',
            ])->values()->all())
            ->all();
    }

    // ——— dílky ———

    /** @return Collection<int, Collection<int, object>> */
    private function dny(Collection $id): Collection
    {
        return DB::table('trip_days')
            ->whereIn('trip_id', $id)
            ->orderBy('date')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('trip_id');
    }

    /** @return Collection<int, Collection<int, object>> */
    private function program(Collection $dny): Collection
    {
        return DB::table('trip_activities')
            ->whereIn('trip_day_id', $dny)
            ->orderBy('starts_at')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('trip_day_id');
    }

    /** @return Collection<int, Collection<int, object>> */
    private function utraty(Collection $id): Collection
    {
        return DB::table('trip_expenses')->whereIn('trip_id', $id)->get()->groupBy('trip_id');
    }

    /** @return Collection<int, Collection<int, object>> */
    private function limity(Collection $id): Collection
    {
        return DB::table('trip_budget_limits')->whereIn('trip_id', $id)->get()->groupBy('trip_id');
    }

    /**
     * Balení: `[co, kdo, sbaleno]`.
     *
     * @return array<int, list<array<int, mixed>>>
     */
    private function baleni(Collection $id, GallerySpace $prostor): array
    {
        if (! Schema::hasTable('trip_packing_items')) {
            return [];
        }

        $jmena = $prostor->members()->pluck('users.name', 'users.id')->all();

        return DB::table('trip_packing_items')
            ->whereIn('trip_id', $id)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('trip_id')
            ->map(fn (Collection $r) => $r->map(fn ($p) => [
                $p->title,
                $jmena[$p->assigned_to] ?? 'oba',
                $p->is_packed ? 1 : 0,
            ])->values()->all())
            ->all();
    }

    /**
     * Doklady: `[co, podrobnost, stav, ikona]`.
     *
     * @return array<int, list<array<int, mixed>>>
     */
    private function doklady(Collection $id): array
    {
        if (! Schema::hasTable('trip_document_checks')) {
            return [];
        }

        return DB::table('trip_document_checks')
            ->whereIn('trip_id', $id)
            ->orderBy('id')
            ->get()
            ->groupBy('trip_id')
            ->map(fn (Collection $r) => $r->map(fn ($d) => [
                $d->title,
                trim(implode(' · ', array_filter([
                    $d->reference,
                    $d->expires_on ? 'platí do '.CarbonImmutable::parse($d->expires_on)->format('j. n. Y') : null,
                ]))),
                match ($d->status) {
                    'ready' => 'zaplaceno',
                    'missing' => 'zařadit',
                    'expiring' => 'čeká akci',
                    default => null,
                },
                $this->ikona((string) $d->type),
            ])->values()->all())
            ->all();
    }

    /**
     * Deník cesty: `[datum, nadpis, text, místo a počet fotek]`.
     *
     * @return array<int, list<array<int, string>>>
     */
    private function denik(Collection $id): array
    {
        if (! Schema::hasTable('travel_journal_entries')) {
            return [];
        }

        return DB::table('travel_journal_entries')
            ->whereIn('trip_id', $id)
            ->where('type', 'note')
            ->orderByDesc('recorded_at')
            ->get()
            ->groupBy('trip_id')
            ->map(fn (Collection $r) => $r->map(fn ($z) => [
                CarbonImmutable::parse($z->recorded_at)->format('j. n. Y'),
                'Zápis',
                (string) ($z->content ?? ''),
                '',
            ])->values()->all())
            ->all();
    }

    /**
     * Deník běžící cesty: `[popisek dne, nadpis, text, počet fotek]`.
     *
     * @return list<array<int, mixed>>
     */
    private function denikCesty(int $cesta, CarbonImmutable $od): array
    {
        if (! Schema::hasTable('travel_journal_entries')) {
            return [];
        }

        $dnes = CarbonImmutable::now()->startOfDay();

        return DB::table('travel_journal_entries')
            ->where('trip_id', $cesta)
            ->where('type', 'note')
            ->orderByDesc('recorded_at')
            ->limit(10)
            ->get()
            ->map(function ($z) use ($od, $dnes) {
                $kdy = CarbonImmutable::parse($z->recorded_at)->startOfDay();
                $den = (int) $od->diffInDays($kdy) + 1;
                $rozdil = (int) $kdy->diffInDays($dnes);

                return [
                    'Den '.$den.match (true) {
                        $rozdil === 0 => ' · dnes',
                        $rozdil === 1 => ' · včera',
                        default => '',
                    },
                    'Zápis',
                    (string) ($z->content ?? ''),
                    0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Tytéž cesty ve tvaru pro telefon.
     *
     * Úzké rozvržení má vlastní, mnohem menší podobu: den je `[popisek, kdy,
     * [[co, kdy, hotovo]]]` a místo se drží zvlášť, protože se podle něj hledá
     * cesta k fotce (`tripOfPlace`).
     *
     * @param  array<string, array<string, mixed>>  $cesty
     * @return array<string, array<string, mixed>>
     */
    private function proTelefon(array $cesty): array
    {
        return collect($cesty)->map(fn (array $c) => [
            'title' => $c['title'],
            'when' => $c['when'],
            'tag' => $c['tag'],
            'place' => explode(' · ', (string) $c['where'])[0] ?? '',
            'seed' => $c['n'],
            'desc' => $c['desc'],
            'stats' => $c['stats'],
            'days' => array_map(fn (array $d) => [
                $d[0],
                $d[1],
                array_map(fn (array $a) => [$a[2], $a[0], $a[4] === 'hotovo' ? 1 : 0], $d[3]),
            ], $c['days']),
        ])->all();
    }

    // ——— formát ———

    /**
     * Rejstřík název → klíč. Prototyp si ho staví při načtení z ukázkových dat,
     * takže by po výměně ukazoval na klíče, které už neexistují.
     *
     * @param  array<string, array<string, mixed>>  $co
     * @return array<string, string>
     */
    private function rejstrik(array $co): array
    {
        $rejstrik = [];

        foreach ($co as $klic => $zaznam) {
            $rejstrik[$zaznam['title']] = $klic;
        }

        return $rejstrik;
    }

    /**
     * Slug pro adresování v prototypu. Kolize dostane pořadové číslo — dvě
     * cesty na Brač jsou v archivu dvojice běžná věc.
     *
     * @param  array<string, mixed>  $uz
     */
    private function klic(string $nazev, array $uz): string
    {
        $bez = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nazev);
        $zaklad = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $bez ?: $nazev)) ?: 'zaznam';
        $zaklad = substr($zaklad, 0, 24);
        $klic = $zaklad;
        $poradi = 2;

        while (array_key_exists($klic, $uz)) {
            $klic = $zaklad.$poradi++;
        }

        return $klic;
    }

    /** „12. – 18. dubna 2027", a přes měsíce „30. dubna – 3. května 2027". */
    private function rozsah(CarbonImmutable $od, CarbonImmutable $do): string
    {
        if ($od->isSameDay($do)) {
            return $od->day.'. '.self::MESICE[$od->month].' '.$od->year;
        }

        if ($od->month === $do->month && $od->year === $do->year) {
            return $od->day.'. – '.$do->day.'. '.self::MESICE[$do->month].' '.$do->year;
        }

        return $od->day.'. '.self::MESICE[$od->month]
            .($od->year === $do->year ? '' : ' '.$od->year)
            .' – '.$do->day.'. '.self::MESICE[$do->month].' '.$do->year;
    }

    private function ikona(string $druh): string
    {
        return match ($druh) {
            'flight' => 'ph-airplane-tilt',
            'train' => 'ph-train-simple',
            'bus' => 'ph-bus',
            'drive', 'transport' => 'ph-car',
            'boat' => 'ph-boat',
            'lodging', 'accommodation' => 'ph-bed',
            'food', 'restaurant' => 'ph-fork-knife',
            'sight', 'activity' => 'ph-mountains',
            'insurance' => 'ph-shield-check',
            'ticket' => 'ph-ticket',
            default => 'ph-map-pin',
        };
    }

    private function stav(string $stav): ?string
    {
        return match ($stav) {
            'done' => 'hotovo',
            'booked', 'paid' => 'zaplaceno',
            'pending' => 'čeká akci',
            'cancelled' => 'zrušeno',
            default => null,
        };
    }

    private function kategorie(string $kategorie): string
    {
        return match ($kategorie) {
            'transport' => 'Doprava',
            'lodging', 'accommodation' => 'Nocleh',
            'food' => 'Jídlo',
            'tickets', 'activities' => 'Vstupy a doprava',
            'shopping' => 'Nákupy',
            'reserve' => 'Rezerva',
            'flights' => 'Letenky',
            default => ucfirst($kategorie),
        };
    }

    private function znacka(string $stav, bool $bylyNavstevy): string
    {
        return match (true) {
            $stav === 'visited' || $bylyNavstevy => 'byli jsme',
            $stav === 'planned' => 'plánujeme',
            $stav === 'favorite' => 'oblíbené',
            $stav === 'avoid' => 'raději ne',
            default => 'chceme',
        };
    }

    private function cislo(int $kolik): string
    {
        return number_format($kolik, 0, ',', ' ');
    }

    private function koruny(int $kolik): string
    {
        return $this->cislo($kolik).' Kč';
    }

    private function pocet(int $kolik, string $jeden, string $dva, string $pet): string
    {
        return $this->cislo($kolik).' '.match (true) {
            $kolik === 1 => $jeden,
            $kolik >= 2 && $kolik <= 4 => $dva,
            default => $pet,
        };
    }
}
