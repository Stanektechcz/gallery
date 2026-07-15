<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('calendar_event_reflections')) return;

        Schema::create('calendar_event_reflections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('mood', 32)->nullable();
            $table->text('highlight')->nullable();
            $table->text('next_time')->nullable();
            $table->timestamps();
            $table->unique('calendar_event_id', 'event_reflection_event_unique');
            $table->index(['gallery_space_id', 'updated_at'], 'event_reflection_space_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_reflections');
    }
};
