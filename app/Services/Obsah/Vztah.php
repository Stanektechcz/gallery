<?php

namespace App\Services\Obsah;

use App\Models\CoupleCoolingPurchase;
use App\Models\CoupleDecision;
use App\Models\CoupleDisagreementPoint;
use App\Models\CoupleNudge;
use App\Models\CouplePromise;
use App\Models\CoupleVeto;
use App\Models\CoupleVetoProposal;
use App\Models\GallerySpace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mechanismy vztahu ve tvaru, ve kterém je kreslí prototyp.
 *
 * Paměť rozhodnutí, rozvaha před nákupem, protokol nesouhlasu a veto banka jsou
 * jediné čtyři, které se v prototypu dají měnit — a proto jediné, které mají
 * tabulku. Zbytek (tiché dohody, kdo mluví za nás, premortem) zůstává v katalogu:
 * tabulka, do které nikdo nepíše, je horší než žádná.
 *
 * Přehled arbitráže i záznam verzí se **odvozují z rozhodnutí**, ne z druhého
 * seznamu — ten by se s rozhodnutími dřív nebo později rozešel.
 */
class Vztah implements PoskytovatelObsahu
{
    private const MESICE = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
        'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

    /** Žádosti se čtou dvakrát — pro seznam i pro přehled trpělivosti. */
    private ?Collection $zadostiCache = null;

    public function __construct(private readonly TichaPravidla $pravidla) {}

    public function skupina(): string
    {
        return 'vztah';
    }

    public function uplne(): array
    {
        return [];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('couple_decisions')) {
            return [];
        }

        $jmena = $prostor->members()->pluck('users.name', 'users.id')->all();
        $rozhodnuti = $this->rozhodnuti($prostor);
        $body = $this->body($prostor);

