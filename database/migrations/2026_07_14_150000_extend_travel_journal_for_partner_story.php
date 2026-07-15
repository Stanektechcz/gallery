<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('travel_journal_entries')) return;

        Schema::table('travel_journal_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('travel_journal_entries', 'trip_day_id')) {
                $table->foreignId('trip_day_id')->nullable()->after('trip_id')->constrained('trip_days')->nullOnDelete();
            }
            if (! Schema::hasColumn('travel_journal_entries', 'visibility')) {
                // Historical entries stay private; new UI deliberately opts into sharing.
                $table->string('visibility', 16)->default('private')->after('type');
            }
            if (! Schema::hasColumn('travel_journal_entries', 'mood')) {
                $table->string('mood', 24)->nullable()->after('visibility');
            }
            if (! Schema::hasColumn('travel_journal_entries', 'is_story_worthy')) {
                $table->boolean('is_story_worthy')->default(false)->after('mood');
            }
        });

        if (Schema::hasColumn('travel_journal_entries', 'visibility')) {
            DB::table('travel_journal_entries')->whereNull('visibility')->update(['visibility' => 'private']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('travel_journal_entries')) return;

        Schema::table('travel_journal_entries', function (Blueprint $table) {
            if (Schema::hasColumn('travel_journal_entries', 'trip_day_id')) $table->dropConstrainedForeignId('trip_day_id');
            $columns = array_values(array_filter(['visibility', 'mood', 'is_story_worthy'], fn ($column) => Schema::hasColumn('travel_journal_entries', $column)));
            if ($columns) $table->dropColumn($columns);
        });
    }
};
