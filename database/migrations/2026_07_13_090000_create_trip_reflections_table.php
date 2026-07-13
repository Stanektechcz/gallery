<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trip_reflections')) {
            return;
        }

        Schema::create('trip_reflections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('highlight')->nullable();
            $table->text('gratitude')->nullable();
            $table->text('next_time')->nullable();
            $table->timestamps();

            // Explicit names keep the migration compatible with MySQL's 64-char limit.
            $table->unique('trip_id', 'trip_reflection_trip_unique');
            $table->index(['gallery_space_id', 'updated_at'], 'trip_reflection_space_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_reflections');
    }
};
