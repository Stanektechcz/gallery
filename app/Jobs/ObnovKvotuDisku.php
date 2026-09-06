<?php

namespace App\Jobs;

use App\Models\StorageConnection;
use App\Services\Storage\GoogleDriveStorageProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Obnoví, kolik místa zbývá na Google Disku.
 *
 * Kvóta se zapsala při připojení účtu a od té chvíle ji nikdo neaktualizoval —
 * postranní panel by tedy ukazoval stav z toho dne, i kdyby se Disk mezitím
 * zaplnil. Volá se z požadavku, ale běží ve frontě: kdo si otevře galerii,
 * nemá čekat, až odpoví Google.
 *
 * Selhání se **zapisuje, ne opakuje**. Nedostupný Disk není důvod, proč by
 * galerie neměla nakreslit postranní panel z toho, co ví.
 */
class ObnovKvotuDisku implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(private readonly int $pripojeniId) {}

    /** Jedno připojení nemá smysl obnovovat víckrát za sebou. */
    public function uniqueId(): string
    {
        return 'kvota-disku-'.$this->pripojeniId;
    }

    public function handle(): void
    {
        $disk = StorageConnection::find($this->pripojeniId);

        if ($disk === null || $disk->revoked_at !== null) {
            return;
        }

        try {
            (new GoogleDriveStorageProvider($disk))->healthCheck();
        } catch (\Throwable $e) {
            Log::info('Kvótu Google Disku se nepodařilo obnovit', [
                'connection' => $disk->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
