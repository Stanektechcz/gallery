<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rejstřík pro „poslední běh každé úlohy".
 *
 * `ScheduledTaskRun::posledni()` počítá `MAX(id)` seskupené podle `task`
 * a volá se při každém otevření administrace. Tabulka měla jen
 * `(task, started_at)`, podle kterého se `MAX(id)` počítat nedá — databáze
 * musela projít všechny řádky úlohy. Při 1,8 milionu řádků ročně je to
 * pokaždé celá tabulka.
 *
 * S `(task, id)` si databáze vezme z každé úlohy poslední položku rejstříku
 * a dál nečte. Úklid protokolu tabulku zmenší, tenhle rejstřík ji zrychlí —
 * jedno bez druhého je jen polovina.
 */
return new class extends Migration
{
    private const REJSTRIK = 'scheduled_task_runs_task_id_index';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_task_runs') || $this->existuje()) {
            return;
        }

        Schema::table('scheduled_task_runs', function (Blueprint $table) {
            $table->index(['task', 'id'], self::REJSTRIK);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_task_runs') || ! $this->existuje()) {
            return;
        }

        Schema::table('scheduled_task_runs', function (Blueprint $table) {
            $table->dropIndex(self::REJSTRIK);
        });
    }

    private function existuje(): bool
    {
        foreach (Schema::getIndexes('scheduled_task_runs') as $rejstrik) {
            if ($rejstrik['columns'] === ['task', 'id']) {
                return true;
            }
        }

        return false;
    }
};
