<?php

namespace App\Jobs\Media;

use App\Services\Hledani\ObnovaHledani;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Znovu složit `search_text` u dávky fotek.
 *
 * Hledaný text se dřív skládal jen při zpracování nahrané fotky a v úpravě
 * ze starého rozhraní. Popisek, místo, štítky a lidé zapsané v prototypu,
 * hromadné akce i přejmenování štítku, osoby, místa nebo alba ho nechaly
 * starý — fotka se hledala podle toho, co na ní bylo před týdnem.
 *
 * Až po potvrzení transakce: úloha čte vazby z databáze a uvnitř transakce
 * by na frontě viděla stav před změnou.
 */
class ObnovHledaniJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @param  list<int>  $idFotek */
    public function __construct(public readonly array $idFotek)
    {
        $this->onQueue('default');
    }

    /**
     * Naplánovat přepočet po dávkách — jedna úloha nikdy nenese víc než `DAVKA` id.
     *
     * @param  iterable<int|string>  $idFotek
     */
    public static function naplanuj(iterable $idFotek): void
    {
        $id = collect($idFotek)->map(fn ($i) => (int) $i)->filter()->unique()->values();

        foreach ($id->chunk(ObnovaHledani::DAVKA) as $davka) {
            static::dispatch($davka->values()->all());
        }
    }

    /**
     * `naplanuj()`, jen chyba zařazení se zapíše do logu místo výjimky.
     *
     * Pro místa, kde se úprava sama už povedla (zařazení do alba, sloučení):
     * hledaný text je odvozený a kvůli němu nemá požadavek skončit 500 —
     * dorovná ho `gallery:rebuild-search`.
     *
     * @param  iterable<int|string>  $idFotek
     */
    public static function zkusNaplanovat(iterable $idFotek, string $proc): void
    {
        try {
            static::naplanuj($idFotek);
        } catch (\Throwable $e) {
            Log::warning('Přepočet hledaného textu se nepodařilo zařadit', ['proc' => $proc, 'chyba' => $e->getMessage()]);
        }
    }

    public function handle(ObnovaHledani $obnova): void
    {
        $obnova->obnov($this->idFotek);
    }
}
