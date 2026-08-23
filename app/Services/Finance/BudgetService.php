<?php

namespace App\Services\Finance;

use App\Models\Budget;
use App\Models\BudgetEntry;
use App\Models\GallerySpace;
use App\Models\MoneyRequest;
use App\Models\User;
use App\Notifications\GalleryNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Přehledy nad rozpočtem na období.
 *
 * Dvě rozhodnutí, která tvarují všechno ostatní:
 *
 * Měny se nesčítají napříč. Kurz nemáme odkud brát a odhadnout ho u peněz je horší než
 * ho neznat — člověk, který si podle vymyšleného kurzu naplánuje nájem, to zjistí až
 * na účtu. Součty se proto drží po měnách zvlášť a rozpočet má svou hlavní měnu, ve
 * které se plánuje.
 *
 * Zbytek se počítá ke dnešku, ne k celému období. Odpověď na „kolik ještě můžu utratit"
 * je za půl roku v cizině užitečná jen tehdy, když bere v úvahu, kolik dní zbývá.
 */
class BudgetService
{
    /** Rozpočty, na které tenhle člověk vidí. */
    public function forUser(GallerySpace $space, User $user): Collection
    {
        return Budget::where('gallery_space_id', $space->id)
            ->with('owner:id,name')
            ->orderByDesc('starts_on')
            ->get()
            ->filter(fn (Budget $budget) => $budget->isVisibleTo($user))
            ->values();
    }

    /**
     * Celý přehled jednoho rozpočtu.
     *
     * @return array<string, mixed>
     */
    public function overview(Budget $budget, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $budget->loadMissing(['categories', 'entries.category', 'entries.author:id,name']);

        $vydaje = $budget->entries->where('kind', 'expense');
        $prijmy = $budget->entries->where('kind', 'income');

        return [
            'budget' => [
                'uuid' => $budget->uuid,
                'name' => $budget->name,
                'currency' => $budget->currency,
                'starts_on' => $budget->starts_on->toDateString(),
                'ends_on' => $budget->ends_on?->toDateString(),
                'monthly_income' => $budget->monthly_income !== null ? (float) $budget->monthly_income : null,
                'note' => $budget->note,
                'is_shared' => $budget->is_shared,
                'owner' => $budget->owner ? ['id' => $budget->owner->id, 'name' => $budget->owner->name] : null,
            ],
            'period' => $this->period($budget, $today),
            'totals' => [
                'spent' => $this->byCurrency($vydaje),
                'income' => $this->byCurrency($prijmy),
            ],
            'categories' => $this->categories($budget, $today),
            'months' => $this->months($budget),
            'allowance' => $this->allowance($budget, $vydaje, $today),
            // Co dochází. Prázdné pole je dobrá zpráva a nic se nekreslí.
            'warnings' => $this->warnings($budget, $today),
            'entries' => $budget->entries
                ->sortByDesc('spent_on')
                ->take(100)
                ->map(fn (BudgetEntry $entry) => [
                    'uuid' => $entry->uuid,
                    'kind' => $entry->kind,
                    'amount' => (float) $entry->amount,
                    'currency' => $entry->currency,
                    'spent_on' => $entry->spent_on->toDateString(),
                    'note' => $entry->note,
                    'is_recurring' => $entry->is_recurring,
                    'category' => $entry->category?->name,
                    'author' => $entry->author?->name,
                ])->values()->all(),
        ];
    }

    /** Kde v období jsme — kolik uplynulo a kolik zbývá. */
    private function period(Budget $budget, Carbon $today): array
    {
        $zacatek = $budget->starts_on;
        $konec = $budget->ends_on;

        $uplynulo = max(0, (int) $zacatek->diffInDays($today->min($konec ?? $today), false));
        $zbyva = $konec ? max(0, (int) $today->diffInDays($konec, false)) : null;

        return [
            'days_elapsed' => $uplynulo,
            'days_left' => $zbyva,
            'days_total' => $konec ? max(1, (int) $zacatek->diffInDays($konec)) : null,
            'has_started' => $today->greaterThanOrEqualTo($zacatek),
            'has_ended' => $konec !== null && $today->greaterThan($konec),
        ];
    }

    /**
     * Plán proti skutečnosti po kategoriích.
     *
     * Plán je měsíční, skutečnost za celé dosavadní období — porovnávat je proto má smysl
     * jen přes počet uplynulých měsíců, a ten se tady dopočítá, aby to nemusel dělat
     * frontend a spočítat to jinak.
     */
    private function categories(Budget $budget, Carbon $today): array
    {
        $konecObdobi = $budget->ends_on && $today->greaterThan($budget->ends_on) ? $budget->ends_on : $today;
        $mesicu = max(1.0, $budget->starts_on->diffInDays($konecObdobi) / 30.44);

        return $budget->categories->map(function ($category) use ($budget, $mesicu) {
            $utraceno = $budget->entries
                ->where('kind', 'expense')
                ->where('budget_category_id', $category->id)
                ->where('currency', $budget->currency)
                ->sum('amount');

            $planovano = (float) $category->planned_monthly * $mesicu;

            return [
                'id' => $category->id,
                'name' => $category->name,
                'color' => $category->color,
                'icon' => $category->icon,
                'planned_monthly' => (float) $category->planned_monthly,
                'planned_to_date' => round($planovano, 2),
                'spent' => round((float) $utraceno, 2),
                // Nad sto procent je varování, ne chyba — někdo prostě utratil víc.
                'used_percent' => $planovano > 0 ? (int) round($utraceno / $planovano * 100) : null,
            ];
        })->values()->all();
    }

