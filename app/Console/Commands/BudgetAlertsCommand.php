<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Models\User;
use App\Notifications\GalleryNotification;
use App\Services\Finance\BudgetService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Ozve se, když rozpočtu docházejí peníze.
 *
 * Systém uměl spočítat, že při současném tempu peníze dojdou třetího října a že jídlo
 * přeteklo plán o čtyřicet procent — jenže to bylo vidět jedině tomu, kdo si stránku
 * otevřel. Kdo je půl roku v cizině, otevře si ji ve chvíli, kdy už mu na kartě něco
 * chybí, ne dva týdny předtím, kdy se s tím ještě dá něco dělat.
 *
 * Každé upozornění chodí jednou. Varování, které přijde každé ráno, se po třetím dni
 * přestane číst a člověk si vypne upozornění úplně — takže se pak nedozví ani to, na
 * čem opravdu záleží. Zapamatuje se proto, na co už bylo upozorněno, a znovu se ozve
 * teprve tehdy, když se stav změní: kategorie přeteče o dalších dvacet procent, nebo
 * se datum vyčerpání posune o týden dřív.
 */
class BudgetAlertsCommand extends Command
{
    protected $signature = 'gallery:budget-alerts
        {--force : Poslat i mimo obvyklou denní dobu}
        {--dry : Jen vypsat, nic neodesílat}';

    protected $description = 'Upozorní na přetečené kategorie a na to, že rozpočtu docházejí peníze.';

    /** Jak dlouho se pamatuje, na co už bylo upozorněno. */
    private const PAMET_DNU = 40;

    public function handle(BudgetService $budgets): int
    {
        // Jednou denně dopoledne, stejně jako ostatní připomínky. Bez toho by hodinový
        // plánovač poslal totéž upozornění desetkrát za den.
        if (! $this->option('force') && ! Carbon::now()->between(Carbon::today()->setTime(8, 0), Carbon::today()->setTime(10, 0))) {
            return self::SUCCESS;
        }

        $dnes = Carbon::today();
        $posláno = 0;

        foreach ($this->beziciRozpocty($dnes) as $budget) {
            $prehled = $budgets->overview($budget, $dnes);

            foreach ($this->zpravy($budget, $prehled, $dnes) as $zprava) {
                $posláno += $this->doruc($budget, $zprava);
            }
        }

        $this->info($posláno > 0
            ? "Odesláno upozornění: {$posláno}."
            : 'Dnes není na co upozorňovat.');

        return self::SUCCESS;
    }

