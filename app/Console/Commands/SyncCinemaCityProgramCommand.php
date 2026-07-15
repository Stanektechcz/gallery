<?php

namespace App\Console\Commands;

use App\Services\Entertainment\CinemaCityProgramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SyncCinemaCityProgramCommand extends Command
{
    protected $signature = 'gallery:sync-cinema {--days=7 : Počet dní programu (1–14)}';
    protected $description = 'Obnoví oficiální program Cinema City Velký Špalíček pro společné plánování.';

    public function handle(CinemaCityProgramService $cinema): int
    {
        if (! Schema::hasTable('cinema_showings')) {
            $this->error('Nejprve spusťte databázové migrace.'); return self::FAILURE;
        }
        try {
            $result = $cinema->sync(now('Europe/Prague')->startOfDay(), (int) $this->option('days'));
            $this->info('Program kina byl obnoven. Nalezeno projekcí: ' . $result['count']); return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception); $this->error('Program kina se nepodařilo obnovit.'); return self::FAILURE;
        }
    }
}
