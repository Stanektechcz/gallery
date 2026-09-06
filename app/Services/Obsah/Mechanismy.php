<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mechanismy pro dva, které si dvojice sama zapisuje.
 *
 * Účet laskavostí, co je odpuštěné, anti-rozpočet, mentální zátěž, rotace
 * kontaktu s rodinou, dvě pravdy o jedné události a pravidla pauzy.
 *
 * Osm kolekcí, které se dosud ukládaly do jednoho dokumentu stavu. Nebylo to
 * ztracené, ale bylo to nedosažitelné: nešlo se zeptat, kolik laskavostí je
 * nevyrovnaných, ani spojit mentální zátěž s dělbou práce.
 *
 * „Ticho v datech" se naopak nezapisuje — počítá se z toho, kdy se naposledy
 * sáhlo do které sekce. Uložené by to bylo druhou, zastarávající pravdou.
 */
class Mechanismy implements PoskytovatelObsahu
{
    /** Sekce, u kterých se sleduje, kdy do nich naposledy někdo sáhl. */
    private const SEKCE = [
        ['Finance', 'x-finance', 'transactions', 'created_at'],
        ['Deník', 'x-denik', 'journal_entries', 'created_at'],
        ['Domácnost', 'x-domacnost', 'house_chore_log', 'done_at'],
        ['Kuchařka', 'x-kucharka', 'recipes', 'created_at'],
        ['Cesty', 'x-cesty', 'trips', 'created_at'],
        ['Plánování', 'x-plan', 'shared_todos', 'created_at'],
    ];

    public function skupina(): string
    {
        return 'mechanismy';
    }

