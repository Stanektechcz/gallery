<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('event_reminders')) return;

        Schema::table('event_reminders', function (Blueprint $table): void {
            if (! Schema::hasColumn('event_reminders', 'original_remind_at')) {
                $table->dateTime('original_remind_at')->nullable()->after('remind_at');
            }
            if (! Schema::hasColumn('event_reminders', 'snoozed_until')) {
                $table->dateTime('snoozed_until')->nullable()->after('original_remind_at');
            }
            if (! Schema::hasColumn('event_reminders', 'snooze_count')) {
                $table->unsignedSmallInteger('snooze_count')->default(0)->after('snoozed_until');
            }
            if (! Schema::hasColumn('event_reminders', 'acknowledged_at')) {
                $table->timestamp('acknowledged_at')->nullable()->after('delivered_at');
            }
            if (! Schema::hasColumn('event_reminders', 'dismissed_at')) {
                $table->timestamp('dismissed_at')->nullable()->after('acknowledged_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('event_reminders')) return;

        $columns = array_values(array_filter([
            'original_remind_at', 'snoozed_until', 'snooze_count', 'acknowledged_at', 'dismissed_at',
        ], fn (string $column): bool => Schema::hasColumn('event_reminders', $column)));

        if ($columns !== []) {
            Schema::table('event_reminders', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
