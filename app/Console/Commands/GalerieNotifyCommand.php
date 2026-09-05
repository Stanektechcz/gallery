<?php

namespace App\Console\Commands;

use App\Models\CoupleState;
use App\Models\GallerySpace;
use App\Services\Notifications\WebPushService;
use Illuminate\Console\Command;

/**
 * Revize rozhodnutí: co si pár odložil „na později", se jednou ozve.
 *
 * Jediné upozornění, které prototyp posílá. Vypršení domluvy zůstává tiché
 * (viz [GalerieExpireCommand]) — kdyby se ozvalo i to, přestalo by upozornění
 * v telefonu něco znamenat.
 *
 * Rozhodnutí čte ze **stavu páru**, ne z výchozích dat klienta. Kdo se
 * rozhodnutí nikdy nedotkl, žádné vlastní nemá — a upozorňovat na ukázkový
 * řádek z prototypu by bylo horší než neupozornit vůbec.
 */
class GalerieNotifyCommand extends Command
{
    protected $signature = 'galerie:notify';

    protected $description = 'Pošle upozornění na rozhodnutí, která čekají na revizi';

    /** Stav, který klient nastaví rozhodnutí čekajícímu na revizi. */
    private const K_REVIZI = 'k revizi';

    public function handle(WebPushService $push): int
    {
        if (! $push->configured()) {
            $this->warn('Klíče VAPID nejsou nastavené — upozornění se neposílají. Vygeneruje je `php artisan gallery:push-keys`.');

            return self::SUCCESS;
        }

        $odeslano = 0;

        CoupleState::query()->each(function (CoupleState $stav) use ($push, &$odeslano) {
            $ceka = collect($stav->data['decs'] ?? [])
                ->filter(fn ($r) => is_array($r) && ($r['status'] ?? null) === self::K_REVIZI);

            if ($ceka->isEmpty()) {
                return;
            }

            $prostor = GallerySpace::find($stav->couple_id);

            if ($prostor === null) {
                return;
            }

            $zprava = [
                'title' => 'Revize rozhodnutí',
                'body' => $ceka->count() === 1
                    ? 'Jedno rozhodnutí čeká na revizi — vraťte se k němu, dokud je čerstvé.'
                    : $ceka->count().' rozhodnutí čeká na revizi.',
                'url' => '/?x=x-rozhodnuti',
                // Stejná značka znamená, že nové upozornění to včerejší nahradí,
                // místo aby se v telefonu vršila.
                'tag' => 'galerie-revize',
            ];

            foreach ($prostor->members as $clen) {
                $odeslano += $push->sendToUser($clen, $zprava);
            }
        });

        $this->info($odeslano ? 'Odesláno '.$odeslano.' upozornění.' : 'Nic k odeslání.');

        return self::SUCCESS;
    }
}
