<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shared_memory_moments') || Schema::hasColumn('shared_memory_moments', 'place_plan_id')) return;
        Schema::table('shared_memory_moments', function (Blueprint $table) {
            $table->foreignId('place_plan_id')->nullable()->unique('shared_memory_place_plan_unique')->constrained('place_plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('shared_memory_moments') || ! Schema::hasColumn('shared_memory_moments', 'place_plan_id')) return;
        Schema::table('shared_memory_moments', function (Blueprint $table) {
            $table->dropForeign(['place_plan_id']);
            $table->dropUnique('shared_memory_place_plan_unique');
            $table->dropColumn('place_plan_id');
        });
    }
};
