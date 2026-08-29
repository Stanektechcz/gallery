<?php

namespace App\Services\Finance;

use App\Models\FinanceCategory;
use App\Models\GallerySpace;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Slučování zdvojených kategorií a účtů.
 *
 * Duplicity vzniknou samy: „Ubytování" a „Bydlení" znamenají totéž, „EUR karta" a
 * „Eura na kartě" taky. Dokud se neslijí, jsou peníze rozdělené mezi dvě položky,
 * ani jedna neukazuje celý obrázek a rozpočet hlídá jen tu, na kterou má limit.
 *
 * Dvě pravidla, na kterých to celé stojí:
 *
 *  1. **Nic se nemaže, všechno se přepojí.** Transakce se převedou na tu, která
 *     zůstává; teprve prázdná položka se odloží. Smazat kategorii i s útratami by
 *     znamenalo přijít o historii, která se stala.
 *  2. **Napřed se řekne, co se stane.** Sloučení je nevratné a člověk musí předem
 *     vidět, kolik záznamů se pohne — jinak si omylem slije půl roku dat.
 */
class SlucovaniService
{
    /**
     * Co se stane, když se tyhle dvě slijí. Nic se přitom nemění.
     *
     * @return array<string, mixed>
     */
    public function nahledKategorii(GallerySpace $space, FinanceCategory $z, FinanceCategory $do): array
    {
        return [
            'from' => ['uuid' => $z->uuid, 'name' => $z->name],
            'to' => ['uuid' => $do->uuid, 'name' => $do->name],
            'transactions' => DB::table('transactions')
                ->where('gallery_space_id', $space->id)->where('category_id', $z->id)->count(),
            'recurring' => DB::table('finance_recurring')
                ->where('gallery_space_id', $space->id)->where('finance_category_id', $z->id)->count(),
            'templates' => DB::table('finance_templates')
                ->where('gallery_space_id', $space->id)->where('finance_category_id', $z->id)->count(),
            'budget_limits' => DB::table('budget_category_limits')->where('finance_category_id', $z->id)->count(),
            'same_kind' => $z->kind === $do->kind,
        ];
    }

    /**
     * Slije kategorii do druhé.
     *
     * Limity rozpočtů se sčítají, ne přepisují. Když byl na obou nějaký, výsledek má
     * pokrýt obojí — přepsat jeden druhým by tiše ubralo peníze z plánu.
     *
     * @return array<string, mixed>
     */
    public function kategorie(GallerySpace $space, FinanceCategory $z, FinanceCategory $do): array
    {
        abort_if($z->id === $do->id, 422, 'Sloučit položku samu se sebou nejde.');
        abort_if($z->kind !== $do->kind, 422,
            'Příjem a výdaj se sloučit nedají — sečetlo by se to, co se má odečítat.');

        $nahled = $this->nahledKategorii($space, $z, $do);

        DB::transaction(function () use ($space, $z, $do) {
            DB::table('transactions')->where('gallery_space_id', $space->id)
                ->where('category_id', $z->id)->update(['category_id' => $do->id]);

            DB::table('finance_recurring')->where('gallery_space_id', $space->id)
                ->where('finance_category_id', $z->id)->update(['finance_category_id' => $do->id]);

            DB::table('finance_templates')->where('gallery_space_id', $space->id)
                ->where('finance_category_id', $z->id)->update(['finance_category_id' => $do->id]);

            $this->slijLimity($z->id, $do->id);

            // Prázdná kategorie se maže, ne odkládá: odložená by zůstala v seznamu
            // a celé slučování by nic nezpřehlednilo.
            $z->delete();
        });

        return $nahled;
    }

    /**
     * Limity rozpočtů: sečíst, ne přepsat.
     *
     * V jednom rozpočtu můžou mít obě kategorie svůj limit. Přenést jen ten první
     * by z plánu tiše ubralo peníze druhého.
     */
    private function slijLimity(int $zId, int $doId): void
    {
        $stare = DB::table('budget_category_limits')->where('finance_category_id', $zId)->get();

        foreach ($stare as $radek) {
            $cilovy = DB::table('budget_category_limits')
                ->where('budget_id', $radek->budget_id)
                ->where('finance_category_id', $doId)
                ->first();

            if ($cilovy === null) {
                DB::table('budget_category_limits')->where('id', $radek->id)
                    ->update(['finance_category_id' => $doId, 'updated_at' => now()]);

                continue;
            }

            DB::table('budget_category_limits')->where('id', $cilovy->id)->update([
                'amount' => (float) $cilovy->amount + (float) $radek->amount,
                'baseline_amount' => (float) ($cilovy->baseline_amount ?? $cilovy->amount)
                    + (float) ($radek->baseline_amount ?? $radek->amount),
                // Z dvou důležitostí platí ta vyšší, tedy nižší číslo. Slitím se
                // položka nesmí propadnout v pořadí níž, než kde byla.
                'priority' => min((int) $cilovy->priority, (int) $radek->priority),
                'updated_at' => now(),
            ]);

            DB::table('budget_category_limits')->where('id', $radek->id)->delete();
        }
    }

