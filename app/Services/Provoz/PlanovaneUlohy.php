<?php

namespace App\Services\Provoz;

use App\Models\ScheduledTaskRun;
use App\Models\SystemSetting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Plánované úlohy tak, jak je vidí administrace.
 *
 * Seznam se **nedrží ve druhé tabulce** — jediná pravda je `routes/console.php`.
 * Kdyby se úlohy vypisovaly z databáze, přidaná úloha by v administraci chyběla
 * a zrušená by tam zůstala navždy. Z databáze se bere jen to, co v plánu být
 * nemůže: kdy úloha naposledy běžela, jak dopadla a jestli ji někdo pozastavil.
 */
class PlanovaneUlohy
{
    /** Pozastavené úlohy — v nastavení, protože jich je pár a mění se výjimečně. */
    private const KLIC_PAUZY = 'schedule.paused';

    /** @return Collection<int, array<string, mixed>> */
    public function seznam(): Collection
    {
        $behy = ScheduledTaskRun::posledni();
        $pozastavene = $this->pozastavene();

        return collect($this->udalosti())
            ->map(function (Event $uloha) use ($behy, $pozastavene) {
                $nazev = $this->nazev($uloha);
                $beh = $behy->get($nazev);
                $stojí = in_array($nazev, $pozastavene, true);

                return [
                    'id' => $nazev,
                    'name' => $this->popis($nazev),
                    'cron' => $stojí ? 'pozastaveno' : $this->kdy($uloha->expression),
                    'last' => $beh?->started_at ? $this->kdyBezela($beh->started_at) : 'nikdy',
                    'dur' => $beh?->duration_ms !== null ? $this->trvani($beh->duration_ms) : '—',
                    'state' => $stojí ? 'pozastavená' : $this->stav($beh?->state),
                    // Příkaz se ven neposílá: nese celou cestu k PHP na serveru
                    // a v administraci pro dva lidi z toho nikdo nic nemá.
                ];
            })
            ->values();
    }

    /** Chyby za posledních třicet dní — to, o čem se člověk jinak nedozví. */
    public function incidenty(int $dni = 30): Collection
    {
        return ScheduledTaskRun::neuspesne()
            ->where('started_at', '>=', now()->subDays($dni))
            ->latest('started_at')
            ->limit(20)
            ->get()
            ->map(fn (ScheduledTaskRun $beh) => [
                'when' => $beh->started_at->format('j. n. G:i'),
                'what' => $this->popis($beh->task).' skončila chybou',
                'fix' => trim(mb_substr((string) ($beh->output ?: 'bez výpisu'), 0, 160)),
            ]);
    }

    public function pozastavena(string $nazev): bool
    {
        return in_array($nazev, $this->pozastavene(), true);
    }

    /** Přepne pozastavení a vrátí nový stav. */
    public function prepni(string $nazev): bool
    {
        $pozastavene = $this->pozastavene();

        if (in_array($nazev, $pozastavene, true)) {
            $pozastavene = array_values(array_diff($pozastavene, [$nazev]));
            $ted = false;
        } else {
            $pozastavene[] = $nazev;
            $ted = true;
        }

        SystemSetting::set(self::KLIC_PAUZY, json_encode(array_values(array_unique($pozastavene))), 'json', 'system');

        return $ted;
    }

    public function najdi(string $nazev): ?Event
    {
        foreach ($this->udalosti() as $uloha) {
            if ($this->nazev($uloha) === $nazev) {
                return $uloha;
            }
        }

        return null;
    }

    /**
     * Naplánované úlohy — i mimo konzoli.
     *
     * `routes/console.php` načítá konzolové jádro, takže ve **webovém** požadavku
     * je plán prázdný. Bez tohohle by administrace ukazovala nula úloh a vypadalo
     * by to, že plánovač není nastavený.
     *
     * @return array<int, Event>
     */
    private function udalosti(): array
    {
        $plan = app(Schedule::class);

        if ($plan->events() === [] && ! app()->runningInConsole() && ! self::$nacteno) {
            self::$nacteno = true;
            require base_path('routes/console.php');
        }

        return $plan->events();
    }

    /** Jednou za požadavek; podruhé by se každá úloha zdvojila. */
    private static bool $nacteno = false;

    public function nazev(Event $uloha): string
    {
        return mb_substr((string) ($uloha->description ?: $uloha->getSummaryForDisplay()), 0, 191);
    }

