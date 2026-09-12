<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

/**
 * Týdenní přehled: `WEEK`.
 *
 * Obrazovka „Týdenní přehled" byla celá napsaná v kódu — „196 fotek, 11 z 16
 * úkolů, utraceno 6 840 Kč", Pustevny nad mlhou a pension v Sintře po termínu.
 * Tatáž čísla chodila v pondělním upozornění a ve sloupcích „fotky po dnech"
 * na úvodní obrazovce. Dvojice tak dostávala shrnutí cizího týdne.
 *
 * Tvar (prototyp si z něj skládá obrazovku sám):
 *
 *     now:  { title, lead, stats: [[popisek, hodnota, poznámka, ikona]],
 *             moments: [{ title, meta, note, tiles, photo }],
 *             slipped: [[co, poznámka, cesta]], days: [počet × 7], daysNote }
 *     next: { title, lead, rows: [[kdy, co, kdo, ikona]] }
 *     past: { head, note, rows: [{ title, photos, tasks, note, tiles }] }
 *
 * Týden začíná pondělím. Fotky se počítají podle dne pořízení — „kolik fotek
 * je z tohohle týdne", ne kdy je kdo nahrál.
 */
class Tyden implements PoskytovatelObsahu
{
    private const DNY = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];

    private const V_DEN = ['v neděli', 'v pondělí', 'v úterý', 've středu', 've čtvrtek', 'v pátek', 'v sobotu'];

    private const MESICE = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
        'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

    /** Kolik minulých týdnů ukazuje archiv. */
    private const MINULYCH = 4;

    /** @var array<int, bool> které snímky mají zmenšeninu */
    private array $nahledy = [];

    public function skupina(): string
    {
        return 'tyden';
    }

    /** Celý: ukázkový týden vedle skutečného nemá co dělat. */
    public function uplne(): array
    {
        return ['WEEK'];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('media_items')) {
            return [];
        }

        $jmena = System::jmenaClenu($prostor);
        $pondeli = CarbonImmutable::now()->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $od = $pondeli->subWeeks(self::MINULYCH);
        $fotky = $this->fotky($prostor, $od, $pondeli->addWeek());
        $ukoly = $this->ukoly($prostor, $od, $pondeli->addWeeks(2));

        return [
            'WEEK' => [
                'now' => $this->tentoTyden($prostor, $pondeli, $fotky, $ukoly, $jmena),
                'next' => $this->pristi($prostor, $pondeli->addWeek(), $ukoly, $jmena),
                'past' => $this->minule($pondeli, $fotky, $ukoly),
            ],
        ];
    }

    // ——— tento týden ———

    /**
     * @param  Collection<int, object>  $fotky
     * @param  Collection<int, object>  $ukoly
     * @param  array<int, string>  $jmena
     * @return array<string, mixed>
     */
    private function tentoTyden(GallerySpace $prostor, CarbonImmutable $pondeli, Collection $fotky, Collection $ukoly, array $jmena): array
    {
        $konec = $pondeli->addWeek();
        $dnes = CarbonImmutable::now();
        $tyden = $this->vTydnu($fotky, $pondeli);
        $minuly = $this->vTydnu($fotky, $pondeli->subWeek());

        $poDnech = array_fill(0, 7, 0);
        foreach ($tyden as $f) {
            $poDnech[$f->den->dayOfWeekIso - 1]++;
        }

        [$hotove, $celkem] = $this->ukolyTydne($ukoly, $pondeli);
        $poTerminu = $ukoly
            ->filter(fn ($u) => ! in_array($u->status, ['completed', 'cancelled'], true) && $u->due_at && CarbonImmutable::parse($u->due_at)->lt($dnes))
            ->sortByDesc('due_at');
        $zapisy = $this->zapisy($prostor, $pondeli, $konec, $jmena);
        $utraceno = $this->utraceno($prostor, $pondeli, $konec);

        $nejvic = max($poDnech);
        $nejsilnejsi = $nejvic > 0 ? array_search($nejvic, $poDnech, true) : null;

        return [
            'title' => 'Týden '.$this->rozsah($pondeli, $konec->subDay()),
            'lead' => $this->uvod($tyden->count(), $nejsilnejsi, $nejvic, $hotove, $celkem, $poTerminu->count()),
            'stats' => [
                ['Fotek z týdne', (string) $tyden->count(), $this->srovnani($tyden->count(), $minuly->count()), 'ph-image'],
                ['Zápisů v deníku', (string) array_sum($zapisy), $zapisy ? implode(', ', array_map(fn ($k, $n) => $k.' '.$n, array_keys($zapisy), $zapisy)) : 'zatím žádný', 'ph-notebook'],
                ['Úkolů hotových', $celkem ? $hotove.' z '.$celkem : '0', $celkem ? ($celkem - $hotove > 0 ? ($celkem - $hotove).' zbývá' : 'všechny hotové') : 'na tenhle týden nic', 'ph-check-square'],
                ['Utraceno', $utraceno[0], $utraceno[1], 'ph-receipt'],
            ],
            'moments' => $this->momenty($tyden, $dnes),
            'slipped' => $poTerminu->take(3)->map(fn ($u) => [
                (string) $u->title,
                'termín byl '.$this->kdyBylo(CarbonImmutable::parse($u->due_at), $dnes),
                'x-plan',
            ])->values()->all(),
            'days' => $poDnech,
            'daysNote' => $nejsilnejsi === null
                ? 'Z tohohle týdne zatím nejsou žádné fotky.'
                : 'Fotky po dnech — nejvíc '.self::V_DEN[($nejsilnejsi + 1) % 7].' ('.$nejvic.').',
        ];
    }

    private function uvod(int $fotek, ?int $den, int $nejvic, int $hotove, int $celkem, int $poTerminu): string
    {
        $vety = [];

        if ($den !== null) {
            $vety[] = 'Nejvíc fotek je '.self::V_DEN[($den + 1) % 7].' ('.$nejvic.' z '.$fotek.').';
        }

        if ($celkem) {
            $vety[] = 'Hotových úkolů '.$hotove.' z '.$celkem.'.';
        }

        if ($poTerminu) {
            $vety[] = $poTerminu === 1 ? 'Jeden úkol je po termínu.' : 'Po termínu jsou úkoly: '.$poTerminu.'.';
        }

        return $vety
            ? implode(' ', $vety)
            : 'Tenhle týden je zatím prázdný — fotky, úkoly a zápisy z deníku se sem propíšou samy.';
    }

    private function srovnani(int $ted, int $minule): string
    {
        if ($minule === 0) {
            return $ted ? 'minulý týden žádné' : 'ani minulý týden';
        }

        $rozdil = (int) round(($ted - $minule) / $minule * 100);

        return ($rozdil >= 0 ? '+' : '−').abs($rozdil).' % proti minulému týdnu';
    }

    /**
     * Tři nejbohatší dny týdne: `{ title, meta, note, tiles, photo }`.
     *
     * @param  Collection<int, object>  $tyden
     * @return list<array<string, mixed>>
     */
    private function momenty(Collection $tyden, CarbonImmutable $dnes): array
    {
        $dny = $tyden->groupBy(fn ($f) => $f->den->format('Y-m-d'))
            ->sortByDesc(fn (Collection $d) => $d->count())
            ->take(3);

        $this->zjistiNahledy($dny->flatMap(fn (Collection $d) => $d->take(4)->pluck('id'))->all());

        return $dny->map(function (Collection $den) use ($dnes) {
            $kdy = $den->first()->den;
            $misto = $den->pluck('location_name')->filter()->countBy()->sortDesc()->keys()->first();
            $popisek = $den->pluck('caption')->filter()->first();

            return [
                'title' => $misto ?: $this->velke(self::DNY[$kdy->dayOfWeek]).' '.$kdy->day.'. '.$kdy->month.'.',
                'meta' => ($kdy->isSameDay($dnes) ? 'dnes' : self::DNY[$kdy->dayOfWeek]).' · '.$this->pocetFotek($den->count()),
                'note' => (string) ($popisek ?? ''),
                'tiles' => $den->take(4)->map(fn ($f) => $this->nahled($f))->values()->all(),
                'photo' => (string) $den->first()->uuid,
            ];
        })->values()->all();
    }

    /**
     * Zápisy v deníku po autorech. Cizí soukromý zápis se nepočítá — jeho
     * existence by prozradila, že si druhý něco píše jen pro sebe.
     *
     * @param  array<int, string>  $jmena
     * @return array<string, int>
     */
    private function zapisy(GallerySpace $prostor, CarbonImmutable $od, CarbonImmutable $do, array $jmena): array
    {
        if (! Schema::hasTable('journal_entries')) {
            return [];
        }

        $ja = auth()->id();

        return DB::table('journal_entries')
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('deleted_at')
            ->where('entry_date', '>=', $od->toDateString())
            ->where('entry_date', '<', $do->toDateString())
            ->where(fn ($q) => $q->where('visibility', '!=', 'private')->orWhere('created_by', $ja))
            ->selectRaw('created_by, COUNT(*) AS n')
            ->groupBy('created_by')
            ->get()
            ->mapWithKeys(fn ($r) => [($jmena[(int) $r->created_by] ?? 'někdo') => (int) $r->n])
            ->all();
    }

    /**
     * Utraceno za týden: `[částka, poznámka]`.
     *
     * Výdaje v různých měnách se nesčítají — koruny s eury by daly číslo,
     * které neplatí ani v jedné. Ukáže se měna s nejvíc výdaji.
     *
     * @return array{0: string, 1: string}
     */
    private function utraceno(GallerySpace $prostor, CarbonImmutable $od, CarbonImmutable $do): array
    {
        if (! Schema::hasTable('transactions')) {
            return ['—', 'finance nejsou založené'];
        }

        $poMenach = DB::table('transactions')
            ->where('gallery_space_id', $prostor->id)
            ->where('type', 'expense')
            ->whereNull('deleted_at')
            ->whereNotIn('state', ['draft', 'rejected'])
            ->where('occurred_at', '>=', $od->toDateString())
            ->where('occurred_at', '<', $do->toDateString())
            ->selectRaw('COALESCE(currency_from, ?) AS mena, SUM(amount_from) AS soucet, COUNT(*) AS n', ['CZK'])
            ->groupBy('mena')
            ->get()
            ->sortByDesc('n');

        if ($poMenach->isEmpty()) {
            return ['0', 'žádný zapsaný výdaj'];
        }

        $hlavni = $poMenach->first();
        $castka = number_format((float) $hlavni->soucet, 0, ',', "\u{00A0}").' '.$this->znak((string) $hlavni->mena);

        return [$castka, $poMenach->count() > 1 ? 'jen výdaje v '.$hlavni->mena.', další jsou v jiné měně' : $this->pocet((int) $hlavni->n, 'výdaj', 'výdaje', 'výdajů')];
    }

    // ——— příští týden ———

    /**
     * @param  Collection<int, object>  $ukoly
     * @param  array<int, string>  $jmena
     * @return array<string, mixed>
     */
    private function pristi(GallerySpace $prostor, CarbonImmutable $pondeli, Collection $ukoly, array $jmena): array
    {
        $konec = $pondeli->addWeek();
        $radky = collect();

        if (Schema::hasTable('calendar_events')) {
            $ucastnici = DB::table('event_participants as u')
                ->join('calendar_events as e', 'e.id', '=', 'u.event_id')
                ->where('e.gallery_space_id', $prostor->id)
                ->whereBetween('e.starts_at', [$pondeli, $konec])
                ->get(['u.event_id', 'u.user_id'])
                ->groupBy('event_id');

            DB::table('calendar_events')
                ->where('gallery_space_id', $prostor->id)
                ->where('is_private', false)
                ->where('starts_at', '>=', $pondeli)
                ->where('starts_at', '<', $konec)
                ->orderBy('starts_at')
                ->get(['id', 'title', 'type', 'starts_at', 'created_by'])
                ->each(function ($e) use ($radky, $ucastnici, $jmena) {
                    $lide = ($ucastnici[$e->id] ?? collect())->pluck('user_id')->all() ?: [$e->created_by];
                    $radky->push([
                        CarbonImmutable::parse($e->starts_at),
                        (string) $e->title,
                        count($lide) > 1 ? 'oba' : ($jmena[(int) ($lide[0] ?? 0)] ?? 'oba'),
                        $this->ikonaUdalosti((string) $e->type),
                    ]);
                });
        }

        $ukoly
            ->filter(fn ($u) => ! in_array($u->status, ['completed', 'cancelled'], true) && $u->due_at
                && CarbonImmutable::parse($u->due_at)->betweenIncluded($pondeli, $konec->subSecond()))
            ->each(fn ($u) => $radky->push([
                CarbonImmutable::parse($u->due_at),
                (string) $u->title,
                $u->assigned_to ? ($jmena[(int) $u->assigned_to] ?? 'oba') : 'oba',
                'ph-check-square',
            ]));

        $radky = $radky->sortBy(fn (array $r) => $r[0]->getTimestamp())->values();

        return [
            'title' => 'Týden '.$this->rozsah($pondeli, $konec->subDay()),
            'lead' => $radky->isEmpty()
                ? 'Příští týden je zatím volný — nic v kalendáři ani úkoly s termínem.'
                : 'V kalendáři a úkolech je '.$this->pocet($radky->count(), 'věc', 'věci', 'věcí').'.',
            'rows' => $radky->take(20)->map(fn (array $r) => [
                $this->velke(self::DNY[$r[0]->dayOfWeek]).' '.$r[0]->day.'. '.$r[0]->month.'.',
                $r[1],
                $r[2],
                $r[3],
            ])->all(),
        ];
    }

    private function ikonaUdalosti(string $typ): string
    {
        return match ($typ) {
            'trip' => 'ph-airplane-tilt',
            'outing' => 'ph-film-slate',
            'birthday', 'anniversary' => 'ph-cake',
            'reservation' => 'ph-credit-card',
            default => 'ph-calendar-dot',
        };
    }

    // ——— minulé týdny ———

    /**
     * @param  Collection<int, object>  $fotky
     * @param  Collection<int, object>  $ukoly
     * @return array<string, mixed>
     */
    private function minule(CarbonImmutable $pondeli, Collection $fotky, Collection $ukoly): array
    {
        $tydny = [];

        for ($i = 1; $i <= self::MINULYCH; $i++) {
            $zacatek = $pondeli->subWeeks($i);
            $tyden = $this->vTydnu($fotky, $zacatek);
            [$hotove, $celkem] = $this->ukolyTydne($ukoly, $zacatek);
            $mista = $tyden->pluck('location_name')->filter()->countBy()->sortDesc()->keys()->take(2)->all();

            $this->zjistiNahledy($tyden->take(5)->pluck('id')->all());

            $tydny[] = [
                'title' => 'Týden '.$this->rozsah($zacatek, $zacatek->addDays(6)),
                'photos' => $tyden->count(),
                'tasks' => $celkem ? $hotove.' z '.$celkem.' úkolů' : 'bez úkolů',
                'note' => $mista ? 'Nejvíc fotek: '.implode(', ', $mista) : ($tyden->count() ? '' : 'Žádné fotky.'),
                'tiles' => $tyden->take(5)->map(fn ($f) => $this->nahled($f))->values()->all(),
            ];
        }

        $sFotkami = array_filter($tydny, fn (array $t) => $t['photos'] > 0);
        $poznamka = 'Zatím žádný z minulých týdnů nemá fotky.';

        if ($sFotkami) {
            usort($sFotkami, fn (array $a, array $b) => $b['photos'] <=> $a['photos']);
            $nej = $sFotkami[0];
            $poznamka = 'Nejvíc fotek měl '.mb_strtolower($nej['title']).' ('.$nej['photos'].').';
        }

        return [
            'head' => 'Poslední '.($this->pocet(self::MINULYCH, 'týden', 'týdny', 'týdnů')).' · počítá se z knihovny, úkolů a deníku',
            'note' => $poznamka,
            'rows' => $tydny,
        ];
    }

    // ——— data ———

    /**
     * Fotky za celé období naráz — pět týdnů jedním dotazem, ne pěti.
     *
     * @return Collection<int, object>
     */
    private function fotky(GallerySpace $prostor, CarbonImmutable $od, CarbonImmutable $do): Collection
    {
        return DB::table('media_items')
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->whereNull('deleted_at')
            ->where('is_hidden', false)
            ->whereRaw('COALESCE(taken_at, uploaded_at, created_at) >= ?', [$od->toDateTimeString()])
            ->whereRaw('COALESCE(taken_at, uploaded_at, created_at) < ?', [$do->toDateTimeString()])
            ->orderByRaw('COALESCE(taken_at, uploaded_at, created_at)')
            ->get(['id', 'uuid', 'taken_at', 'uploaded_at', 'created_at', 'location_name', 'caption'])
            ->map(function ($f) {
                $f->den = CarbonImmutable::parse($f->taken_at ?? $f->uploaded_at ?? $f->created_at);

                return $f;
            });
    }

    /**
     * Úkoly, které mají v období termín nebo v něm byly hotové.
     *
     * @return Collection<int, object>
     */
    private function ukoly(GallerySpace $prostor, CarbonImmutable $od, CarbonImmutable $do): Collection
    {
        if (! Schema::hasTable('shared_todos')) {
            return collect();
        }

        return DB::table('shared_todos')
            ->where('gallery_space_id', $prostor->id)
            ->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q
                ->whereBetween('due_at', [$od, $do])
                ->orWhereBetween('completed_at', [$od, $do])
                // Po termínu může být i úkol starší než pět týdnů.
                ->orWhere(fn ($s) => $s->whereNotNull('due_at')->where('due_at', '<', $od)->where('status', '!=', 'completed')))
            ->get(['id', 'title', 'status', 'due_at', 'completed_at', 'assigned_to']);
    }

    /**
     * Hotové a všechny úkoly týdne: co mělo termín v týdnu, nebo se v týdnu dodělalo.
     *
     * @param  Collection<int, object>  $ukoly
     * @return array{0: int, 1: int}
     */
    private function ukolyTydne(Collection $ukoly, CarbonImmutable $pondeli): array
    {
        $konec = $pondeli->addWeek();
        $v = fn (?string $kdy) => $kdy !== null && CarbonImmutable::parse($kdy)->gte($pondeli) && CarbonImmutable::parse($kdy)->lt($konec);

        $tydne = $ukoly->filter(fn ($u) => $v($u->due_at) || ($u->status === 'completed' && $v($u->completed_at)));

        return [
            $tydne->filter(fn ($u) => $u->status === 'completed' && $v($u->completed_at))->count(),
            $tydne->count(),
        ];
    }

    /**
     * @param  Collection<int, object>  $fotky
     * @return Collection<int, object>
     */
    private function vTydnu(Collection $fotky, CarbonImmutable $pondeli): Collection
    {
        $konec = $pondeli->addWeek();

        return $fotky->filter(fn ($f) => $f->den->gte($pondeli) && $f->den->lt($konec))->values();
    }

    // ——— formát ———

    private function rozsah(CarbonImmutable $od, CarbonImmutable $do): string
    {
        if ($od->month === $do->month) {
            return $od->day.'. – '.$do->day.'. '.self::MESICE[$do->month].' '.$do->year;
        }

        return $od->day.'. '.self::MESICE[$od->month].' – '.$do->day.'. '.self::MESICE[$do->month].' '.$do->year;
    }

    private function kdyBylo(CarbonImmutable $kdy, CarbonImmutable $dnes): string
    {
        $dni = (int) $kdy->startOfDay()->diffInDays($dnes->startOfDay());

        return match (true) {
            $dni === 0 => 'dnes',
            $dni === 1 => 'včera',
            $dni < 7 => self::V_DEN[$kdy->dayOfWeek],
            default => $kdy->day.'. '.$kdy->month.'.',
        };
    }

    /** `ucfirst` po bajtech: z „úterý" dělá rozbité „\xC3terý". */
    private function velke(string $slovo): string
    {
        return mb_strtoupper(mb_substr($slovo, 0, 1)).mb_substr($slovo, 1);
    }

    private function pocetFotek(int $n): string
    {
        return $this->pocet($n, 'fotka', 'fotky', 'fotek');
    }

    private function pocet(int $n, string $jedna, string $dve, string $pet): string
    {
        return $n.' '.($n === 1 ? $jedna : ($n >= 2 && $n <= 4 ? $dve : $pet));
    }

    private function znak(string $mena): string
    {
        return match (strtoupper($mena)) {
            'CZK' => 'Kč',
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            default => strtoupper($mena),
        };
    }

    /**
     * Náhled snímku jako pozadí dlaždice — týž tvar, jaký posílá knihovna.
     */
    private function nahled(object $f): string
    {
        $n = (int) $f->id;

        if (! ($this->nahledy[$n] ?? false)) {
            // Bez zmenšeniny týž přechod, jaký kreslí knihovna — dlaždice nezůstane prázdná.
            $uhel = 130 + ($n % 5) * 12;
            $odstin = ($n * 47 + 12) % 360;
            $sytost = 16 + ($n % 5) * 5;

            return 'linear-gradient('.$uhel.'deg, hsl('.$odstin.' '.$sytost.'% 58%), hsl('.(($odstin + 34) % 360).' '.($sytost + 6).'% 30%))';
        }

        $adresa = URL::temporarySignedRoute('galerie.media.thumb', CarbonImmutable::tomorrow()->endOfDay(), ['uuid' => $f->uuid]);

        return "url('".$adresa."') center/cover no-repeat #2b2842";
    }

    /** @param  list<int|string>  $id */
    private function zjistiNahledy(array $id): void
    {
        $id = array_values(array_diff(array_map('intval', $id), array_keys($this->nahledy)));

        if (! $id || ! Schema::hasTable('media_variants')) {
            return;
        }

        $maji = DB::table('media_variants')
            ->whereIn('media_item_id', $id)
            ->whereIn('type', ['thumbnail', 'small', 'video_poster', 'original'])
            ->distinct()
            ->pluck('media_item_id')
            ->map(fn ($i) => (int) $i)
            ->all();

        foreach ($id as $jeden) {
            $this->nahledy[$jeden] = in_array($jeden, $maji, true);
        }
    }
}