    /** @return array<string, mixed> */
    public function nahledUctu(GallerySpace $space, Wallet $z, Wallet $do): array
    {
        return [
            'from' => ['uuid' => $z->uuid, 'name' => $z->name, 'opening_balance' => (float) $z->opening_balance],
            'to' => ['uuid' => $do->uuid, 'name' => $do->name, 'opening_balance' => (float) $do->opening_balance],
            'transactions' => DB::table('transactions')->where('gallery_space_id', $space->id)
                ->where(fn ($q) => $q->where('wallet_from_id', $z->id)->orWhere('wallet_to_id', $z->id))->count(),
            'same_currency' => $z->currency === $do->currency,
            'new_opening_balance' => (float) $z->opening_balance + (float) $do->opening_balance,
            /*
             * Dva stejné nenulové počátky nejsou dva různé účty.
             *
             * Je to podpis omylem zdvojeného účtu: tytéž peníze zapsané dvakrát.
             * Sečíst je znamená vyrobit peníze, které nikdy neexistovaly — a protože
             * výsledek vypadá jako správný součet, nikdo si toho nevšimne.
             */
            'suspicious_double' => abs((float) $z->opening_balance) > 0.005
                && abs((float) $z->opening_balance - (float) $do->opening_balance) < 0.005,
        ];
    }

    /**
     * Slije účet do druhého.
     *
     * Počáteční zůstatky se sčítají. Zůstatek vzniká z pohybů a počátku; kdyby se
     * počátek zahodil, slitím by se ztratily peníze, které na účtu byly od začátku.
     *
     * @return array<string, mixed>
     */
    public function ucet(GallerySpace $space, Wallet $z, Wallet $do): array
    {
        abort_if($z->id === $do->id, 422, 'Sloučit účet sám se sebou nejde.');
        abort_if($z->currency !== $do->currency, 422,
            'Účty v různých měnách se sloučit nedají — koruny a eura se sečíst nedají bez kurzu, který si nevymýšlíme.');

        $nahled = $this->nahledUctu($space, $z, $do);

        DB::transaction(function () use ($space, $z, $do) {
            DB::table('transactions')->where('gallery_space_id', $space->id)
                ->where('wallet_from_id', $z->id)->update(['wallet_from_id' => $do->id]);

            DB::table('transactions')->where('gallery_space_id', $space->id)
                ->where('wallet_to_id', $z->id)->update(['wallet_to_id' => $do->id]);

            foreach ([
                ['finance_recurring', 'wallet_id'],
                ['finance_templates', 'wallet_id'],
                ['finance_projects', 'default_wallet_id'],
            ] as [$tabulka, $sloupec]) {
                DB::table($tabulka)->where('gallery_space_id', $space->id)
                    ->where($sloupec, $z->id)->update([$sloupec => $do->id]);
            }

            $do->update(['opening_balance' => (float) $do->opening_balance + (float) $z->opening_balance]);
            $z->delete();
        });

        return $nahled;
    }

    /**
     * Co v prostoru vypadá na duplicitu.
     *
     * Nabízí se, nespouští se. Dvě kategorie se stejným smyslem pozná člověk, ne
     * porovnání řetězců — „Psi" a „Zdraví a lékárna" můžou u někoho být totéž a
     * u někoho ne.
     *
     * @return array<string, mixed>
     */
    public function navrhy(GallerySpace $space): array
    {
        $ucty = Wallet::where('gallery_space_id', $space->id)->orderBy('id')->get()
            ->groupBy(fn (Wallet $w) => $w->currency.'|'.($w->kind === 'cash' ? 'hotovost' : 'ucet'))
            ->filter(fn ($skupina) => $skupina->count() > 1)
            ->map(fn ($skupina) => [
                'currency' => $skupina->first()->currency,
                'kind' => $skupina->first()->kind === 'cash' ? 'hotovost' : 'účet',
                'wallets' => $skupina->map(fn (Wallet $w) => [
                    'uuid' => $w->uuid, 'name' => $w->name,
                    'opening_balance' => (float) $w->opening_balance,
                ])->values(),
            ])->values();

        return ['wallets' => $ucty];
    }
}
