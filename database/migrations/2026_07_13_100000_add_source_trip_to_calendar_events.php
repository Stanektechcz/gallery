<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('calendar_events') || Schema::hasColumn('calendar_events', 'source_trip_id')) {
            return;
        }

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->foreignId('source_trip_id')->nullable()->after('trip_id')->constrained('trips')->nullOnDelete();
            $table->index(['source_trip_id', 'starts_at'], 'calendar_source_trip_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('calendar_events') || ! Schema::hasColumn('calendar_events', 'source_trip_id')) {
            return;
        }

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropForeign(['source_trip_id']);
            $table->dropIndex('calendar_source_trip_idx');
            $table->dropColumn('source_trip_id');
        });
    }
};
