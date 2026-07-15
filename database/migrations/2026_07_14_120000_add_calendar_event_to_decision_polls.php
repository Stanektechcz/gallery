<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('decision_polls') || Schema::hasColumn('decision_polls', 'calendar_event_id')) return;

        Schema::table('decision_polls', function (Blueprint $table) {
            $table->foreignId('calendar_event_id')->nullable()->after('gallery_space_id')->constrained('calendar_events')->nullOnDelete();
            $table->index(['calendar_event_id', 'status']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('decision_polls') || ! Schema::hasColumn('decision_polls', 'calendar_event_id')) return;

        Schema::table('decision_polls', function (Blueprint $table) {
            $table->dropForeign(['calendar_event_id']);
            $table->dropIndex(['calendar_event_id', 'status']);
            $table->dropColumn('calendar_event_id');
        });
    }
};
