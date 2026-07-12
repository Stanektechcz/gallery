<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backfills the event link for installations that already received the
     * original shared-memory table before calendar integration was added.
     */
    public function up(): void
    {
        if (! Schema::hasTable('shared_memory_moments') || Schema::hasColumn('shared_memory_moments', 'calendar_event_id')) {
            return;
        }

        Schema::table('shared_memory_moments', function (Blueprint $table) {
            $table->foreignId('calendar_event_id')
                ->nullable()
                ->unique('shared_memory_event_unique')
                ->constrained('calendar_events')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('shared_memory_moments') || ! Schema::hasColumn('shared_memory_moments', 'calendar_event_id')) {
            return;
        }

        Schema::table('shared_memory_moments', function (Blueprint $table) {
            $table->dropForeign(['calendar_event_id']);
            $table->dropUnique('shared_memory_event_unique');
            $table->dropColumn('calendar_event_id');
        });
    }
};