    /**
     * Rozpočty, které právě běží.
     *
     * Skončený rozpočet upozorňovat nemá na co a ten, který ještě nezačal, taky ne.
     *
     * @return \Illuminate\Support\Collection<int, Budget>
     */
    private function beziciRozpocty(Carbon $dnes)
    {
        return Budget::whereNull('deleted_at')
            ->whereDate('starts_on', '<=', $dnes)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $dnes))
            ->with('gallerySpace')
            ->get();
    }

    /**
     * Co je dnes na tomhle rozpočtu za zprávu.
     *
     * Klíč slouží k zapamatování; mění se spolu se závažností, takže zhoršení projde
     * a stejný stav podruhé ne.
     *
     * @param  array<string, mixed>  $prehled
     * @return array<int, array{klic: string, text: string, ikona: string}>
     */
    private function zpravy(Budget $budget, array $prehled, Carbon $dnes): array
    {
        $ven = [];

        // Peníze dojdou dřív než období. Klíč nese týden vyčerpání, takže se posun
        // o pár dní neohlásí znovu, ale posun o týden ano — to už je jiná zpráva.
        $tempo = $prehled['runway'] ?? null;

        if ($tempo && ! $tempo['covers_period'] && $budget->ends_on) {
            $dojde = Carbon::parse($tempo['runs_out_on']);
            $chybiDnu = (int) $dojde->diffInDays($budget->ends_on, false);

            $ven[] = [
                'klic' => 'runway:'.$dojde->format('o-W'),
                // sprintf i tady, ne vkládání do dvojitých uvozovek: uvnitř by se text
                // rozpadl na české uvozovce, kterou parser vezme jako konec řetězce.
                'text' => $tempo['days_left'] === 0
                    ? sprintf('Rozpočet „%s" je vyčerpaný.', $budget->name)
                    : sprintf(
                        'Rozpočet „%s": při současném tempu %s denně peníze dojdou %s — %s před koncem období.',
                        $budget->name,
                        $this->castka((float) $tempo['per_day'], $budget->currency),
                        $dojde->locale('cs')->isoFormat('D. M.'),
                        $this->dny(max(1, $chybiDnu)),
                    ),
                'ikona' => '⚠️',
            ];
        }

        // Přetečené kategorie. Do jedné zprávy, ne po jedné — tři upozornění za sebou
        // z téhož rozpočtu působí jako porucha, ne jako informace.
        $pres = collect($prehled['warnings'] ?? [])->where('level', 'over')->values();

        if ($pres->isNotEmpty()) {
            // Zaokrouhlení na desítky procent: klíč se změní, až když se to zhorší
            // znatelně, ne při každém dalším nákupu.
            $stav = $pres->map(fn (array $v) => $v['category'].':'.(int) floor($v['percent'] / 20))->implode('|');

            $ven[] = [
                'klic' => 'over:'.md5($stav),
                'text' => sprintf(
                    'Rozpočet „%s": %s %s plán — %s.',
                    $budget->name,
                    $pres->count() === 1 ? 'kategorie' : 'kategorie',
                    $pres->count() === 1 ? 'přetekla' : 'přetekly',
                    $pres->map(fn (array $v) => "{$v['category']} {$v['percent']} %")->implode(', '),
                ),
                'ikona' => '📊',
            ];
        }

        // Spoření: blíží se termín a pořád chybí. Ozve se měsíc a týden předem, ne dřív
        // — na dlouhou vzdálenost se s tím stejně nedá nic dělat.
        $sporeni = $prehled['savings'] ?? null;

        if ($sporeni && ! $sporeni['reached'] && ! $sporeni['overdue'] && $sporeni['days_left'] !== null) {
            $zbyva = (int) $sporeni['days_left'];

            if (in_array($zbyva, [30, 7], true)) {
                $ven[] = [
                    'klic' => "savings:{$zbyva}:".$sporeni['target_on'],
                    'text' => sprintf(
                        'Do cíle „%s" zbývá %s a chybí %s.',
                        $budget->name,
                        $this->dny($zbyva),
                        $this->castka((float) $sporeni['missing'], $budget->currency),
                    ),
                    'ikona' => '🐖',
                ];
            }
        }

        return $ven;
    }

    /**
     * Pošle zprávu těm, kdo na rozpočet vidí.
     *
     * Osobní rozpočet je soukromý, dokud ho vlastník nesdílí — a to platí i pro
     * upozornění. Poslat partnerovi, že „docházejí peníze na tajný dárek", by prozradilo
     * přesně to, co si člověk nechává pro sebe.
     *
     * @param  array{klic: string, text: string, ikona: string}  $zprava
     */
    private function doruc(Budget $budget, array $zprava): int
    {
        $prijemci = $budget->owner_user_id !== null && ! $budget->is_shared
            ? User::whereKey($budget->owner_user_id)->get()
            : ($budget->gallerySpace?->members()->get() ?? collect());

        $poslano = 0;

        foreach ($prijemci as $clen) {
            $klic = "budget:alert:{$budget->id}:{$clen->id}:{$zprava['klic']}";

            // Cache::add zapisuje jen když klíč ještě není — dvě souběžná spuštění
            // příkazu tak nepošlou tutéž zprávu dvakrát.
            if (! Cache::add($klic, true, now()->addDays(self::PAMET_DNU))) {
                continue;
            }

            $this->line("  → {$clen->name}: {$zprava['text']}");

            if (! $this->option('dry')) {
                $clen->notify(new GalleryNotification(
                    'finance.budget',
                    $zprava['text'],
                    '/rozpocty',
                    $zprava['ikona'],
                    ['budget_uuid' => $budget->uuid],
                ));
            }

            $poslano++;
        }

        return $poslano;
    }

    private function castka(float $castka, string $mena): string
    {
        return number_format($castka, 0, ',', ' ').' '.$mena;
    }

    private function dny(int $pocet): string
    {
        return $pocet.' '.match (true) {
            $pocet === 1 => 'den',
            $pocet >= 2 && $pocet <= 4 => 'dny',
            default => 'dní',
        };
    }
}