    /**
     * Všechno celé.
     *
     * Ukázková laskavost mezi skutečnými by znamenala, že účet nesedí — a je
     * to účet o tom, kdo komu co dluží.
     */
    public function uplne(): array
    {
        return ['FAV', 'FORGIVEN', 'ANTI', 'ML_LOAD', 'FAMILY', 'TRUTHS', 'PAUSE_LOG', 'PAUSE_PLAN', 'CAS_ROWS'];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        $jmena = $this->jmena($prostor);

        return array_filter([
            'FAV' => $this->laskavosti($prostor, $jmena),
            'FORGIVEN' => $this->odpustene($prostor, $jmena),
            'ANTI' => $this->antiRozpocet($prostor),
            'ML_LOAD' => $this->mentalniZatez($prostor, $jmena),
            'FAMILY' => $this->rodina($prostor, $jmena),
            'TRUTHS' => $this->pravdy($prostor, $jmena),
            'PAUSE_LOG' => $this->pauzy($prostor, $jmena),
            'PAUSE_PLAN' => $this->pravidlaPauzy($prostor),
            'TICHO' => $this->ticho($prostor),
            'CAS_ROWS' => $this->casVersusSluzba($prostor, $jmena),
            'VIS_ROWS' => $this->neviditelnaPrace($prostor, $jmena),
            'SPEAK' => $this->kdoMluvi($prostor, $jmena),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Neviditelná práce: `[{ side, label, y, hours, init }]`.
     *
     * Kontakt s rodinou stojí čas, který nikde nefiguruje — nikdo si ho
     * nepamatuje a nikdo za něj neděkuje. Tabulka kontaktů uměla říct jen
     * „naposledy" a „jak často"; kolik hodin to komu sebralo, se z ní vyčíst
     * nedalo. Proto protokol `couple_outreach_log`.
     *
     * `y` je **skutečný počet** za poslední rok, ne plánovaná rotace: jestli
     * se to opravdu děje, je celý smysl té obrazovky.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function neviditelnaPrace(GallerySpace $prostor, array $jmena): array
    {
        if (! Schema::hasTable('couple_outreach_log') || ! Schema::hasTable('couple_family_contacts')) {
            return [];
        }

        $od = CarbonImmutable::now()->subYear()->toDateString();

        $zapisy = DB::table('couple_outreach_log as z')
            ->join('couple_family_contacts as k', 'k.id', '=', 'z.couple_family_contact_id')
            ->where('z.gallery_space_id', $prostor->id)
            ->where('z.happened_on', '>=', $od)
            ->orderBy('k.sort_order')
            ->limit(2000)
            ->get(['k.id', 'k.name', 'k.side_user_id', 'z.minutes', 'z.by_user_id']);

        if ($zapisy->isEmpty()) {
            return [];
        }

        return $zapisy
            ->groupBy('id')
            ->map(function ($radky) use ($jmena) {
                $prvni = $radky->first();

                // Kdo to domlouvá: ten, kdo to dělal nejčastěji, ne naposledy.
                $kdo = $radky->groupBy('by_user_id')->sortByDesc(fn ($s) => $s->count())->keys()->first();

                return [
                    'side' => $jmena[$prvni->side_user_id] ?? 'oba',
                    'label' => $prvni->name,
                    'y' => $radky->count(),
                    // Nezměřený kontakt přispívá nulou; nula znamená
                    // „nikdo to neměřil", ne „nezabralo to čas".
                    'hours' => (int) round($radky->sum('minutes') / 60),
                    'init' => $jmena[$kdo] ?? 'oba',
                ];
            })
            ->sortByDesc('y')
            ->values()
            ->all();
    }

    /**
     * Kdo mluví za koho: `[{ area, a, k, ask, note }]`.
     *
     * Týž protokol, jen seskupený po oblastech. `a` a `k` jsou počty za oba
     * z dvojice v tom pořadí, ve kterém je kreslí prototyp.
     *
     * `ask` znamená „rozhoduje se tu za oba, aniž by se někdo ptal". Je to
     * jediná věc, kterou nejde odvodit — proto je to sloupec, který se
     * u zápisu vyplňuje. Bez něj by obrazovka tvrdila buď že se ptá vždycky,
     * nebo nikdy, a obojí by byla lež.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function kdoMluvi(GallerySpace $prostor, array $jmena): array
    {
        if (! Schema::hasTable('couple_outreach_log')) {
            return [];
        }

        $od = CarbonImmutable::now()->subDays(90)->toDateString();

        $zapisy = DB::table('couple_outreach_log')
            ->where('gallery_space_id', $prostor->id)
            ->where('happened_on', '>=', $od)
            ->limit(2000)
            ->get(['area', 'by_user_id', 'asked_partner', 'happened_on']);

        if ($zapisy->isEmpty()) {
            return [];
        }

        $poradi = array_keys($jmena);
        $prvni = $poradi[0] ?? null;
        $druhy = $poradi[1] ?? null;

        return $zapisy
            ->groupBy('area')
            ->map(function ($radky, string $oblast) use ($prvni, $druhy) {
                $a = $radky->where('by_user_id', $prvni)->count();
                $k = $druhy === null ? 0 : $radky->where('by_user_id', $druhy)->count();
                $ptalSe = $radky->filter(fn (object $z) => (bool) $z->asked_partner)->count();
                $naposledy = CarbonImmutable::parse($radky->max('happened_on'));

                return [
                    'area' => $oblast,
                    'a' => $a,
                    'k' => $k,
                    // Pod čtvrtinou dotazů se tu rozhoduje za oba bez ptaní.
                    'ask' => $radky->count() > 0 && $ptalSe / $radky->count() < 0.25,
                    'note' => $radky->count().'× za 90 dní · naposledy '.$naposledy->format('j. n.'),
                ];
            })
            ->sortByDesc(fn (array $r) => $r['a'] + $r['k'])
            ->values()
            ->all();
    }

    /**
     * Čas versus služba: `[{ task, hours, service, doer }]`.
     *
     * Kolik hodin měsíčně padne na kterou práci doma — ze zapsaného protokolu
     * dělby práce, ne z odhadu. Cena služby se **nepočítá**: ceník úklidové
     * firmy aplikace nezná a vymyslet ho by znamenalo tvrdit dvojici, že se
     * jí vyplatí něco, co nikdo nenacenil.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function casVersusSluzba(GallerySpace $prostor, array $jmena): array
    {
        if (! Schema::hasTable('house_chore_log')) {
            return [];
        }

        $od = CarbonImmutable::now()->subDays(90);

        $prace = DB::table('house_chore_log')
            ->where('gallery_space_id', $prostor->id)
            ->where('done_at', '>=', $od)
            ->selectRaw('chore_name, user_id, SUM(minutes) AS minut, COUNT(*) AS kolikrat')
            ->groupBy('chore_name', 'user_id')
            ->get();

        if ($prace->isEmpty()) {
            return [];
        }

        return $prace
            ->groupBy('chore_name')
            ->map(function ($radky, string $nazev) use ($jmena) {
                // Kdo to dělá nejčastěji — ne kdo to dělal naposledy.
                $hlavni = $radky->sortByDesc('kolikrat')->first();
                $minut = (int) $radky->sum('minut');

                return [
                    'task' => $nazev,
                    // Na měsíc: protokol je za čtvrt roku.
                    'hours' => round($minut / 60 / 3, 1),
                    // Cenu služby aplikace nezná; nula znamená „nenaceněno".
                    'service' => 0,
                    'doer' => $jmena[$hlavni->user_id] ?? 'spolu',
                ];
            })
            ->sortByDesc('hours')
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * Účet laskavostí: `[{ id, from, what, date, w, settled }]`.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function laskavosti(GallerySpace $prostor, array $jmena): array
    {
        if (! Schema::hasTable('couple_favours')) {
            return [];
        }

        return DB::table('couple_favours')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('happened_on')
            ->limit(60)
            ->get()
            ->map(fn (object $l) => [
                'id' => $l->uuid,
                'from' => $jmena[$l->from_user_id] ?? '—',
                'what' => $l->what,
                'date' => CarbonImmutable::parse($l->happened_on)->toDateString(),
                'w' => (int) $l->weight,
                'settled' => (bool) $l->is_settled,
            ])
            ->values()
            ->all();
    }

    /**
     * Co je odpuštěné: `[{ id, what, by, date, tries }]`.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function odpustene(GallerySpace $prostor, array $jmena): array
    {
        if (! Schema::hasTable('couple_forgiven')) {
            return [];
        }

        return DB::table('couple_forgiven')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('happened_on')
            ->limit(40)
            ->get()
            ->map(fn (object $o) => [
                'id' => $o->uuid,
                'what' => $o->what,
                'by' => $jmena[$o->forgiven_by] ?? '—',
                'date' => CarbonImmutable::parse($o->happened_on)->toDateString(),
                // Odpuštěné neznamená zapomenuté; tohle je jediné, co o tom
                // něco řekne — kolikrát se to vrátilo do řeči.
                'tries' => (int) $o->tries,
            ])
            ->values()
            ->all();
    }

    /**
     * Anti-rozpočet: `[{ month, name, type, saved, back }]`.
     *
     * @return list<array<string, mixed>>
     */
    private function antiRozpocet(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('couple_anti_budget')) {
            return [];
        }

        $mesice = [1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
            'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];

        return DB::table('couple_anti_budget')
            ->where('gallery_space_id', $prostor->id)
            ->orderBy('decided_on')
            ->limit(60)
            ->get()
            ->map(fn (object $a) => [
                'month' => $mesice[CarbonImmutable::parse($a->decided_on)->month],
                'name' => $a->name,
                'type' => $this->druhAnti((string) $a->kind),
                'saved' => (int) $a->saved,
                'back' => (bool) $a->came_back,
            ])
            ->values()
            ->all();
    }

    /**
     * Mentální zátěž: `[{ task, doer, head, freq, min }]`.
     *
     * V rozdílu mezi „kdo to dělá" a „kdo na to musí myslet" je celý smysl.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function mentalniZatez(GallerySpace $prostor, array $jmena): array
    {
        if (! Schema::hasTable('couple_mental_load')) {
            return [];
        }

        return DB::table('couple_mental_load')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('times_a_year')
            ->limit(60)
            ->get()
            ->map(fn (object $z) => [
                'task' => $z->task,
                'doer' => $jmena[$z->doer_id] ?? 'spolu',
                'head' => $jmena[$z->keeper_id] ?? 'spolu',
                'freq' => (int) $z->times_a_year,
                'min' => (int) $z->minutes,
            ])
            ->values()
            ->all();
    }

    /**
     * Rotace kontaktu s rodinou: `[{ id, name, side, last, lastWho, every, over, note }]`.
     *
     * Jestli je to po lhůtě, se počítá teď — uložený příznak by byl den po
     * termínu vedle.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function rodina(GallerySpace $prostor, array $jmena): array
    {
        if (! Schema::hasTable('couple_family_contacts')) {
            return [];
        }

        $dnes = CarbonImmutable::now()->startOfDay();

        return DB::table('couple_family_contacts')
            ->where('gallery_space_id', $prostor->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (object $r) use ($jmena, $dnes) {
                $kdy = $r->last_contact_on ? CarbonImmutable::parse($r->last_contact_on) : null;
                $dni = $kdy ? (int) $kdy->startOfDay()->diffInDays($dnes) : null;

                return [
                    'id' => $r->uuid,
                    'name' => $r->name,
                    'side' => $jmena[$r->side_user_id] ?? 'spolu',
                    'last' => $this->naposledy($dni),
                    'lastWho' => $jmena[$r->last_contact_by] ?? '—',
                    'every' => (int) $r->every_days,
                    'over' => $dni !== null && $dni > (int) $r->every_days,
                    'note' => (string) ($r->note ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Dvě pravdy o jedné události: `[{ id, title, when, a, m }]`.
     *
     * Ne „kdo měl pravdu" — obě verze stojí vedle sebe a žádná nevyhrává.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function pravdy(GallerySpace $prostor, array $jmena): array
    {
        if (! Schema::hasTable('couple_truths')) {
            return [];
        }

        return DB::table('couple_truths')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(fn (object $p) => [
                'id' => $p->uuid,
                'title' => $p->title,
                'when' => (string) ($p->context ?? ''),
                'a' => (string) ($p->first_version ?? ''),
                'm' => (string) ($p->second_version ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * Proběhlé pauzy: `[{ when, who, topic, after }]`.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, string>>
     */
    private function pauzy(GallerySpace $prostor, array $jmena): array
    {
        if (! Schema::hasTable('couple_pause')) {
            return [];
        }

        $mesice = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
            'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

        return DB::table('couple_pause')
            ->where('gallery_space_id', $prostor->id)
            ->where('kind', 'log')
            ->orderByDesc('happened_on')
            ->limit(30)
            ->get()
            ->map(function (object $p) use ($jmena, $mesice) {
                $kdy = $p->happened_on ? CarbonImmutable::parse($p->happened_on) : null;

                return [
                    'when' => $kdy ? $kdy->day.'. '.$mesice[$kdy->month] : '—',
                    'who' => $jmena[$p->called_by] ?? '—',
                    'topic' => (string) ($p->topic ?? $p->text),
                    'after' => (string) ($p->outcome ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Pravidla pauzy: `[{ step, agreed }]`.
     *
     * @return list<array<string, mixed>>
     */
    private function pravidlaPauzy(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('couple_pause')) {
            return [];
        }

        return DB::table('couple_pause')
            ->where('gallery_space_id', $prostor->id)
            ->where('kind', 'rule')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (object $p) => ['step' => $p->text, 'agreed' => (bool) $p->agreed])
            ->values()
            ->all();
    }

    /**
     * Ticho v datech: `[{ name, last, route }]`.
     *
     * Tohle se **nezapisuje**. Je to poslední zápis do sekce a ten aplikace
     * zná; uložený by byl druhou, zastarávající pravdou.
     *
     * @return list<array<string, string>>
     */
    private function ticho(GallerySpace $prostor): array
    {
        $radky = [];

        foreach (self::SEKCE as [$nazev, $cesta, $tabulka, $sloupec]) {
            if (! Schema::hasTable($tabulka)) {
                continue;
            }

            $kdy = DB::table($tabulka)
                ->where('gallery_space_id', $prostor->id)
                ->max($sloupec);

            if (! $kdy) {
                continue;
            }

            $radky[] = [
                'name' => $nazev,
                'last' => CarbonImmutable::parse($kdy)->toDateString(),
                'route' => $cesta,
            ];
        }

        // Nejtišší nahoře — o to na téhle obrazovce jde.
        usort($radky, fn (array $a, array $b) => strcmp($a['last'], $b['last']));

        return $radky;
    }

    // ——— formát ———

    private function naposledy(?int $dni): string
    {
        return match (true) {
            $dni === null => 'nikdy',
            $dni === 0 => 'dnes',
            $dni === 1 => 'včera',
            default => 'před '.$dni.' '.($dni >= 5 ? 'dny' : 'dny'),
        };
    }

    private function druhAnti(string $druh): string
    {
        return match ($druh) {
            'predplatne' => 'předplatné',
            'vec' => 'věc',
            'sluzba' => 'služba',
            default => 'jiné',
        };
    }

    /** @return array<int, string> */
    private function jmena(GallerySpace $prostor): array
    {
        return $prostor->members()->pluck('users.name', 'users.id')->all();
    }
}