    /** Měsíc po měsíci, aby šlo vidět, jestli to někam ujíždí. */
    private function months(Budget $budget): array
    {
        return $budget->entries
            ->groupBy(fn (BudgetEntry $entry) => $entry->spent_on->format('Y-m'))
            ->map(fn (Collection $mesic, string $klic) => [
                'month' => $klic,
                'spent' => round((float) $mesic->where('kind', 'expense')->sum('amount'), 2),
                'income' => round((float) $mesic->where('kind', 'income')->sum('amount'), 2),
                'count' => $mesic->count(),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * Kolik zbývá na den.
     *
     * Jediné číslo, které v cizině opravdu řídí chování. Počítá se z toho, co ještě
     * zbývá, a z počtu zbývajících dní — ne z původního plánu, který už neplatí.
     */
    private function allowance(Budget $budget, Collection $vydaje, Carbon $today): array
    {
        $rozpocet = (float) $budget->categories->sum('planned_monthly') * $budget->monthsCovered();
        $utraceno = (float) $vydaje->where('currency', $budget->currency)->sum('amount');
        $zbyva = round($rozpocet - $utraceno, 2);

        $dniDoKonce = $budget->ends_on ? max(1, (int) $today->diffInDays($budget->ends_on, false)) : null;

        return [
            'planned_total' => round($rozpocet, 2),
            'spent' => round($utraceno, 2),
            'left' => $zbyva,
            'per_day' => $dniDoKonce ? round($zbyva / $dniDoKonce, 2) : null,
            'days_left' => $dniDoKonce,
            'currency' => $budget->currency,
        ];
    }

    /**
     * Kategorie, které docházejí nebo už přetekly.
     *
     * Počítá se proti tomu, co mělo být utraceno k dnešku, ne proti celému období —
     * utratit polovinu rozpočtu na jídlo je v půlce měsíce v pořádku a po týdnu ne, a
     * číslo, které tenhle rozdíl nevidí, varuje buď pořád, nebo nikdy.
     *
     * @return array<int, array<string, mixed>>
     */
    public function warnings(Budget $budget, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $budget->loadMissing(['categories', 'entries']);

        $konec = $budget->ends_on && $today->greaterThan($budget->ends_on) ? $budget->ends_on : $today;
        $mesicu = max(0.2, $budget->starts_on->diffInDays($konec) / 30.44);

        $varovani = [];

        foreach ($budget->categories as $category) {
            if ((float) $category->planned_monthly <= 0) continue;

            $utraceno = (float) $budget->entries
                ->where('kind', 'expense')
                ->where('budget_category_id', $category->id)
                ->where('currency', $budget->currency)
                ->sum('amount');

            $melo = (float) $category->planned_monthly * $mesicu;
            if ($melo <= 0) continue;

            $podil = $utraceno / $melo;
            if ($podil < 0.8) continue;

            $varovani[] = [
                'category' => $category->name,
                'spent' => round($utraceno, 2),
                'planned_to_date' => round($melo, 2),
                'percent' => (int) round($podil * 100),
                'level' => $podil >= 1 ? 'over' : 'close',
            ];
        }

        return $varovani;
    }

    /** @param Collection<int, BudgetEntry> $entries */
    private function byCurrency(Collection $entries): array
    {
        return $entries
            ->groupBy('currency')
            ->map(fn (Collection $skupina) => round((float) $skupina->sum('amount'), 2))
            ->all();
    }

    /**
     * Požádá partnera o peníze.
     *
     * Notifikace odchází hned: žádost, o které se druhý dozví, až sám otevře aplikaci,
     * je k ničemu právě ve chvíli, kdy na ni docházejí peníze.
     */
    public function requestMoney(GallerySpace $space, User $from, User $to, array $data): MoneyRequest
    {
        $request = MoneyRequest::create([
            'gallery_space_id' => $space->id,
            'from_user_id' => $from->id,
            'to_user_id' => $to->id,
            'amount' => $data['amount'],
            'currency' => strtoupper($data['currency']),
            'reason' => $data['reason'] ?? null,
            'status' => MoneyRequest::STATUS_PENDING,
        ]);

        $castka = number_format((float) $data['amount'], 2, ',', ' ') . ' ' . strtoupper($data['currency']);

        $to->notify(new GalleryNotification(
            'finance.request',
            $from->name . ' žádá o ' . $castka . ($data['reason'] ?? null ? ' — ' . $data['reason'] : ''),
            '/rozpocty#zadosti',
            '💸',
            ['money_request_uuid' => $request->uuid],
        ));

        return $request;
    }

    /**
     * Vyřízení žádosti.
     *
     * Kurz a skutečně poslaná částka se zapisují tady, protože teprve teď je někdo zná.
     */
    public function respond(MoneyRequest $request, User $user, string $status, array $data = []): MoneyRequest
    {
        abort_unless($request->isOpen(), 422, 'O této žádosti už bylo rozhodnuto.');

        $request->forceFill([
            'status' => $status,
            'responded_at' => now(),
            'response_note' => $data['response_note'] ?? null,
            'settled_amount' => $data['settled_amount'] ?? null,
            'settled_currency' => isset($data['settled_currency']) ? strtoupper($data['settled_currency']) : null,
            'exchange_rate' => $data['exchange_rate'] ?? null,
        ])->save();

        $request->requester?->notify(new GalleryNotification(
            'finance.request',
            $status === MoneyRequest::STATUS_SENT
                ? $user->name . ' peníze poslal/a.'
                : $user->name . ' žádost zamítl/a.',
            '/rozpocty#zadosti',
            $status === MoneyRequest::STATUS_SENT ? '✅' : '↩️',
            ['money_request_uuid' => $request->uuid],
        ));

        return $request;
    }
}
