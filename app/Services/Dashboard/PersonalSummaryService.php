<?php

namespace App\Services\Dashboard;

use App\Models\Budget;
use App\Models\CycleDay;
use App\Models\FinanceProject;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\FinanceService;
use App\Services\Health\CycleService;
use Illuminate\Support\Carbon;

/**
 * Dvě čísla z rozpočtů a cyklu na nástěnku.
 *
 * Obojí byl dosud ostrov — dalo se tam dostat jedině přes menu, takže „kolik dneska
 * můžu utratit" znamenalo dvě kliknutí a načtení celé stránky. Přitom je to údaj, kvůli
 * kterému člověk aplikaci otevírá.
 *
 * Soukromí se neřeší tady. Rozpočet se filtruje jeho vlastní viditelností a cyklus
 * partnera projde přes partnerView, který zahodí všechno nad rámec nastaveného sdílení —
 * odfiltrovat to až na obrazovce by znamenalo, že údaj po drátě stejně přišel.
 */
class PersonalSummaryService
{
    public function __construct(
        private readonly FinanceService $finance,
        private readonly CycleService $cycles,
    ) {}

    /** @return array<string, mixed> */
    public function forUser(GallerySpace $space, User $user, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        $cyklus = $this->cycle($space, $user, $today);

        return [
            'budget' => $this->budget($space, $user, $today),
            'cycle' => $cyklus === null ? null : $cyklus + [
                // Jedním klepnutím z nástěnky místo pěti kroků přes kalendář.
                'can_log_start' => $this->nabidnoutZapis($user, $cyklus, $today),
                'today' => $today->toDateString(),
            ],
        ];
    }

    /**
     * Rozpočet na domovské stránce.
     *
     * Bere se z modulu Rozpočet, ne ze staré evidence s vlastními položkami. Kdyby
     * domovská stránka počítala z jednoho zdroje a `/rozpocty` z druhého, ukázaly by
     * dvě různá čísla o téže věci — a nikdo by nepoznal, které platí.
     *
     * Přednost má aktivní cesta: kdo je v Německu, se ptá na pobyt, ne na měsíc.
     * Teprve když se nikam nejede, ukáže se měsíční rozpočet.
     */
    private function budget(GallerySpace $space, User $user, Carbon $today): ?array
    {
        $cesta = FinanceProject::where('gallery_space_id', $space->id)
            ->where('kind', 'trip')->where('is_active', true)
            ->whereNotNull('budget_amount')
            ->first();

        $rozpocet = $cesta ? null : Budget::where('gallery_space_id', $space->id)
            ->where('scope', 'ledger')->where('budget_kind', 'monthly')
            ->orderByDesc('id')->first();

        if (! $cesta && ! $rozpocet) return null;

        $mena = $cesta?->base_currency ?? $rozpocet->currency;
        $limit = (float) ($cesta?->budget_amount ?? $rozpocet->starting_funds ?? 0);
        $rezerva = (float) ($cesta?->reserve_amount ?? $rozpocet->reserve_amount ?? 0);

        [$od, $do] = $cesta
            ? [$cesta->starts_on, $cesta->ends_on]
            : [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()];

        $pohyby = Transaction::where('gallery_space_id', $space->id)
            ->whereDate('occurred_at', '>=', $od)
            ->when($do, fn ($q) => $q->whereDate('occurred_at', '<=', $do))
            ->when($cesta, fn ($q) => $q->where('finance_project_id', $cesta->id))
            ->get();

        $utraceno = (float) $pohyby
            ->filter(fn (Transaction $t) => $t->countsTowardsBudget() && $t->currency_from === $mena)
            ->sum('amount_from')
            + $pohyby->sum(fn (Transaction $t) => ($t->fee_currency ?? $t->currency_from) === $mena ? $t->feePaidExtra() : 0);

        $bezpecne = $this->finance->safeDaily($limit, $utraceno, $rezerva, $od, $do, $today);

        return [
            'uuid' => $cesta?->uuid ?? $rozpocet->uuid,
            'name' => $cesta?->name ?? $rozpocet->name,
            'currency' => $mena,
            'per_day' => $bezpecne['per_day'],
            'left' => $bezpecne['remaining'],
            'days_left' => $bezpecne['days_left'],
            // Jedno číslo místo celého panelu: kolik procent je vyčerpáno.
            'percent' => $limit > 0 ? min(999, (int) round($utraceno / $limit * 100)) : 0,
            'over_by' => $bezpecne['over_by'],
        ];
    }

    /**
     * Vlastní cyklus, a když si ho člověk nevede, tak partnerčin v míře, kterou sdílí.
     *
     * @return array<string, mixed>|null
     */
    private function cycle(GallerySpace $space, User $user, Carbon $today): ?array
    {
        // Vlastní má přednost. Pozná se podle toho, že vůbec něco zapsal — bez záznamů
        // by se ukazovala předpověď spočítaná z výchozích čísel, což není informace.
        if (CycleDay::where('user_id', $user->id)->exists()) {
            $muj = $this->cycles->overview($space, $user, $today);

            return $this->zeStavu($muj['prediction'] ?? null, $muj['today'] ?? null, null);
        }

        foreach ($space->members()->where('users.id', '!=', $user->id)->get() as $clen) {
            $pohled = $this->cycles->partnerView($space, $clen, $today);

            if ($pohled && $pohled['prediction']) {
                return $this->zeStavu($pohled['prediction'], $pohled['today'], $clen->name);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $predpoved
     * @param  array<string, mixed>|null  $dnes
     * @return array<string, mixed>|null
     */
    private function zeStavu(?array $predpoved, ?array $dnes, ?string $cizi): ?array
    {
        if (! $predpoved) return null;

        return [
            // Čí to je. Null znamená „moje" — a na obrazovce se pak nepíše žádné jméno.
            'owner' => $cizi,
            'next_period_on' => $predpoved['next_period_on'],
            'days_until' => (int) $predpoved['days_until'],
            'phase' => $dnes['phase'] ?? null,
            'cycle_day' => $dnes['cycle_day'] ?? null,
            'confidence' => $predpoved['confidence'],
        ];
    }

    /**
     * Má se na nástěnce nabídnout „začalo to dnes"?
     *
     * Zapsat první den šlo dosud jedině přes menu, kalendář, výběr dne, výběr síly
     * a uložení — pět kroků pro věc, kterou člověk dělá dvanáctkrát ročně ve chvíli,
     * kdy se mu do proklikávání nechce. Přitom právě první den je ten, na kterém stojí
     * celá předpověď: bez něj se posune všechno ostatní.
     *
     * Nabízí se jen ve dnech kolem očekávaného termínu a jen tehdy, když dnešek ještě
     * není zapsaný. Tlačítko, které svítí pořád, by v kalendáři nadělalo víc škody než
     * užitku — omylem zapsaný začátek posune předpověď stejně jako ten skutečný.
     *
     * Cizí cyklus tlačítko nedostane nikdy. Sdílet termíny znamená „můžeš se podívat",
     * ne „zapisuj mi to".
     */
    private function nabidnoutZapis(User $user, ?array $cyklus, Carbon $dnes): bool
    {
        if ($cyklus === null || $cyklus['owner'] !== null) return false;

        // Od tří dnů před očekávaným termínem po pět dní po něm. Dřív je to plané,
        // později už si toho člověk všiml sám.
        if ($cyklus['days_until'] > 3 || $cyklus['days_until'] < -5) return false;

        return ! CycleDay::where('user_id', $user->id)
            ->whereDate('day', $dnes->toDateString())
            ->where('is_predicted', false)
            ->exists();
    }
}
