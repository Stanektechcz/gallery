<?php

namespace App\Services\Finance;

use App\Models\FinanceRecurring;
use App\Models\GallerySpace;
use App\Models\Partner;
use App\Models\Transaction;
use App\Models\TransactionShare;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Z předpisů dělá skutečné zápisy.
 *
 * Generuje se **jen do dneška**, ne dopředu. Nájem, který se zaplatí příští měsíc,
 * ještě z účtu neodešel — zapsat ho předem by znamenalo zůstatek, který neodpovídá
 * tomu, co je v bance, a to je přesně ta chyba, kvůli které lidé přestanou aplikaci
 * věřit. Co teprve přijde, se hlásí zvlášť jako závazek.
 *
 * Splátka vzniká jednou **za měsíc**. Pozná se podle dvojice předpis + měsíc, ne podle
 * přesného data ani částky. Přesné datum nestačí: kdo posune zářijový nájem z prvního
 * na třetího, protože ho banka strhla později, by při dalším načtení přehledu dostal
 * nájem na prvního znovu — a změna dne v měsíci z 1 na 5 by dopsala celý rok podruhé.
 * Částka nestačí taky: dvě stejné útraty za den jsou běžné.
 *
 * Smazaná splátka se počítá jako hotová (`withTrashed`) — tak funguje i „přeskočit".
 */
class RecurringService
{
    /** Jak dlouho počká souběžné načtení, než generování přenechá tomu prvnímu. */
    private const CEKAT_NA_ZAMEK_SEKUND = 3;

    /** Jak dlouho zámek nejvýš platí, kdyby běh uprostřed spadl. */
    private const DRZET_ZAMEK_SEKUND = 30;

    /**
     * Dopíše splátky, které měly proběhnout a chybí.
     *
     * Běží při každém načtení přehledu, takže dva otevřené telefony by bez zámku
     * zapsaly tentýž nájem dvakrát — oba by se podívaly „ještě tu není" a oba by ho
     * založily. Druhý běh počká na první; když se nedočká, nezapíše nic a nechá to
     * na něm.
     *
     * @return int kolik zápisů vzniklo
     */
    public function generovat(GallerySpace $space, ?Carbon $dnes = null): int
    {
        $dnes ??= FinanceFilter::dnes();

        try {
            return Cache::lock("fin-recurring:{$space->id}", self::DRZET_ZAMEK_SEKUND)
                ->block(self::CEKAT_NA_ZAMEK_SEKUND, fn () => $this->generovatVse($space, $dnes));
        } catch (LockTimeoutException) {
            return 0;
        }
    }

    private function generovatVse(GallerySpace $space, Carbon $dnes): int
    {
        $predpisy = FinanceRecurring::where('gallery_space_id', $space->id)
            ->where('is_active', true)
            ->whereDate('starts_on', '<=', $dnes)
            ->get();

        $vzniklo = 0;

        foreach ($predpisy as $p) {
            $vzniklo += DB::transaction(fn () => $this->generovatPredpis($p, $dnes));
        }

        return $vzniklo;
    }

    private function generovatPredpis(FinanceRecurring $p, Carbon $dnes): int
    {
        $terminy = $p->terminy($this->odKdy($p), $dnes);

        // Co už existuje — měsíce, ne data. Jedním dotazem, ne jedním na každý termín.
        $hotoveMesice = Transaction::withTrashed()
            ->where('gallery_space_id', $p->gallery_space_id)
            ->where('recurring_id', $p->id)
            ->pluck('occurred_at')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m'))
            ->flip();

        $vzniklo = 0;

        foreach ($terminy as $termin) {
            if ($hotoveMesice->has($termin->format('Y-m'))) {
                continue;
            }

            $this->zapsat($p, $termin);
            $hotoveMesice->put($termin->format('Y-m'), true);
            $vzniklo++;
        }

        // Nikdy zpátky: běh s dřívějším dnem nesmí znovu otevřít měsíce, které už prošly.
        $doposud = $p->generated_until;

        if ($doposud === null || $doposud->lessThan($dnes)) {
            $p->forceFill(['generated_until' => $dnes->toDateString()])->save();
        }

        return $vzniklo;
    }

