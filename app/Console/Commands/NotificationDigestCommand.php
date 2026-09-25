<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\GalleryNotification;
use App\Services\Notifications\NotificationPreferenceService;
use App\Support\Cas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Večerní souhrn místo drobností během dne.
 *
 * Kdo si souhrn zapne, dostane jednu zprávu navečer a nízkoprioritní upozornění mu
 * během dne nechodí vůbec — o to jde. Souhrn, který přibude k pěti stávajícím
 * upozorněním, situaci zhoršuje.
 *
 * Sbírá se z toho, co se opravdu stalo. Zastavená upozornění nikde nezůstala, takže
 * držet frontu čekajících textů by znamenalo další tabulku a další místo, kde se něco
 * rozejde; sečíst dnešek z databáze je spolehlivější.
 */
class NotificationDigestCommand extends Command
{
    protected $signature = 'gallery:notification-digest {--force : Poslat i mimo večerní čas}';

    protected $description = 'Pošle večerní souhrn těm, kdo si ho zapnuli.';

    public function handle(NotificationPreferenceService $preferences): int
    {
        /*
         * Podvečer v pásmu dvojice, ne v UTC.
         *
         * „19 až 21" vycházelo v létě na 21 až 23 pražského času a `$od` jako
         * půlnoc UTC znamenalo druhou ráno — do „dnešního souhrnu" se tedy
         * nedostalo nic z prvních dvou hodin dne.
         */
        $ted = Cas::ted();

        if (! $this->option('force') && ! $ted->between($ted->setTime(19, 0), $ted->setTime(21, 0))) {
            return self::SUCCESS;
        }

        // `Cas::dnes()` dá datum jako půlnoc UTC (kvůli řazení s DATE sloupci);
        // pro porovnání s `created_at` (skutečný okamžik v UTC) je potřeba
        // opravdová půlnoc Prahy — jinak zůstávalo mimo souhrn všechno mezi
        // půlnocí a druhou ráno pražského času.
        $od = Cas::pulnocUtc();
        $denPraha = Cas::dnes();
        $posláno = 0;

        foreach (User::all() as $user) {
            if (! $preferences->wantsDigest($user)) {
                continue;
            }

            // Nepřečtená upozornění z dneška, po kategoriích. Přečtené se nepřipomínají —
            // člověk je viděl a souhrn není výpis historie.
            $souhrn = DB::table('notifications')
                ->where('notifiable_id', $user->id)
                ->whereNull('read_at')
                ->where('created_at', '>=', $od)
                ->pluck('data')
                ->map(fn ($json) => json_decode($json, true)['category_label'] ?? 'Ostatní')
                ->countBy();

            if ($souhrn->isEmpty()) {
                continue;
            }

            $klic = "digest:sent:{$user->id}:".$denPraha->toDateString();
            if (! Cache::add($klic, true, now()->addHours(20))) {
                continue;
            }

            $text = $souhrn->map(fn (int $pocet, string $kategorie) => "{$kategorie} ({$pocet})")->implode(', ');

            $user->notify(new GalleryNotification(
                'system.digest',
                'Dnešní souhrn: '.$text.'.',
                '/inbox',
                '📬',
                ['digest' => true],
            ));

            $posláno++;
        }

        $this->info($posláno > 0 ? "Odesláno souhrnů: {$posláno}." : 'Nikomu není co shrnout.');

        return self::SUCCESS;
    }
}
