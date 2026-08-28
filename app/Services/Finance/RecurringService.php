<?php

namespace App\Services\Finance;

use App\Models\FinanceRecurring;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\TransactionShare;
use App\Models\Partner;
use Illuminate\Support\Carbon;

/**
 * Z předpisů dělá skutečné zápisy.
 *
 * Generuje se **jen do dneška**, ne dopředu. Nájem, který se zaplatí příští měsíc,
 * ještě z účtu neodešel — zapsat ho předem by znamenalo zůstatek, který neodpovídá
 * tomu, co je v bance, a to je přesně ta chyba, kvůli které lidé přestanou aplikaci
 * věřit. Co teprve přijde, se hlásí zvlášť jako závazek.
 *
 * Splátka vzniká jednou. Pozná se podle dvojice předpis + datum, ne podle částky —
 * dva nájmy stejné výše ve stejný měsíc jsou nesmysl, ale dvě stejné útraty za den
 * jsou běžné, a kdyby se rozlišovalo částkou, spletlo by se to.
 */
class RecurringService
{
    /**
     * Dopíše splátky, které měly proběhnout a chybí.
     *
     * @return int kolik zápisů vzniklo
     */
    public function generovat(GallerySpace $space, ?Carbon $dnes = null): int
    {
        $dnes ??= Carbon::today();

        $predpisy = FinanceRecurring::where('gallery_space_id', $space->id)
            ->where('is_active', true)
            ->whereDate('starts_on', '<=', $dnes)
            ->get();

        $vzniklo = 0;

        foreach ($predpisy as $p) {
            $vzniklo += $this->generovatPredpis($p, $dnes);
        }

        return $vzniklo;
    }

    private function generovatPredpis(FinanceRecurring $p, Carbon $dnes): int
    {
        // Od začátku předpisu, ne od `generated_until` — kdyby se předpis založil
        // zpětně (což u nájmu na rozjeté cestě dává smysl), musí se dopsat i minulost.
        $terminy = $p->terminy($p->starts_on, $dnes);

        if ($terminy === []) {
            return 0;
        }

        // Co už existuje. Jedním dotazem, ne jedním na každý termín.
        $existujici = Transaction::withTrashed()
            ->where('gallery_space_id', $p->gallery_space_id)
            ->where('recurring_id', $p->id)
            ->pluck('occurred_at')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->flip();

        $vzniklo = 0;

        foreach ($terminy as $termin) {
            if ($existujici->has($termin->toDateString())) {
                continue;
            }

            $this->zapsat($p, $termin);
            $vzniklo++;
        }

        $p->forceFill(['generated_until' => $dnes->toDateString()])->save();

        return $vzniklo;
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
        $dnes ??= Carbon::today();

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