    /**
     * Od kterého dne má smysl termíny hledat.
     *
     * Poprvé od začátku předpisu — založený zpětně (nájem na rozjeté cestě) má dopsat
     * i minulost. Potom až od měsíce posledního běhu: měsíce před ním generátor prošel
     * celé, a co v nich chybí, člověk přesunul nebo odstranil sám. Otevřít je znovu by
     * vrátilo nájem, který někdo vědomě přesunul do jiného měsíce.
     */
    private function odKdy(FinanceRecurring $p): Carbon
    {
        $zacatek = $p->starts_on->copy();

        if ($p->generated_until === null) {
            return $zacatek;
        }

        return $zacatek->max($p->generated_until->copy()->startOfMonth());
    }

    private function zapsat(FinanceRecurring $p, Carbon $den): void
    {
        $vydaj = $p->type === 'expense';

        $t = Transaction::create([
            'gallery_space_id' => $p->gallery_space_id,
            'type' => $p->type,
            'occurred_at' => $den->toDateString(),
            'wallet_from_id' => $vydaj ? $p->wallet_id : null,
            'wallet_to_id' => $vydaj ? null : $p->wallet_id,
            'amount_from' => $vydaj ? $p->amount : null,
            'currency_from' => $p->currency,
            'amount_to' => $vydaj ? null : $p->amount,
            'currency_to' => $vydaj ? null : $p->currency,
            'category_id' => $p->finance_category_id,
            'finance_project_id' => $p->finance_project_id,
            'payer_partner_id' => $p->payer_partner_id,
            'description' => $p->name,
            'recurring_id' => $p->id,
            'state' => 'approved',
            'created_by' => $p->created_by,
        ]);

        $this->rozdelit($p, $t);
    }

    /** Rozdělení mezi partnery podle předpisu. */
    private function rozdelit(FinanceRecurring $p, Transaction $t): void
    {
        if ($p->split === null || $p->type !== 'expense') {
            return;
        }

        $partneri = Partner::where('gallery_space_id', $p->gallery_space_id)
            ->where('is_active', true)->orderBy('id')->get();

        if ($partneri->count() < 2) {
            return;
        }

        $castka = (float) $p->amount;

        if ($p->split === 'equal') {
            // Haléř navíc prvnímu, aby dvě generování dala týž výsledek.
            $druhy = round($castka / 2, 2);

            TransactionShare::create(['transaction_id' => $t->id, 'partner_id' => $partneri[0]->id,
                'amount' => round($castka - $druhy, 2), 'currency' => $p->currency, 'basis' => 'equal']);
            TransactionShare::create(['transaction_id' => $t->id, 'partner_id' => $partneri[1]->id,
                'amount' => $druhy, 'currency' => $p->currency, 'basis' => 'equal']);

            return;
        }

        $kdo = $p->split === 'first' ? $partneri[0] : $partneri[1];

        TransactionShare::create(['transaction_id' => $t->id, 'partner_id' => $kdo->id,
            'amount' => $castka, 'currency' => $p->currency, 'basis' => 'fixed']);
    }

    /**
     * Závazky do konce období — co z předpisů ještě přijde.
     *
     * @return array{total: float, items: array<int, array<string, mixed>>}
     */
    public function zavazky(GallerySpace $space, string $mena, ?Carbon $konec, ?Carbon $dnes = null): array
    {
        $dnes ??= FinanceFilter::dnes();

        if ($konec === null) {
            return ['total' => 0.0, 'items' => []];
        }

        $polozky = [];
        $celkem = 0.0;

        foreach (FinanceRecurring::where('gallery_space_id', $space->id)
            ->where('is_active', true)->where('type', 'expense')->where('currency', $mena)
            ->with('category:id,name,color')->get() as $p) {

            $terminy = $p->terminy($dnes->copy()->addDay(), $konec);

            if ($terminy === []) {
                continue;
            }

            $castka = count($terminy) * (float) $p->amount;
            $celkem += $castka;

            $polozky[] = [
                'uuid' => $p->uuid,
                'name' => $p->name,
                'category' => $p->category?->name,
                'color' => $p->category?->color,
                'amount' => (float) $p->amount,
                'times' => count($terminy),
                'total' => round($castka, 2),
                'next_on' => $terminy[0]->toDateString(),
                'currency' => $mena,
            ];
        }

        usort($polozky, fn ($a, $b) => $b['total'] <=> $a['total']);

        return ['total' => round($celkem, 2), 'items' => $polozky];
    }
}
