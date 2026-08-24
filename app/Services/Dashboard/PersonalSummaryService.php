<?php

namespace App\Services\Dashboard;

use App\Models\Budget;
use App\Models\CycleDay;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Finance\BudgetService;
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
        private readonly BudgetService $budgets,
        private readonly CycleService $cycles,
    ) {}

    /** @return array<string, mixed> */
    public function forUser(GallerySpace $space, User $user, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();

        return [
            'budget' => $this->budget($space, $user, $today),
            'cycle' => $this->cycle($space, $user, $today),
        ];
    }

    /**
     * Rozpočet, který právě běží.
     *
     * Když jich běží víc, vyhraje ten, co končí nejdřív — tomu docházejí peníze jako
     * prvnímu a je to ten, o kterém má smysl vědět.
     *
     * @return array<string, mixed>|null
     */
    private function budget(GallerySpace $space, User $user, Carbon $today): ?array
    {
        $bezici = $this->budgets->forUser($space, $user)
            ->filter(fn (Budget $b) => $b->starts_on->lessThanOrEqualTo($today)
                && ($b->ends_on === null || $b->ends_on->greaterThanOrEqualTo($today)))
            ->sortBy(fn (Budget $b) => $b->ends_on?->timestamp ?? PHP_INT_MAX)
            ->first();

        if (! $bezici) return null;

        $prehled = $this->budgets->overview($bezici, $today);
        $prideI = $prehled['allowance'];

        return [
            'uuid' => $bezici->uuid,
            'name' => $bezici->name,
            'currency' => $prideI['currency'],
            'per_day' => $prideI['per_day'],
            'left' => $prideI['left'],
            'days_left' => $prideI['days_left'],
            // Kolik kategorií je přes plán. Jedno číslo místo celého panelu varování.
            'warnings' => count($prehled['warnings'] ?? []),
            // Jestli to podle současného tempa nevyjde do konce období.
            'runs_out_on' => ($prehled['runway'] ?? null) && ! $prehled['runway']['covers_period']
                ? $prehled['runway']['runs_out_on']
                : null,
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
}
