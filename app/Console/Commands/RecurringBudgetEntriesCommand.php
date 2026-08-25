<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Models\BudgetEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Zapíše opakující se položky na nový měsíc.
 *
 * Rozpočet nabízel u položky zaškrtnutí „pravidelné" a nedělalo to nic — nájem se
 * zobrazil s odznáčkem a další měsíc si ho člověk musel zapsat znovu. Půl roku v cizině
 * znamená šest ručních zápisů nájmu, šest telefonu, šest pojištění; přesně ta práce,
 * kvůli které lidi přestanou rozpočet vést.
 *
 * Kopíruje se poslední výskyt, ne první: když se nájem zvedl, opakuje se ta nová
 * částka. A jen do rozpočtu, který v daném měsíci ještě běží — do skončeného už nic
 * nepřibývá.
 */
class RecurringBudgetEntriesCommand extends Command
{
    protected $signature = 'gallery:recurring-entries
                            {--month= : Který měsíc doplnit, ve tvaru 2026-09; výchozí je tenhle}
                            {--apply : Skutečně zapsat; bez toho jen ukáže, co by vzniklo}';

    protected $description = 'Doplní pravidelné položky rozpočtu na nový měsíc.';

    public function handle(): int
    {
        $mesic = $this->option('month')
            ? Carbon::parse($this->option('month') . '-01')->startOfMonth()
            : Carbon::now()->startOfMonth();

        $zapsat = (bool) $this->option('apply');
        $vzniklo = 0;

        foreach (Budget::whereNull('deleted_at')->get() as $budget) {
            // Rozpočet, který v tomhle měsíci neběží, nic nedostane.
            if ($budget->starts_on->greaterThan($mesic->copy()->endOfMonth())) continue;
            if ($budget->ends_on && $budget->ends_on->lessThan($mesic)) continue;

            $pravidelne = BudgetEntry::where('budget_id', $budget->id)
                ->where('is_recurring', true)
                ->orderByDesc('spent_on')
                ->get()
                // Podle kategorie a popisu: dva různé pravidelné výdaje ve stejné
                // kategorii (nájem a záloha na energie) se nesmí slít v jeden.
                ->unique(fn (BudgetEntry $e) => $e->budget_category_id . '|' . $e->note . '|' . $e->kind);

            foreach ($pravidelne as $vzor) {
                if ($vzor->spent_on->startOfMonth()->equalTo($mesic)) continue;

                // Den v měsíci se zachová; kratší měsíc se ořízne na svůj poslední den,
                // aby nájem k 31. nespadl v únoru na březen.
                $den = min((int) $vzor->spent_on->day, (int) $mesic->copy()->endOfMonth()->day);
                $datum = $mesic->copy()->setDay($den);

                $uzJe = BudgetEntry::where('budget_id', $budget->id)
                    ->where('is_recurring', true)
                    ->where('budget_category_id', $vzor->budget_category_id)
                    ->where('kind', $vzor->kind)
                    ->whereBetween('spent_on', [$mesic, $mesic->copy()->endOfMonth()])
                    ->when($vzor->note, fn ($q) => $q->where('note', $vzor->note))
                    ->exists();

                if ($uzJe) continue;

                $this->line(sprintf(
                    '  %-22s %8s %s  %s',
                    mb_strimwidth($budget->name, 0, 22, '…'),
                    number_format((float) $vzor->amount, 2, ',', ' '),
                    $vzor->currency,
                    $datum->toDateString() . ($vzor->note ? " — {$vzor->note}" : ''),
                ));

                if ($zapsat) {
                    BudgetEntry::create([
                        'budget_id' => $budget->id,
                        'budget_category_id' => $vzor->budget_category_id,
                        'user_id' => $vzor->user_id,
                        'kind' => $vzor->kind,
                        'amount' => $vzor->amount,
                        'currency' => $vzor->currency,
                        'spent_on' => $datum,
                        'note' => $vzor->note,
                        'is_recurring' => true,
                        // Kdo platí a jak se to dělí patří k opakování stejně jako částka.
                        // Bez toho se nájem dělený napůl zkopíroval jako nedělený a do
                        // vyrovnání se každý další měsíc nezapočítal — dvojice by po půl
                        // roce zjistila, že jí v dluhu chybí pět měsíců nájmu.
                        'paid_by' => $vzor->paid_by ?? $vzor->user_id,
                        'split' => $vzor->split,
                        // Účtenka se nekopíruje schválně: nový měsíc svou účtenku nemá
                        // a připnout k němu tu starou by z dokladu udělalo lež.
                    ]);
                }

                $vzniklo++;
            }
        }

        $this->newLine();
        $this->info($vzniklo === 0
            ? 'Nic k doplnění — pravidelné položky už tenhle měsíc mají.'
            : ($zapsat ? "Doplněno {$vzniklo} položek." : "Vzniklo by {$vzniklo} položek. Spusťte s --apply."));

        return self::SUCCESS;
    }
}
