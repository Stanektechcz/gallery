<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('event_tasks')) {
            if (! Schema::hasColumn('event_tasks', 'automation_source')) {
                Schema::table('event_tasks', fn (Blueprint $table) => $table->string('automation_source', 40)->nullable()->after('sort_order'));
            }
            if (! Schema::hasColumn('event_tasks', 'automation_key')) {
                Schema::table('event_tasks', fn (Blueprint $table) => $table->string('automation_key', 120)->nullable()->after('automation_source'));
            }
            if (! Schema::hasIndex('event_tasks', 'event_task_automation_unique')) {
                Schema::table('event_tasks', fn (Blueprint $table) => $table->unique(['event_id', 'automation_key'], 'event_task_automation_unique'));
            }
        }

        if (Schema::hasTable('event_reminders')) {
            if (! Schema::hasColumn('event_reminders', 'automation_source')) {
                Schema::table('event_reminders', fn (Blueprint $table) => $table->string('automation_source', 40)->nullable()->after('status'));
            }
            if (! Schema::hasColumn('event_reminders', 'automation_key')) {
                Schema::table('event_reminders', fn (Blueprint $table) => $table->string('automation_key', 120)->nullable()->after('automation_source'));
            }
            if (! Schema::hasIndex('event_reminders', 'event_reminder_automation_unique')) {
                Schema::table('event_reminders', fn (Blueprint $table) => $table->unique(['event_id', 'user_id', 'automation_key'], 'event_reminder_automation_unique'));
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('event_reminders')) {
            if (Schema::hasIndex('event_reminders', 'event_reminder_automation_unique')) {
                Schema::table('event_reminders', fn (Blueprint $table) => $table->dropUnique('event_reminder_automation_unique'));
            }
            $columns = array_values(array_filter(['automation_source', 'automation_key'], fn (string $column) => Schema::hasColumn('event_reminders', $column)));
            if ($columns !== []) Schema::table('event_reminders', fn (Blueprint $table) => $table->dropColumn($columns));
        }

        if (Schema::hasTable('event_tasks')) {
            if (Schema::hasIndex('event_tasks', 'event_task_automation_unique')) {
                Schema::table('event_tasks', fn (Blueprint $table) => $table->dropUnique('event_task_automation_unique'));
            }
            $columns = array_values(array_filter(['automation_source', 'automation_key'], fn (string $column) => Schema::hasColumn('event_tasks', $column)));
            if ($columns !== []) Schema::table('event_tasks', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
