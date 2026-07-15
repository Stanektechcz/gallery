<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('event_tasks') || Schema::hasColumn('event_tasks', 'last_reminded_at')) {
            return;
        }

        Schema::table('event_tasks', function (Blueprint $table) {
            $table->timestamp('last_reminded_at')->nullable()->after('completed_at');
            $table->index(['completed_at', 'due_at'], 'event_tasks_due_reminder_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('event_tasks') || ! Schema::hasColumn('event_tasks', 'last_reminded_at')) {
            return;
        }

        Schema::table('event_tasks', function (Blueprint $table) {
            $table->dropIndex('event_tasks_due_reminder_idx');
            $table->dropColumn('last_reminded_at');
        });
    }
};
