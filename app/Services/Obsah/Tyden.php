<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Services\Finance\ExchangeRateService;
use App\Services\Finance\SouctyPoMenach;
use App\Support\Cas;
use App\Support\Meny;
use App\Support\Tabulky;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
 *             slipped: [[co, poznámka, cesta, uuid úkolu]], days: [počet × 7], daysNote }
 *     next: { title, lead, rows: [[kdy, co, kdo, ikona]] }
 *     past: { head, note, rows: [{ title, photos, tasks, note, tiles, photo }] }
 *
 * Týden začíná pondělím. Fotky se počítají podle dne pořízení — „kolik fotek
 * je z tohohle týdne", ne kdy je kdo nahrál.
 */
class Tyden implements MaPrazdneKolekce, PoskytovatelObsahu
{
    private const DNY = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];

    private const V_DEN = ['v neděli', 'v pondělí', 'v úterý', 've středu', 've čtvrtek', 'v pátek', 'v sobotu'];

    private const MESICE = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
        'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

    /** Kolik minulých týdnů ukazuje archiv. */
    private const MINULYCH = 4;

    /** @var array<int, bool> které snímky mají zmenšeninu */
    private array $nahledy = [];

    // Útrata týdne a roční finance sčítají přes měny v hlavní měně kurzem ECB.
    public function __construct(private readonly ExchangeRateService $kurzy) {}

    public function skupina(): string
    {
        return 'tyden';
    }

    /** Celý: ukázkový týden vedle skutečného nemá co dělat. */
    public function uplne(): array
    {
        return ['WEEK', 'ROKVCISLECH', 'VYROCNI', 'SVET'];
    }

    /**
     * Prázdný týden — ne cizí.
     *
     * `uplne()` samo o sobě nestačí: kontroler smaže z úplných klíčů ty, které
     * v datech nejsou, takže když `kolekce()` vrátí `[]` (galerie bez tabulky
     * médií, výjimka v dotazu), zůstala dvojici na obrazovce ukázka —
     * „196 fotek, 11 z 16 úkolů, utraceno 6 840 Kč" a Chorvatsko 2026.
     * Teď se místo toho pošle prázdný tvar a obrazovka řekne pravdu.
     *
     * @return array<string, mixed>
     */
    public function prazdne(): array
    {
        return [
            'WEEK' => [
                'now' => [
                    'title' => 'Tenhle týden',
                    'lead' => 'Z tohohle týdne zatím nic není — ani fotka, ani úkol, ani zápis.',
                    'stats' => [],
                    'moments' => [],
                    'slipped' => [],
                    'days' => array_fill(0, 7, 0),
                    'daysNote' => 'Z tohohle týdne zatím nejsou žádné fotky.',
                ],
                'next' => [
                    'title' => 'Příští týden',
                    'lead' => 'Příští týden je zatím volný — nic v kalendáři ani úkoly s termínem.',
                    'rows' => [],
                ],
                'past' => [
                    'head' => 'Poslední '.$this->pocet(self::MINULYCH, 'týden', 'týdny', 'týdnů'),
                    'note' => 'Zatím žádný z minulých týdnů nemá fotky.',
                    'rows' => [],
                ],
            ],
            'ROKVCISLECH' => new \stdClass,
            'VYROCNI' => ['den' => '', 'rows' => []],
            'SVET' => ['zeme' => [], 'chceme' => []],
        ];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        if (! Tabulky::je('media_items')) {
            return [];
        }

        $jmena = System::jmenaClenu($prostor);

        /*
         * Týden začíná podle nástěnných hodin dvojice, ne podle serveru.
         *
         * `now()` je UTC; v pondělí v 01:30 pražského času je v Londýně ještě
         * neděle, takže `startOfWeek()` vrátil pondělí **o týden zpět**. Fotky,
         * úkoly i útraty minulého týdne se pak ukazovaly pod nadpisem tohohle
         * a „Rok v číslech" si první hodinu nového roku myslel, že je pořád
         * loni.
         */
        $pondeli = Cas::dnes()->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $od = $pondeli->subWeeks(self::MINULYCH);
        $fotky = $this->fotky($prostor, $od, $pondeli->addWeek());
        $ukoly = $this->ukoly($prostor, $od, $pondeli->addWeeks(2));

        $rok = Cas::ted()->year;

        return [
            'WEEK' => [
                'now' => $this->tentoTyden($prostor, $pondeli, $fotky, $ukoly, $jmena),
                'next' => $this->pristi($prostor, $pondeli->addWeek(), $ukoly, $jmena),
                'past' => $this->minule($pondeli, $fotky, $ukoly),
            ],
            // Kapitoly knihy „Rok v číslech" za letošek a loňsko.
            'ROKVCISLECH' => [
                (string) ($rok - 1) => $this->rokVCislech($prostor, $rok - 1, $jmena),
                (string) $rok => $this->rokVCislech($prostor, $rok, $jmena),
            ],
            'VYROCNI' => $this->vyrocniAlbum($prostor),
            'SVET' => $this->svet($prostor),
        ];
    }

    /**
     * Výroční album: jedna řada fotek za každý rok ke dni výročí.
     *
     * Obrazovka měla jedenáct let od 2016 s napsanými místy (Ostrava, Zadar,
     * Brač) a počty „6 + i·5 fotek" — pro každou dvojici stejné. Den výročí je
     * první společný milník; bez něj album nemá podle čeho vzniknout.
     *
     * @return array<string, mixed>
     */
    private function vyrocniAlbum(GallerySpace $prostor): array
    {
        $od = Tabulky::je('relationship_milestones')
            ? DB::table('relationship_milestones')->where('gallery_space_id', $prostor->id)->where('visibility', '!=', 'private')->min('occurred_on')
            : null;

        if (! $od) {
            return ['den' => '', 'rows' => []];
        }

        $zacatek = CarbonImmutable::parse($od);
        $dnes = Cas::dnes();
        $radky = [];

        for ($rok = $zacatek->year; $rok <= $dnes->year; $rok++) {
            $den = CarbonImmutable::create($rok, $zacatek->month, min($zacatek->day, CarbonImmutable::create($rok, $zacatek->month)->daysInMonth));

            // Dva dny kolem výročí: oslava se často fotí den předem nebo potom.
            $fotky = DB::table('media_items')->where('gallery_space_id', $prostor->id)->whereNull('trashed_at')->whereNull('deleted_at')->where('is_hidden', false)
                ->whereBetween('taken_at', [$den->subDays(2)->startOfDay(), $den->addDays(2)->endOfDay()])
                ->orderBy('taken_at')->get(['id', 'uuid', 'location_name']);

            if ($fotky->isEmpty()) {
                continue;
            }

            $this->zjistiNahledy($fotky->take(5)->pluck('id')->all());

            $radky[] = [
                'year' => (string) $rok,
                'count' => $fotky->count(),
                'place' => (string) ($fotky->pluck('location_name')->filter()->countBy()->sortDesc()->keys()->first() ?? ''),
                'fotky' => $fotky->take(5)->map(fn ($f) => ['id' => (string) $f->uuid, 'bg' => $this->nahled($f)])->values()->all(),
            ];
        }

        return ['den' => $zacatek->day.'. '.self::MESICE[$zacatek->month], 'rows' => $radky];
    }

    /**
     * Světový itinerář: kde jsme byli (podle GPS a míst ve fotkách) a kam chceme.
     *
     * Obrazovka měla osm zemí napsaných v kódu (Chorvatsko 2026: Zadar, Krka,
     * Brač…) a pět přání (Lofoty, Japonsko, Island). Země jsou z fotek, přání
     * z míst označených „chceme" a z naplánovaných cest.
     *
     * @return array<string, list<array<int, mixed>>>
     */
    private function svet(GallerySpace $prostor): array
    {
        /*
         * Kód země je na albech, ne na fotkách.
         *
         * `media_items` má `location_name`, `location_country` a `location_source` —
         * `location_country_code` je sloupec alba. Dotaz si o něj přesto říkal:
         * SQLite z neznámého jména tiše udělá řetězec, ale MySQL na produkci vrátí
         * `Unknown column`, celá skupina spadne do prázdna a prototyp dokreslí
         * ukázkové Chorvatsko. Kód si proto bereme z alb dvojice a když ho nemají,
         * zůstane prázdný — vlajka se nenakreslí, ale čísla sedí.
         */
        $kody = Tabulky::sloupec('albums', 'location_country_code')
            ? DB::table('albums')->where('gallery_space_id', $prostor->id)
                ->whereNotNull('location_country')->whereNotNull('location_country_code')
                ->pluck('location_country_code', 'location_country')
            : collect();

        /*
         * Bez trezoru: země ze skryté fotky by prozradila, kde vznikla.
         *
         * Sčítá databáze. Dřív se kvůli pár řádkům zemí natáhla při každém
         * načtení týdne celá knihovna s polohou.
         */
        $fotkyZemi = fn () => DB::table('media_items')->where('gallery_space_id', $prostor->id)->whereNull('trashed_at')->whereNull('deleted_at')
            ->where('is_hidden', false)
            ->whereNotNull('location_country')->where('location_country', '!=', '');

        $mista = $fotkyZemi()
            ->whereNotNull('location_name')->where('location_name', '!=', '')
            ->selectRaw('location_country, location_name, COUNT(*) AS pocet')
            ->groupBy('location_country', 'location_name')
            ->get()
            ->groupBy('location_country');

        $zeme = $fotkyZemi()
            ->selectRaw('location_country, COUNT(*) AS pocet, MIN(taken_at) AS prvni_porizena, MIN(uploaded_at) AS prvni_nahrana')
            ->groupBy('location_country')
            ->get()
            ->map(fn (object $z) => [
                (string) $z->location_country,
                (string) ($kody[$z->location_country] ?? ''),
                $this->prvniRok($z->prvni_porizena, $z->prvni_nahrana),
                (int) $z->pocet,
                ($mista[$z->location_country] ?? collect())
                    ->sortBy([['pocet', 'desc'], ['location_name', 'asc']])
                    ->pluck('location_name')->take(5)->values()->all(),
            ])
            ->sortBy([[3, 'desc'], [0, 'asc']])
            ->values()
            ->all();

        $chceme = [];

        if (Tabulky::sloupec('places', 'lifecycle_status')) {
            DB::table('places')->where('gallery_space_id', $prostor->id)->whereIn('lifecycle_status', ['idea', 'planned'])
                ->orderBy('name')->limit(30)->get(['name', 'city', 'country', 'lifecycle_status'])
                ->each(function ($m) use (&$chceme) {
                    $chceme[] = [(string) $m->name, 'ph-map-pin', trim(implode(', ', array_filter([$m->city, $m->country]))), $m->lifecycle_status === 'planned' ? 'plánujeme' : 'někdy'];
                });
        }

        if (Tabulky::je('trips')) {
            DB::table('trips')->where('gallery_space_id', $prostor->id)->whereDate('start_date', '>', Cas::dnes()->toDateString())
                ->orderBy('start_date')->limit(10)->get(['name', 'start_date'])
                ->each(function ($c) use (&$chceme) {
                    $chceme[] = [(string) $c->name, 'ph-airplane-tilt', 'cesta od '.CarbonImmutable::parse($c->start_date)->format('j. n. Y'), 'naplánováno'];
                });
        }

        return ['zeme' => $zeme, 'chceme' => $chceme];
    }

    /**
     * Rok první fotky ze země — podle pořízení, ne podle nahrání.
     *
     * `taken_at ?? uploaded_at` znamenalo, že jediná fotka bez data z EXIF
     * (naskenovaná, přeposlaná) přepsala „poprvé 2018" na rok, kdy se nahrála.
     * Datum nahrání se bere až tehdy, když v celé zemi není ani jedna fotka
     * s časem pořízení.
     *
     * Dostává rovnou `MIN(taken_at)` a `MIN(uploaded_at)` z databáze — `MIN`
     * prázdné hodnoty přeskočí, takže pravidlo zůstává stejné.
     */
    private function prvniRok(mixed $prvniPorizena, mixed $prvniNahrana): int
    {
        if ($prvniPorizena !== null && $prvniPorizena !== '') {
            return (int) CarbonImmutable::parse($prvniPorizena)->year;
        }

        if ($prvniNahrana !== null && $prvniNahrana !== '') {
            return (int) CarbonImmutable::parse($prvniNahrana)->year;
        }

        return Cas::ted()->year;
    }

    /**
     * Kapitoly „Rok v číslech": `[klíč, název, stran, poznámka, [[popisek, hodnota, doplněk]]]`.
     *
     * Kniha se skládala z napsaných čísel („4 218 fotek, sedm výjezdů, fond na
     * Island") a obrazovka navíc spadla, jakmile knihovna poslala vlastní seznam
     * roků pod stejným klíčem. Kapitola, pro kterou v daném roce nic není,
     * se vynechá — tisknout dvoustranu s nulami nemá smysl.
     *
     * @param  array<int, string>  $jmena
     * @return list<array<int, mixed>>
     */
    private function rokVCislech(GallerySpace $prostor, int $rok, array $jmena): array
    {
        $od = CarbonImmutable::create($rok)->startOfYear();
        $do = $od->endOfYear();
        $kapitoly = [];

        /*
         * Týž počet jako v knihovně — fotky v trezoru se nepočítají.
         *
         * Součty dělá databáze: dřív se každým načtením týdne natáhly dva
         * celé roky knihovny, jen aby se spočítaly. Měsíc je `SUBSTR(DATE(…))`
         * — platí v MySQL i SQLite (na rozdíl od `strftime`).
         */
        $rokMedii = fn () => DB::table('media_items')->where('gallery_space_id', $prostor->id)->whereNull('trashed_at')->whereNull('deleted_at')
            ->where('is_hidden', false)
            ->whereRaw('COALESCE(taken_at, uploaded_at, created_at) BETWEEN ? AND ?', [$od->toDateTimeString(), $do->toDateTimeString()]);

        $souhrn = $rokMedii()
            ->selectRaw("COUNT(*) AS celkem, SUM(CASE WHEN media_type = 'video' THEN 1 ELSE 0 END) AS videi, SUM(CASE WHEN media_type = 'video' THEN COALESCE(duration_ms, 0) ELSE 0 END) AS ms")
            ->first();

        if ((int) ($souhrn->celkem ?? 0) > 0) {
            $videi = (int) $souhrn->videi;
            $minut = (int) round((float) $souhrn->ms / 60000);
            // Nejplodnější měsíc; při shodě ten dřívější.
            $mesic = $rokMedii()
                ->selectRaw('SUBSTR(DATE(COALESCE(taken_at, uploaded_at, created_at)), 6, 2) AS mesic, COUNT(*) AS pocet')
                ->groupBy('mesic')
                ->orderByDesc('pocet')
                ->orderBy('mesic')
                ->first();
            $alb = DB::table('albums')->where('gallery_space_id', $prostor->id)->whereNull('deleted_at')->whereBetween('created_at', [$od, $do])->count();

            $kapitoly[] = ['fotky', 'Fotky a videa', 4, 'Kolik jsme toho nafotili.', array_values(array_filter([
                ['Fotek', $this->cislo((int) $souhrn->celkem - $videi), 'za rok '.$rok],
                $videi ? ['Videí', $this->cislo($videi), intdiv($minut, 60).' h '.($minut % 60).' min záznamu'] : null,
                ['Albumů', $this->cislo($alb), 'založených v roce '.$rok],
                ['Nejplodnější měsíc', self::MESICE_1[(int) $mesic->mesic], $this->cislo((int) $mesic->pocet).' snímků'],
            ]))];
        }

        if (Tabulky::je('trips')) {
            $cesty = DB::table('trips')->where('gallery_space_id', $prostor->id)
                ->whereDate('start_date', '<=', $do->toDateString())->whereDate('end_date', '>=', $od->toDateString())
                ->get(['name', 'start_date', 'end_date']);

            if ($cesty->isNotEmpty()) {
                $delky = $cesty->mapWithKeys(fn ($c) => [$c->name => (int) CarbonImmutable::parse($c->start_date)->diffInDays(CarbonImmutable::parse($c->end_date)) + 1]);
                $nejdelsi = $delky->sortDesc()->keys()->first();

                $kapitoly[] = ['cesty', 'Cesty', 4, 'Kam jsme vyrazili.', [
                    ['Cest', (string) $cesty->count(), $this->pocet($delky->sum(), 'den', 'dny', 'dní').' na cestách'],
                    ['Nejdelší cesta', $this->pocet($delky[$nejdelsi], 'den', 'dny', 'dní'), $nejdelsi],
                ]];
            }
        }

        if (Tabulky::je('shared_todos')) {
            $hotove = DB::table('shared_todos')->where('gallery_space_id', $prostor->id)->where('status', 'completed')
                ->whereBetween('completed_at', [$od, $do])->get(['completed_by']);

            if ($hotove->isNotEmpty()) {
                $podil = $hotove->countBy('completed_by')->sortDesc();
                $kdo = $podil->map(fn ($n, $id) => (int) round($n / $hotove->count() * 100).' % '.($jmena[(int) $id] ?? 'bez jména'))->take(2)->implode(', ');

                $kapitoly[] = ['domov', 'Domácnost', 2, 'Kdo co odškrtl — bez komentáře.', [
                    ['Úkolů hotových', $this->cislo($hotove->count()), $kdo],
                ]];
            }
        }

        if (Tabulky::je('transactions')) {
            /*
             * Po měnách, jen zapsané pohyby.
             *
             * Eura a koruny se sčítaly dohromady a psaly jako „Kč"; a
             * `NOT IN (draft, rejected)` bralo i čekající (`pending`), které
             * kniha (`Transaction::ZAPSANE`) ještě nepočítá. Pak se ukazovala
             * jen měna s nejvíc pohyby. Teď se měny přepočtou do hlavní kurzem
             * ECB (`financeRoku()`), a bez kurzu se ukáže jen hlavní měna.
             */
            $poMenach = DB::table('transactions')->where('gallery_space_id', $prostor->id)->whereNull('deleted_at')
                ->whereIn('state', Transaction::ZAPSANE)->whereIn('type', Transaction::VYSLEDKOVE)
                ->whereBetween('occurred_at', [$od->toDateString(), $do->toDateString()])
                ->selectRaw('type, COALESCE(currency_from, currency_to, ?) AS mena, SUM(ABS(COALESCE(amount_from, amount_to))) AS castka, COUNT(*) AS n', [Meny::hlavni($prostor)])
                ->groupBy('type', 'mena')
                ->get();

            if ($poMenach->isNotEmpty()) {
                $kapitoly[] = ['finance', 'Finance', 3, 'Roční souhrn bez detailů transakcí.', $this->financeRoku($prostor, $poMenach)];
            }
        }

        if (Tabulky::je('recipe_cooking_sessions')) {
            $vareni = DB::table('recipe_cooking_sessions as v')->join('recipes as r', 'r.id', '=', 'v.recipe_id')
                ->where('r.gallery_space_id', $prostor->id)->whereBetween('v.cooked_at', [$od, $do])->count();

            if ($vareni) {
                $kapitoly[] = ['kuchyne', 'Kuchyně', 2, 'Co jsme uvařili.', [['Vaření', $this->cislo($vareni), 'zapsaných z kuchařky']]];
            }
        }

        if (Tabulky::je('watch_titles')) {
            $tituly = DB::table('watch_titles')->where('gallery_space_id', $prostor->id)->where('status', 'seen')
                ->whereBetween('updated_at', [$od, $do])->get(['kind']);

            if ($tituly->isNotEmpty()) {
                $kapitoly[] = ['kultura', 'Filmy a seriály', 2, 'Večery u projektoru.', array_values(array_filter([
                    ['Filmů', (string) $tituly->where('kind', 'film')->count(), 'viděných'],
                    $tituly->where('kind', '!=', 'film')->count() ? ['Seriálů', (string) $tituly->where('kind', '!=', 'film')->count(), 'viděných'] : null,
                ]))];
            }
        }

        if (Tabulky::je('journal_entries')) {
            $ja = auth()->id();
            $zapisu = DB::table('journal_entries')->where('gallery_space_id', $prostor->id)->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('visibility', '!=', 'private')->orWhere('created_by', $ja))
                ->whereBetween('entry_date', [$od->toDateString(), $do->toDateString()])->count();
            $hlasovky = Tabulky::je('voice_notes')
                ? DB::table('voice_notes')->where('gallery_space_id', $prostor->id)->whereBetween('created_at', [$od, $do])->get(['duration_ms'])
                : collect();

            if ($zapisu || $hlasovky->isNotEmpty()) {
                $minut = (int) round($hlasovky->sum('duration_ms') / 60000);
                $kapitoly[] = ['denik', 'Deník a hlasovky', 3, 'Vlastními slovy.', [
                    ['Zápisů', $this->cislo($zapisu), 'v deníku'],
                    ['Hlasovek', $this->cislo($hlasovky->count()), intdiv($minut, 60).' h '.($minut % 60).' min mluvení'],
                ]];
            }
        }

        if (Tabulky::je('relationship_milestones')) {
            $milniky = DB::table('relationship_milestones')->where('gallery_space_id', $prostor->id)->where('visibility', '!=', 'private')
                ->whereBetween('occurred_on', [$od->toDateString(), $do->toDateString()])->count();

            if ($milniky) {
                $kapitoly[] = ['milniky', 'Milníky', 2, 'Osa roku na jedné dvoustraně.', [['Milníků', (string) $milniky, 'zapsaných v roce '.$rok]]];
            }
        }

        return $kapitoly;
    }

    private const MESICE_1 = [1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
        'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];

    private function cislo(int $n): string
    {
        return number_format($n, 0, ',', "\u{00A0}");
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
        $dnes = Cas::dnes();
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
                // Klíč pro „Na příští týden" (`POST /api/ukoly/{uuid}/pristi-tyden`).
                (string) $u->uuid,
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
        if (! Tabulky::je('journal_entries')) {
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
     * Výdaje v různých měnách se sčítají v hlavní měně kurzem ECB a poznámka
     * nese rozpis po měnách i datum kurzu („3 000 Kč + 50 € · přepočteno kurzem
     * ECB k 24. 9. 2026"). Dřív se ukázala jen měna s nejvíc výdaji a ostatní
     * zmizely. Bez kurzu se nesčítá nic: ukáže se hlavní měna a zbytek stranou —
     * koruny s eury by daly číslo, které neplatí ani v jedné.
     *
     * @return array{0: string, 1: string}
     */
    private function utraceno(GallerySpace $prostor, CarbonImmutable $od, CarbonImmutable $do): array
    {
        if (! Tabulky::je('transactions')) {
            return ['—', 'finance nejsou založené'];
        }

        $hlavni = Meny::hlavni($prostor);
        $radky = DB::table('transactions')
            ->where('gallery_space_id', $prostor->id)
            ->where('type', 'expense')
            ->whereNull('deleted_at')
            // Jako kniha: čekající (`pending`) ještě není utracené.
            ->whereIn('state', Transaction::ZAPSANE)
            ->where('occurred_at', '>=', $od->toDateString())
            ->where('occurred_at', '<', $do->toDateString())
            ->selectRaw('COALESCE(currency_from, ?) AS mena, SUM(amount_from) AS soucet, COUNT(*) AS n', [$hlavni])
            ->groupBy('mena')
            ->get();

        if ($radky->isEmpty()) {
            return ['0', 'žádný zapsaný výdaj'];
        }

        $soucty = $this->poMenach($radky, $hlavni);
        $vysledek = $this->kurzy->doHlavni($soucty, $prostor);

        if ($vysledek['uplne']) {
            $castka = $this->castka((float) $vysledek['celkem'], $vysledek['mena']);
            $popisek = $this->kurzy->popisek($vysledek);

            return [$castka, $popisek === null
                ? $this->pocet((int) $radky->sum('n'), 'výdaj', 'výdaje', 'výdajů')
                : $this->castky($vysledek['poMenach']).' · '.$popisek];
        }

        $mena = $this->ukazanaMena($vysledek['poMenach'], $hlavni);
        $stranou = array_diff_key($vysledek['poMenach'], [$mena => true]);

        return [
            $this->castka($vysledek['poMenach'][$mena] ?? 0.0, $mena),
            // Jediná měna bez kurzu (třeba týden jen v eurech) nemá co dát stranou.
            $stranou === []
                ? $this->pocet((int) $radky->sum('n'), 'výdaj', 'výdaje', 'výdajů')
                : 'jen výdaje v '.$mena.', '.$this->castky($stranou).' stranou',
        ];
    }

    /**
     * Kapitola „Finance" roku v číslech: výdaje, příjmy a rozdíl.
     *
     * S kurzem se všechno převede do hlavní měny; výdaje i příjmy se převádějí
     * jen spolu — kdyby výdaje přepočtené byly a příjmy ne, rozdíl by odečítal
     * úplné číslo od neúplného. Bez kurzu jedna měna (hlavní, když v ní něco
     * je) a zbytek se v poznámce napíše částkou „stranou".
     *
     * @param  Collection<int, object>  $radky  {type, mena, castka, n}
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function financeRoku(GallerySpace $prostor, Collection $radky): array
    {
        $hlavni = Meny::hlavni($prostor);
        $vydajePoMenach = $this->poMenach($radky->where('type', 'expense'), $hlavni);
        $prijmyPoMenach = $this->poMenach($radky->where('type', 'income'), $hlavni);

        $vydaje = $this->kurzy->doHlavni($vydajePoMenach, $prostor);
        $prijmy = $this->kurzy->doHlavni($prijmyPoMenach, $prostor);

        if ($vydaje['uplne'] && $prijmy['uplne']) {
            $mena = $vydaje['mena'];
            $v = (float) $vydaje['celkem'];
            $p = (float) $prijmy['celkem'];
            $poznamka = fn (array $vysledek) => ($popisek = $this->kurzy->popisek($vysledek)) === null
                ? 'zapsané v knize'
                : 'zapsané v knize · '.$this->castky($vysledek['poMenach']).' · '.$popisek;

            return [
                ['Výdaje', $this->castka($v, $mena), $poznamka($vydaje)],
                ['Příjmy', $this->castka($p, $mena), $poznamka($prijmy)],
                ['Rozdíl', $this->castka($p - $v, $mena), $p >= $v ? 'zbylo' : 'chybělo'],
            ];
        }

        // Měna, ve které se ukáže obojí: hlavní, jinak ta s nejvíc pohyby.
        $mena = $this->ukazanaMena($this->poMenach($radky, $hlavni, true), $hlavni);
        $v = (float) ($vydaje['poMenach'][$mena] ?? 0.0);
        $p = (float) ($prijmy['poMenach'][$mena] ?? 0.0);
        $poznamka = function (array $poMenach) use ($mena) {
            $stranou = array_diff_key($poMenach, [$mena => true]);

            return 'zapsané v knize · jen v '.$mena.($stranou === [] ? '' : ' · '.$this->castky($stranou).' stranou');
        };

        return [
            ['Výdaje', $this->castka($v, $mena), $poznamka($vydaje['poMenach'])],
            ['Příjmy', $this->castka($p, $mena), $poznamka($prijmy['poMenach'])],
            ['Rozdíl', $this->castka($p - $v, $mena), $p >= $v ? 'zbylo' : 'chybělo'],
        ];
    }

    /**
     * Řádky `{mena, soucet|castka, n}` jako `měna => součet`, měny s nejvíc zápisy první.
     *
     * Pořadí rozhoduje, kterou měnu ukázat, když kurz chybí a hlavní měna v datech
     * není. S `$jenPocty` se místo částek sčítá počet zápisů — tak se vybírá měna
     * společná výdajům i příjmům.
     *
     * @param  Collection<int, object>  $radky
     * @return array<string, float>
     */
    private function poMenach(Collection $radky, string $hlavni, bool $jenPocty = false): array
    {
        $soucty = [];
        $pocty = [];

        foreach ($radky as $r) {
            $mena = SouctyPoMenach::klic($r->mena, $hlavni);
            $castka = $jenPocty ? (int) $r->n : (float) ($r->soucet ?? $r->castka ?? 0);
            $soucty[$mena] = ($soucty[$mena] ?? 0.0) + $castka;
            $pocty[$mena] = ($pocty[$mena] ?? 0) + (int) $r->n;
        }

        arsort($pocty);

        // Klíče v pořadí podle počtu, hodnoty součty.
        return array_map(fn (string $mena) => $soucty[$mena], array_combine(array_keys($pocty), array_keys($pocty)));
    }

    /**
     * Měna, ve které se ukáže číslo, když kurz chybí: hlavní, když v ní něco je, jinak první.
     *
     * @param  array<string, float>  $poMenach  seřazené podle přednosti
     */
    private function ukazanaMena(array $poMenach, string $hlavni): string
    {
        return array_key_exists($hlavni, $poMenach) || $poMenach === [] ? $hlavni : (string) array_key_first($poMenach);
    }

    /** Částka jako na celé obrazovce týdne: tisíce pevnou mezerou, ať se číslo nezlomí. */
    private function castka(float $castka, string $mena): string
    {
        return SouctyPoMenach::castkaPevnaMezera($castka, $mena);
    }

    /** @param  array<string, float>  $castky  „3 000 Kč + 50 €" */
    private function castky(array $castky): string
    {
        return SouctyPoMenach::spoj($castky, ' + ', $this->castka(...));
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

        if (Tabulky::je('calendar_events')) {
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
                // Týden se otevře na své první fotce (dřív obecná časová osa).
                'photo' => $tyden->isNotEmpty() ? (string) $tyden->first()->uuid : null,
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
        if (! Tabulky::je('shared_todos')) {
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
            ->get(['id', 'uuid', 'title', 'status', 'due_at', 'completed_at', 'assigned_to']);
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

        if (! $id || ! Tabulky::je('media_variants')) {
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