        return array_filter([
            'DEC_LIST' => $this->pamet($rozhodnuti, $jmena),
            'ARB' => $this->arbitraz($rozhodnuti, $jmena),
            'VERSIONS' => $this->verze($rozhodnuti, $jmena),
            'DEC_COOL' => $this->rozvahy($prostor, $jmena),
            // Protokol nesouhlasu se dělí podle toho, kdo se dívá.
            'SPOR_MINE' => $this->protokol($body, $this->ja(), true),
            'SPOR_THEIRS' => $this->protokol($body, $this->ja(), false),
            'VETO_USED' => $this->veta($prostor, $jmena),
            'VETO_PROP' => $this->navrhy($prostor, $jmena),
            'PROMISES' => $sliby = $this->sliby($prostor, $jmena),
            'NUDGES' => $this->zadosti($prostor, $jmena),
            'PATIENCE' => $this->trpelivost($prostor, $jmena),
            // Témata, ke kterým se dvojice vrací, aniž by je zavřela.
            'DISP' => $this->vracejiciSeTemata($body, $prostor),
            // Co rozhodl čas místo nich.
            'AUTO_DEC' => $this->rozhodlCas($prostor),
            // Vzorce, na kterých se nikdo nedohodl a přesto platí.
            'TACIT' => $this->pravidla->najdi($prostor),
            // Uložené nápady na randíčko.
            'AL' => array_filter([
                'datesSaved' => $this->randicka($prostor),
                'datesGen' => $this->vygenerovanaRandicka($prostor),
            ], fn (array $v) => $v !== []),
            // Telefon kreslí sliby z vlastní kolekce; tvar je tentýž.
            'MOBIL' => $sliby ? ['PROMISES' => $sliby] : [],
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Kdo se dívá.
     *
     * Protokol nesouhlasu je jediná kolekce, která vypadá jinak pro každého
     * z dvojice: „moje podmínky" a „jeho podmínky" jsou tytéž řádky obrácené.
     */
    private function ja(): ?int
    {
        return auth()->id();
    }

    /** @return Collection<int, CoupleDecision> */
    private function rozhodnuti(GallerySpace $prostor): Collection
    {
        return CoupleDecision::where('gallery_space_id', $prostor->id)
            ->with('revize')
            ->orderByDesc('decided_on')
            ->limit(60)
            ->get();
    }

    /**
     * Paměť rozhodnutí: `{ id, title, date, by, status, why, rejected, review }`.
     *
     * @param  Collection<int, CoupleDecision>  $rozhodnuti
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function pamet(Collection $rozhodnuti, array $jmena): array
    {
        return $rozhodnuti->map(fn (CoupleDecision $r) => array_filter([
            'id' => $r->uuid,
            'title' => $r->title,
            'date' => CarbonImmutable::parse($r->decided_on)->format('j. n. Y'),
            'by' => $r->together ? implode(' a ', array_values($jmena)) : ($jmena[$r->decided_by] ?? 'oba'),
            'status' => $r->status,
            'why' => $r->why ?: ['Důvod zapíšeme později.'],
            'rejected' => $r->rejected ?: ['Nic dalšího jsme nezvažovali'],
            'review' => $r->review_note ?: ($r->review_on
                ? 'v '.self::MESICE[CarbonImmutable::parse($r->review_on)->month].' '.CarbonImmutable::parse($r->review_on)->year
                : 'bez revize'),
            'changedAt' => $r->changed_at ? CarbonImmutable::parse($r->changed_at)->format('j. n. Y') : null,
        ], fn ($v) => $v !== null))->values()->all();
    }

    /**
     * Kdo měl poslední slovo: `{ q, w, date, how }`.
     *
     * Odvozuje se z rozhodnutí, která mají arbitra — ne z druhého seznamu.
     *
     * @param  Collection<int, CoupleDecision>  $rozhodnuti
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function arbitraz(Collection $rozhodnuti, array $jmena): array
    {
        return $rozhodnuti
            ->filter(fn (CoupleDecision $r) => $r->arbiter_user_id !== null)
            ->map(fn (CoupleDecision $r) => [
                'q' => $r->title,
                'w' => $jmena[$r->arbiter_user_id] ?? '—',
                'date' => CarbonImmutable::parse($r->decided_on)->format('Y-m-d'),
                'how' => $r->arbiter_method ?: 'poslední slovo',
            ])
            ->values()
            ->all();
    }

    /**
     * Záznam verzí: `{ dec, steps: [{ v, by, when }] }`.
     *
     * @param  Collection<int, CoupleDecision>  $rozhodnuti
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function verze(Collection $rozhodnuti, array $jmena): array
    {
        return $rozhodnuti
            ->filter(fn (CoupleDecision $r) => $r->revize->isNotEmpty())
            ->map(fn (CoupleDecision $r) => [
                'dec' => $r->title,
                'steps' => $r->revize->map(fn ($v) => [
                    'v' => $v->wording,
                    'by' => $jmena[$v->changed_by] ?? 'oba',
                    'when' => CarbonImmutable::parse($v->valid_from)->format('Y-m-d'),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Rozvaha před nákupem: `{ id, what, price, who, opened, left, opinion, opinionBy }`.
     *
     * `left` jsou **hodiny do konce lhůty**, spočítané teď — ne uložené číslo,
     * které by po zavření prohlížeče zamrzlo.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function rozvahy(GallerySpace $prostor, array $jmena): array
    {
        $ted = CarbonImmutable::now();

        return CoupleCoolingPurchase::where('gallery_space_id', $prostor->id)
            ->whereNull('closed_at')
            ->orderBy('cools_until')
            ->get()
            ->map(fn (CoupleCoolingPurchase $n) => [
                'id' => $n->uuid,
                'what' => $n->what,
                'price' => (int) $n->price,
                'who' => $jmena[$n->requested_by] ?? 'oba',
                'opened' => $this->kdy(CarbonImmutable::parse($n->opened_at)),
                // Nahoru, ne dolů: začatá hodina se ještě počítá, jinak by
                // rozvaha otevřená před vteřinou hlásila o hodinu míň.
                'left' => max(0, (int) ceil($ted->diffInHours(CarbonImmutable::parse($n->cools_until), false))),
                'opinion' => $n->opinion,
                'opinionBy' => $n->opinion ? ($jmena[$n->opinion_by] ?? null) : null,
                'verdict' => $n->verdict,
            ])
            ->values()
            ->all();
    }

    /**
     * Co rozhodl čas: `[{ what, kind, date, cost, note }]`.
     *
     * Obrazovka o sobě říká, že hledá tři vzorce — a hledá je doopravdy:
     *
     *  - **propadlá rozvaha** (`vyprodáno`): lhůta na rozmyšlenou uplynula
     *    a nikdo nic nenapsal. Cena je cena té věci; nekoupit ji taky něco
     *    stálo, ale kolik, aplikace neví.
     *  - **uplynulá lhůta** (`lhůta`): úkol s termínem, který prošel a nikdo
     *    ho nezavřel ani neposunul.
     *  - **mlčení** (`mlčení`): věc odložená do „až budeme mít čas", která
     *    tam leží déle než čtvrt roku.
     *
     * `cost` je nula všude, kde se cena nedá vyčíst. Nula znamená „bez přímé
     * ceny" — dopočítat, co stálo nerozhodnutí termínu u zubaře, by znamenalo
     * vymyslet číslo, kterým se pak měří chování dvojice.
     *
     * @return list<array<string, mixed>>
     */
    private function rozhodlCas(GallerySpace $prostor): array
    {
        $ted = CarbonImmutable::now();
        $radky = [];

        // Rozvaha, které vypršela lhůta a nikdo se nevyjádřil.
        foreach (CoupleCoolingPurchase::where('gallery_space_id', $prostor->id)
            ->whereNull('closed_at')
            ->whereNull('opinion')
            ->where('cools_until', '<', $ted)
            ->orderByDesc('price')
            ->limit(20)
            ->get() as $n) {
            $hodin = (int) round(CarbonImmutable::parse($n->opened_at)->diffInHours(CarbonImmutable::parse($n->cools_until)));

            $radky[] = [
                'what' => $n->what,
                'kind' => 'vyprodáno',
                'date' => CarbonImmutable::parse($n->cools_until)->toDateString(),
                'cost' => (int) $n->price,
                'note' => 'Rozvaha běžela '.$hodin.' hodin, nikdo se nevyjádřil.',
            ];
        }

        // Úkol s termínem, který prošel a nikdo ho nezavřel.
        if (Schema::hasTable('shared_todos')) {
            foreach (DB::table('shared_todos')
                ->where('gallery_space_id', $prostor->id)
                ->whereNotNull('due_at')
                ->where('due_at', '<', $ted)
                ->where('status', '!=', 'completed')
                ->orderBy('due_at')
                ->limit(20)
                ->get(['title', 'due_at']) as $u) {
                $radky[] = [
                    'what' => $u->title,
                    'kind' => 'lhůta',
                    'date' => CarbonImmutable::parse($u->due_at)->toDateString(),
                    'cost' => 0,
                    'note' => 'Termín prošel a nikdo ho nezavřel ani neposunul.',
                ];
            }
        }

        /*
         * Věc odložená do „až budeme mít čas" na víc než čtvrt roku.
         *
         * „Až budeme mít čas" není vlastní tabulka — je to úkol **bez termínu**
         * (`LATER_ITEMS` se čte odtamtud). Úkol s termínem už je o řádek výš
         * jako propadlá lhůta, takže se sem nedostane dvakrát.
         */
        if (Schema::hasTable('shared_todos')) {
            foreach (DB::table('shared_todos')
                ->where('gallery_space_id', $prostor->id)
                ->whereNull('due_at')
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->where('created_at', '<', $ted->subDays(90))
                ->orderBy('created_at')
                ->limit(20)
                ->get(['title', 'created_at']) as $v) {
                $dnu = (int) round(CarbonImmutable::parse($v->created_at)->diffInDays($ted));

                $radky[] = [
                    'what' => $v->title,
                    'kind' => 'mlčení',
                    'date' => CarbonImmutable::parse($v->created_at)->toDateString(),
                    'cost' => 0,
                    'note' => 'Leží v „až budeme mít čas“ '.$dnu.' dní. Nikdo neřekl ne, jen se nic nestalo.',
                ];
            }
        }

        usort($radky, fn (array $a, array $b) => $b['cost'] <=> $a['cost']);

        return array_slice($radky, 0, 24);
    }

    /** @return Collection<int, CoupleDisagreementPoint> */
    private function body(GallerySpace $prostor): Collection
    {
        return CoupleDisagreementPoint::where('gallery_space_id', $prostor->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * Témata, která se vracejí: `[{ topic, date, end, cost }]`.
     *
     * Jeden řádek na každé, kdy se to téma znovu objevilo v protokolu
     * nesouhlasu. Obrazovka z toho počítá, jak často se vrací a jak dlouho
     * mezi tím bývá.
     *
     * `end` je „dohoda" jen tehdy, když k tématu existuje **zapsané
     * rozhodnutí** — jinak „odloženo". `cost` zůstává nula: co ten odklad
     * stál, nikdo neměří, a vyčíslit ho odhadem by znamenalo poslat dvojici
     * účet za něco, co si nespočítala.
     *
     * @param  Collection<int, CoupleDisagreementPoint>  $body
     * @return list<array<string, mixed>>
     */
    private function vracejiciSeTemata(Collection $body, GallerySpace $prostor): array
    {
        $temata = $body->filter(fn (CoupleDisagreementPoint $b) => trim((string) $b->topic) !== '');

        if ($temata->isEmpty()) {
            return [];
        }

        $rozhodnuta = Schema::hasTable('couple_decisions')
            ? DB::table('couple_decisions')
                ->where('gallery_space_id', $prostor->id)
                ->pluck('title')
                ->map(fn ($t) => mb_strtolower(trim((string) $t)))
            : collect();

        return $temata
            ->map(function (CoupleDisagreementPoint $b) use ($rozhodnuta) {
                $tema = trim((string) $b->topic);

                return [
                    'topic' => $tema,
                    'date' => CarbonImmutable::parse($b->created_at)->toDateString(),
                    'end' => $rozhodnuta->contains(mb_strtolower($tema)) ? 'dohoda' : 'odloženo',
                    // Cenu odkladu nikdo neměří; nula je pravda, odhad by byl účet.
                    'cost' => 0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Protokol nesouhlasu z jedné strany: `{ text, tag, kind }`.
     *
     * @param  Collection<int, CoupleDisagreementPoint>  $body
     * @return list<array<string, mixed>>
     */
    private function protokol(Collection $body, ?int $ja, bool $moje): array
    {
        return $body
            ->filter(fn (CoupleDisagreementPoint $b) => $moje
                ? $b->author_user_id === $ja
                : $b->author_user_id !== $ja)
            ->map(fn (CoupleDisagreementPoint $b) => [
                'text' => $b->text,
                'tag' => (string) ($b->tag ?? ''),
                'kind' => $b->kind,
            ])
            ->values()
            ->all();
    }

    /**
     * Použitá veta: `{ who, text, date, reason }`.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function veta(GallerySpace $prostor, array $jmena): array
    {
        return CoupleVeto::where('gallery_space_id', $prostor->id)
            ->orderByDesc('used_on')
            ->get()
            ->map(fn (CoupleVeto $v) => [
                'who' => $jmena[$v->user_id] ?? '—',
                'text' => $v->text,
                'date' => $this->denCesky(CarbonImmutable::parse($v->used_on)),
                'reason' => (string) ($v->reason ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * Návrhy k vetu: `{ id, by, text, date, price, done }`.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function navrhy(GallerySpace $prostor, array $jmena): array
    {
        return CoupleVetoProposal::where('gallery_space_id', $prostor->id)
            ->orderByDesc('proposed_on')
            ->get()
            ->map(fn (CoupleVetoProposal $n) => array_filter([
                'id' => $n->uuid,
                'by' => $jmena[$n->proposed_by] ?? '—',
                'text' => $n->text,
                'date' => CarbonImmutable::parse($n->proposed_on)->day.'. '
                    .self::MESICE[CarbonImmutable::parse($n->proposed_on)->month],
                'price' => (int) $n->price,
                'done' => $n->outcome,
            ], fn ($v) => $v !== null))
            ->values()
            ->all();
    }

    /**
     * Sliby: `{ id, who, to, what, due, days, state, said }`.
     *
     * `days` se počítá **teď**, ne ukládá: „čtyři dny po termínu" platí jen ten
     * den, kdy se to čte. Stav `late` se ze stejného důvodu odvozuje z data —
     * uložený by po termínu pořád tvrdil, že slib platí.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function sliby(GallerySpace $prostor, array $jmena): array
    {
        if (! Schema::hasTable('couple_promises')) {
            return [];
        }

        $dnes = CarbonImmutable::now()->startOfDay();

        return CouplePromise::where('gallery_space_id', $prostor->id)
            // Zrušené po dohodě zůstávají v databázi, ale na obrazovku nepatří:
            // prototyp je z ní odebírá a nesmí se mu vrátit.
            ->where('state', '!=', 'released')
            ->orderByDesc('created_at')
            ->limit(60)
            ->get()
            ->map(function (CouplePromise $s) use ($jmena, $dnes) {
                $termin = $s->due_on ? CarbonImmutable::parse($s->due_on)->startOfDay() : null;
                $dni = $termin ? (int) $dnes->diffInDays($termin, false) : 99;
                $stav = $s->state === 'open' && $dni < 0 ? 'late' : $s->state;

                return [
                    'id' => $s->uuid,
                    'who' => $jmena[$s->promised_by] ?? '—',
                    'to' => $jmena[$s->promised_to] ?? '—',
                    'what' => $s->what,
                    'due' => $s->due_label ?: ($termin ? $termin->format('j. n.') : 'bez termínu'),
                    'days' => $dni,
                    'state' => $stav,
                    'said' => (string) ($s->said ?? 'zapsáno ručně'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Žádosti mezi partnery: `[id, od, komu, co, druh, kdy, stav, poznámka]`.
     *
     * Pole, ne objekt — prototyp je tak čte a rozebírá podle pořadí.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<int, mixed>>
     */
    private function zadosti(GallerySpace $prostor, array $jmena): array
    {
        return $this->nudge($prostor)
            ->map(fn (CoupleNudge $z) => [
                $z->uuid,
                $jmena[$z->asked_by] ?? '—',
                $jmena[$z->asked_of] ?? '—',
                $z->text,
                $z->kind,
                $this->kdy(CarbonImmutable::parse($z->created_at)),
                $z->state,
                (string) ($z->note ?? ''),
                // Kolikrát se to muselo připomenout — prototyp si z toho drží
                // počítadlo a posílá ho zpátky, když přibude další.
                $z->pripominky->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * Trpělivost: `{ task, who, rem, done }`.
     *
     * Není to vlastní seznam, ale **pohled na žádosti**: kdo co komu slíbil
     * obstarat a kolikrát se mu to muselo za poslední měsíc připomenout.
     * Připomínat je práce jako každá jiná — jen se za ni nikdy neděkuje.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<string, mixed>>
     */
    private function trpelivost(GallerySpace $prostor, array $jmena): array
    {
        $mesic = CarbonImmutable::now()->subMonth();

        return $this->nudge($prostor)
            // Odmítnutá žádost není nesplněný slib; a co převzalo pravidlo,
            // se nemá nikomu připomínat.
            ->reject(fn (CoupleNudge $z) => $z->state === 'odmitnuto' || $z->automated_at !== null)
            ->map(fn (CoupleNudge $z) => [
                'task' => $z->text,
                // Kdo to má na starost — připomínal ten druhý.
                'who' => $jmena[$z->asked_of] ?? '—',
                'rem' => $z->pripominky
                    ->filter(fn ($p) => CarbonImmutable::parse($p->created_at)->gte($mesic))
                    ->count(),
                'done' => $z->state === 'hotovo',
            ])
            ->filter(fn (array $r) => $r['rem'] > 0 || ! $r['done'])
            ->values()
            ->all();
    }

    /** @return Collection<int, CoupleNudge> */
    private function nudge(GallerySpace $prostor): Collection
    {
        if (! Schema::hasTable('couple_nudges')) {
            return collect();
        }

        return $this->zadostiCache ??= CoupleNudge::where('gallery_space_id', $prostor->id)
            ->with('pripominky')
            ->orderByDesc('created_at')
            ->limit(60)
            ->get();
    }

    // ——— formát ———

    /** „dnes 7:40", „včera 20:15", jinak datum. */
    private function kdy(CarbonImmutable $kdy): string
    {
        $dni = (int) $kdy->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay());

        return match (true) {
            $dni === 0 => 'dnes '.$kdy->format('G:i'),
            $dni === 1 => 'včera '.$kdy->format('G:i'),
            default => $kdy->format('j. n.'),
        };
    }

    private function denCesky(CarbonImmutable $den): string
    {
        return $den->day.'. '.self::MESICE[$den->month].' '.$den->year;
    }

    /**
     * Uložené nápady na randíčko: `[název, kdy a za kolik, štítek]`.
     *
     * Záložka „Uložené" brala řádky z `galerie-data.js` — tři nápady cizí
     * dvojice včetně cen. `couple_date_ideas` přitom v aplikaci je a plní ji
     * generátor návrhů i ruční zápis.
     *
     * @return list<array<int, ?string>>
     */
    private function randicka(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('couple_date_ideas')) {
            return [];
        }

        return DB::table('couple_date_ideas')
            ->where('gallery_space_id', $prostor->id)
            ->whereIn('status', ['saved', 'planned', 'done'])
            ->orderByDesc('created_at')
            ->limit(40)
            ->get(['title', 'status', 'estimated_cost', 'currency', 'estimated_minutes', 'created_at'])
            ->map(function (object $n) {
                $kdy = CarbonImmutable::parse($n->created_at);

                return [
                    (string) $n->title,
                    trim(implode(' · ', array_filter([
                        'uloženo '.$kdy->day.'. '.$kdy->month.'.',
                        $n->estimated_cost ? ((int) round((float) $n->estimated_cost)).' '.($n->currency ?: 'Kč') : null,
                        $n->estimated_minutes ? ((int) $n->estimated_minutes).' minut' : null,
                    ]))),
                    match ($n->status) {
                        'planned' => 'naplánováno',
                        'done' => 'bylo',
                        default => 'uloženo',
                    },
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Vygenerované návrhy, které dvojice ještě neuložila.
     *
     * Záložka „Vygenerované" kreslila tři napsané nápady („Slepá mapa — kam
     * ukáže prst") a tvářila se, že je aplikace právě vymyslela. Vznikají
     * přitom v `couple_date_ideas` při kliknutí na „Zamíchat návrh" a leží
     * tam se stavem, který ještě není `saved`.
     *
     * U každého se proto píše, **kdy vznikl**. To byla původní námitka proti
     * tomu je vůbec posílat — návrh z minulého týdne by se tvářil jako
     * čerstvý —, a datum ji řeší líp než tři vymyšlené řádky.
     *
     * @return list<array<int, ?string>>
     */
    private function vygenerovanaRandicka(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('couple_date_ideas')) {
            return [];
        }

        return DB::table('couple_date_ideas')
            ->where('gallery_space_id', $prostor->id)
            ->whereNotIn('status', ['saved', 'planned', 'done'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['title', 'theme', 'estimated_cost', 'currency', 'estimated_minutes', 'created_at'])
            ->map(function (object $n) {
                $kdy = CarbonImmutable::parse($n->created_at);
                $dnes = CarbonImmutable::now()->startOfDay();
                $dni = (int) $kdy->startOfDay()->diffInDays($dnes);

                return [
                    (string) $n->title,
                    trim(implode(' · ', array_filter([
                        match (true) {
                            $dni === 0 => 'vygenerováno dnes',
                            $dni === 1 => 'vygenerováno včera',
                            default => 'vygenerováno '.$kdy->day.'. '.$kdy->month.'.',
                        },
                        $n->theme ?: null,
                        $n->estimated_cost
                            ? ((int) round((float) $n->estimated_cost)).' '.($n->currency ?: 'Kč')
                            : null,
                        $n->estimated_minutes ? ((int) $n->estimated_minutes).' minut' : null,
                    ]))),
                    // „Nové" jen prvních čtyřiadvacet hodin. Návrh, na který
                    // se týden nesáhlo, není novinka.
                    $dni === 0 ? 'nové' : 'návrh',
                ];
            })
            ->values()
            ->all();
    }
}
