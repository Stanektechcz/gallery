<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Jeden běh plánované úlohy. Zapisuje ho [\App\Listeners\ZaznamenejBehUlohy]
 * při noční jízdě plánovače a [\App\Jobs\SpustPlanovanouUlohu] při ručním spuštění.
 */
class ScheduledTaskRun extends Model
{
    public const BEZI = 'running';

    public const HOTOVO = 'ok';

    public const CHYBA = 'failed';

    public const PRESKOCENO = 'skipped';

    /** Delší výpis nikdo nečte a tabulka by rostla rychleji než galerie. */
    public const VYPIS_MAX = 4000;

    protected $fillable = [
        'task', 'command', 'started_at', 'finished_at',
        'duration_ms', 'state', 'exit_code', 'output', 'manual',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'exit_code' => 'integer',
        'manual' => 'boolean',
    ];

    public function scopeNeuspesne($query)
    {
        return $query->where('state', self::CHYBA);
    }

    /** Poslední běh každé úlohy — jeden dotaz, ne jeden na úlohu. */
    public static function posledni(): Collection
    {
        return static::query()
            ->whereIn('id', function ($poddotaz) {
                $poddotaz->selectRaw('MAX(id)')->from('scheduled_task_runs')->groupBy('task');
            })
            ->get()
            ->keyBy('task');
    }
}
