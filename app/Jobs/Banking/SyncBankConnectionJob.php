<?php

namespace App\Jobs\Banking;

use App\Models\BankConnection;
use App\Services\Banking\BankingIntegrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncBankConnectionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 600, 1800];

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function __construct(public int $connectionId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "bank-connection-{$this->connectionId}";
    }

    public function handle(BankingIntegrationService $banking): void
    {
        $connection = BankConnection::find($this->connectionId);
        if (! $connection || ! $connection->sync_enabled || $connection->revoked_at || $connection->status !== 'active') {
            return;
        }
        $banking->sync($connection);
    }
}
