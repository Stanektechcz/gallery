<?php

namespace App\Jobs\Drive;

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

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        private readonly int    $storageConnectionId,
        private readonly string $channelId,
        private readonly string $state,
        private readonly ?string $resourceId,
        private readonly int    $messageNumber,
    ) {}

    public function handle(): void
    {
        $connection = StorageConnection::find($this->storageConnectionId);
        if (!$connection) return;

        $channel = DriveChangeChannel::where('channel_id', $this->channelId)->first();
        if (!$channel) return;

        try {
            $provider  = new GoogleDriveStorageProvider($connection);
            $pageToken = $channel->page_token ?? $provider->getStartPageToken();

            $result = $provider->listChanges($pageToken);

            foreach ($result['changes'] as $change) {
                \App\Models\DriveChange::create([
                    'storage_connection_id' => $this->storageConnectionId,
                    'change_type'           => $this->state,
                    'file_id'               => $change['file_id'],
                    'file_name'             => $change['file']['name'] ?? null,
                    'removed'               => $change['removed'] ?? false,
                    'trashed'               => $change['file']['trashed'] ?? false,
                    'change_payload'        => $change,
                    'processed_status'      => 'pending',
                    'change_time'           => $change['time'] ?? null,
                ]);
            }

            // Update page token
            if ($result['new_start_token']) {
                $channel->update(['page_token' => $result['new_start_token']]);
            } elseif ($result['next_page_token']) {
                $channel->update(['page_token' => $result['next_page_token']]);
            }

            Log::info("Processed {$this->messageNumber} Drive webhook changes for connection #{$this->storageConnectionId}");
        } catch (\Throwable $e) {
            Log::error("Drive webhook processing failed", [
                'channel'  => $this->channelId,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
