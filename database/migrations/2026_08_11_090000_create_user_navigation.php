<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each person's own arrangement of the navigation.
 *
 * Stored as a difference, not as a copy. Somebody who has changed nothing has no rows
 * here and sees the built-in navigation; a row exists only where they moved, renamed,
 * hid or nested something.
 *
 * That choice decides how the app ages. A stored copy would freeze the menu on the day it
 * was saved: every feature added afterwards would be invisible to anyone who had ever
 * touched their sidebar, and the bug would look like the feature failing to ship. With a
 * difference, a new item appears for everyone and keeps its default place until they say
 * otherwise.
 *
 * href identifies the item, because it is what the item *is* — a destination. An id from
 * the frontend array would break the moment that array is reordered in a release.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_navigation_items')) {
            Schema::create('user_navigation_items', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();

                // Null for a heading the person invented; otherwise the destination.
                $table->string('href', 190)->nullable();
                // Null means "keep whatever the application calls it".
                $table->string('label', 120)->nullable();
                $table->string('icon', 16)->nullable();

                // Self-reference: one level of nesting, which is as deep as a menu should go.
                $table->foreignId('parent_id')->nullable()->constrained('user_navigation_items')->cascadeOnDelete();
                $table->unsignedSmallInteger('position')->default(0);

                // Hidden rather than deleted, so it can come back without being recreated.
                $table->boolean('is_hidden')->default(false);
                // A heading of their own making, which has no destination of its own.
                $table->boolean('is_group')->default(false);

                $table->timestamps();

                // The read: this person's arrangement, in order.
                $table->index(['user_id', 'position'], 'user_navigation_order_idx');
                // One row per destination per person; a second would be ambiguous.
                $table->unique(['user_id', 'href'], 'user_navigation_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_navigation_items');
    }
};
