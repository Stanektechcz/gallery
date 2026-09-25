<?php

namespace App\Services\Obsah;

use App\Models\FinanceAccess;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Services\Auth\PristupDoGalerie;
use App\Services\Finance\ExchangeRateService;
use App\Services\Finance\FinanceService;
use App\Services\Finance\SouctyPoMenach;
use App\Support\Cas;
use App\Support\Meny;
use App\Support\SpaceContext;
use App\Support\Tabulky;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rozbory nad financemi: obálka pro sebe, vlastní inflace, sezónní fondy,
 * cena cesty, cena odkladu, nečekané výdaje, odhad proti skutečnosti a co
 * ta útrata znamenala.
 *
 * Žádná nová tabulka — všechno se počítá z transakcí, limitů, cílů a lhůt,
 * které dvojice už má.
 *
 * Co se spočítat nedá, se **neposílá** — obrazovka pak drží ukázku, a to je
 * pořád lepší než vymyšlené číslo, podle kterého se dvojice rozhoduje.
 *
 * Měny se nesčítají jako jedna. Částky se sečtou po měnách a teprve pak přepočtou
 * kurzem ECB do hlavní měny prostoru (u limitu rozpočtu do měny toho rozpočtu).
 * Dřív přidalo 200 € na účtu předpovědi dvě stě „korun" a směna korun na eura
 * zůstatek snížila, přestože peníze nikam neodešly. Měna bez kurzu se vynechá
 * a řádek to řekne (`vynechano`, `chybi`) — smíšené číslo se neukazuje nikdy.
 *
 * Řádky nesou `mena` (v čem jsou jejich čísla), a kde se přepočítávalo, i
 * `prepocteno` a `popisek` s datem kurzu. Původní částka zůstává v `puvodne`
 * a `puvodniMena`.
 */
