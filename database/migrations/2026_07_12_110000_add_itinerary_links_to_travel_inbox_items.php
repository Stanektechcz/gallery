<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_inbox_items', function (Blueprint $table) {
            if (!Schema::hasColumn('travel_inbox_items', 'trip_day_id')) $table->foreignId('trip_day_id')->nullable()->after('trip_id')->constrained('trip_days')->nullOnDelete();
            if (!Schema::hasColumn('travel_inbox_items', 'trip_activity_id')) $table->foreignId('trip_activity_id')->nullable()->after('trip_day_id')->constrained('trip_activities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('travel_inbox_items', function (Blueprint $table) {
            foreach (['trip_day_id', 'trip_activity_id'] as $column) if (Schema::hasColumn('travel_inbox_items', $column)) { $table->dropForeign([$column]); $table->dropColumn($column); }
        });
    }
};
