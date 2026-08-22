<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Zároveň" — one moment a day, photographed by both people at once.
 *
 * The gallery is otherwise a record of occasions: the holiday, the birthday, the day
 * somebody remembered to get the camera out. What it never holds is an ordinary
 * Tuesday afternoon, because nobody photographs those on purpose. This asks at a time
 * neither person chose, which is the only way those afternoons ever get kept.
 *
 * Two rules make it work, and both live here rather than in the interface:
 *
 * The time is drawn per space, per day. A fixed hour would be photographed around --
 * everyone would be ready, and ready is the opposite of the point.
 *
 * Nobody sees the other's picture until they have posted their own. That is enforced by
 * the query that reads these rows, never by hiding something the browser was already
 * given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_moments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();

            // The day this belongs to, kept separately from notify_at so a prompt drawn
            // late in the evening still files under the day it was asked about.
            $table->date('moment_date');
            $table->timestamp('notify_at');
            $table->timestamp('notified_at')->nullable();

            // How long the answer still counts as on time. Late answers are kept, just
            // marked -- refusing them would only teach people not to bother.
            $table->unsignedSmallInteger('window_minutes')->default(120);
            $table->string('prompt', 255)->nullable();

            $table->timestamps();

            $table->unique(['gallery_space_id', 'moment_date']);
            $table->index('notify_at');
        });

        Schema::create('daily_moment_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('daily_moment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Both cameras at once, which is what makes it a moment rather than a photo:
            // what you were looking at, and your face while you looked at it. The front
            // one is optional -- a laptop with one camera should not lock somebody out.
            $table->unsignedBigInteger('back_media_id')->nullable();
            $table->unsignedBigInteger('front_media_id')->nullable();

            $table->string('caption', 500)->nullable();
            $table->timestamp('posted_at');

            // Minutes past the window. Zero is on time; the number is shown rather than
            // hidden, because "three hours late" is part of the story of that day.
            $table->unsignedInteger('late_minutes')->default(0);

            $table->timestamps();

            $table->unique(['daily_moment_id', 'user_id']);
            $table->foreign('back_media_id')->references('id')->on('media_items')->nullOnDelete();
            $table->foreign('front_media_id')->references('id')->on('media_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_moment_entries');
        Schema::dropIfExists('daily_moments');
    }
};