class FinanceRozbory implements MaPrazdneKolekce, PoskytovatelObsahu
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
            'COSTMEAN' => [],
            'DELAY' => [],
            'ENV' => ['limit' => 0, 'months' => []],
            'EST' => [],
            'HORIZON' => [],
            'INFL' => [],
            'P60' => ['start' => 0, 'daily' => 0, 'events' => []],
            'SCEN' => [],
            'SEASON' => [],
            'SURPRISE' => [],
            'TRIPCOST' => new \stdClass,
        ];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        if (! Tabulky::je('transactions')) {
            return [];
        }

        return array_filter([
            'ENV' => $this->obalka($prostor),
            'INFL' => $this->inflace($prostor),
            'SEASON' => $this->fondy($prostor),
            'TRIPCOST' => $this->cenyCest($prostor),
            'DELAY' => $this->cenaOdkladu($prostor),
            'SURPRISE' => $this->necekane($prostor),
            'EST' => $this->odhadySkutecnost($prostor),
            'COSTMEAN' => $this->coToZnamenalo($prostor),
            'P60' => $predpoved = $this->predpoved($prostor),
            'SCEN' => $predpoved ? $this->scenare($prostor) : [],
            'HORIZON' => $this->horizont($prostor),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Scénáře k šedesátidenní předpovědi.
     *
     * Katalog, ale jedna poznámka v něm jmenovala člověka: „Makinka jde na
     * částečný úvazek." U jiné dvojice to bylo jméno někoho cizího. Jméno
     * se proto bere z prostoru — a když se vzít nedá, věta ho neobsahuje.
     *
     * Bez předpovědi se neposílají: samotné popisky nemají co ovládat.
     *
     * @return list<array<string, mixed>>
     */
    private function scenare(GallerySpace $prostor): array
    {
        /*
         * Popisky říkají, co scénář opravdu počítá.
         *
         * „Makinka jde na částečný úvazek" u výpočtu, který snižuje **všechny**
         * příjmy o pětinu, bylo tvrzení navíc — a ještě o cizím člověku.
         *
         * Dvě čísla v popiscích byla napsaná v kódu: splátka 4 900 a úspora
         * 438 Kč. Ta druhá se tvářila jako spočítaná z jejich předplatných —
         * teď z nich doopravdy je a jmenuje je. Splátka se vzít odkud nedá,
         * takže je z ní kulatá modelová částka a popisek to říká.
         */
        [$uspora, $usporaCo, $popisek] = $this->dveNejmensiPlatby($prostor);
        $hlavni = Meny::hlavni($prostor);

        return array_values(array_filter([
            ['key' => 'income', 'label' => 'Příjem o 20 % nižší', 'note' => 'Všechny pravidelné příjmy o pětinu níž.', 'amt' => 0],
            ['key' => 'loan', 'label' => 'Modelová splátka 5 000 / měs.', 'note' => 'Zkušební částka, ne nabídka, kterou máte. Odejde dvakrát za dva měsíce.', 'amt' => 5000],
            ['key' => 'parent', 'label' => 'Rodičovská za půl roku', 'note' => 'Nižší z pravidelných příjmů klesne na 40 %.', 'amt' => 0],
            $uspora > 0
                ? [
                    'key' => 'save',
                    'label' => 'Zrušit dvě nejmenší platby',
                    'note' => $usporaCo.' — '.Meny::castka($uspora, $hlavni).' měsíčně'.($popisek ? ' ('.$popisek.')' : '').'.',
                    'amt' => $uspora,
                ]
                : null,
        ]));
    }

    /**
     * Dvě nejmenší pravidelné platby: `[kolik měsíčně v hlavní měně, jak se jmenují, popisek přepočtu]`.
     *
     * „Nejmenší" se určuje po přepočtu. Řazení podle holé částky by za nejmenší
     * vzalo předplatné za 10 € před tím za 199 Kč, přestože stojí víc. Platba
     * v měně, ke které není kurz, se do výběru nebere — nedá se s ničím porovnat.
     *
     * @return array{0: int, 1: string, 2: ?string}
     */
    private function dveNejmensiPlatby(GallerySpace $prostor): array
    {
        if (! Tabulky::je('finance_recurring')) {
            return [0, '', null];
        }

        $hlavni = Meny::hlavni($prostor);

        $platby = DB::table('finance_recurring')
            ->where('gallery_space_id', $prostor->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where('type', '!=', 'income')
            ->get(['name', 'amount', 'currency']);

        $prevod = $this->prevodnik($prostor, $hlavni, $platby->map(fn ($p) => self::mena($p->currency, $hlavni)));

        $nejmensi = $platby
            ->map(function (object $p) use ($prevod, $hlavni) {
                $mena = self::mena($p->currency, $hlavni);
                $kurz = $prevod['kurzy'][$mena] ?? null;

                return $kurz === null ? null : [
                    'name' => (string) $p->name,
                    'castka' => abs((float) $p->amount) * $kurz,
                    'prepocteno' => $mena !== $hlavni,
                ];
            })
            ->filter()
            ->sortBy('castka')
            ->take(2)
            ->values();

        // Jedna platba není „dvě předplatná" — scénář se pak neposílá.
        if ($nejmensi->count() < 2) {
            return [0, '', null];
        }

        return [
            (int) round($nejmensi->sum('castka')),
            $nejmensi->pluck('name')->implode(' a '),
            $this->popisek($nejmensi->contains('prepocteno', true), $prevod),
        ];
    }

    /**
     * Předpověď na šedesát dní: `{ start, daily, events: [{ d, label, amt, move }] }`.
     *
     * Obrazovka o sobě říká: „Počítá se s pevnými platbami, oběma mzdami
     * a průměrnou denní útratou. Nic se nemodeluje ručně." Přesně tak se to
     * teď počítá — z peněženek, pravidelných plateb a skutečných transakcí.
     *
     * `move` znamená „dá se s tím pohnout": mzda ne, předplatné ano. Pozná se
     * podle typu položky, ne podle názvu.
     *
     * Bez pravidelných plateb se **neposílá nic**. Čára, která šedesát dní jen
     * rovnoměrně klesá, není předpověď, je to odečítání.
     *
     * Celá předpověď je v hlavní měně: zůstatky, denní průměr i platby se sečtou
     * po měnách a přepočtou kurzem ECB. Bez kurzu se ostatní měny vynechají ze
     * **všech tří** najednou (`vynechano`, `poznamka`) — kdyby eurový účet vypadl
     * jen ze zůstatku a eurové předplatné v událostech zůstalo, čára by klesala
     * o platby, na které předpověď nemá peníze.
     *
     * Událost nese `mena` a `puvodne` — v čem a kolik se doopravdy platí; `amt`
     * je totéž v hlavní měně.
     *
     * @return array<string, mixed>
     */
    private function predpoved(GallerySpace $prostor): array
    {
        if (! Tabulky::je('finance_recurring') || ! Tabulky::je('wallets')) {
            return [];
        }

        $dnes = Cas::dnes();
        $hlavni = Meny::hlavni($prostor);

        $platby = DB::table('finance_recurring')
            ->where('gallery_space_id', $prostor->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $dnes->toDateString()))
            ->whereNotNull('day_of_month')
            ->get(['name', 'type', 'amount', 'currency', 'day_of_month', 'starts_on']);

        if ($platby->isEmpty()) {
            return [];
        }

        $zustatky = $this->zustatekPoMenach($prostor, $hlavni);
        [$utrata, $pevne] = $this->denniUtrataPoMenach($prostor, $hlavni);

        $prevod = $this->prevodnik($prostor, $hlavni, array_merge(
            array_keys($zustatky),
            array_keys($utrata),
            array_keys($pevne),
            $platby->map(fn ($p) => self::mena($p->currency, $hlavni))->all(),
        ));

        $start = self::prepocti($zustatky, $prevod);
        $utraceno = self::prepocti($utrata, $prevod);
        $pevnych = self::prepocti($pevne, $prevod);

        $zustatek = (int) round($start['castka']);
        // Pevné platby za tři měsíce: v předpovědi stojí jako události ve svůj den
        // a v denním průměru by byly podruhé (viz `denniUtrataPoMenach`).
        $denne = max(0, (int) round(max(0, $utraceno['castka'] - $pevnych['castka'] * 3) / 90));
        $vynechano = array_merge($start['chybi'], $utraceno['chybi'], $pevnych['chybi']);
        $prepocteno = $start['prepocteno'] || $utraceno['prepocteno'] || $pevnych['prepocteno'];

        /*
         * Den v měsíci na pořadí v šedesátidenní ose.
         *
         * Platba splatná 4. připadne na dva dny — jednou v tomhle měsíci
         * a jednou v příštím. Kdyby se bral jen nejbližší výskyt, druhá půlka
         * osy by byla podezřele klidná.
         */
        $udalosti = [];

        foreach ($platby as $p) {
            $prijem = $p->type === 'income';
            $mena = self::mena($p->currency, $hlavni);
            $kurz = $prevod['kurzy'][$mena] ?? null;
            $puvodne = round(abs((float) $p->amount), 2) * ($prijem ? 1 : -1);

            if (abs($puvodne) < 0.005) {
                continue;
            }

            if ($kurz === null) {
                $vynechano[] = $mena;

                continue;
            }

            $castka = (int) round(abs((float) $p->amount) * $kurz) * ($prijem ? 1 : -1);
            $prepocteno = $prepocteno || $mena !== $hlavni;

            if ($castka === 0) {
                continue;
            }

            for ($mesic = 0; $mesic <= 2; $mesic++) {
                /*
                 * Nejdřív na začátek měsíce, teprve pak přičíst měsíce.
                 *
                 * Opačné pořadí přetéká: 31. ledna + 1 měsíc je 3. března,
                 * takže z ledna vyšly termíny leden, březen, březen — únorový
                 * nájem i výplata z předpovědi zmizely a březnové se počítaly
                 * dvakrát. `FinanceRecurring::terminy()` na to má poznámku.
                 */
                $den = $dnes->startOfMonth()->addMonths($mesic);
                $splatnost = $den->addDays(min((int) $p->day_of_month, (int) $den->daysInMonth) - 1);
                $poradi = (int) $dnes->diffInDays($splatnost, false);

                if ($poradi < 1 || $poradi > 60) {
                    continue;
                }

                // Platba, která teprve začne (nový nájem od listopadu), se
                // před svým začátkem neplatí — jinak by čára klesala o splátky,
                // které nikdo nepošle.
                if ($p->starts_on !== null && $splatnost->toDateString() < substr((string) $p->starts_on, 0, 10)) {
                    continue;
                }

                $udalosti[] = [
                    'd' => $poradi,
                    'label' => $p->name,
                    'amt' => $castka,
                    // Mzdou se pohnout nedá, splátkou nájmu prakticky taky ne.
                    // Posunout jde to, co si dvojice objednala sama.
                    'move' => ! $prijem,
                    'mena' => $mena,
                    'puvodne' => $puvodne,
                ];
            }
        }

        if ($udalosti === []) {
            return [];
        }

        usort($udalosti, fn (array $a, array $b) => $a['d'] <=> $b['d']);

        $vynechano = array_values(array_unique($vynechano));
        sort($vynechano);

        return [
            'start' => $zustatek,
            'daily' => $denne,
            'events' => $udalosti,
            'mena' => $hlavni,
            'prepocteno' => $prepocteno,
            'kurzKeDni' => $prepocteno ? $prevod['kurzKeDni'] : null,
            'popisek' => $this->popisek($prepocteno, $prevod),
            'vynechano' => $vynechano,
            'poznamka' => $vynechano === []
                ? null
                : 'Bez '.implode(', ', $vynechano).' — kurz ECB se nepodařilo zjistit, předpověď počítá jen s částkami v '.$hlavni.'.',
        ];
    }

    /**
     * Zůstatky společných účtů k dnešku po měnách: `[měna => částka]`.
     *
     * Hotovost se nepočítá: předpověď je o tom, co odejde z účtu, a peníze
     * v peněžence žádnou pevnou platbu nezaplatí.
     *
     * Každá peněženka se počítá ve své měně. Směna 2 500 Kč na 100 € ubere
     * korunovému účtu 2 500 Kč a eurovému přidá 100 € — dřív se obojí četlo jako
     * koruny a zůstatek po směně klesl o 2 400, přestože peníze nikam neodešly.
     *
     * Poplatek jde stejnou cestou jako v účetní knize (`FinanceService::poplatkyPoUctech`):
     * zahrnutý v částce se znovu neodečítá a placený navíc se bere z peněženky
     * v jeho měně. Kdyby se tu počítal po svém, předpověď by začínala z jiného
     * zůstatku, než jaký ukazuje Rozpočet.
     *
     * @return array<string, float>
     */
    private function zustatekPoMenach(GallerySpace $prostor, string $hlavni): array
    {
        $penezenky = DB::table('wallets')
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('kind', '!=', 'cash')
            ->get(['id', 'opening_balance', 'currency']);

        if ($penezenky->isEmpty()) {
            return [];
        }

        $stav = $penezenky->mapWithKeys(fn ($p) => [(int) $p->id => (float) $p->opening_balance])->all();
        $ids = array_keys($stav);

        // Přes model, ne `DB::table`: ten obchází měkké mazání, takže smazaný zápis
        // posouval výchozí zůstatek celé předpovědi.
        $pohyby = Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->zapsane()
            ->where(fn ($q) => $q->whereIn('wallet_from_id', $ids)->orWhereIn('wallet_to_id', $ids))
            ->get(['wallet_from_id', 'wallet_to_id', 'amount_from', 'amount_to', 'currency_from', 'currency_to', 'fee_amount', 'fee_currency', 'fee_included']);

        foreach ($pohyby as $p) {
            if (isset($stav[(int) $p->wallet_to_id])) {
                $stav[(int) $p->wallet_to_id] += (float) $p->amount_to;
            }

            if (isset($stav[(int) $p->wallet_from_id])) {
                $stav[(int) $p->wallet_from_id] -= (float) $p->amount_from;
            }
        }

        foreach (FinanceService::poplatkyPoUctech($pohyby) as $penezenka => $poplatek) {
            if (isset($stav[(int) $penezenka])) {
                $stav[(int) $penezenka] -= $poplatek;
            }
        }

        $poMenach = [];

        foreach ($penezenky as $p) {
            $mena = self::mena($p->currency, $hlavni);
            $poMenach[$mena] = ($poMenach[$mena] ?? 0.0) + $stav[(int) $p->id];
        }

        return $poMenach;
    }

    /**
     * Útrata za posledních devadesát dnů a měsíční pevné platby, obojí po měnách.
     *
     * Pevné platby se od útraty odečítají: v předpovědi stojí jako události ve svůj
     * den a v denním průměru by byly podruhé. Bez toho by čára klesala dvakrát
     * rychleji, než jak peníze doopravdy ubývají.
     *
     * Po měnách proto, že 90 € za večeři ve Vídni není devadesát korun — a eurové
     * předplatné se nesmí odečíst od korunové útraty, dokud se obojí nepřepočte.
     *
     * @return array{0: array<string, float>, 1: array<string, float>}
     */
    private function denniUtrataPoMenach(GallerySpace $prostor, string $hlavni): array
    {
        $od = CarbonImmutable::now()->subDays(90)->startOfDay();

        $utrata = Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->utraty()
            ->where('occurred_at', '>=', $od)
            ->selectRaw('currency_from AS mena, SUM(amount_from) AS castka')
            ->groupBy('currency_from')
            ->get();

        $pevne = Tabulky::je('finance_recurring')
            ? DB::table('finance_recurring')
                ->where('gallery_space_id', $prostor->id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->where('type', '!=', 'income')
                ->selectRaw('currency AS mena, SUM(amount) AS castka')
                ->groupBy('currency')
                ->get()
            : collect();

        return [self::poMenach($utrata, $hlavni), self::poMenach($pevne, $hlavni)];
    }

    /**
     * Desetiletý horizont: `[{ what, monthly, oneOff, note }]`.
     *
     * Obrazovka bere pravidelné platby a ukazuje, na kolik vyjdou za deset let.
     * Sloupec si počítá sama; sem patří jen to, co se opravdu platí.
     *
     * `note` je věta o **té platbě**, ne o životě: od kdy běží a kolikátého
     * odchází. Vymýšlet k ní úvahu („druhé auto stojí 22 hodin denně") by
     * znamenalo mluvit za dvojici o něčem, co aplikace neví.
     *
     * `monthly` je v hlavní měně (`mena`), aby se desetileté sloupce daly
     * porovnat mezi řádky; `what` a `note` mluví v měně, ve které se platí
     * („Streaming 10 € / měs."), protože tak to dvojice zná z výpisu. Platba
     * bez kurzu zůstane ve své měně a `mena` to řekne.
     *
     * @return list<array<string, mixed>>
     */
    private function horizont(GallerySpace $prostor): array
    {
        if (! Tabulky::je('finance_recurring')) {
            return [];
        }

        // Dnešek dvojice: `starts_on` a `ends_on` jsou data podle jejích hodin.
        // S okamžikem v UTC platba, která skončila včera, v noci ještě „běžela"
        // a první noc v měsíci se jí ubral jeden měsíc („5 měsíců" místo 6).
        $dnes = Cas::dnes();
        $hlavni = Meny::hlavni($prostor);

        $platby = DB::table('finance_recurring')
            ->where('gallery_space_id', $prostor->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where('type', '!=', 'income')
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $dnes->toDateString()))
            ->get(['name', 'amount', 'currency', 'day_of_month', 'starts_on']);

        $prevod = $this->prevodnik($prostor, $hlavni, $platby->map(fn ($p) => self::mena($p->currency, $hlavni)));

        return $platby
            ->map(function (object $p) use ($dnes, $hlavni, $prevod) {
                $puvodniMena = self::mena($p->currency, $hlavni);
                $puvodne = (int) round(abs((float) $p->amount));
                $kurz = $prevod['kurzy'][$puvodniMena] ?? null;
                $bezi = $p->starts_on ? CarbonImmutable::parse($p->starts_on) : null;
                $mesicu = $bezi && $bezi->lt($dnes) ? (int) $bezi->diffInMonths($dnes) : 0;
                // Znak jen u cizí měny — u hlavní ho řádek nikdy neměl a obrazovka
                // ho k číslům píše sama.
                $znak = $puvodniMena === $hlavni ? '' : ' '.Meny::znak($puvodniMena);

                return [
                    'what' => $p->name.' '.number_format($puvodne, 0, ',', ' ').$znak.' / měs.',
                    'monthly' => $kurz === null ? $puvodne : (int) round(abs((float) $p->amount) * $kurz),
                    // Jednorázová část se u pravidelné platby nikde nevede;
                    // dopsat odhad by znamenalo přičíst číslo, které nikdo nezadal.
                    'oneOff' => 0,
                    'note' => $mesicu >= 1
                        ? 'Platí se '.$this->pocetMesicu($mesicu).' · dohromady už '
                            .Meny::castka($puvodne * $mesicu, $puvodniMena).'.'
                        : 'Nová pravidelná platba.',
                    'mena' => $kurz === null ? $puvodniMena : $hlavni,
                    'prepocteno' => $kurz !== null && $puvodniMena !== $hlavni,
                    'puvodne' => $puvodne,
                    'puvodniMena' => $puvodniMena,
                ];
            })
            // Nejdražší napřed — po přepočtu, jinak by 10 € stálo za 199 Kč.
            // Co přepočítat nejde, jde na konec: s ostatními se porovnat nedá.
            ->sortBy([
                fn (array $a, array $b) => ($a['mena'] !== $hlavni) <=> ($b['mena'] !== $hlavni),
                fn (array $a, array $b) => $b['monthly'] <=> $a['monthly'],
            ])
            ->take(12)
            ->values()
            ->all();
    }

    private function pocetMesicu(int $mesicu): string
    {
        return $mesicu.' '.match (true) {
            $mesicu === 1 => 'měsíc',
            $mesicu <= 4 => 'měsíce',
            default => 'měsíců',
        };
    }

    /**
     * Cena odkladu: `[{ what, days, cost, open, route, tab }]`.
     *
     * Lhůta, která se protáhla, a co to stálo. Obojí si dvojice zapisuje sama
     * (`delay_cost`, `delay_note`) — dopočítat cenu odkladu z ničeho nejde
     * a odhadnout ji by znamenalo vyčíslit dvojici chybu, kterou nikdo
     * nezměřil.
     *
     * @return list<array<string, mixed>>
     */
    private function cenaOdkladu(GallerySpace $prostor): array
    {
        if (! Tabulky::je('house_dues')) {
            return [];
        }

        $dnes = Cas::dnes();

        return DB::table('house_dues')
            ->where('gallery_space_id', $prostor->id)
            ->whereNotNull('due_on')
            ->where(fn ($q) => $q->whereNotNull('delay_cost')->orWhereNotNull('delay_note'))
            ->orderByDesc('due_on')
            ->limit(20)
            ->get()
            ->map(function (object $z) use ($dnes) {
                $termin = CarbonImmutable::parse($z->due_on)->startOfDay();
                // Vyřízené se počítá ke dni vyřízení, otevřené k dnešku.
                $konec = $z->settled_at ? CarbonImmutable::parse($z->settled_at)->startOfDay() : $dnes;

                return [
                    'what' => $z->delay_note ?: $z->what,
                    'days' => max(0, (int) $termin->diffInDays($konec, false)),
                    'cost' => (int) ($z->delay_cost ?? 0),
                    'open' => $z->settled_at === null,
                    'route' => 'x-domacnost',
                    'tab' => 'dues',
                ];
            })
            ->filter(fn (array $r) => $r['days'] > 0)
            ->values()
            ->all();
    }

    /**
     * Nečekané výdaje: `[{ what, cost, month }]`.
     *
     * Nečekaný je ten, který **nemá kategorii s limitem** — na co si dvojice
     * hranici nedala, s tím nepočítala. Malé částky se nepočítají: nečekaný
     * výdaj za osmdesát korun není nečekaný výdaj, je to oběd.
     *
     * Velikost se měří v hlavní měně. Pokuta 100 € je 2 500 Kč, ne „sto" pod
     * hranicí — `cost` je proto přepočtená částka a `puvodne` s `puvodniMena`
     * říkají, co stálo na účtence. Výdaj v měně bez kurzu se změřit nedá a
     * vynechá se.
     *
     * @return list<array<string, mixed>>
     */
    private function necekane(GallerySpace $prostor): array
    {
        $hlavni = Meny::hlavni($prostor);
        $prevod = $this->prevodnik($prostor, $hlavni, $this->menyUtrat($prostor, $hlavni, CarbonImmutable::now()->subYear()));
        $hranice = $this->hranice($prostor, $hlavni, $prevod);

        if ($hranice === 0.0) {
            return [];
        }

        // Jen limity z rozpočtů, které divák vidí — partnerův soukromý limit
        // by z „nečekaného" výdaje udělal plánovaný, a prozradil by, na co ho má.
        $sLimitem = Tabulky::je('budget_category_limits')
            ? DB::table('budget_category_limits as l')
                ->whereIn('l.budget_id', $this->viditelneRozpocty($prostor))
                ->pluck('finance_category_id')
            : collect();

        return Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->utraty()
            ->where('occurred_at', '>=', Cas::dnes()->startOfYear())
            // Výdaj se ukládá kladně a znaménko dělá `type`; záporná částka
            // je vratka, a ta je stejně velká událost jako nákup. Hranice je
            // v hlavní měně, takže databáze smí předem vyřadit jen malé výdaje
            // v hlavní měně — cizí měny se změří až po přepočtu.
            ->where(fn ($q) => $q
                ->where(fn ($v) => $v->where('amount_from', '>=', $hranice)->orWhere('amount_from', '<=', -$hranice))
                ->orWhere(fn ($v) => $v->whereNotNull('currency_from')->where('currency_from', '!=', $hlavni)))
            ->when($sLimitem->isNotEmpty(), fn ($q) => $q->where(
                fn ($v) => $v->whereNull('category_id')->orWhereNotIn('category_id', $sLimitem),
            ))
            ->orderByDesc('occurred_at')
            ->get(['description', 'counterparty', 'amount_from', 'currency_from', 'occurred_at'])
            ->map(function (Transaction $t) use ($hlavni, $prevod, $hranice) {
                $mena = self::mena($t->currency_from, $hlavni);
                $kurz = $prevod['kurzy'][$mena] ?? null;
                $velikost = $kurz === null ? null : abs((float) $t->amount_from) * $kurz;

                return $velikost === null || $velikost < $hranice ? null : [
                    'what' => $t->description ?: ($t->counterparty ?: 'Bez popisu'),
                    'cost' => (int) round($velikost),
                    'month' => self::MESICE[CarbonImmutable::parse($t->occurred_at)->month],
                    'mena' => $hlavni,
                    'prepocteno' => $mena !== $hlavni,
                    'puvodne' => round(abs((float) $t->amount_from), 2),
                    'puvodniMena' => $mena,
                ];
            })
            ->filter()
            ->take(20)
            ->values()
            ->all();
    }

    /**
     * Kolik je „velký" výdaj u téhle dvojice — v hlavní měně.
     *
     * Ne pevná tisícovka: u někoho je nečekaných pět set, u někoho pět tisíc.
     * Bere se desetinásobek běžné útraty — medián, ne průměr, aby to jeden
     * velký nákup neposunul. Medián z korun a eur dohromady byl medián čísel,
     * ne peněz; proto se každá částka nejdřív přepočte, a co přepočítat nejde,
     * do mediánu nevstoupí.
     *
     * @param  array{cil: string, kurzy: array<string, float>, kurzKeDni: ?string, chybi: list<string>}  $prevod
     */
    private function hranice(GallerySpace $prostor, string $hlavni, array $prevod): float
    {
        $castky = Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->utraty()
            ->where('occurred_at', '>=', CarbonImmutable::now()->subYear())
            ->get(['amount_from', 'currency_from'])
            ->map(function (Transaction $t) use ($hlavni, $prevod) {
                $kurz = $prevod['kurzy'][self::mena($t->currency_from, $hlavni)] ?? null;

                return $kurz === null ? null : abs((float) $t->amount_from) * $kurz;
            })
            ->filter()
            ->sort()
            ->values();

        if ($castky->count() < 5) {
            return 0.0;
        }

        return max(1000.0, $castky[(int) floor($castky->count() / 2)] * 10);
    }

    /**
     * Odhad proti skutečnosti: `[{ name, who, unit, est, real }]`.
     *
     * Cíl rozpočtu je odhad („na dovolenou 45 000"), útrata v jeho kategorii
     * je skutečnost. Bez obojího se řádek neposílá — polovina té dvojice
     * neříká nic.
     *
     * Limit je v měně svého rozpočtu (`mena`), a útrata se do ní přepočte:
     * eurová večeře se do korunového limitu na jídlo počítá, jen ne jako
     * „sto" korun. Měna bez kurzu se vynechá a řádek ji jmenuje v `chybi`.
     *
     * @return list<array<string, mixed>>
     */
    private function odhadySkutecnost(GallerySpace $prostor): array
    {
        if (! Tabulky::je('budget_category_limits')) {
            return [];
        }

        $jmena = $prostor->members()->pluck('users.name', 'users.id')->all();
        $hlavni = Meny::hlavni($prostor);

        // Soukromý rozpočet druhého (jména i limity) sem nepatří — a smazaný
        // rozpočet už žádný odhad nenese.
        $limity = DB::table('budget_category_limits as l')
            ->join('budgets as r', 'r.id', '=', 'l.budget_id')
            ->join('finance_categories as k', 'k.id', '=', 'l.finance_category_id')
            ->whereIn('r.id', $this->viditelneRozpocty($prostor))
            ->get(['l.finance_category_id', 'l.amount', 'k.name', 'r.created_by', 'r.currency']);

        if ($limity->isEmpty()) {
            return [];
        }

        /*
         * Do konce dneška dvojice, ne do „teď" v UTC.
         *
         * `occurred_at` je datum (půlnoc), horní mez byl okamžik v UTC. Mezi
         * pražskou půlnocí a druhou ráno je to ještě včerejší večer, takže
         * dnešní útrata ze skutečnosti vypadla — a 1. ledna v noci bylo okno
         * „od Nového roku do teď" prázdné a odhady zmizely úplně.
         */
        $utraceno = $this->utracenoPoKategoriich(
            $prostor,
            $hlavni,
            Cas::dnes()->startOfYear(),
            Cas::dnes()->endOfDay(),
        );

        $meny = collect($utraceno)->flatMap(fn (array $poMenach) => array_keys($poMenach))->unique()->values();
        // Jeden převodník na měnu rozpočtu — kurzy se nehledají pro každý řádek znovu.
        $prevody = [];

        return $limity
            ->map(function (object $l) use ($jmena, $utraceno, $hlavni, $meny, $prostor, &$prevody) {
                $mena = self::mena($l->currency, $hlavni);
                $prevody[$mena] ??= $this->prevodnik($prostor, $hlavni, $meny, $mena);
                $skutecnost = self::prepocti($utraceno[$l->finance_category_id] ?? [], $prevody[$mena]);

                return [
                    'name' => $l->name,
                    'who' => $jmena[$l->created_by] ?? 'spolu',
                    'unit' => 'kc',
                    'est' => (int) round((float) $l->amount),
                    'real' => (int) round($skutecnost['castka']),
                    'mena' => $mena,
                    'prepocteno' => $skutecnost['prepocteno'],
                    'popisek' => $this->popisek($skutecnost['prepocteno'], $prevody[$mena]),
                    'chybi' => $skutecnost['chybi'],
                ];
            })
            // Kategorie, do které se letos nesáhlo, není odhad — je to plán.
            ->filter(fn (array $r) => $r['real'] > 0 && $r['est'] > 0)
            ->sortByDesc(fn (array $r) => abs($r['real'] - $r['est']))
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * Co ta útrata znamenala: `[název, částka, podíl, věta, ikona]`.
     *
     * Podíl na ročním rozpočtu je to jediné, co k tomu aplikace umí říct sama.
     * Věty typu „za rok je z toho letenka do Lisabonu" se **nevymýšlí** —
     * místo nich stojí, kolik z celku to je a kolikátá největší položka to je.
     *
     * Částky i podíly jsou v hlavní měně. Řádek má na konci dvě místa navíc:
     * `[5]` měna částky a `[6]` poznámku k ní — „přepočteno kurzem ECB k …",
     * nebo které měny chybí, protože k nim kurz není. Útrata v měně bez kurzu
     * se do částek ani do celku nepočítá: podíl z eur a korun dohromady by byl
     * podíl čísel, ne peněz.
     *
     * @return list<array<int, mixed>>
     */
    private function coToZnamenalo(GallerySpace $prostor): array
    {
        $od = Cas::dnes()->startOfYear();
        $hlavni = Meny::hlavni($prostor);

        $poKategoriich = Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->utraty()
            ->where('occurred_at', '>=', $od)
            ->with('category:id,name,icon')
            ->get(['category_id', 'amount_from', 'currency_from'])
            ->groupBy(fn (Transaction $t) => $t->category?->name ?? 'Nezařazeno');

        if ($poKategoriich->isEmpty()) {
            return [];
        }

        $prevod = $this->prevodnik(
            $prostor,
            $hlavni,
            $poKategoriich->flatten()->map(fn (Transaction $t) => self::mena($t->currency_from, $hlavni)),
        );

        $kategorie = $poKategoriich
            ->map(function (Collection $pohyby, string $nazev) use ($hlavni, $prevod) {
                $poMenach = [];

                foreach ($pohyby as $t) {
                    $mena = self::mena($t->currency_from, $hlavni);
                    $poMenach[$mena] = ($poMenach[$mena] ?? 0.0) + abs((float) $t->amount_from);
                }

                return [
                    'nazev' => $nazev,
                    'soucet' => self::prepocti($poMenach, $prevod),
                    'pocet' => $pohyby->count(),
                    'ikona' => $this->ikonaKategorie($pohyby->first()?->category?->icon),
                ];
            })
            ->filter(fn (array $k) => $k['soucet']['castka'] > 0);

        $celkem = (float) $kategorie->sum(fn (array $k) => $k['soucet']['castka']);

        if ($celkem <= 0) {
            return [];
        }

        return $kategorie
            ->sortByDesc(fn (array $k) => $k['soucet']['castka'])
            ->take(8)
            ->values()
            ->map(function (array $k) use ($celkem, $hlavni, $prevod) {
                $podil = $k['soucet']['castka'] / $celkem * 100;
                $poznamka = array_filter([
                    $this->popisek($k['soucet']['prepocteno'], $prevod),
                    $k['soucet']['chybi'] !== [] ? 'bez '.implode(', ', $k['soucet']['chybi']).' — chybí kurz' : null,
                ]);

                return [
                    $k['nazev'],
                    (int) round($k['soucet']['castka']),
                    str_replace('.', ',', (string) round($podil, 1)).' % letošních výdajů',
                    $this->pocet($k['pocet'], 'platba', 'platby', 'plateb').' za tenhle rok.',
                    $k['ikona'],
                    $hlavni,
                    $poznamka === [] ? null : implode(' · ', $poznamka),
                ];
            })
            ->all();
    }

    /**
     * Utraceno po kategoriích a měnách za dané období: `[kategorie => [měna => částka]]`.
     *
     * Po měnách, protože limit, se kterým se to porovnává, má svou měnu — a součet
     * přes měny by do korunového limitu započítal eura jako koruny.
     *
     * @return array<int, array<string, float>>
     */
    private function utracenoPoKategoriich(GallerySpace $prostor, string $hlavni, CarbonImmutable $od, CarbonImmutable $do): array
    {
        $utraceno = [];

        Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->utraty()
            ->whereBetween('occurred_at', [$od, $do])
            ->selectRaw('category_id, currency_from, SUM(ABS(amount_from)) AS castka')
            ->groupBy('category_id', 'currency_from')
            ->get()
            ->each(function (Transaction $r) use (&$utraceno, $hlavni) {
                $mena = self::mena($r->currency_from, $hlavni);
                $kategorie = (int) $r->category_id;
                $utraceno[$kategorie][$mena] = ($utraceno[$kategorie][$mena] ?? 0.0) + (float) $r->getAttribute('castka');
            });

        return $utraceno;
    }

    private function ikonaKategorie(?string $ikona): string
    {
        return $ikona ? 'ph-'.ltrim($ikona, 'ph-') : 'ph-tag';
    }

    /**
     * Obálka jen pro sebe: `{ limit, months: [{ m, a, k }] }`.
     *
     * Jediná položka rozpočtu bez odůvodnění — proto se počítá z kategorie,
     * kterou si dvojice takhle pojmenovala, a ne z odhadu. Bez ní se neposílá
     * nic: vymyslet, kolik si kdo „smí vzít, aniž by se ptal", by bylo to
     * poslední, co by měl dělat server.
     *
     * Čerpání jsou jen zapsané výdaje (`utraty()`), jako všude jinde v rozborech:
     * rozepsaný koncept ani příjem do kategorie z obálky nic nevzaly. A počítá se
     * v měně limitu (bez limitu v hlavní měně) — `mena` to říká, `vynechano`
     * jmenuje měny, ke kterým není kurz.
     *
     * @return array<string, mixed>|null
     */
    private function obalka(GallerySpace $prostor): ?array
    {
        $kategorie = self::osobniKategorie($prostor);

        if (! $kategorie) {
            return null;
        }

        $dvojice = $this->dvojice($prostor);
        $od = Cas::dnes()->startOfMonth()->subMonths(self::MESICU - 1);
        $hlavni = Meny::hlavni($prostor);

        $pohyby = Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->utraty()
            ->where('category_id', $kategorie->id)
            ->where('occurred_at', '>=', $od)
            ->get(['occurred_at', 'amount_from', 'amount_to', 'currency_from', 'currency_to', 'payer_partner_id']);

        $limit = $this->limitKategorie($prostor, $kategorie->id);
        $mena = $limit !== null ? self::mena($limit->currency, $hlavni) : $hlavni;
        $prevod = $this->prevodnik(
            $prostor,
            $hlavni,
            $pohyby->map(fn (Transaction $t) => self::mena($t->currency_from ?? $t->currency_to, $hlavni)),
            $mena,
        );

        $mesice = [];
        $prepocteno = false;
        $vynechano = [];

        for ($i = 0; $i < self::MESICU; $i++) {
            $mesic = $od->addMonths($i);

            $vMesici = $pohyby->filter(
                fn (Transaction $t) => CarbonImmutable::parse($t->occurred_at)->isSameMonth($mesic),
            );

            $a = $this->soucet($vMesici, $dvojice[0], $hlavni, $prevod);
            $k = $this->soucet($vMesici, $dvojice[1], $hlavni, $prevod);
            $prepocteno = $prepocteno || $a['prepocteno'] || $k['prepocteno'];
            $vynechano = array_merge($vynechano, $a['chybi'], $k['chybi']);

            $mesice[] = [
                'm' => self::MESICE[$mesic->month],
                'a' => (int) round($a['castka']),
                'k' => (int) round($k['castka']),
            ];
        }

        return [
            'limit' => (int) round((float) ($limit->amount ?? 0)),
            'months' => $mesice,
            // Pod tímhle jménem se obálka zvedá („Zvednout obálku" → limit kategorie).
            'kategorie' => $kategorie->name,
            'mena' => $mena,
            'prepocteno' => $prepocteno,
            'popisek' => $this->popisek($prepocteno, $prevod),
            'vynechano' => array_values(array_unique($vynechano)),
        ];
    }

    /**
     * Vlastní inflace: `{ name, y25, y26, qty, cat }`.
     *
     * Ne ta ze zpráv — tahle se počítá z toho, co dvojice doopravdy kupuje
     * pořád dokola. Bere se **medián**, ne průměr: jeden velký nákup by jinak
     * z rohlíků udělal luxusní zboží.
     *
     * Ceny se porovnávají v měně, ve které se platily: káva ve Vídni za 4 € a
     * doma za 79 Kč jsou dvě různé ceny, ne jedna, která „zlevnila". Teprve
     * mediány se přepočtou do hlavní měny (`mena`), aby se řádky daly sečíst —
     * oba roky stejným dnešním kurzem, takže zdražení zůstane, jaké bylo.
     * Řádek v měně bez kurzu se neposílá; obrazovka by ho sečetla s korunami.
     *
     * @return list<array<string, mixed>>
     */
    private function inflace(GallerySpace $prostor): array
    {
        $letos = Cas::dnes()->year;
        $hlavni = Meny::hlavni($prostor);

        $pohyby = Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->utraty()
            ->where('occurred_at', '>=', CarbonImmutable::create($letos - 1, 1, 1))
            ->with('category:id,name')
            ->get();

        $prevod = $this->prevodnik($prostor, $hlavni, $pohyby->map(fn (Transaction $t) => self::mena($t->currency_from, $hlavni)));

        return $pohyby
            ->filter(fn (Transaction $t) => trim((string) $t->description) !== '')
            ->groupBy(fn (Transaction $t) => mb_strtolower(trim((string) $t->description)).'|'.self::mena($t->currency_from, $hlavni))
            ->map(function (Collection $stejne) use ($letos, $hlavni, $prevod) {
                $mena = self::mena($stejne->first()->currency_from, $hlavni);
                $kurz = $prevod['kurzy'][$mena] ?? null;

                if ($kurz === null) {
                    return null;
                }

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
                    'y25' => (int) round($loni->median() * $kurz),
                    'y26' => (int) round($ted->median() * $kurz),
                    'qty' => $ted->count(),
                    'cat' => mb_strtolower((string) ($prvni->category?->name ?? 'ostatní')),
                    'mena' => $hlavni,
                    'prepocteno' => $mena !== $hlavni,
                    'puvodniMena' => $mena,
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
        if (! Tabulky::je('budget_goals')) {
            return [];
        }

        $dnes = CarbonImmutable::now();

        // Fondy jen z viditelných a nesmazaných rozpočtů: „Její překvapení"
        // z partnerova soukromého rozpočtu by jinak viděl ten, pro koho je.
        return DB::table('budget_goals as c')
            ->whereIn('c.budget_id', $this->viditelneRozpocty($prostor))
            ->orderBy('c.sort_order')
            ->get(['c.uuid', 'c.name', 'c.target_amount', 'c.saved_amount', 'c.currency', 'c.target_on', 'c.note'])
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
                    // Cíl si svou měnu drží: fond na dovolenou v eurech je v eurech.
                    'mena' => self::mena($c->currency, Meny::HLAVNI),
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
     * Útraty se sečtou po měnách a přepočtou do hlavní měny (`mena`), takže večeře
     * za 100 € a vlak za tisíc korun dají 3 500 Kč, ne 1 100. Když ke kurzu není
     * přístup, cesta placená jen v jedné měně zůstane v ní; smíšená cesta ukáže
     * jen to, co přepočítat jde, a zbytek jmenuje ve `vynechano`. S předchozí
     * cestou se porovnává jen ve stejné měně — tisíc korun na den proti tisíci
     * eur na den by tvrdilo, že obě cesty stály stejně.
     *
     * @return array<string, array<string, mixed>>
     */
    private function cenyCest(GallerySpace $prostor): array
    {
        if (! Tabulky::je('trips') || ! Tabulky::je('trip_expenses')) {
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

        // Kolik lidí na cestě doopravdy bylo. `max(2, …)` vymyslel druhého
        // člověka i v prostoru, kde je jeden — a každé „na osobu a den"
        // tím spadlo na polovinu.
        // Host galerie na cestě nebyl — „na osobu" se dělí jen dvojicí.
        $lidi = max(1, app(PristupDoGalerie::class)->dvojice($prostor)->count());
        $hlavni = Meny::hlavni($prostor);
        $prevod = $this->prevodnik($prostor, $hlavni, $utraty->flatten(1)->map(fn ($u) => self::mena($u->currency, $hlavni)));
        $vysledek = [];
        // Předchozí cesta pro každou měnu zvlášť — viz popis nahoře.
        $predchozi = [];

        // Odzadu, aby každá cesta znala tu předchozí.
        foreach ($cesty->reverse() as $c) {
            $moje = $utraty[$c->id] ?? collect();

            if ($moje->isEmpty()) {
                continue;
            }

            $od = CarbonImmutable::parse($c->start_date);
            $dnu = max(1, (int) $od->diffInDays(CarbonImmutable::parse($c->end_date)) + 1);
            $mojePrevod = $this->prevodCesty($moje, $hlavni, $prevod);
            $soucet = self::prepocti(self::castkyCesty($moje, $hlavni), $mojePrevod);
            $mena = $mojePrevod['cil'];
            $celkem = (int) round($soucet['castka']);

            $vysledek[$this->klic($c->name, $vysledek)] = [
                'total' => $celkem,
                'days' => $dnu,
                'people' => $lidi,
                'prev' => $predchozi[$mena]['name'] ?? '',
                'prevPerDay' => $predchozi[$mena]['perDay'] ?? 0,
                'items' => $moje
                    ->groupBy('category')
                    ->map(function (Collection $co, $kategorie) use ($hlavni, $mojePrevod) {
                        $polozka = self::prepocti(self::castkyCesty($co, $hlavni), $mojePrevod);

                        // Kategorie zaplacená jen v měně bez kurzu nemá částku, kterou
                        // by šlo ukázat — nula by lhala, že nestála nic.
                        return $polozka['chybi'] !== [] && abs($polozka['castka']) < 0.005 ? null : [
                            'name' => $this->kategorie((string) $kategorie),
                            'amount' => (int) round($polozka['castka']),
                            'note' => $this->pocet($co->count(), 'položka', 'položky', 'položek'),
                        ];
                    })
                    ->filter()
                    ->sortByDesc('amount')
                    ->values()
                    ->all(),
                'mena' => $mena,
                'prepocteno' => $soucet['prepocteno'],
                'popisek' => $this->popisek($soucet['prepocteno'], $mojePrevod),
                'vynechano' => $soucet['chybi'],
            ];

            $predchozi[$mena] = ['name' => $c->name, 'perDay' => (int) round($celkem / $dnu)];
        }

        return $vysledek;
    }

    /**
     * Útraty cesty po měnách: `[měna => částka]`.
     *
     * @param  Collection<int, object>  $utraty
     * @return array<string, float>
     */
    private static function castkyCesty(Collection $utraty, string $hlavni): array
    {
        return SouctyPoMenach::secti($utraty, fn ($u) => $u->currency, fn ($u) => (float) $u->amount, $hlavni);
    }

    /**
     * Do jaké měny se cesta počítá.
     *
     * Do hlavní, když to jde. Cesta zaplacená celá v jedné měně, ke které kurz
     * zrovna není, zůstane ve své měně — celé číslo v eurech řekne víc než nula
     * korun. Smíšená cesta bez kurzu zůstane v hlavní měně a to, co chybí, vyjde
     * z `prepocti()` v `chybi`.
     *
     * @param  Collection<int, object>  $utraty
     * @param  array{cil: string, kurzy: array<string, float>, kurzKeDni: ?string, chybi: list<string>}  $prevod
     * @return array{cil: string, kurzy: array<string, float>, kurzKeDni: ?string, chybi: list<string>}
     */
    private function prevodCesty(Collection $utraty, string $hlavni, array $prevod): array
    {
        $meny = array_keys(self::castkyCesty($utraty, $hlavni));

        if (count($meny) === 1 && ! isset($prevod['kurzy'][$meny[0]])) {
            return ['cil' => $meny[0], 'kurzy' => [$meny[0] => 1.0], 'kurzKeDni' => null, 'chybi' => []];
        }

        return $prevod;
    }

    // ——— měny ———

    /**
     * Kód měny položky: velkými písmeny, a bez měny je to hlavní měna.
     *
     * Starší zápisy měnu nemají — do aplikace se dřív psalo jen v korunách. Stejně
     * to čte `ExchangeRateService::doHlavni()`, takže se klíče obou stran potkají.
     */
    private static function mena(?string $mena, string $hlavni): string
    {
        return SouctyPoMenach::klic($mena, $hlavni);
    }

    /**
     * Řádky `{mena, castka}` z dotazu po měnách jako `[měna => částka]`.
     *
     * Prázdná měna a hlavní měna jsou jedna — sečtou se.
     *
     * @param  iterable<object>  $radky
     * @return array<string, float>
     */
    private static function poMenach(iterable $radky, string $hlavni): array
    {
        return SouctyPoMenach::secti($radky, fn ($r) => $r->mena, fn ($r) => (float) $r->castka, $hlavni);
    }

    /**
     * Měny zapsaných útrat od daného dne — ať se kurzy hledají jen pro ty, co tu jsou.
     *
     * @return list<string>
     */
    private function menyUtrat(GallerySpace $prostor, string $hlavni, CarbonImmutable $od): array
    {
        return Transaction::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->utraty()
            ->where('occurred_at', '>=', $od)
            ->distinct()
            ->pluck('currency_from')
            ->map(fn ($mena) => self::mena($mena, $hlavni))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Kurzy z daných měn do cílové: `{cil, kurzy: [měna => kurz], kurzKeDni, chybi}`.
     *
     * Kurzy se zjistí jednou pro celý rozbor a částky se přepočtou až po sečtení
     * po měnách — sto drobných přepočtů by nasbíralo zaokrouhlovací chybu. Cíl je
     * hlavní měna prostoru; u limitu rozpočtu měna toho rozpočtu.
     *
     * Do hlavní měny jde přes `doHlavni()`: po prvním výpadku se na síť už neptá,
     * takže nedostupné kurzy obrazovku zdrží jednou, ne za každou měnu.
     * Ruční tabulka `currency_rates` se nepoužívá (viz `ExchangeRateService`).
     *
     * @param  iterable<string>  $meny
     * @return array{cil: string, kurzy: array<string, float>, kurzKeDni: ?string, chybi: list<string>}
     */
    private function prevodnik(GallerySpace $prostor, string $hlavni, iterable $meny, ?string $cil = null): array
    {
        $cil = self::mena($cil, $hlavni);
        $ostatni = collect($meny)
            ->map(fn ($mena) => self::mena((string) $mena, $hlavni))
            ->unique()
            ->reject(fn (string $mena) => $mena === $cil)
            ->values()
            ->all();

        $prevod = ['cil' => $cil, 'kurzy' => [$cil => 1.0], 'kurzKeDni' => null, 'chybi' => []];

        if ($ostatni === []) {
            return $prevod;
        }

        $smenarna = app(ExchangeRateService::class);

        if ($cil === $hlavni) {
            $vysledek = $smenarna->doHlavni(array_fill_keys($ostatni, 1.0), $prostor);

            return [...$prevod, 'kurzy' => $prevod['kurzy'] + $vysledek['kurzy'], 'kurzKeDni' => $vysledek['kurzKeDni'], 'chybi' => $vysledek['chybi']];
        }

        foreach ($ostatni as $mena) {
            $kurz = Meny::kod($mena) === null ? null : $smenarna->rate($mena, $cil);

            if ($kurz === null) {
                $prevod['chybi'][] = $mena;

                continue;
            }

            $prevod['kurzy'][$mena] = $kurz['rate'];
            // Nejstarší z použitých kurzů — rozbor není čerstvější než jeho nejstarší část.
            $prevod['kurzKeDni'] = $prevod['kurzKeDni'] === null || $kurz['date'] < $prevod['kurzKeDni'] ? $kurz['date'] : $prevod['kurzKeDni'];
        }

        return $prevod;
    }

    /**
     * Částky po měnách v cílové měně převodníku.
     *
     * Co přepočítat nejde, se nepřičte a vrátí se v `chybi` — polovičatý součet se
     * nesmí tvářit jako celý. `prepocteno` říká, jestli se opravdu sahalo na kurz.
     *
     * @param  array<string, float>  $poMenach
     * @param  array{cil: string, kurzy: array<string, float>, kurzKeDni: ?string, chybi: list<string>}  $prevod
     * @return array{castka: float, prepocteno: bool, chybi: list<string>}
     */
    private static function prepocti(array $poMenach, array $prevod): array
    {
        $castka = 0.0;
        $prepocteno = false;
        $chybi = [];

        foreach ($poMenach as $mena => $kolik) {
            if (abs($kolik) < 0.005) {
                continue;
            }

            $kurz = $prevod['kurzy'][$mena] ?? null;

            if ($kurz === null) {
                $chybi[] = (string) $mena;

                continue;
            }

            $castka += round($kolik, 2) * $kurz;
            $prepocteno = $prepocteno || $mena !== $prevod['cil'];
        }

        return ['castka' => $castka, 'prepocteno' => $prepocteno, 'chybi' => $chybi];
    }

    /**
     * „přepočteno kurzem ECB k 24. 9. 2026", nebo null, když se nepřepočítávalo.
     *
     * @param  array{cil: string, kurzy: array<string, float>, kurzKeDni: ?string, chybi: list<string>}  $prevod
     */
    private function popisek(bool $prepocteno, array $prevod): ?string
    {
        return app(ExchangeRateService::class)->popisek(['prepocteno' => $prepocteno, 'kurzKeDni' => $prevod['kurzKeDni']]);
    }

    // ——— dílky ———

    /**
     * Kategorie, kterou si dvojice vede jako osobní obálku.
     *
     * Pozná se podle jména — vlastní příznak na to v aplikaci není a vymýšlet
     * ho jen kvůli jedné obrazovce by bylo horší než se zeptat názvu.
     */
    public static function osobniKategorie(GallerySpace $prostor): ?object
    {
        if (! Tabulky::je('finance_categories')) {
            return null;
        }

        return DB::table('finance_categories')
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                foreach (['obálka', 'obalka', 'osobní', 'osobni', 'kapesné', 'kapesne'] as $slovo) {
                    $q->orWhereRaw('LOWER(name) LIKE ?', ['%'.$slovo.'%']);
                }
            })
            ->first(['id', 'name']);
    }

    /**
     * Limit kategorie z nejnovějšího viditelného rozpočtu: `{amount, currency}`, nebo null.
     *
     * S měnou toho rozpočtu — limit „400" je v eurovém rozpočtu 400 €, a čerpání
     * se proto přepočítává do eur, ne do korun.
     */
    private function limitKategorie(GallerySpace $prostor, int $kategorie): ?object
    {
        if (! Tabulky::je('budget_category_limits')) {
            return null;
        }

        return DB::table('budget_category_limits as l')
            ->join('budgets as r', 'r.id', '=', 'l.budget_id')
            ->whereIn('l.budget_id', $this->viditelneRozpocty($prostor))
            ->where('l.finance_category_id', $kategorie)
            ->orderByDesc('l.budget_id')
            ->first(['l.amount', 'r.currency']);
    }

    /**
     * Rozpočty, ze kterých se smí počítat — viz `FinanceAccess::viditelneRozpocty()`.
     *
     * Bez paměti mezi voláními: poskytovatel může v kontejneru žít déle než
     * jeden požadavek, a seznam uložený pro jednoho diváka by dostal druhý.
     *
     * @return list<int>
     */
    private function viditelneRozpocty(GallerySpace $prostor): array
    {
        $ja = auth()->id();

        return FinanceAccess::viditelneRozpocty((int) $prostor->id, $ja !== null ? (int) $ja : null);
    }

    /**
     * Dvojice jako partneři plateb — ten, kdo se dívá, první.
     *
     * Obrazovka obálky popisuje `a` jménem z `DVOJICE`, kde je první ten, kdo
     * se dívá. Se zakladatelem prostoru na prvním místě viděla Makinka
     * Adrianovo čerpání pod svým jménem.
     *
     * @return array<int, int|null>
     */
    private function dvojice(GallerySpace $prostor): array
    {
        if (! Tabulky::je('partners')) {
            return [null, null];
        }

        // Jen dvojice, ne hosté: host s nižším id seděl na místě `k`
        // a partnerovo čerpání z obálky zmizelo.
        $lide = app(PristupDoGalerie::class)->dvojiceOdDivaka($prostor, auth()->user())
            ->map(fn ($clen) => (int) $clen->id)
            ->values();

        $partneri = DB::table('partners')
            ->where('gallery_space_id', $prostor->id)
            ->whereIn('user_id', $lide)
            ->pluck('id', 'user_id');

        return [$partneri[$lide[0] ?? 0] ?? null, $partneri[$lide[1] ?? 0] ?? null];
    }

    /**
     * Kolik partner z obálky vzal — po měnách sečtené a přepočtené převodníkem.
     *
     * @param  Collection<int, Transaction>  $pohyby
     * @param  array{cil: string, kurzy: array<string, float>, kurzKeDni: ?string, chybi: list<string>}  $prevod
     * @return array{castka: float, prepocteno: bool, chybi: list<string>}
     */
    private function soucet(Collection $pohyby, ?int $partner, string $hlavni, array $prevod): array
    {
        if (! $partner) {
            return ['castka' => 0.0, 'prepocteno' => false, 'chybi' => []];
        }

        $poMenach = [];

        foreach ($pohyby as $t) {
            if ((int) $t->payer_partner_id !== $partner) {
                continue;
            }

            $mena = self::mena($t->currency_from ?? $t->currency_to, $hlavni);
            $poMenach[$mena] = ($poMenach[$mena] ?? 0.0) + abs((float) ($t->amount_from ?? $t->amount_to ?? 0));
        }

        return self::prepocti($poMenach, $prevod);
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
