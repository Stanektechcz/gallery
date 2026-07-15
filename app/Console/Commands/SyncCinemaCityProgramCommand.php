<?php

namespace App\Console\Commands;

use App\Services\Entertainment\CinemaCityProgramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncCinemaCityProgramCommand extends Command
{
    protected $signature = 'gallery:sync-cinema {--days=7 : Počet dní programu (1–14)}';

    protected $description = 'Obnoví oficiální program Cinema City Velký Špalíček pro společné plánování.';

    public function handle(CinemaCityProgramService $cinema): int
    {
        if (! Schema::hasTable('cinema_showings')) {
            $this->error('Nejprve spusťte databázové migrace.');

            return self::FAILURE;
        }
        try {
            $result = $cinema->sync(now('Europe/Prague')->startOfDay(), (int) $this->option('days'));
            $this->info('Program kina byl obnoven. Nalezeno projekcí: '.$result['count']);
            foreach ($result['warnings'] ?? [] as $warning) {
                $this->warn($warning);
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $reason = Schema::hasTable('cinema_sync_runs')
                ? DB::table('cinema_sync_runs')->where('provider', 'cinema_city')->where('cinema_code', CinemaCityProgramService::CINEMA_CODE)->latest('id')->value('last_error')
                : null;
            $this->error('Program kina se nepodařilo obnovit.'.($reason ? ' Důvod: '.$reason : ''));

            return self::FAILURE;
        }
    }
}
