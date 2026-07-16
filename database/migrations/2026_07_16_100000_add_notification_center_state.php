<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) return;

        $addSnoozed = ! Schema::hasColumn('notifications', 'snoozed_until');
        $addArchived = ! Schema::hasColumn('notifications', 'archived_at');

        if ($addSnoozed || $addArchived) {
            Schema::table('notifications', function (Blueprint $table) use ($addSnoozed, $addArchived): void {
                if ($addSnoozed) $table->dateTime('snoozed_until')->nullable()->after('read_at');
                if ($addArchived) $table->timestamp('archived_at')->nullable()->after('snoozed_until');
            });
        }

        if (! Schema::hasIndex('notifications', 'notif_center_state_idx')) {
            Schema::table('notifications', fn (Blueprint $table) => $table->index(
                ['notifiable_type', 'notifiable_id', 'archived_at', 'snoozed_until'],
                'notif_center_state_idx'
            ));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications')) return;
        if (Schema::hasIndex('notifications', 'notif_center_state_idx')) {
            Schema::table('notifications', fn (Blueprint $table) => $table->dropIndex('notif_center_state_idx'));
        }
        $columns = array_values(array_filter(
            ['snoozed_until', 'archived_at'],
            fn (string $column): bool => Schema::hasColumn('notifications', $column)
        ));
        if ($columns !== []) Schema::table('notifications', fn (Blueprint $table) => $table->dropColumn($columns));
    }
};
