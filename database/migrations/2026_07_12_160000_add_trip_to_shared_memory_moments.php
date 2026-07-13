<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shared_memory_moments') || Schema::hasColumn('shared_memory_moments', 'trip_id')) {
            return;
        }

        Schema::table('shared_memory_moments', function (Blueprint $table) {
            $table->foreignId('trip_id')
                ->nullable()
                ->unique('shared_memory_trip_unique')
                ->constrained('trips')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('shared_memory_moments') || ! Schema::hasColumn('shared_memory_moments', 'trip_id')) {
            return;
        }

        Schema::table('shared_memory_moments', function (Blueprint $table) {
            $table->dropForeign(['trip_id']);
            $table->dropUnique('shared_memory_trip_unique');
            $table->dropColumn('trip_id');
        });
    }
};
