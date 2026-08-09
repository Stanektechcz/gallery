<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memories that exist, rather than being recomputed on every visit.
 *
 * Until now the memories tab derived its cards from the timeline each time it was opened.
 * That works for showing something, and fails at everything else: a card that is computed
 * cannot be notified about, cannot be dismissed and stay dismissed, and cannot be there
 * before somebody looks. "Actively stock the tab" only means anything if the stock is
 * kept somewhere.
 *
 * A row is one card. The generator writes them ahead of the day they belong to, so
 * opening the tab reads rather than computes, and the notification has something to point
 * at.
 *
 * source_type / source_id say what the card was made from — an album, a calendar event, a
 * day of photographs — so re-running the generator recognises what it already made rather
 * than producing a second copy of the same memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('generated_memories')) {
            Schema::create('generated_memories', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();

                // anniversary | event | album | streak
                $table->string('kind', 20);
                $table->string('title', 190);
                $table->string('subtitle', 250)->nullable();
                $table->string('icon', 16)->nullable();

                // What it was built from, so a rerun updates instead of duplicating.
                $table->string('source_type', 30)->nullable();
                $table->string('source_id', 64)->nullable();

                // The day it belongs to, which is not the day it was generated.
                $table->date('occurs_on');
                $table->unsignedSmallInteger('years_ago')->nullable();

                // Media ids for the card's thumbnails; the media itself is not copied.
                $table->json('media_ids')->nullable();
                $table->string('link', 300)->nullable();

                // Ranking, so the tab can lead with the strongest card of the day.
                $table->unsignedSmallInteger('score')->default(0);

                $table->timestamp('notified_at')->nullable();
                $table->timestamp('dismissed_at')->nullable();
                $table->timestamps();

                // The read the tab makes: this space, around today, best first.
                $table->index(['gallery_space_id', 'occurs_on', 'score'], 'generated_memories_day_idx');
                // One card per source per day, which is what makes reruns safe.
                $table->unique(['gallery_space_id', 'source_type', 'source_id', 'occurs_on'], 'generated_memories_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_memories');
    }
};
