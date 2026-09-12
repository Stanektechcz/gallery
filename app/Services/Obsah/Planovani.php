<?php

namespace App\Services\Obsah;

use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\LifeEvent;
use App\Models\SharedTodo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kalendář, úkoly a „až budeme mít čas" ve tvaru, ve kterém je kreslí prototyp.
 *
 * Aplikace má celý plánovací modul — kalendářní události, sdílené úkoly se
 * seznamy, připomínky i společnou stopu toho, co se stalo. Prototyp z toho
 * neukazoval nic: kreslil čtyři napsané události z léta 2026, nástěnku o devíti
 * vymyšlených úkolech a v „Co se změnilo" cizí den.
 *
 * Kolekce jsou **výchozí hodnota**. Jakmile dvojice v prototypu něco upraví,
 * drží si vlastní seznam ve stavu (`evList`, `xBoard`, `hsLater`) — tak je
 * prototyp napsaný a nemění se to.
 */
class Planovani implements PoskytovatelObsahu
{
    /** Kolik dopředu a dozadu kalendář posílá. */
    private const DNU_ZPET = 90;

    private const DNU_VPRED = 400;

    /** Co se vejde na nástěnku a do seznamu „Co se změnilo". */
    private const UKOLU = 120;

    private const UDALOSTI = 40;

    /** Kalendář: kolik událostí zpátky a dopředu. Každá je pár desítek bajtů. */
    private const UDALOSTI_ZPET = 150;

    private const UDALOSTI_VPRED = 300;

    /** Sloupce nástěnky. Zápis zpátky je podle nich pozná. */
    public const TENTO_TYDEN = 'Tento týden';

    public const POZDEJI = 'Později';

    public const NEKDY = 'Někdy';

    /** Seznamy úkolů dvojice; drží se kvůli tomu, co patří do domácnosti. */
    private array $seznamyUkolu = [];

    public function skupina(): string
    {
        return 'planovani';
    }

