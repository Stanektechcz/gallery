<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A companion module to burps, with its own scoring criteria — a burp is judged on
 * artistry, a fart on aroma and stealth, so they are separate tables rather than one with
 * a type column.
 *
 * Both can now carry either a recording made on the spot or a voice note attached from
 * the existing library, hence the nullable link to voice_notes on each.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('farts')) {
            Schema::create('farts', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->string('title', 180)->nullable();
                $table->string('occasion', 120)->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->string('path', 400)->nullable();
                $table->string('mime_type', 80)->nullable();
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->foreignId('voice_note_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamp('happened_at');
                $table->timestamps();
                $table->index(['gallery_space_id', 'happened_at'], 'farts_space_happened_idx');
            });
        }

        if (! Schema::hasTable('fart_ratings')) {
            Schema::create('fart_ratings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('fart_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedTinyInteger('loudness');    // 1-5
                $table->unsignedTinyInteger('aroma');       // 1-5, the fart-specific one
                $table->unsignedTinyInteger('stealth');     // 1-5, surprise value
                $table->unsignedTinyInteger('timing');      // 1-5, comic timing
                $table->decimal('score', 4, 2);
                $table->string('comment', 400)->nullable();
                $table->timestamps();
                $table->unique(['fart_id', 'user_id'], 'fart_rating_user_unique');
            });
        }

        // Burps gain the same ability to reference an existing voice note.
        Schema::table('burps', function (Blueprint $table) {
            if (! Schema::hasColumn('burps', 'voice_note_id')) {
                $table->foreignId('voice_note_id')->nullable()->after('size_bytes')->constrained()->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('burps', function (Blueprint $table) {
            if (Schema::hasColumn('burps', 'voice_note_id')) {
                $table->dropConstrainedForeignId('voice_note_id');
            }
        });
        Schema::dropIfExists('fart_ratings');
        Schema::dropIfExists('farts');
    }
};
