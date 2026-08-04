<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two independent modules:
 *  - voice_notes: short spoken messages between members of a space
 *  - burps + burp_ratings: the first paid add-on, scored by the other member
 *
 * Audio lives on the private disk and is streamed through an authorised controller,
 * never from a guessable public URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('voice_notes')) {
            Schema::create('voice_notes', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->string('title', 180)->nullable();
                $table->string('path', 400);                 // relative to the private disk
                $table->string('mime_type', 80);
                $table->unsignedBigInteger('size_bytes');
                $table->unsignedInteger('duration_ms')->nullable();
                $table->text('transcript')->nullable();      // optional, filled in by hand
                $table->timestamp('recorded_at')->nullable();
                $table->timestamps();
                $table->index(['gallery_space_id', 'created_at'], 'voice_notes_space_created_idx');
            });
        }

        // Who has already heard a note. Keeps "new for you" honest for both members.
        if (! Schema::hasTable('voice_note_listens')) {
            Schema::create('voice_note_listens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('voice_note_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamp('listened_at');
                $table->unique(['voice_note_id', 'user_id'], 'voice_note_listen_unique');
            });
        }

        if (! Schema::hasTable('burps')) {
            Schema::create('burps', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->string('title', 180)->nullable();
                $table->string('occasion', 120)->nullable();      // "po večeři", "na výletě"
                $table->unsignedInteger('duration_ms')->nullable();
                $table->string('path', 400)->nullable();          // optional recording
                $table->string('mime_type', 80)->nullable();
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->timestamp('happened_at');
                $table->timestamps();
                $table->index(['gallery_space_id', 'happened_at'], 'burps_space_happened_idx');
            });
        }

        if (! Schema::hasTable('burp_ratings')) {
            Schema::create('burp_ratings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('burp_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedTinyInteger('loudness');       // 1-5
                $table->unsignedTinyInteger('length');         // 1-5
                $table->unsignedTinyInteger('artistry');       // 1-5
                $table->unsignedTinyInteger('surprise');       // 1-5
                $table->decimal('score', 4, 2);                // derived average, kept for sorting
                $table->string('comment', 400)->nullable();
                $table->timestamps();
                $table->unique(['burp_id', 'user_id'], 'burp_rating_user_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('burp_ratings');
        Schema::dropIfExists('burps');
        Schema::dropIfExists('voice_note_listens');
        Schema::dropIfExists('voice_notes');
    }
};