    /**
     * Nic. `AL` má vedle hotových úkolů dalších čtyřicet seznamů, které tenhle
     * poskytovatel nepočítá, a `ATASKS` nástěnku domácnosti, která patří
     * ke skupině Domácnost.
     */
    public function uplne(): array
    {
        return [];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        $jmena = $this->jmena($prostor);

        if (Schema::hasTable('shared_todo_lists')) {
            $this->seznamyUkolu = DB::table('shared_todo_lists')
                ->where('gallery_space_id', $prostor->id)
                ->get(['id', 'title', 'kind'])
                ->keyBy('id')
                ->all();
        }

        return array_filter([
            'CALEV' => $this->udalosti($prostor, $jmena),
            'ATASKS' => $this->nastenka($prostor, $jmena),
            'LATER_ITEMS' => $this->nekdy($prostor, $jmena),
            'AL' => $this->seznamy($prostor, $jmena),
            'EVSEED' => $this->stopa($prostor, $jmena),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Kdo je kdo. Prototyp píše jména, ne čísla.
     *
     * @return array<int, string>
     */
    private function jmena(GallerySpace $prostor): array
    {
        return $prostor->members()
            ->pluck('users.name', 'users.id')
            ->all();
    }

    /**
     * Kalendář: `{ y, m, d, time, t, kind, who, note, remind, album }`.
     *
     * Měsíc je **od nuly** — prototyp ho tak čte i zapisuje (`m: +p[1] - 1`).
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function udalosti(GallerySpace $prostor, array $jmena): array
    {
        $ucastnici = $this->ucastnici($prostor);
        $pripomenuti = $this->pripomenuti($prostor);
        // Prototyp album pozná podle identifikátoru, který mu posílá knihovna.
        $alba = DB::table('albums')->where('gallery_space_id', $prostor->id)->pluck('uuid', 'id')->all();

        /*
         * Minulost a budoucnost zvlášť.
         *
         * Jeden dotaz od -90 dnů vzestupně s limitem čtyřiceti znamenal, že
         * dvojice se čtyřiceti událostmi za poslední čtvrtrok neviděla v
         * kalendáři **nic dopředu** — limit se vyčerpal na minulosti.
         */
        $ted = CarbonImmutable::now()->startOfDay();
        $dotaz = fn () => CalendarEvent::where('gallery_space_id', $prostor->id)->where('is_private', false);
        $minule = $dotaz()
            ->where('starts_at', '>=', $ted->subDays(self::DNU_ZPET))
            // Ostře menší: půlnoční událost dneška patří jen do budoucích.
            ->where('starts_at', '<', $ted)
            ->orderByDesc('starts_at')
            ->limit(self::UDALOSTI_ZPET)
            ->get();
        $budouci = $dotaz()
            ->where('starts_at', '>=', $ted)
            ->where('starts_at', '<=', $ted->addDays(self::DNU_VPRED))
            ->orderBy('starts_at')
            ->limit(self::UDALOSTI_VPRED)
            ->get();

        return $minule->reverse()->concat($budouci)
            ->map(function (CalendarEvent $e) use ($jmena, $ucastnici, $pripomenuti, $alba) {
                $kdy = CarbonImmutable::parse($e->starts_at);

                return array_filter([
                    'id' => 'ev-'.$e->uuid,
                    'y' => $kdy->year,
                    // Prototyp počítá měsíce od nuly.
                    'm' => $kdy->month - 1,
                    'd' => $kdy->day,
                    'time' => $e->all_day ? '' : $kdy->format('G:i'),
                    't' => $e->title,
                    'kind' => $this->druh((string) $e->type),
                    // Co to byla za společnou věc. Účet radosti podle toho
                    // seskupuje; bez toho by se neměl podle čeho.
                    'act' => $e->activity_kind ?? '',
                    'who' => $this->kdo($e->id, $e->created_by, $jmena, $ucastnici),
                    'note' => (string) ($e->description ?? ''),
                    'remind' => $pripomenuti[$e->id] ?? '',
                    // Odkaz na album drží prototyp jako jeho identifikátor, takže
                    // `albums().find(a => a.id === e.album)` sedne beze změny.
                    'album' => $alba[$e->album_id] ?? '',
                ], fn ($v) => $v !== null);
            })
            ->values()
            ->all();
    }

    /**
     * Kdo událost má: jeden člověk jménem, oba „spolu".
     *
     * @param  array<int, string>  $jmena
     * @param  array<int, list<int>>  $ucastnici
     */
    private function kdo(int $udalost, ?int $zalozil, array $jmena, array $ucastnici): string
    {
        $lide = $ucastnici[$udalost] ?? ($zalozil ? [$zalozil] : []);

        if (count($lide) > 1) {
            return 'spolu';
        }

        return $jmena[$lide[0] ?? 0] ?? 'spolu';
    }

    /**
     * Účastníci událostí — jedním dotazem.
     *
     * @return array<int, list<int>>
     */
    private function ucastnici(GallerySpace $prostor): array
    {
        return DB::table('event_participants as u')
            ->join('calendar_events as e', 'e.id', '=', 'u.event_id')
            ->where('e.gallery_space_id', $prostor->id)
            ->get(['u.event_id', 'u.user_id'])
            ->groupBy('event_id')
            ->map(fn (Collection $r) => $r->pluck('user_id')->map(fn ($i) => (int) $i)->all())
            ->all();
    }

    /**
     * Připomenutí přeložené do slov prototypu.
     *
     * Nabídka v dialogu má jen čtyři možnosti (`''`, `ráno`, `den předem`,
     * `týden předem`); databáze drží přesný okamžik, takže se z rozdílu vybere
     * ta nejbližší.
     *
     * @return array<int, string>
     */
    private function pripomenuti(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('event_reminders')) {
            return [];
        }

        return DB::table('event_reminders as p')
            ->join('calendar_events as e', 'e.id', '=', 'p.event_id')
            ->where('e.gallery_space_id', $prostor->id)
            ->orderBy('p.remind_at')
            ->get(['p.event_id', 'p.remind_at', 'e.starts_at'])
            ->groupBy('event_id')
            ->map(function (Collection $r) {
                $prvni = $r->first();
                $hodin = CarbonImmutable::parse($prvni->remind_at)
                    ->diffInHours(CarbonImmutable::parse($prvni->starts_at), false);

                return match (true) {
                    $hodin >= 120 => 'týden předem',
                    $hodin >= 12 => 'den předem',
                    default => 'ráno',
                };
            })
            ->all();
    }

    /** Typ události v aplikaci → druh, který prototyp umí obarvit. */
    private function druh(string $typ): string
    {
        return match ($typ) {
            'trip' => 'cesta',
            'outing' => 'kultura',
            'birthday', 'anniversary' => 'oslava',
            'reservation' => 'platba',
            default => 'jine',
        };
    }

    /**
     * Nástěnka úkolů: `{ all: [[sloupec, [[co, kdo, kdy, hotovo], …]], …] }`.
     *
     * Sloupce jsou podle času, ne podle seznamů: „Tento týden" je jediné, co
     * dvojice ráno potřebuje vidět, a „Někdy" je to, co se nemá tvářit jako úkol.
     *
     * @param  array<int, string>  $jmena
     * @return array<string, mixed>
     */
    private function nastenka(GallerySpace $prostor, array $jmena): array
    {
        $ukoly = $this->ukoly($prostor);

        if ($ukoly->isEmpty()) {
            return [];
        }

        $tyden = CarbonImmutable::now()->addWeek();

        /*
         * Čtyři pole kreslí prototyp, tři jsou navíc pro cestu zpátky:
         * `[co, kdo, termín slovy, hotovo, identifikátor, termín datem, priorita]`.
         *
         * Bez identifikátoru by se úprava neměla kam zapsat, bez data by se
         * termín musel hádat z popisku a bez priority by každý úkol po termínu
         * skončil jako „spěchá“, protože si ji prototyp z popisku dopočítává sám.
         */
        $radek = fn (SharedTodo $u) => [
            $u->title,
            $u->assigned_to ? ($jmena[$u->assigned_to] ?? 'spolu') : 'spolu',
            $this->termin($u),
            $u->status === 'completed' ? 1 : 0,
            $u->uuid,
            $u->due_at ? CarbonImmutable::parse($u->due_at)->format('Y-m-d') : null,
            match ($u->priority) {
                'urgent', 'high' => 2,
                'low' => 1,
                default => 0,
            },
        ];

        $sloupec = fn (string $nazev, Collection $co) => $co->isEmpty()
            ? null
            : [$nazev, $co->map($radek)->values()->all()];

        $podleCasu = fn (Collection $co) => array_values(array_filter([
            $sloupec(self::TENTO_TYDEN, $co->filter(fn (SharedTodo $u) => $u->due_at && $u->due_at <= $tyden)),
            $sloupec(self::POZDEJI, $co->filter(fn (SharedTodo $u) => $u->due_at && $u->due_at > $tyden)),
            $sloupec(self::NEKDY, $co->filter(fn (SharedTodo $u) => ! $u->due_at)),
        ]));

        // Domácnost je vlastní nástěnka: úkoly ze seznamů, které si dvojice
        // vede jako domácí. Bez nich se záložka neposílá a zůstane napsaná.
        $domaci = $ukoly->filter(fn (SharedTodo $u) => $this->jeDomaci($u));

        return array_filter([
            'all' => $podleCasu($ukoly),
            'home' => $domaci->isEmpty() ? [] : $podleCasu($domaci),
        ], fn ($v) => $v !== []);
    }

    /**
     * Patří úkol na nástěnku domácnosti?
     *
     * Pozná se to podle seznamu, do kterého ho dvojice dala — ne podle slov
     * v názvu. „Zavolat instalatérovi" je domácnost, „Zavolat mámě" není,
     * a rozeznat to z textu nejde.
     */
    private function jeDomaci(SharedTodo $u): bool
    {
        $seznam = $this->seznamyUkolu[$u->list_id ?? 0] ?? null;

        if (! $seznam) {
            return false;
        }

        return $seznam->kind === 'household'
            || mb_strtolower((string) $seznam->title) === 'domácnost';
    }

    /**
     * Termín slovy. Prototyp podle nich pozná, co spěchá
     * (`/po termínu|dnes/` zvedne prioritu).
     */
    private function termin(SharedTodo $u): string
    {
        if (! $u->due_at) {
            return 'bez termínu';
        }

        $den = CarbonImmutable::parse($u->due_at)->startOfDay();
        $dnes = CarbonImmutable::now()->startOfDay();
        $rozdil = (int) $dnes->diffInDays($den, false);

        return match (true) {
            $rozdil < 0 => 'po termínu',
            $rozdil === 0 => 'dnes',
            $rozdil === 1 => 'zítra',
            $rozdil <= 6 => ['neděli', 'pondělí', 'úterý', 'středu', 'čtvrtek', 'pátek', 'sobotu'][$den->dayOfWeek],
            default => 'do '.$den->format('j. n.'),
        };
    }

    /**
     * „Až budeme mít čas": `{ id, text, by, added, state }`.
     *
     * Jsou to tytéž úkoly bez termínu, které stojí ve sloupci „Někdy" — jen se
     * na ně tahle obrazovka dívá jinak: měří jim věk a po roce je označí za
     * propadlé. Dvojí pohled na jednu věc, ne dvě různé pravdy.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function nekdy(GallerySpace $prostor, array $jmena): array
    {
        return SharedTodo::where('gallery_space_id', $prostor->id)
            ->whereNull('due_at')
            ->orderBy('created_at')
            ->limit(self::UKOLU)
            ->get()
            ->map(fn (SharedTodo $u) => [
                'id' => $u->uuid,
                'text' => $u->title,
                'by' => $jmena[$u->created_by] ?? 'spolu',
                'added' => CarbonImmutable::parse($u->created_at)->format('Y-m-d'),
                'state' => match ($u->status) {
                    'completed' => 'done',
                    'cancelled' => 'dropped',
                    default => 'open',
                },
            ])
            ->values()
            ->all();
    }

    /**
     * Seznamy obrazovek. Zatím jediný: hotové úkoly pod nástěnkou.
     *
     * `AL` je katalog o čtyřiceti seznamech; posílá se jen ten, který sem patří,
     * a zbytek zůstává nedotčený — klient klíče přepisuje, nemaže.
     *
     * @param  array<int, string>  $jmena
     * @return array<string, mixed>
     */
    private function seznamy(GallerySpace $prostor, array $jmena): array
    {
        $hotove = SharedTodo::where('gallery_space_id', $prostor->id)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->limit(30)
            ->get()
            ->map(fn (SharedTodo $u) => [
                $u->title,
                ($jmena[$u->completed_by ?? $u->assigned_to] ?? 'spolu')
                    .' · '.CarbonImmutable::parse($u->completed_at)->format('j. n.'),
                'hotovo',
            ])
            ->values()
            ->all();

        return $hotove ? ['doneTasks' => $hotove] : [];
    }

    /**
     * Co se změnilo: `[věta, ikona, kdo, čas, kdy]`.
     *
     * Bere se ze společné stopy (`life_events`), kterou si moduly zapisují samy —
     * ne z jednotlivých tabulek. Prototyp k tomu přidává vlastní záznamy o tom,
     * co člověk udělal právě teď.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<int, string>>
     */
    private function stopa(GallerySpace $prostor, array $jmena): array
    {
        if (! Schema::hasTable('life_events')) {
            return [];
        }

        $dnes = CarbonImmutable::now()->startOfDay();

        /*
         * Řadí se podle zápisu, ne podle `occurred_at`.
         *
         * Ta hodnota je čas té věci samotné — u kalendářní události její začátek,
         * tedy budoucnost. Seznam „Co se změnilo" je ale kronika toho, co se
         * stalo, takže by jinak hlásil, že se zítra něco už změnilo.
         */
        return LifeEvent::where('gallery_space_id', $prostor->id)
            ->orderByDesc('created_at')
            ->limit(self::UDALOSTI)
            ->get()
            ->map(function (LifeEvent $u) use ($jmena, $dnes) {
                $kdy = CarbonImmutable::parse($u->created_at);
                $rozdil = (int) $kdy->startOfDay()->diffInDays($dnes);

                return [
                    $this->veta($u),
                    $this->ikona((string) $u->kind),
                    $jmena[$u->created_by] ?? 'spolu',
                    $kdy->format('G:i'),
                    match (true) {
                        $rozdil === 0 => 'dnes',
                        $rozdil === 1 => 'včera',
                        default => $kdy->format('j. n.'),
                    },
                ];
            })
            ->values()
            ->all();
    }

    /** Věta do seznamu — co se stalo, ne jméno události v kódu. */
    private function veta(LifeEvent $u): string
    {
        $co = $u->title;

        return match (true) {
            str_starts_with($u->kind, 'calendar.event') => 'Přidala/l do kalendáře '.$co,
            str_starts_with($u->kind, 'shopping.item') => 'Na nákupní seznam '.$co,
            str_starts_with($u->kind, 'planning.todo') => 'Nový úkol '.$co,
            str_starts_with($u->kind, 'recipe') => 'Do kuchařky '.$co,
            str_starts_with($u->kind, 'trip') => 'Založila/l cestu '.$co,
            str_starts_with($u->kind, 'gift') => 'K dárkům '.$co,
            str_starts_with($u->kind, 'milestone') => 'Nový milník '.$co,
            str_starts_with($u->kind, 'album') => 'Album '.$co,
            str_starts_with($u->kind, 'finance') => 'Útrata '.$co,
            str_starts_with($u->kind, 'watchlist') => 'Na seznam k vidění '.$co,
            str_starts_with($u->kind, 'travel') => 'Do cestovní schránky '.$co,
            default => $co,
        };
    }

    /** Ikona z Phosphoru; prototyp jiné písmo ikon nemá. */
    private function ikona(string $druh): string
    {
        return match (true) {
            str_starts_with($druh, 'calendar') => 'ph-calendar-dots',
            str_starts_with($druh, 'shopping') => 'ph-basket',
            str_starts_with($druh, 'planning') => 'ph-list-checks',
            str_starts_with($druh, 'recipe') => 'ph-cooking-pot',
            str_starts_with($druh, 'trip') => 'ph-suitcase-rolling',
            str_starts_with($druh, 'gift') => 'ph-gift',
            str_starts_with($druh, 'milestone') => 'ph-flag-banner',
            str_starts_with($druh, 'album') => 'ph-images',
            str_starts_with($druh, 'finance') => 'ph-receipt',
            str_starts_with($druh, 'watchlist') => 'ph-film-strip',
            str_starts_with($druh, 'travel') => 'ph-airplane-tilt',
            default => 'ph-sparkle',
        };
    }

    /** @return Collection<int, SharedTodo> */
    private function ukoly(GallerySpace $prostor): Collection
    {
        return SharedTodo::where('gallery_space_id', $prostor->id)
            ->where('status', '!=', 'cancelled')
            ->orderBy('due_at')
            ->orderBy('sort_order')
            ->limit(self::UKOLU)
            ->get();
    }
}
