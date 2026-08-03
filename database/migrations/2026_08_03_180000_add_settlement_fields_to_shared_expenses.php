<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shared_expenses')) return;
        Schema::table('shared_expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('shared_expenses', 'paid_by_user_id')) $table->foreignId('paid_by_user_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            if (! Schema::hasColumn('shared_expenses', 'split_mode')) $table->string('split_mode', 16)->default('equal')->after('source');
            if (! Schema::hasColumn('shared_expenses', 'split')) $table->json('split')->nullable()->after('split_mode');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('shared_expenses')) return;
        Schema::table('shared_expenses', function (Blueprint $table) {
            if (Schema::hasColumn('shared_expenses', 'paid_by_user_id')) $table->dropConstrainedForeignId('paid_by_user_id');
            if (Schema::hasColumn('shared_expenses', 'split_mode')) $table->dropColumn('split_mode');
            if (Schema::hasColumn('shared_expenses', 'split')) $table->dropColumn('split');
        });
    }
};