    // ——— pomocné ———

    /** @return list<string> */
    private function pozastavene(): array
    {
        $ulozene = json_decode((string) SystemSetting::get(self::KLIC_PAUZY, '[]'), true);

        return is_array($ulozene) ? array_values(array_filter($ulozene, 'is_string')) : [];
    }

    /**
     * Jméno úlohy je technický klíč (`trash-purge`). Administraci čtou dva lidi,
     * ne správce serveru, takže se ukazuje česky.
     */
    private function popis(string $nazev): string
    {
        return self::POPISY[$nazev] ?? ucfirst(str_replace('-', ' ', $nazev));
    }

    private const POPISY = [
        'calendar-reminders' => 'Připomínky kalendáře',
        'daily-moment' => 'Okamžik dne',
        'cycle-reminders' => 'Připomínky cyklu',
        'notification-digest' => 'Souhrn upozornění',
        'auto-tag' => 'Automatické štítky',
        'close-elapsed-calendar-events' => 'Uzavření proběhlých akcí',
        'planning-followups' => 'Návaznosti plánování',
        'relationship-milestones' => 'Výročí a milníky',
        'cinema-city-program' => 'Program kina',
        'read-only-bank-sync' => 'Načtení z banky',
        'storage-health' => 'Kontrola úložiště',
        'retry-pending-drive' => 'Opakování nedoručených úloh',
        'quick-reconciliation' => 'Přepočet alb',
        'daily-status' => 'Denní přehled',
        'mirror-backlog' => 'Kopie originálů do cloudu',
        'temp-cleanup' => 'Úklid dočasných souborů',
        'weekly-duplicate-scan' => 'Hledání duplicit',
        'trash-purge' => 'Vysypání koše po lhůtě',
        'galerie-expire' => 'Vypršení domluv',
        'galerie-notify' => 'Upozornění na revize',
        'scheduler-heartbeat' => 'Tep plánovače',
    ];

    /** Výraz cronu česky. Pokrývá tvary, které aplikace používá. */
    private function kdy(string $vyraz): string
    {
        [$min, $hod, $den, $mesic, $tyden] = array_pad(preg_split('/\s+/', trim($vyraz)), 5, '*');

        $cas = fn () => sprintf('%d:%02d', (int) $hod, (int) $min);

        return match (true) {
            $vyraz === '* * * * *' => 'každou minutu',
            str_starts_with($min, '*/') && $hod === '*' => 'každých '.substr($min, 2).' minut',
            $hod === '*' && $min === '0' => 'každou hodinu',
            $hod === '*' && ctype_digit($min) => 'každou hodinu v '.((int) $min).'. minutě',
            str_starts_with($hod, '*/') => 'každých '.substr($hod, 2).' hodin',
            $tyden !== '*' => 'týdně '.$this->den($tyden).' '.$cas(),
            $den !== '*' => 'měsíčně '.$den.'. v '.$cas(),
            $mesic !== '*' => 'ročně '.$cas(),
            default => 'denně '.$cas(),
        };
    }

    private function den(string $cislo): string
    {
        return [
            '0' => 'v neděli', '1' => 'v pondělí', '2' => 'v úterý', '3' => 've středu',
            '4' => 've čtvrtek', '5' => 'v pátek', '6' => 'v sobotu',
        ][$cislo] ?? 'v den '.$cislo;
    }

    private function stav(?string $stav): string
    {
        return match ($stav) {
            ScheduledTaskRun::HOTOVO => 'hotovo',
            ScheduledTaskRun::CHYBA => 'chyba',
            ScheduledTaskRun::BEZI => 'běží',
            ScheduledTaskRun::PRESKOCENO => 'přeskočená',
            default => 'čeká',
        };
    }

    private function kdyBezela(Carbon $kdy): string
    {
        return match (true) {
            $kdy->isToday() => 'dnes '.$kdy->format('G:i'),
            $kdy->isYesterday() => 'včera '.$kdy->format('G:i'),
            default => $kdy->format('j. n. G:i'),
        };
    }

    private function trvani(int $ms): string
    {
        return match (true) {
            $ms < 1000 => $ms.' ms',
            $ms < 90_000 => round($ms / 1000).' s',
            default => round($ms / 60_000).' min',
        };
    }
}
