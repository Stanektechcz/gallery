<?php

namespace App\Console\Commands;

use App\Jobs\Banking\SyncBankConnectionJob;
use App\Models\BankConnection;
use Illuminate\Console\Command;

class SyncBankingCommand extends Command
{
    protected $signature = 'gallery:sync-banking {--connection= : ID konkrétního připojení}';

    protected $description = 'Zařadí bezpečnou read-only synchronizaci bankovních účtů do fronty';

    public function handle(): int
    {
        $query = BankConnection::where('provider', 'gocardless')->where('status', 'active')->where('sync_enabled', true)->whereNull('revoked_at');
        if ($id = $this->option('connection')) {
            $query->whereKey($id);
        }
        $connections = $query->get();
        foreach ($connections as $connection) {
            SyncBankConnectionJob::dispatch($connection->id);
        }
        $this->info("Zařazeno připojení: {$connections->count()}");

        return self::SUCCESS;
    }
}
