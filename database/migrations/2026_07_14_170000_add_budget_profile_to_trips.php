<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('trips')) {
            return;
        }

        if (! Schema::hasColumn('trips', 'budget_profile')) {
            Schema::table('trips', function (Blueprint $table) {
                $table->string('budget_profile', 16)->default('balanced')->after('currency');
            });
        }

        if (! Schema::hasColumn('trips', 'daily_budget_limit')) {
            Schema::table('trips', function (Blueprint $table) {
                $table->decimal('daily_budget_limit', 12, 2)->nullable()->after('budget');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('trips')) {
            return;
        }

        $columns = array_values(array_filter(
            ['budget_profile', 'daily_budget_limit'],
            fn (string $column) => Schema::hasColumn('trips', $column),
        ));

        if ($columns !== []) {
            Schema::table('trips', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
