<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'calendar_events',
        'trips',
        'travel_inbox_items',
        'shared_todos',
        'entertainment_titles',
        'recipes',
        'relationship_milestones',
        'shared_expenses',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) continue;
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'created_from')) $blueprint->string('created_from', 32)->default('manual');
                if (! Schema::hasColumn($table, 'source_reference')) $blueprint->string('source_reference', 120)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) continue;
            $columns = [];
            if (Schema::hasColumn($table, 'created_from')) $columns[] = 'created_from';
            if (Schema::hasColumn($table, 'source_reference')) $columns[] = 'source_reference';
            if ($columns) Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($columns));
        }
    }
};