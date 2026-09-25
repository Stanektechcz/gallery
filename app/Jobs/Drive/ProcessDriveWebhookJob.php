<?php

namespace App\Jobs\Drive;

use App\Models\DriveChange;
use App\Models\DriveChangeChannel;
use App\Models\StorageConnection;
use App\Services\Storage\GoogleDriveStorageProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDriveWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        private readonly int $storageConnectionId,
        private readonly string $channelId,
        private readonly string $state,
        private readonly ?string $resourceId,
        private readonly int $messageNumber,
    ) {}

    public function handle(): void
    {
        $connection = StorageConnection::find($this->storageConnectionId);
        if (! $connection) {
            return;
        }

        $channel = DriveChangeChannel::where('channel_id', $this->channelId)->first();
        if (! $channel) {
            return;
        }

        try {
            $provider = app(GoogleDriveStorageProvider::class, ['connection' => $connection]);
            $pageToken = $channel->page_token ?? $provider->getStartPageToken();

            $result = $provider->listChanges($pageToken);

            // Každá změna zvlášť. Dřív jedna nepřijatá (název přes 255 znaků) shodila
            // celou dávku, značka stránky se neposunula a každé další upozornění
            // narazilo na tutéž změnu znovu — Disk se přestal synchronizovat úplně.
            foreach ($result['changes'] as $change) {
                $this->ulozZmenu($change);
            }

            // Update page token
            if ($result['new_start_token']) {
                $channel->update(['page_token' => $result['new_start_token']]);
            } elseif ($result['next_page_token']) {
                $channel->update(['page_token' => $result['next_page_token']]);
            }

            Log::info("Processed {$this->messageNumber} Drive webhook changes for connection #{$this->storageConnectionId}");
        } catch (\Throwable $e) {
            Log::error('Drive webhook processing failed', [
                'channel' => $this->channelId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Uloží jednu změnu; co databáze nepřijme, zaznamená do logu a přeskočí.
     *
     * Řetězce se zkracují na délku sloupců — Disk dovolí delší názvy, než
     * `drive_changes` unese, a MySQL by je odmítl (SQLite v testech ne).
     *
     * @param  array<string, mixed>  $change
     */
    private function ulozZmenu(array $change): void
    {
        $soubor = is_array($change['file'] ?? null) ? $change['file'] : [];

        try {
            DriveChange::create([
                'storage_connection_id' => $this->storageConnectionId,
                'change_type' => mb_substr($this->state, 0, 30),
                'file_id' => $this->zkrat($change['file_id'] ?? null),
                'file_name' => $this->zkrat($soubor['name'] ?? null),
                'removed' => (bool) ($change['removed'] ?? false),
                'trashed' => (bool) ($soubor['trashed'] ?? false),
                'change_payload' => $change,
                'processed_status' => 'pending',
                'change_time' => $change['time'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Drive webhook: změnu se nepodařilo uložit, přeskočena', [
                'connection' => $this->storageConnectionId,
                'file_id' => $this->zkrat($change['file_id'] ?? null),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function zkrat(mixed $hodnota): ?string
    {
        return is_scalar($hodnota) ? mb_substr((string) $hodnota, 0, 255) : null;
    }
}
