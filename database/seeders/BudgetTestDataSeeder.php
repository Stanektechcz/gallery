<?php

namespace Database\Seeders;

use App\Models\Budget;
use App\Models\BudgetCategory;
use App\Models\BudgetEntry;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Ukázkový rozpočet, na kterém je vidět, co stránka umí.
 *
 * Prázdný rozpočet vypadá stejně jako rozbitý — nedá se na něm poznat, jestli přehledy,
 * rozvaha mezi dvěma lidmi nebo srovnání měsíců dávají smysl, ani jestli se čísla někde
 * nepřetečou. Data proto odpovídají příběhu, na který je rozpočet stavěný: půl roku
 * v zahraničí, příjem a nájem v eurech, občasné výdaje doma v korunách, dva lidé, kteří
 * si střídavě platí věci za sebe.
 *
 * Spouští se ručně (`db:seed --class=BudgetTestDataSeeder`), ne z DatabaseSeeder — do
 * ostrých dat ukázkový rozpočet nepatří. Opakované spuštění nic nezdvojí, protože se
 * pozná podle jména.
 */
class BudgetTestDataSeeder extends Seeder
{
    private const JMENO = 'Půl roku v Německu';

    public function run(): void
    {
        if (! Schema::hasTable('budgets') || ! Schema::hasTable('budget_entries')) {
            $this->command?->warn('Tabulky rozpočtů nejsou k dispozici, ukázková data se přeskočila.');

            return;
        }

        $space = GallerySpace::where('is_default', true)->orderBy('id')->first() ?? GallerySpace::orderBy('id')->first();

        if (! $space) {
            $this->command?->warn('Není žádný prostor galerie, ukázková data se přeskočila.');

            return;
        }

        if (Budget::withoutGlobalScopes()->where('gallery_space_id', $space->id)->where('name', self::JMENO)->exists()) {
            $this->command?->info('Ukázkový rozpočet už existuje.');

            return;
        }

        $lide = User::whereHas('gallerySpaces', fn ($q) => $q->where('gallery_spaces.id', $space->id))
            ->orderBy('id')->take(2)->get();

        $ja = $lide->first() ?? User::orderBy('id')->first();
        $druhy = $lide->skip(1)->first() ?? $ja;

        // Období končí za dva měsíce, takže je vidět i zbývající doba a rozvržení na den.
        $zacatek = Carbon::today()->subMonthsNoOverflow(3)->startOfMonth();
        $konec = Carbon::today()->addMonthsNoOverflow(2)->endOfMonth();

        $budget = Budget::create([
            'gallery_space_id' => $space->id,
            'owner_user_id' => null,
            'name' => self::JMENO,
            'currency' => 'EUR',
            'starts_on' => $zacatek,
            'ends_on' => $konec,
            'monthly_income' => 2400,
            'note' => 'Pobyt kvůli práci. Nájem a život v eurech, doma zůstávají koruny.',
            'is_shared' => true,
            'created_by' => $ja->id,
            'savings_target' => 3000,
            'savings_target_on' => $konec->copy()->subMonthNoOverflow(),
            'period_unit' => 'month',
        ]);

        $kategorie = collect([
            ['Bydlení', 950, '#6366f1', 0],
            ['Jídlo a nákupy', 420, '#22c55e', 1],
            ['Doprava', 140, '#f59e0b', 2],
            ['Zdraví', 90, '#ef4444', 3],
            ['Volný čas', 180, '#ec4899', 4],
            ['Cesty domů', 0, '#06b6d4', 5],
        ])->mapWithKeys(function (array $radek) use ($budget) {
            [$nazev, $plan, $barva, $poradi] = $radek;

            $kategorie = BudgetCategory::create([
                'budget_id' => $budget->id,
                'name' => $nazev,
                'planned_monthly' => $plan,
                'color' => $barva,
                'sort_order' => $poradi,
            ]);

            return [$nazev => $kategorie->id];
        });

        $polozky = 0;

        // Pravidelné měsíční položky za celé dosavadní období.
        $mesic = $zacatek->copy();

        while ($mesic->lte(Carbon::today())) {
            $polozky += $this->zapis($budget, $ja, $kategorie['Bydlení'], 'expense', 950, 'EUR', $mesic->copy()->day(3), 'Nájem', true, $ja->id, 'equal');
            $polozky += $this->zapis($budget, $ja, null, 'income', 2400, 'EUR', $mesic->copy()->day(15), 'Výplata', true, $ja->id, 'none');
            $polozky += $this->zapis($budget, $druhy, $kategorie['Bydlení'], 'expense', 62, 'EUR', $mesic->copy()->day(8), 'Internet a elektřina', true, $druhy->id, 'equal');
            $polozky += $this->zapis($budget, $ja, $kategorie['Doprava'], 'expense', 49, 'EUR', $mesic->copy()->day(2), 'Měsíční jízdenka', true, $ja->id, 'none');

            $mesic->addMonthNoOverflow();
        }

        // Jednorázové výdaje rozprostřené po období, ať mají měsíce různý průběh.
        $jednorazove = [
            [-88, 'Jídlo a nákupy', 74.20, 'EUR', 'Velký nákup', $druhy, 'equal'],
            [-86, 'Volný čas', 32.00, 'EUR', 'Kino a večeře', $ja, 'equal'],
            [-74, 'Jídlo a nákupy', 61.50, 'EUR', 'Nákup na týden', $ja, 'equal'],
            [-70, 'Zdraví', 45.00, 'EUR', 'Lékárna', $druhy, 'other'],
            [-64, 'Cesty domů', 3200.00, 'CZK', 'Vlak domů', $ja, 'none'],
            [-58, 'Jídlo a nákupy', 88.90, 'EUR', 'Nákup a drogerie', $druhy, 'equal'],
            [-52, 'Volný čas', 120.00, 'EUR', 'Výlet do hor', $ja, 'equal'],
            [-45, 'Jídlo a nákupy', 70.10, 'EUR', 'Nákup', $ja, 'equal'],
            [-40, 'Doprava', 26.00, 'EUR', 'Jízdné navíc', $druhy, 'none'],
            [-33, 'Zdraví', 180.00, 'EUR', 'Zubař', $ja, 'none'],
            [-28, 'Jídlo a nákupy', 95.40, 'EUR', 'Nákup', $druhy, 'equal'],
            [-21, 'Volný čas', 48.00, 'EUR', 'Koncert', $druhy, 'equal'],
            [-16, 'Cesty domů', 2450.00, 'CZK', 'Zpáteční jízdenka', $druhy, 'equal'],
            [-12, 'Jídlo a nákupy', 66.30, 'EUR', 'Nákup', $ja, 'equal'],
            [-8, 'Doprava', 18.50, 'EUR', 'Taxi z nádraží', $ja, 'none'],
            [-5, 'Jídlo a nákupy', 52.70, 'EUR', 'Nákup', $druhy, 'equal'],
            [-3, 'Volný čas', 24.00, 'EUR', 'Kavárna a knihy', $ja, 'none'],
            [-1, 'Jídlo a nákupy', 31.20, 'EUR', 'Rychlý nákup', $druhy, 'equal'],
        ];

        foreach ($jednorazove as [$odstup, $kat, $castka, $mena, $popis, $kdo, $deleni]) {
            $den = Carbon::today()->addDays($odstup);

            if ($den->lt($zacatek)) {
                continue;
            }

            $polozky += $this->zapis($budget, $kdo, $kategorie[$kat] ?? null, 'expense', $castka, $mena, $den, $popis, false, $kdo->id, $deleni);
        }

        // Vedlejší příjem, aby srovnání měsíců nebylo jen o výdajích.
        $polozky += $this->zapis($budget, $druhy, null, 'income', 480, 'EUR', Carbon::today()->subDays(35), 'Fakturace navíc', false, $druhy->id, 'none');

        $this->command?->info("Ukázkový rozpočet „{$budget->name}\" má {$kategorie->count()} kategorií a {$polozky} položek.");
    }

    private function zapis(Budget $budget, User $kdo, ?int $kategorie, string $druh, float $castka, string $mena, Carbon $den, string $popis, bool $pravidelna, int $platil, string $deleni): int
    {
        if ($den->gt(Carbon::today())) {
            return 0;
        }

        BudgetEntry::create([
            'budget_id' => $budget->id,
            'budget_category_id' => $kategorie,
            'user_id' => $kdo->id,
            'paid_by' => $platil,
            'split' => $deleni,
            'kind' => $druh,
            'amount' => $castka,
            'currency' => $mena,
            'spent_on' => $den,
            'note' => $popis,
            'is_recurring' => $pravidelna,
        ]);

        return 1;
    }
}
