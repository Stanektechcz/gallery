<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('couple_date_ideas')) {
            Schema::create('couple_date_ideas', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique('date_ideas_uuid_uq');
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->foreignId('calendar_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
                $table->foreignId('trip_id')->nullable()->constrained('trips')->nullOnDelete();
                $table->char('generation_key', 64);
                $table->string('title', 180);
                $table->text('summary');
                $table->string('theme', 32);
                $table->string('status', 24)->default('generated');
                $table->string('travel_scope', 24)->default('local');
                $table->string('transport_mode', 24)->default('transit');
                $table->decimal('estimated_cost', 10, 2)->default(0);
                $table->string('currency', 3)->default('CZK');
                $table->unsignedInteger('estimated_minutes');
                $table->unsignedTinyInteger('novelty_percent')->default(100);
                $table->dateTime('suggested_starts_at')->nullable();
                $table->json('destination')->nullable();
                $table->json('parameters');
                $table->json('plan');
                $table->timestamps();

                $table->unique(['gallery_space_id', 'generation_key'], 'date_ideas_space_key_uq');
                $table->index(['gallery_space_id', 'status', 'created_at'], 'date_ideas_space_status_idx');
            });
        }

        if (! Schema::hasTable('couple_date_idea_reactions')) {
            Schema::create('couple_date_idea_reactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('date_idea_id')->constrained('couple_date_ideas')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('reaction', 16);
                $table->unsignedTinyInteger('rating')->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamps();

                $table->unique(['date_idea_id', 'user_id'], 'date_reactions_idea_user_uq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('couple_date_idea_reactions');
        Schema::dropIfExists('couple_date_ideas');
    }
};
