<?php

namespace App\Console\Commands;

use App\Models\CycleSetting;
use App\Models\User;
use App\Notifications\GalleryNotification;
use App\Services\Health\CycleService;
use App\Support\Cas;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Připomene blížící se menstruaci.
 *
 * Kalendář nabízel „upozorni mě dva dny předem" a nikdy nic neposlal — nastavení se
 * uložilo a tím to skončilo. Zapnutá připomínka, která nepřijde, je horší než žádná:
 * člověk na ni spoléhá.
 *
 * Chodí jen majitelce záznamu. Partnerovi ne, ani při plném sdílení — sdílet kalendář
 * znamená „můžeš se podívat", ne „ozvi se mi kvůli tomu sám".
 */
class CycleRemindersCommand extends Command
{
    protected $signature = 'gallery:cycle-reminders {--force : Poslat i mimo obvyklou denní dobu}';

    protected $description = 'Upozorní na blížící se menstruaci podle nastavení kalendáře.';

    public function handle(CycleService $cycles): int
    {
        $dnes = Cas::dnes();

        /*
         * Jednou denně dopoledne. Bez toho by minutový plánovač poslal totéž
         * upozornění tisíckrát za den.
         *
         * V pásmu dvojice, ne v UTC: `config('app.timezone')` je UTC, takže
         * „8 až 10" vycházelo v létě na 10 až 12 pražského času — proti tomu,
         * co slibuje komentář i obrazovka nastavení.
         */
        $ted = Cas::ted();

        if (! $this->option('force') && ! $ted->between($ted->setTime(8, 0), $ted->setTime(10, 0))) {
            return self::SUCCESS;
        }

        $posláno = 0;

        foreach (CycleSetting::where('remind_upcoming', true)->with('user')->get() as $settings) {
            $user = User::find($settings->user_id);
            $space = $user?->gallerySpaces()->whereKey($settings->gallery_space_id)->first();

            if (! $user || ! $space) {
                continue;
            }

            $prehled = $cycles->overview($space, $user, $dnes);
            $predpoved = $prehled['prediction'] ?? null;

            if (! $predpoved) {
                continue;
            }

            $zbyva = (int) $predpoved['days_until'];

            if ($zbyva !== (int) $settings->remind_days_before) {
                continue;
            }

            // Klíč na den, aby dvojí běh za stejné dopoledne neposlal dvě zprávy.
            $klic = "cycle:reminded:{$user->id}:".$dnes->toDateString();
            if (! Cache::add($klic, true, now()->addHours(20))) {
                continue;
            }

            $user->notify(new GalleryNotification(
                'health.cycle',
                $zbyva === 0
                    ? 'Menstruace by měla začít dnes.'
                    : 'Menstruace se blíží — čekaná '.Carbon::parse($predpoved['next_period_on'])->locale('cs')->isoFormat('D. M.').'.',
                '/cyklus',
                '🩸',
                ['days_until' => $zbyva],
            ));

            $posláno++;
        }

        $this->info($posláno > 0 ? "Odesláno připomínek: {$posláno}." : 'Dnes není koho upozornit.');

        return self::SUCCESS;
    }
}
