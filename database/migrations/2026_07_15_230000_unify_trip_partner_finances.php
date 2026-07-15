<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trip_expenses') && ! Schema::hasColumn('trip_expenses', 'payment_source')) {
            Schema::table('trip_expenses', function (Blueprint $table) {
                $table->string('payment_source', 24)->default('personal')->after('paid_by_user_id');
                $table->index(['trip_id', 'payment_source'], 'trip_expenses_source_idx');
            });
        }
        if (Schema::hasTable('trip_expenses') && Schema::hasColumn('trip_expenses', 'payment_source') && Schema::hasColumn('trip_expenses', 'automation_source')) {
            DB::table('trip_expenses')->where('automation_source', 'bank_transaction')->update([
                'payment_source' => 'joint', 'paid_by_user_id' => null, 'split' => null,
            ]);
        }
        if (Schema::hasTable('trip_settlements') && ! Schema::hasColumn('trip_settlements', 'created_by')) {
            Schema::table('trip_settlements', fn (Blueprint $table) => $table->foreignId('created_by')->nullable()->after('trip_id')->constrained('users')->nullOnDelete());
        }
        if (Schema::hasTable('trip_settlements') && ! Schema::hasColumn('trip_settlements', 'note')) {
            Schema::table('trip_settlements', fn (Blueprint $table) => $table->string('note', 500)->nullable()->after('status'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('trip_settlements') && Schema::hasColumn('trip_settlements', 'note')) {
            Schema::table('trip_settlements', fn (Blueprint $table) => $table->dropColumn('note'));
        }
        if (Schema::hasTable('trip_settlements') && Schema::hasColumn('trip_settlements', 'created_by')) {
            Schema::table('trip_settlements', function (Blueprint $table) {
                $table->dropForeign(['created_by']);
                $table->dropColumn('created_by');
            });
        }
        if (Schema::hasTable('trip_expenses') && Schema::hasColumn('trip_expenses', 'payment_source')) {
            Schema::table('trip_expenses', function (Blueprint $table) {
                $table->dropIndex('trip_expenses_source_idx');
                $table->dropColumn('payment_source');
            });
        }
    }
};
