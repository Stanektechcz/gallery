<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use App\Services\Finance\ExchangeRateService;
use App\Services\Finance\SouctyPoMenach;
use App\Support\Cas;
use App\Support\Meny;
use App\Support\Tabulky;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
class Cesty implements MaPrazdneKolekce, PoskytovatelObsahu
{
    private const MESICE = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
        'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

    private const DNY = ['Neděle', 'Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota'];

    // Útraty cesty ve víc měnách se sčítají v hlavní měně kurzem ECB.
    public function __construct(private readonly ExchangeRateService $kurzy) {}

    public function skupina(): string
    {
        return 'cesty';
    }

    public function uplne(): array
    {
        return ['TRIPS', 'TRIP_BY_TITLE', 'PLACES', 'PLACE_BY_TITLE', 'NOWTRIP'];
    }

    /**
     * Prázdné kolekce pro modul, který dvojice zatím nepoužila.
     *
     * Bez nich zůstala na obrazovce ukázka z prototypu (viz MaPrazdneKolekce).
     *
     * @return array<string, mixed>
     */
    public function prazdne(): array
    {
        return [
            'TRIPS' => new \stdClass,
            'TRIP_BY_TITLE' => new \stdClass,
            'PLACES' => new \stdClass,
            'PLACE_BY_TITLE' => new \stdClass,
            'NOWTRIP' => new \stdClass,
            'AL' => ['tripsPlanned' => [], 'tripsPast' => [], 'ticket' => [], 'placesWish' => [], 'placesVisited' => [], 'travelInbox' => []],
            'MOBIL' => ['TRIPS' => new \stdClass],
        ];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        $cesty = Tabulky::je('trips') ? $this->cesty($prostor) : [];
        $mista = Tabulky::je('places') ? $this->mista($prostor) : [];

        return array_filter([
            'TRIPS' => $cesty,
            'TRIP_BY_TITLE' => $this->rejstrik($cesty),
            /*
             * Běžící cesta chodí **vždycky**, když zrovna žádná není, tak prázdná.
             *
             * Neposlaná nechala u klienta ukázkový Brač a cestovní režim je ve
             * výchozím stavu zapnutý — horní lišta pak na každé obrazovce
             * tvrdila „Jsme na cestě · den 5 z 8" dvojici, která seděla doma.
             * Prázdný objekt je úplná kolekce, takže ukázku smaže.
             */
            'NOWTRIP' => Tabulky::je('trips') ? ($this->prave($prostor, $cesty) ?? (object) []) : null,
            'PLACES' => $mista,
            'PLACE_BY_TITLE' => $this->rejstrik($mista),
            // Tytéž cesty a místa ve tvaru seznamu — víc dotazů to nestojí.
            'AL' => $this->seznamy($cesty, $mista, $prostor),
            // Telefon kreslí cesty z mnohem menšího tvaru a drží si ho stranou.
            'MOBIL' => $cesty ? ['TRIPS' => $this->proTelefon($cesty)] : [],
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Seznamy `AL`: cesty, místa a jízdenky jako `[název, popis, štítek]`.
     *
     * Obrazovky „Cesty a výlety" a „Místa a podniky" kreslí tytéž věci ještě
     * jednou — jako seznam. Ten se ale bral z `galerie-data.js`, takže vedle
     * skutečné cesty do Chorvatska stálo Lisabon a Vídeň někoho cizího.
     *
     * Počítá se z toho, co už je načtené; databáze se kvůli tomu neptá znovu.
     *
     * @param  array<string, array<string, mixed>>  $cesty
     * @param  array<string, array<string, mixed>>  $mista
     * @return array<string, list<array<int, ?string>>>
     */
    private function seznamy(array $cesty, array $mista, GallerySpace $prostor): array
    {
        $doCesty = fn (array $c) => [
            $c['title'],
            trim(implode(' · ', array_filter([$c['when'], $c['where']]))),
            $c['tag'],
        ];

        $doMista = fn (array $m) => [
            $m['title'],
            trim(implode(' · ', array_filter([$m['city'], $m['kind']]))),
            $m['tag'],
        ];

        $budouci = array_values(array_filter($cesty, fn (array $c) => ! $c['past']));
        $minule = array_values(array_filter($cesty, fn (array $c) => $c['past']));
        // „Byli jsme" pozná místo podle štítku, který mu dal `znacka()`.
        $navstivena = array_values(array_filter($mista, fn (array $m) => $m['tag'] === 'byli jsme'));
        // Do „kam chceme" nepatří místo označené za „raději ne" — dvojice
        // o něm rozhodla přesně naopak.
        $chteji = array_values(array_filter($mista, fn (array $m) => ! in_array($m['tag'], ['byli jsme', 'raději ne'], true)));

        return array_filter([
            'tripsPlanned' => array_map($doCesty, $budouci),
            'tripsPast' => array_map($doCesty, $minule),
            'placesWish' => array_map($doMista, $chteji),
            'placesVisited' => array_map($doMista, $navstivena),
            'ticket' => $this->jizdenky($prostor),
            'travelInbox' => $this->cestovniInbox($prostor),
        ], fn (array $v) => $v !== []);
    }

    /**
     * Uložená spojení a rezervace.
     *
     * `saved_transport_routes` drží trasy, které si dvojice uložila; obrazovka
     * z nich dělá „jízdenky". Datum ani cenu tabulka nenese, takže se
     * nevymýšlejí — v popisku je trasa a kdy se uložila.
     *
     * @return list<array<int, ?string>>
     */
    private function jizdenky(GallerySpace $prostor): array
    {
        if (! Tabulky::je('saved_transport_routes')) {
            return [];
        }

        return DB::table('saved_transport_routes')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['uuid', 'name', 'origin', 'destination', 'created_at'])
            ->map(function (object $t) {
                $trasa = implode(' – ', array_filter([trim((string) $t->origin), trim((string) $t->destination)]));

                return [
                    (string) ($t->name ?: $trasa),
                    trim(implode(' · ', array_filter([
                        $trasa !== '' ? $trasa : null,
                        'uloženo '.CarbonImmutable::parse($t->created_at)->format('j. n. Y'),
                    ]))),
                    'uloženo',
                    // Klíč pro smazání (`DELETE /api/cesty/jizdenky/{uuid}`).
                    null, null, null, null, (string) $t->uuid,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Co přišlo do cestovní schránky a čeká na zařazení.
     *
     * @return list<array<int, ?string>>
     */
    private function cestovniInbox(GallerySpace $prostor): array
    {
        if (! Tabulky::je('travel_inbox_items')) {
            return [];
        }

        return DB::table('travel_inbox_items as i')
            ->leftJoin('trips as t', 't.id', '=', 'i.trip_id')
            ->where('i.gallery_space_id', $prostor->id)
            // Archivované (odložené) do schránky nepatří.
            ->where('i.state', '!=', 'archived')
            ->orderByDesc('i.created_at')
            ->limit(40)
            ->get(['i.uuid', 'i.title', 'i.kind', 'i.state', 'i.created_at', 't.name as cesta'])
            ->map(function (object $p) {
                // Zařazené je i to, co API zapsalo jako `assigned` (s cestou).
                $zarazeno = in_array($p->state, ['assigned', 'filed', 'zarazeno'], true);

                return [
                    (string) $p->title,
                    trim(implode(' · ', array_filter([
                        // Druh česky — obrazovka ukazovala „reservation" a „idea".
                        match ((string) $p->kind) {
                            'reservation' => 'rezervace', 'link' => 'odkaz', 'idea' => 'nápad',
                            'note' => 'poznámka', 'itinerary' => 'itinerář', 'file' => 'soubor', '' => null,
                            default => (string) $p->kind,
                        },
                        CarbonImmutable::parse($p->created_at)->format('j. n.'),
                        $zarazeno && $p->cesta ? 'cesta '.$p->cesta : null,
                    ]))),
                    $zarazeno ? 'zařazeno' : 'zařadit',
                    null, null, null, null,
                    // Identifikátor pro zařazení, archivaci a smazání na serveru.
                    (string) $p->uuid,
                ];
            })
            ->values()
            ->all();
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

        $dnes = Cas::dnes();
        $hlavniMena = Meny::hlavni($prostor);
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
                'od' => $od->toDateString(),
                'do' => $do->toDateString(),
                'past' => $dnes->gt($do),
                // Prototyp z toho dělá dotaz do knihovny, ne číslo: pruh fotek
                // pod cestou vzniká hledáním.
                'photoMatch' => $mistaCesty->first() ?: $c->name,
                'desc' => (string) ($c->description ?? ''),
                // Obrazovka podle ní odvozuje rozpočet další cesty z útraty
                // této — jen když jsou obě ve stejné měně, jinak by 90 € zapsala
                // jako 90 Kč.
                'mena' => Meny::kod($c->currency ?? null) ?? $hlavniMena,
                'stats' => $this->cisla($prostor, $c, $od, $do, $delka, $dnes, $utraty[$c->id] ?? collect(), $limity[$c->id] ?? collect(), $dny[$c->id] ?? collect(), $program),
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
     * „Utraceno" ve víc měnách se s kurzem ECB sečte v hlavní měně a třetí
     * prvek řádku nese datum kurzu a rozpis po měnách; bez kurzu se ukáže
     * jen rozpis („70 € + 500 Kč") — dřív tu stálo „sečíst nejde".
     *
     * @return list<array<int, string>>
     */
    private function cisla(GallerySpace $prostor, object $c, CarbonImmutable $od, CarbonImmutable $do, int $delka, CarbonImmutable $dnes, Collection $utraty, Collection $limity, Collection $dny, Collection $program): array
    {
        // Zrušený program v plánu není — „Položek v plánu" ho počítalo taky.
        $polozek = $dny->sum(fn ($d) => ($program[$d->id] ?? collect())
            ->reject(fn ($p) => in_array((string) ($p->status ?? ''), ['cancelled', 'canceled'], true))
            ->count());
        // Limity kategorií mají přednost; bez nich platí celkový rozpočet z dialogu nové cesty.
        $plan = (int) $limity->sum('amount') ?: (int) round((float) ($c->budget ?? 0));
        $skutecne = $utraty->where('state', '!=', 'planned');
        $utraceno = (int) $skutecne->sum('amount');

        // Měna cesty; při dvou různých měnách v útratách se součet neukazuje.
        $menaPlanu = $this->jednaMena($limity, $c->currency ?? null);
        $menaUtrat = $this->jednaMena($skutecne, $c->currency ?? null);

        return array_values(array_filter([
            ['Délka', $this->pocet($delka, 'den', 'dny', 'dní')],
            match (true) {
                $dnes->lt($od) => ['Odjezd za', $this->pocet((int) $dnes->diffInDays($od), 'den', 'dny', 'dní')],
                $dnes->gt($do) => ['Bylo', 'před '.$this->pocet((int) $do->diffInDays($dnes), 'dnem', 'dny', 'dny')],
                default => ['Dnes', 'den '.((int) $od->diffInDays($dnes) + 1)],
            },
            $plan && $menaPlanu !== null ? ['Rozpočet', Meny::castka($plan, $menaPlanu)] : null,
            $utraceno && $menaUtrat !== null ? ['Utraceno', Meny::castka($utraceno, $menaUtrat)] : null,
            $utraceno && $menaUtrat === null ? $this->utracenoVeVicMenach($prostor, $skutecne, $c->currency ?? null) : null,
            $polozek ? ['Položek v plánu', (string) $polozek] : null,
        ]));
    }

    /**
     * „Utraceno" z útrat ve víc měnách: `['Utraceno', částka, popisek]`, nebo bez kurzu `['Utraceno', rozpis]`.
     *
     * @param  Collection<int, object>  $utraty
     * @return array<int, string>
     */
    private function utracenoVeVicMenach(GallerySpace $prostor, Collection $utraty, ?string $menaCesty): array
    {
        $poMenach = $this->poMenach($utraty, Meny::kod($menaCesty) ?? Meny::hlavni($prostor));
        $vysledek = $this->kurzy->doHlavni($poMenach, $prostor);

        if (! $vysledek['uplne']) {
            return ['Utraceno', $this->castky($poMenach)];
        }

        // Víc měn a úplný přepočet: popisek s datem kurzu tu je vždycky. Kdyby se
        // měny sešly jen v hlavní (třeba „czk" a „CZK"), stačí rozpis.
        $popisek = $this->kurzy->popisek($vysledek);

        return [
            'Utraceno',
            Meny::castka((float) $vysledek['celkem'], $vysledek['mena']),
            $popisek === null ? $this->castky($poMenach) : $popisek.' · '.$this->castky($poMenach),
        ];
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
                    // Identifikátor bodu — odškrtnutí „splněno" jde na server k němu.
                    (int) $a->id,
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
     * S limitem se srovnává jen útrata v jeho měně. Koruny se sčítaly s eury
     * a „540 z 100 €" tvrdilo přečerpání, které nenastalo. Útrata v jiné měně
     * se připíše vedle („· 500 Kč v jiné měně"), do procent nevstupuje:
     * limit cesty je v měně cesty a na jeho přepočet by kurz ECB do hlavní
     * měny nestačil.
     *
     * @return list<array<int, mixed>>
     */
    private function rozpocet(Collection $limity, Collection $utraty): array
    {
        $podleKategorie = $utraty->where('state', '!=', 'planned')->groupBy('category');

        return $limity->map(function ($l) use ($podleKategorie) {
            $limit = (int) $l->amount;
            $mena = Meny::kod($l->currency ?? null) ?? Meny::HLAVNI;
            // Útrata bez měny patří k limitu, jako dřív — starší zápisy měnu nemají.
            $poMenach = $this->poMenach($podleKategorie[$l->category] ?? collect(), $mena);
            $utraceno = (int) round($poMenach[$mena] ?? 0.0);
            $jine = array_diff_key($poMenach, [$mena => true]);

            return [
                $this->kategorie((string) $l->category),
                ($utraceno
                    ? $this->cislo($utraceno).' z '.Meny::castka($limit, $mena)
                    : 'plán '.Meny::castka($limit, $mena))
                .($jine === [] ? '' : ' · '.$this->castky($jine).' v jiné měně'),
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
        $dnes = Cas::dnes();

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
        $dnesni = $utraty->filter(fn ($u) => $u->occurred_at && CarbonImmutable::parse($u->occurred_at)->isSameDay($dnes));

        /*
         * Čísla běžící cesty jsou v měně cesty.
         *
         * `fund`, `spent` i `todaySpent` sčítaly koruny s eury a obrazovka je
         * pak kreslila globálním znakem — eurová cesta s večeří za 500 Kč
         * „utratila 540 €". Teď nesou jen útraty v měně cesty; ostatní měny
         * chodí zvlášť (`spentOther`, `todayOther`) a souhrn všeho v hlavní
         * měně kurzem ECB (`spentMain`, bez kurzu `celkem: null`). Limity v jiné
         * měně do fondu nepatří — jinak by se znovu sčítalo, co sečíst nejde.
         */
        $mena = Meny::kod($ted->currency ?? null) ?? Meny::hlavni($prostor);
        $limity = DB::table('trip_budget_limits')->where('trip_id', $ted->id)->get(['amount', 'currency']);
        $fond = $limity->filter(fn ($l) => (Meny::kod($l->currency) ?? $mena) === $mena)->sum('amount');
        $poMenach = $this->poMenach($utraty, $mena);
        $dnesPoMenach = $this->poMenach($dnesni, $mena);
        $vHlavni = $this->kurzy->doHlavni($poMenach, $prostor);

        return [
            'id' => $klic,
            // Číslo cesty pro zápis výdaje a bodu programu z cestovního režimu.
            'n' => (int) $ted->id,
            'title' => $ted->name,
            'where' => $zaznam['where'] ?? '',
            'day' => (int) $od->diffInDays($dnes) + 1,
            'days' => (int) $od->diffInDays($do) + 1,
            'when' => self::DNY[$dnes->dayOfWeek].' '.$dnes->day.'. '.self::MESICE[$dnes->month].' '.$dnes->year,
            'mena' => $mena,
            'znak' => Meny::znak($mena),
            // Jako u seznamu cest: limity kategorií, jinak celkový rozpočet z dialogu nové cesty.
            'fund' => (int) $fond ?: (int) round((float) ($ted->budget ?? 0)),
            'spent' => (int) round($poMenach[$mena] ?? 0.0),
            'todaySpent' => (int) round($dnesPoMenach[$mena] ?? 0.0),
            // Mapa měna => částka; prázdná jako `{}`, ať má klíč pořád stejný tvar.
            'spentOther' => array_diff_key($poMenach, [$mena => true]) ?: new \stdClass,
            'todayOther' => array_diff_key($dnesPoMenach, [$mena => true]) ?: new \stdClass,
            'spentMain' => [
                'mena' => $vHlavni['mena'],
                'znak' => Meny::znak($vHlavni['mena']),
                'celkem' => $vHlavni['celkem'],
                'kurzKeDni' => $vHlavni['kurzKeDni'],
                'popisek' => $this->kurzy->popisek($vHlavni),
                'chybi' => $vHlavni['chybi'],
            ],
            'plan' => $program->map(fn ($a) => [
                $a->starts_at ? CarbonImmutable::parse($a->starts_at)->format('G:i') : '—',
                $this->ikona((string) $a->type),
                $a->title,
                (string) ($a->description ?? $a->place_name ?? ''),
                $this->stav((string) $a->status),
                // Číslo bodu — pro „Posunout o hodinu".
                (int) $a->id,
            ])->values()->all(),
            'spendRows' => $dnesni
                ->map(fn ($u) => [
                    $u->title,
                    (int) $u->amount,
                    $this->kategorie((string) $u->category),
                    CarbonImmutable::parse($u->occurred_at)->format('G:i'),
                    // Měna řádku — částka je v ní, ne v měně cesty.
                    Meny::kod($u->currency) ?? $mena,
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
        if (! Tabulky::sloupec('places', 'gallery_space_id')) {
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
                    // „Zdrží 1 minut" / „3 minut" — číslo si žádá správný tvar.
                    $m->estimated_visit_minutes ? ['Zdrží', $this->pocet((int) $m->estimated_visit_minutes, 'minuta', 'minuty', 'minut')] : null,
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
        if (! Tabulky::je('place_plans')) {
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
        if (! Tabulky::je('place_notes')) {
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
        if (! Tabulky::je('trip_packing_items')) {
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
                // Id položky — zaškrtnutí jde na server (dřív jen do stavu prohlížeče).
                (int) $p->id,
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
        if (! Tabulky::je('trip_document_checks')) {
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
                $this->stitekDokladu($d),
                $this->ikona((string) $d->type),
            ])->values()->all())
            ->all();
    }

    /**
     * Štítek u dokladu — a hlavně: propadlý pas se pozná.
     *
     * Rozhodovalo se jen podle sloupce `status`, jehož hodnota `'expiring'`
     * se nikde nezapisuje. Doklad s prošlou platností tak neměl **žádný**
     * štítek, a zrovna u něj na tom záleží: s propadlým pasem se nikam nejede.
     * Datum platnosti v tabulce je, tak se z něj vychází.
     */
    private function stitekDokladu(object $d): ?string
    {
        $doKdy = $d->expires_on ? CarbonImmutable::parse($d->expires_on) : null;
        $dni = $doKdy ? (int) Cas::dnes()->diffInDays($doKdy, false) : null;

        return match (true) {
            $dni !== null && $dni < 0 => 'propadlo',
            // Čtvrt roku dopředu: na nový pas je potřeba čas.
            $dni !== null && $dni <= 90 => 'brzy propadne',
            (string) $d->status === 'ready' => 'zaplaceno',
            (string) $d->status === 'missing' => 'zařadit',
            default => null,
        };
    }

    /**
     * Zápis v deníku cesty, který je buď společný, nebo můj.
     *
     * `travel_journal_entries.visibility` drží `shared` / `private` a čtecí
     * cesta modulu (`TripPlanController`) ji respektuje. Obsah pro obrazovky
     * ne, takže zápis, který si jeden označil za soukromý, vypadl druhému
     * v deníku cesty. U poznámek k místům se to o pár řádků výš hlídá.
     *
     * @param  Builder  $dotaz
     */
    private function jenMojeNeboSdilene($dotaz): void
    {
        if (! Tabulky::sloupec('travel_journal_entries', 'visibility')) {
            return;
        }

        $ja = auth()->id();

        $dotaz->where(fn ($q) => $q->where('visibility', 'shared')
            ->orWhereNull('visibility')
            ->when($ja !== null, fn ($w) => $w->orWhere('user_id', $ja)));
    }

    /**
     * Deník cesty: `[datum, nadpis, text, místo a počet fotek]`.
     *
     * @return array<int, list<array<int, string>>>
     */
    private function denik(Collection $id): array
    {
        if (! Tabulky::je('travel_journal_entries')) {
            return [];
        }

        return DB::table('travel_journal_entries')
            ->whereIn('trip_id', $id)
            ->where('type', 'note')
            ->tap($this->jenMojeNeboSdilene(...))
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
        if (! Tabulky::je('travel_journal_entries')) {
            return [];
        }

        $dnes = Cas::dnes();

        return DB::table('travel_journal_entries')
            ->where('trip_id', $cesta)
            ->where('type', 'note')
            ->tap($this->jenMojeNeboSdilene(...))
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
            // Telefon zapisuje k cestě bod programu (`/cesty/{n}/program`) a den vybírá z rozsahu od–do.
            'n' => $c['n'],
            'od' => $c['od'],
            'do' => $c['do'],
            'desc' => $c['desc'],
            'stats' => $c['stats'],
            'days' => array_map(fn (array $d) => [
                $d[0],
                $d[1],
                array_map(fn (array $a) => [$a[2], $a[0], $a[4] === 'hotovo' ? 1 : 0, $a[5] ?? null], $d[3]),
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

    /**
     * Útraty sečtené po měnách, měna `$vychozi` první.
     *
     * Útrata bez měny patří k `$vychozi` (měna cesty nebo limitu) — starší
     * zápisy měnu nemají. Částky se nesčítají přes měny; to dělá až
     * `ExchangeRateService::doHlavni()`, s kurzem a datem.
     *
     * @param  Collection<int, object>  $utraty
     * @return array<string, float>
     */
    private function poMenach(Collection $utraty, string $vychozi): array
    {
        $soucty = [];

        foreach ($utraty as $u) {
            $mena = Meny::kod($u->currency ?? null) ?? $vychozi;
            $soucty[$mena] = round(($soucty[$mena] ?? 0.0) + (float) $u->amount, 2);
        }

        $soucty = SouctyPoMenach::odfiltrujNulove($soucty);

        return SouctyPoMenach::presunNaZacatek($soucty, $vychozi);
    }

    /**
     * Částky po měnách vedle sebe: „70 € + 500 Kč".
     *
     * Částka se píše v měně, ve které je (`Meny::castka`). Dřív tu stálo natvrdo
     * „Kč" a cesta rozpočtovaná v eurech o sobě tvrdila „Rozpočet 1 200 Kč".
     *
     * @param  array<string, float>  $castky
     */
    private function castky(array $castky): string
    {
        return SouctyPoMenach::spoj($castky, ' + ', Meny::castka(...));
    }

    /**
     * Měna, ve které jsou všechny ty řádky — nebo `null`, když se míchají.
     *
     * @param  Collection<int, object>  $radky
     */
    private function jednaMena(Collection $radky, ?string $vychozi = null): ?string
    {
        $meny = $radky->pluck('currency')->filter()
            ->map(fn ($m) => mb_strtoupper(trim((string) $m)))
            ->unique()
            ->values();

        if ($meny->count() > 1) {
            return null;
        }

        return $meny->first() ?? ($vychozi ? mb_strtoupper(trim($vychozi)) : null);
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
