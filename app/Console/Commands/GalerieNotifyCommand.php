<?php

namespace App\Console\Commands;

use App\Models\CoupleDecision;
use App\Models\GallerySpace;
use App\Services\Auth\PristupDoGalerie;
use App\Services\Notifications\WebPushService;
use App\Support\Tabulky;
use Illuminate\Console\Command;

/**
 * Revize rozhodnutí: co si pár odložil „na později", se jednou ozve.
 *
 * Jediné upozornění, které prototyp posílá. Vypršení domluvy zůstává tiché
 * (viz [GalerieExpireCommand]) — kdyby se ozvalo i to, přestalo by upozornění
 * v telefonu něco znamenat.
 *
 * Rozhodnutí čte z **paměti rozhodnutí** (`couple_decisions`), ne ze stavu
 * páru. `decs` je serverový klíč (`VztahVeStavu::SERVEROVE`): zápis ho ze
 * stavu vyhodí a uloží do databáze. Příkaz dřív četl stav, a tak se nikdy
 * neozval — a co ve stavu zbylo z dob před databází, je stará kopie. Kdo se
 * rozhodnutí nikdy nedotkl, žádné vlastní nemá, takže se mu nic neposílá.
 */
class GalerieNotifyCommand extends Command
{
    protected $signature = 'galerie:notify';

    protected $description = 'Pošle upozornění na rozhodnutí, která čekají na revizi';

    /** Stav, který klient nastaví rozhodnutí čekajícímu na revizi. */
    private const K_REVIZI = 'k revizi';

    public function handle(WebPushService $push, PristupDoGalerie $pristup): int
    {
        if (! $push->configured()) {
            $this->warn('Klíče VAPID nejsou nastavené — upozornění se neposílají. Vygeneruje je `php artisan gallery:push-keys`.');

            return self::SUCCESS;
        }

        $odeslano = 0;

        $cekajiciPodleProstoru = Tabulky::je('couple_decisions')
            ? CoupleDecision::where('status', self::K_REVIZI)
                ->selectRaw('gallery_space_id, count(*) as pocet')
                ->groupBy('gallery_space_id')
                ->pluck('pocet', 'gallery_space_id')
            : collect();

        foreach ($cekajiciPodleProstoru as $prostorId => $pocet) {
            $prostor = GallerySpace::find($prostorId);

            if ($prostor === null) {
                continue;
            }

            $zprava = [
                'title' => 'Revize rozhodnutí',
                'body' => (int) $pocet === 1
                    ? 'Jedno rozhodnutí čeká na revizi — vraťte se k němu, dokud je čerstvé.'
                    : $pocet.' rozhodnutí čeká na revizi.',
                'url' => '/',
                // Obrazovka rozhodnutí; worker prototypu podle toho otevře přímo ji.
                'route' => 'x-rozhodnuti',
                // Stejná značka znamená, že nové upozornění to včerejší nahradí,
                // místo aby se v telefonu vršila.
                'tag' => 'galerie-revize',
            ];

            // Jen ti, kdo do galerie smějí: host vidí jen sdílené odkazy a komu
            // vlastník přístup odebral, tomu se o obsahu dvojice nic neposílá.
            // `proc()` posuzuje první prostor účtu, proto se role ověřuje ještě
            // v tomhle — host odsud s vlastní galerií jinde by jinak prošel.
            foreach ($prostor->members as $clen) {
                $dvojice = (int) $prostor->owner_id === (int) $clen->id
                    || in_array((string) $clen->pivot->role, PristupDoGalerie::ROLE_DVOJICE, true);

                if (! $dvojice || $pristup->proc($clen) !== null) {
                    continue;
                }

                $odeslano += $push->sendToUser($clen, $zprava);
            }
        }

        $this->info($odeslano ? 'Odesláno '.$odeslano.' upozornění.' : 'Nic k odeslání.');

        return self::SUCCESS;
    }
}
