<?php

namespace App\Services\Finance;

use App\Models\FinanceCategory;
use App\Models\FinanceProject;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Výpočty modulu Rozpočet.
 *
 * Celá služba stojí na jednom rozlišení, které se v aplikacích na peníze plete
 * nejčastěji: **co je spotřeba a co je jen přesun.** Výběr pěti set eur z bankomatu
 * není výdaj — peníze nikam nezmizely. Směna padesáti tisíc korun na eura není výdaj
 * ani příjem — je to tatáž hodnota v jiné měně. U obojího je skutečným nákladem jen
 * poplatek.
 *
 * Kdyby se to nerozlišovalo, součty by pořád „seděly" a přitom by lhaly: měsíc, ve
 * kterém se jednou směnilo osm tisíc eur, by vypadal jako měsíc s osmitisícovým
 * příjmem a nikdo by nepoznal, že žádné peníze nepřibyly.
 */
class FinanceService
{
    /**
     * Zůstatky peněženek, seskupené po měnách.
     *
     * Měny se nesčítají do jedné částky. Součet „12 400" bez měny je číslo, se kterým
     * se nedá nic dělat — a přepočet na jednu měnu by předstíral přesnost, kterou kurz
     * nemá. Kdo chce jedno číslo, dostane ho jako výslovně informativní odhad jinde.
     *
     * @return array{by_currency: array<int, array<string, mixed>>, wallets: array<int, array<string, mixed>>}
     */
    public function balances(GallerySpace $space, ?Carbon $kDatu = null): array
    {
        $penezenky = Wallet::where('gallery_space_id', $space->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with('partner:id,name')
            ->get();

        $pohyby = Transaction::where('gallery_space_id', $space->id)
            ->when($kDatu, fn ($q) => $q->whereDate('occurred_at', '<=', $kDatu))
            ->get(['wallet_from_id', 'wallet_to_id', 'amount_from', 'amount_to', 'fee_amount', 'fee_currency', 'fee_included', 'currency_from']);

        $odchozi = $pohyby->groupBy('wallet_from_id')->map(fn (Collection $s) => (float) $s->sum('amount_from'));
        $prichozi = $pohyby->groupBy('wallet_to_id')->map(fn (Collection $s) => (float) $s->sum('amount_to'));

        // Poplatek placený navíc odešel ze zdrojové peněženky a v `amount_from` není.
        // Bez tohohle řádku by zůstatek na účtu vycházel o poplatky vyšší, než jaký je.
        $poplatky = $pohyby
            ->filter(fn (Transaction $t) => ! $t->fee_included && (float) $t->fee_amount > 0)
            ->groupBy('wallet_from_id')
            ->map(fn (Collection $s) => (float) $s->sum('fee_amount'));

        $radky = $penezenky->map(function (Wallet $p) use ($odchozi, $prichozi, $poplatky) {
            $zustatek = (float) $p->opening_balance
                + ($prichozi[$p->id] ?? 0)
                - ($odchozi[$p->id] ?? 0)
                - ($poplatky[$p->id] ?? 0);

            return [
                'uuid' => $p->uuid,
                'name' => $p->name,
                'kind' => $p->kind,
                'kind_label' => $this->druhPenezenky($p->kind),
                'currency' => $p->currency,
                'owner' => $p->partner?->name,
                'opening_balance' => (float) $p->opening_balance,
                'balance' => round($zustatek, 2),
                'is_negative' => $zustatek < 0,
            ];
        })->values();

        $poMenach = $radky->groupBy('currency')->map(fn (Collection $s, string $mena) => [
            'currency' => $mena,
            'total' => round((float) $s->sum('balance'), 2),
            'cash' => round((float) $s->where('kind', 'cash')->sum('balance'), 2),
            'wallets' => $s->count(),
        ])->values();

        return ['by_currency' => $poMenach->all(), 'wallets' => $radky->all()];
    }

    /**
     * Příjmy, výdaje a čistý rozdíl za období — po měnách.
     *
     * Do součtů jde jen spotřeba. Poplatek je jediná část přesunů, která je skutečným
     * nákladem, a připočítává se k výdajům v měně, ve které se platil.
     *
     * @param  Collection<int, Transaction>  $pohyby
     * @return array<int, array<string, mixed>>
     */
    public function summary(Collection $pohyby): array
    {
        $vysledek = [];

        foreach ($pohyby as $t) {
            /** @var Transaction $t */
            if ($t->type === 'income' && ! $t->is_settlement) {
                $mena = $t->currency_to ?? $t->currency_from;
                $vysledek[$mena]['income'] = ($vysledek[$mena]['income'] ?? 0) + (float) $t->amount_to;
            }

            if ($t->type === 'expense' && ! $t->is_settlement) {
                $mena = $t->currency_from;
                $vysledek[$mena]['expense'] = ($vysledek[$mena]['expense'] ?? 0) + (float) $t->amount_from;
            }

            $poplatek = $t->feePaidExtra();

            if ($poplatek > 0) {
                $mena = $t->fee_currency ?? $t->currency_from;
                $vysledek[$mena]['fees'] = ($vysledek[$mena]['fees'] ?? 0) + $poplatek;
            }
        }

        $radky = [];

        foreach ($vysledek as $mena => $c) {
            $prijem = round($c['income'] ?? 0, 2);
            $vydaj = round($c['expense'] ?? 0, 2);
            $poplatky = round($c['fees'] ?? 0, 2);

            $radky[] = [
                'currency' => $mena,
                'income' => $prijem,
                'expense' => $vydaj,
                'fees' => $poplatky,
                // Poplatky patří k výdajům — jsou to peníze, které banka nevrátí.
                'spent' => round($vydaj + $poplatky, 2),
                'net' => round($prijem - $vydaj - $poplatky, 2),
            ];
        }

        usort($radky, fn (array $a, array $b) => $b['spent'] <=> $a['spent']);

        return $radky;
    }

    /**
     * Skutečný kurz jedné směny, včetně poplatku.
     *
     * Vždycky jako **korun za jedno euro**, ať se směňuje kterýmkoli směrem. Kdyby se
     * u směny zpět ukázalo „euro za korunu", byla by to sice pravda, ale porovnat dvě
     * čísla, z nichž jedno je 24,5 a druhé 0,0408, nedokáže nikdo — a právě porovnání
     * je jediné, kvůli čemu se kurz ukazuje.
     *
     * Poplatek se započítá právě jednou. `fee_included` říká, že už je v částkách
     * obsažený; přičíst ho pak znovu by udělalo kurz horší, než jaký doopravdy byl.
     *
     * @return array<string, mixed>|null
     */
    public function exchangeRate(Transaction $t): ?array
    {
        if ($t->type !== 'exchange' || ! $t->amount_from || ! $t->amount_to) {
            return null;
        }

        $poplatek = $t->feePaidExtra();
        $mena = $t->fee_currency ?? $t->currency_from;

        // Náklad je to, co doopravdy odešlo; výnos to, co doopravdy přišlo.
        $vydano = (float) $t->amount_from + ($poplatek > 0 && $mena === $t->currency_from ? $poplatek : 0);
        $prijato = (float) $t->amount_to - ($poplatek > 0 && $mena === $t->currency_to ? $poplatek : 0);

        if ($vydano <= 0 || $prijato <= 0) {
            return null;
        }

        $doEur = $t->currency_to === 'EUR';

        // CZK za 1 EUR: podle směru je euro jednou na straně přijaté, jednou vydané.
        $kurz = $doEur ? $vydano / $prijato : $prijato / $vydano;

        // Nominální kurz bez poplatku — rozdíl proti efektivnímu je cena té směny.
        $nominalni = $doEur
            ? (float) $t->amount_from / (float) $t->amount_to
            : (float) $t->amount_to / (float) $t->amount_from;

        return [
            'direction' => $doEur ? 'czk_to_eur' : 'eur_to_czk',
            'spent' => round($vydano, 2),
            'spent_currency' => $t->currency_from,
            'received' => round($prijato, 2),
            'received_currency' => $t->currency_to,
            'effective' => round($kurz, 4),
            'nominal' => round($nominalni, 4),
            'fee' => round($poplatek, 2),
            'fee_currency' => $poplatek > 0 ? $mena : null,
            'fee_included' => (bool) $t->fee_included,
            // Kolik eur padne na tisícikorunu — číslo, které jde porovnat mezi směnami
            // různé velikosti, na rozdíl od samotného objemu.
            'eur_per_1000_czk' => $kurz > 0 ? round(1000 / $kurz, 2) : null,
            'reference' => $t->reference_rate !== null ? (float) $t->reference_rate : null,
            'reference_gap' => $t->reference_rate !== null && (float) $t->reference_rate > 0
                ? round(($kurz - (float) $t->reference_rate) / (float) $t->reference_rate * 100, 2)
                : null,
        ];
    }

    /**
     * Vážený pořizovací kurz držených eur.
     *
     * Odpovídá na otázku „za kolik jsme ta eura, co teď máme, doopravdy pořídili".
     * Prochází se chronologicky a drží se dvě čísla: kolik eur je a kolik korun to
     * stálo. Směna korun na eura obojí zvýší. Útrata v eurech obojí sníží — poměrně,
     * protože z peněženky nejde utratit „to euro z března".
     *
     * Eura z počátečního zůstatku se počítají zvlášť jako neznámá pořizovací cena a
     * do průměru nevstupují. Dopočítat jim kurz by znamenalo si ho vymyslet; radši
     * ať je vidět, že se u části zásoby neví.
     *
     * @return array<string, mixed>
     */
    public function eurAcquisition(GallerySpace $space): array
    {
        $penezenkyEur = Wallet::where('gallery_space_id', $space->id)->where('currency', 'EUR')->pluck('id');

        $neznameEur = (float) Wallet::where('gallery_space_id', $space->id)
            ->where('currency', 'EUR')->sum('opening_balance');

        $pohyby = Transaction::where('gallery_space_id', $space->id)
            ->where(function ($q) use ($penezenkyEur) {
                $q->whereIn('wallet_from_id', $penezenkyEur)->orWhereIn('wallet_to_id', $penezenkyEur);
            })
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $eur = 0.0;      // eura pořízená směnou, u kterých se kurz zná
        $koruny = 0.0;   // co ta eura stála

        foreach ($pohyby as $t) {
            /** @var Transaction $t */
            if ($t->type === 'exchange' && $t->currency_to === 'EUR') {
                $kurz = $this->exchangeRate($t);

                if ($kurz) {
                    $eur += $kurz['received'];
                    $koruny += $kurz['received'] * $kurz['effective'];
                }

                continue;
            }

            // Odliv eur: útrata, směna zpět, nebo odchod mimo eurové peněženky.
            $odchazi = $t->currency_from === 'EUR'
                && $penezenkyEur->contains($t->wallet_from_id)
                && ! ($t->currency_to === 'EUR' && $penezenkyEur->contains($t->wallet_to_id));

            if (! $odchazi) {
                continue;
            }

            $castka = (float) $t->amount_from + ($t->fee_currency === 'EUR' ? $t->feePaidExtra() : 0);

            // Nejdřív se spotřebují eura s neznámou cenou. Jinak by průměr zůstal viset
            // na počátečním zůstatku, který se nikdy neutratí.
            $zNeznamych = min($neznameEur, $castka);
            $neznameEur -= $zNeznamych;
            $zbytek = $castka - $zNeznamych;

            if ($zbytek > 0 && $eur > 0) {
                $podil = min(1.0, $zbytek / $eur);
                $koruny -= $koruny * $podil;
                $eur -= min($eur, $zbytek);
            }
        }

        $drzeno = round($eur + $neznameEur, 2);

        return [
            'held_eur' => $drzeno,
            'known_eur' => round($eur, 2),
            'unknown_eur' => round($neznameEur, 2),
            'cost_czk' => round($koruny, 2),
            // Průměr jen z té části, u které se kurz zná.
            'average_rate' => $eur > 0.005 ? round($koruny / $eur, 4) : null,
            'has_unknown' => $neznameEur > 0.005,
        ];
    }

    /**
     * Bezpečná částka na jeden zbývající den.
     *
     * `(zbývající rozpočet − rezerva) / zbývající dny`. Zajímavé jsou ale okrajové
     * případy, kterých je víc než těch běžných, a každý znamená jinou větu na obrazovce:
     * rozpočet, který ještě nezačal; období, které skončilo; poslední den; a hlavně
     * překročený rozpočet, u kterého záporná „doporučená částka" nedává smysl — nikdo
     * neumí utratit mínus deset eur. Tam se místo doporučení řekne, o kolik se přesáhlo.
     *
     * @return array<string, mixed>
     */
    public function safeDaily(float $limit, float $utraceno, float $rezerva, Carbon $od, ?Carbon $do, ?Carbon $dnes = null): array
    {
        $dnes ??= Carbon::today();

        $zbyva = $limit - $utraceno;
        $kRozdeleni = $zbyva - $rezerva;

        if ($dnes->lessThan($od)) {
            // I tady se počítá první i poslední den, stejně jako u rozjeté cesty níž.
            // Bez +1 by cesta den před odjezdem měla o den míň než den po něm — a denní
            // částka by se přes noc změnila, aniž by se cokoli utratilo.
            $dni = max(1, (int) $od->diffInDays($do ?? $od) + 1);

            return [
                'state' => 'not_started',
                'per_day' => round(max(0, $kRozdeleni) / $dni, 2),
                'days_left' => $dni,
                'remaining' => round($zbyva, 2),
                'over_by' => null,
                'reserve_kept' => round($rezerva, 2),
            ];
        }

        if ($do === null) {
            return [
                'state' => 'open_ended',
                'per_day' => null,
                'days_left' => null,
                'remaining' => round($zbyva, 2),
                'over_by' => null,
                'reserve_kept' => round($rezerva, 2),
            ];
        }

        // Dnešek se počítá jako celý den, který ještě jde utratit — proto +1. Bez toho
        // by v poslední den vyšlo nula dní a bezpečná částka by byla nekonečno.
        $dniDoKonce = (int) $dnes->diffInDays($do, false) + 1;

        if ($zbyva < 0) {
            $utracenoZaDen = $od->diffInDays($dnes, false) >= 0
                ? $utraceno / max(1, (int) $od->diffInDays($dnes, false) + 1)
                : 0;

            return [
                'state' => 'over',
                'per_day' => null,
                'days_left' => max(0, $dniDoKonce),
                'remaining' => round($zbyva, 2),
                'over_by' => round(abs($zbyva), 2),
                'pace_so_far' => round($utracenoZaDen, 2),
                'reserve_kept' => round($rezerva, 2),
            ];
        }

        if ($dniDoKonce <= 0) {
            return [
                'state' => 'ended',
                'per_day' => null,
                'days_left' => 0,
                'remaining' => round($zbyva, 2),
                'over_by' => null,
                'reserve_kept' => round($rezerva, 2),
            ];
        }

        return [
            'state' => $kRozdeleni <= 0 ? 'reserve_only' : 'ok',
            'per_day' => round(max(0, $kRozdeleni) / $dniDoKonce, 2),
            'days_left' => $dniDoKonce,
            'remaining' => round($zbyva, 2),
            'over_by' => null,
            'reserve_kept' => round($rezerva, 2),
        ];
    }

    /**
     * Kdo komu kolik dluží.
     *
     * Dvě odlišné informace u každého výdaje: kdo ho zaplatil a komu náležel. Teprve
     * jejich rozdíl je dluh. Kdo zaplatí svůj vlastní nákup, nedluží nikomu; kdo
     * zaplatí společný, má u druhého jeho polovinu.
     *
     * Výdaj ze společného účtu dluh nevytváří vůbec — peníze byly už předtím obou.
     * Bez téhle výjimky by každý nákup ze společné karty vyrobil dluh vůči nikomu.
     *
     * @param  Collection<int, Transaction>  $pohyby
     * @return array<string, mixed>
     */
    public function partnerBalance(Collection $pohyby, Collection $partneri): array
    {
        $zaplatil = [];
        $nesl = [];

        foreach ($pohyby as $t) {
            /** @var Transaction $t */
            if ($t->type !== 'expense' || $t->is_settlement) {
                continue;
            }

            $mena = $t->currency_from;
            $castka = (float) $t->amount_from + ($t->fee_currency === $mena ? $t->feePaidExtra() : 0);

            // Společný účet nemá osobního majitele — platba z něj nikomu nevzniká.
            $platce = $t->walletFrom?->partner_id ?? $t->payer_partner_id;

            if ($platce !== null) {
                $zaplatil[$mena][$platce] = ($zaplatil[$mena][$platce] ?? 0) + $castka;
            }

            $podily = $t->shares;

            if ($podily->isNotEmpty()) {
                foreach ($podily as $p) {
                    $nesl[$mena][$p->partner_id] = ($nesl[$mena][$p->partner_id] ?? 0) + (float) $p->amount;
                }
            } elseif ($t->beneficiary_partner_id !== null) {
                $nesl[$mena][$t->beneficiary_partner_id] = ($nesl[$mena][$t->beneficiary_partner_id] ?? 0) + $castka;
            } elseif ($platce !== null || $partneri->count() > 0) {
                // Bez rozdělení je výdaj společný rovným dílem. Nechat ho bez nositele
                // by znamenalo, že se v saldu ztratí a nikomu se nezapočítá.
                $naHlavu = $partneri->count() > 0 ? $castka / $partneri->count() : 0;

                foreach ($partneri as $p) {
                    $nesl[$mena][$p->id] = ($nesl[$mena][$p->id] ?? 0) + $naHlavu;
                }
            }
        }

        // Vyrovnání saldo snižuje, ale není příjem ani výdaj.
        foreach ($pohyby->where('is_settlement', true) as $t) {
            /** @var Transaction $t */
            $mena = $t->currency_from;
            $od = $t->walletFrom?->partner_id ?? $t->payer_partner_id;
            $komu = $t->walletTo?->partner_id ?? $t->beneficiary_partner_id;

            if ($od !== null) $zaplatil[$mena][$od] = ($zaplatil[$mena][$od] ?? 0) + (float) $t->amount_from;
            if ($komu !== null) $zaplatil[$mena][$komu] = ($zaplatil[$mena][$komu] ?? 0) - (float) $t->amount_from;
        }

        $vysledek = [];

        foreach (array_unique([...array_keys($zaplatil), ...array_keys($nesl)]) as $mena) {
            $radky = [];

            foreach ($partneri as $p) {
                $z = round($zaplatil[$mena][$p->id] ?? 0, 2);
                $n = round($nesl[$mena][$p->id] ?? 0, 2);

                $radky[] = [
                    'partner_id' => $p->id,
                    'name' => $p->name,
                    'paid' => $z,
                    'owes' => $n,
                    'balance' => round($z - $n, 2),
                ];
            }

            $vysledek[] = ['currency' => $mena, 'partners' => $radky, 'settlement' => $this->settlement($radky, $mena)];
        }

        return ['by_currency' => $vysledek];
    }

    /**
     * Kdo má komu poslat kolik, aby bylo vyrovnáno.
     *
     * U dvou lidí je to jedna platba. Obecně se páruje největší dlužník s největším
     * věřitelem, dokud něco zbývá — tím vznikne nejmenší možný počet převodů.
     *
     * @param  array<int, array<string, mixed>>  $radky
     * @return array<int, array<string, mixed>>
     */
    private function settlement(array $radky, string $mena): array
    {
        $dluznici = array_values(array_filter($radky, fn ($r) => $r['balance'] < -0.005));
        $veritele = array_values(array_filter($radky, fn ($r) => $r['balance'] > 0.005));

        usort($dluznici, fn ($a, $b) => $a['balance'] <=> $b['balance']);
        usort($veritele, fn ($a, $b) => $b['balance'] <=> $a['balance']);

        $prevody = [];
        $i = $j = 0;

        while ($i < count($dluznici) && $j < count($veritele)) {
            $castka = min(abs($dluznici[$i]['balance']), $veritele[$j]['balance']);

            if ($castka > 0.005) {
                $prevody[] = [
                    'from' => $dluznici[$i]['name'],
                    'from_id' => $dluznici[$i]['partner_id'],
                    'to' => $veritele[$j]['name'],
                    'to_id' => $veritele[$j]['partner_id'],
                    'amount' => round($castka, 2),
                    'currency' => $mena,
                ];
            }

            $dluznici[$i]['balance'] += $castka;
            $veritele[$j]['balance'] -= $castka;

            if (abs($dluznici[$i]['balance']) < 0.005) $i++;
            if ($veritele[$j]['balance'] < 0.005) $j++;
        }

        return $prevody;
    }

    /**
     * Výdaje po dnech v hlavní měně období.
     *
     * @param  Collection<int, Transaction>  $pohyby
     * @return array<int, array<string, mixed>>
     */
    public function daily(Collection $pohyby, string $mena, Carbon $od, Carbon $do): array
    {
        $poDnech = $pohyby
            ->filter(fn (Transaction $t) => $t->countsTowardsBudget() && $t->currency_from === $mena)
            ->groupBy(fn (Transaction $t) => $t->occurred_at->toDateString())
            ->map(fn (Collection $s) => round((float) $s->sum('amount_from'), 2));

        $radky = [];
        $den = $od->copy();

        while ($den->lessThanOrEqualTo($do)) {
            $klic = $den->toDateString();
            $radky[] = ['date' => $klic, 'amount' => $poDnech[$klic] ?? 0.0];
            $den->addDay();
        }

        return $radky;
    }

    /**
     * Rozpad po kategoriích. Barva jde z kategorie, ne z pořadí v seznamu —
     * po zapnutí filtru se tak Potraviny nepřebarví na to, co měly Restaurace.
     *
     * @param  Collection<int, Transaction>  $pohyby
     * @return array<int, array<string, mixed>>
     */
    public function byCategory(Collection $pohyby, string $mena): array
    {
        $vydaje = $pohyby->filter(fn (Transaction $t) => $t->countsTowardsBudget() && $t->currency_from === $mena);
        $celkem = (float) $vydaje->sum('amount_from');

        // Refundace snižuje čisté čerpání kategorie, ke které patří původní výdaj.
        $refundace = $pohyby
            ->filter(fn (Transaction $t) => $t->type === 'income' && $t->refund_of_id !== null)
            ->groupBy(fn (Transaction $t) => $t->refundOf?->category_id ?? 0)
            ->map(fn (Collection $s) => (float) $s->sum('amount_to'));

        $radky = $vydaje
            ->groupBy('category_id')
            ->map(function (Collection $s, $id) use ($celkem, $refundace, $mena) {
                $kategorie = $s->first()->category;
                $hruby = (float) $s->sum('amount_from');
                $vraceno = (float) ($refundace[$id ?: 0] ?? 0);

                return [
                    'category_id' => $id ?: null,
                    // uuid je to, čím se filtruje. Bez něj posílal proklik z grafu
                    // číselné id, filtr hledal podle uuid a seznam vyšel prázdný —
                    // s hláškou „tomuhle výběru nic neodpovídá", která zněla věrohodně.
                    'category_uuid' => $kategorie?->uuid,
                    'name' => $kategorie?->name ?? 'Bez kategorie',
                    'color' => $kategorie?->color,
                    'icon' => $kategorie?->icon,
                    'amount' => round($hruby - $vraceno, 2),
                    'gross' => round($hruby, 2),
                    'refunded' => round($vraceno, 2),
                    'count' => $s->count(),
                    'currency' => $mena,
                    'percent' => $celkem > 0 ? round(($hruby - $vraceno) / $celkem * 100, 1) : 0,
                ];
            })
            ->sortByDesc('amount')
            ->values();

        return $radky->all();
    }

    /** Aktivní cesta prostoru. Null, když se právě nikam nejede. */
    public function activeTrip(GallerySpace $space): ?FinanceProject
    {
        return FinanceProject::where('gallery_space_id', $space->id)
            ->where('kind', 'trip')
            ->where('is_active', true)
            ->first();
    }

    /** Zajistí, že prostor má kategorie i nastavení. */
    public function prepare(GallerySpace $space): void
    {
        FinanceCategory::nachystej($space->id);
    }

    private function druhPenezenky(string $kind): string
    {
        return match ($kind) {
            'cash' => 'hotovost',
            'card' => 'karta',
            'bank' => 'bankovní účet',
            default => 'jiný účet',
        };
    }
}
