<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array{parent: string, index: string}> */
    private const TABLES = [
        'trip_activities' => ['parent' => 'trip_day_id', 'index' => 'ta_auto_unique'],
        'trip_expenses' => ['parent' => 'trip_id', 'index' => 'te_auto_unique'],
        'trip_route_variants' => ['parent' => 'trip_id', 'index' => 'trv_auto_unique'],
        'trip_waypoints' => ['parent' => 'trip_id', 'index' => 'tw_auto_unique'],
        'trip_packing_items' => ['parent' => 'trip_id', 'index' => 'tpi_auto_unique'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName => $definition) {
            if (! Schema::hasTable($tableName)) continue;

            if (! Schema::hasColumn($tableName, 'automation_source')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->string('automation_source', 40)->nullable());
            }
            if (! Schema::hasColumn($tableName, 'automation_key')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->string('automation_key', 64)->nullable());
            }
            if (! Schema::hasIndex($tableName, $definition['index'])) {
                Schema::table($tableName, fn (Blueprint $table) => $table->unique(
                    [$definition['parent'], 'automation_key'],
                    $definition['index'],
                ));
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES, true) as $tableName => $definition) {
            if (! Schema::hasTable($tableName)) continue;

            if (Schema::hasIndex($tableName, $definition['index'])) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropUnique($definition['index']));
            }
            $columns = array_values(array_filter(
                ['automation_source', 'automation_key'],
                fn (string $column) => Schema::hasColumn($tableName, $column),
            ));
            if ($columns !== []) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($columns));
            }
        }
    }
};
