<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shared_memory_reflections')) return;

        Schema::create('shared_memory_reflections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_memory_moment_id')->constrained('shared_memory_moments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('mood', 24)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['shared_memory_moment_id', 'user_id'], 'sm_ref_moment_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_memory_reflections');
    }
};
