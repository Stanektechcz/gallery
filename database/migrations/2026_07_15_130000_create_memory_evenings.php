<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('memory_evenings')) {
            Schema::create('memory_evenings', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->foreignId('calendar_event_id')->nullable()->unique('memory_evening_event_unique')->constrained('calendar_events')->nullOnDelete();
                $table->foreignId('curation_board_id')->nullable()->unique('memory_evening_board_unique')->constrained('curation_boards')->nullOnDelete();
                $table->foreignId('album_id')->nullable()->unique('memory_evening_album_unique')->constrained('albums')->nullOnDelete();
                $table->foreignId('shared_memory_moment_id')->nullable()->unique('memory_evening_moment_unique')->constrained('shared_memory_moments')->nullOnDelete();
                $table->char('fingerprint', 64);
                $table->char('dedupe_key', 64)->unique();
                $table->string('source_type', 32);
                $table->string('title', 160);
                $table->text('description')->nullable();
                $table->dateTime('scheduled_for');
                $table->string('status', 24)->default('planned');
                $table->boolean('repeat_annually')->default(false);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['gallery_space_id', 'status', 'scheduled_for'], 'memory_evening_space_status_idx');
                $table->index(['fingerprint', 'scheduled_for'], 'memory_evening_fingerprint_idx');
            });
        }

        if (! Schema::hasTable('memory_evening_reflections')) {
            Schema::create('memory_evening_reflections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('memory_evening_id')->constrained('memory_evenings')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('mood', 24)->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
                $table->unique(['memory_evening_id', 'user_id'], 'memory_evening_reflection_user_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_evening_reflections');
        Schema::dropIfExists('memory_evenings');
    }
};
