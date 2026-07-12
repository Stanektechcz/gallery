<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_expenses', function (Blueprint $table) {
            if (!Schema::hasColumn('trip_expenses', 'paid_by_user_id')) {
                $table->foreignId('paid_by_user_id')->nullable()->after('paid_by')->constrained('users')->nullOnDelete();
                $table->index(['trip_id', 'paid_by_user_id'], 'trip_expenses_payer_index');
            }
        });
        if (Schema::hasTable('trip_savings_goals')) return;
        Schema::create('trip_savings_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('target_amount', 12, 2);
            $table->decimal('saved_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('CZK');
            $table->date('target_date')->nullable();
            $table->decimal('monthly_contribution', 12, 2)->nullable();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('trip_savings_goals');
        Schema::table('trip_expenses', function (Blueprint $table) { if (Schema::hasColumn('trip_expenses', 'paid_by_user_id')) { $table->dropForeign(['paid_by_user_id']); $table->dropIndex('trip_expenses_payer_index'); $table->dropColumn('paid_by_user_id'); } });
    }
};
