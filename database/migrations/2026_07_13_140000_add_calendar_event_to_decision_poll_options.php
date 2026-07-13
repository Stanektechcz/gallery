<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('decision_poll_options') || Schema::hasColumn('decision_poll_options', 'calendar_event_id')) {
            return;
        }

        Schema::table('decision_poll_options', function (Blueprint $table) {
            $table->foreignId('calendar_event_id')->nullable()->after('poll_id')->unique('poll_option_calendar_unique')->constrained('calendar_events')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('decision_poll_options') || ! Schema::hasColumn('decision_poll_options', 'calendar_event_id')) {
            return;
        }

        Schema::table('decision_poll_options', function (Blueprint $table) {
            $table->dropUnique('poll_option_calendar_unique');
            $table->dropForeign(['calendar_event_id']);
            $table->dropColumn('calendar_event_id');
        });
    }
};
