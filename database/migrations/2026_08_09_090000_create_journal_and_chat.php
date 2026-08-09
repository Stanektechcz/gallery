<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two modules that share a space but nothing else:
 *
 *  - journal_entries: a diary that starts private to its author. Sharing is a deliberate
 *    act on a single entry, never a setting on the whole diary, so writing something down
 *    can never accidentally publish it to the other member.
 *
 *  - chat_messages: a running conversation for the space, with read receipts so "seen"
 *    means something.
 *
 * Both carry gallery_space_id because every read in this app is scoped to a space by a
 * global scope; without the column the scope cannot protect them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journal_entries')) {
            Schema::create('journal_entries', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->string('title', 180)->nullable();
                $table->longText('body');
                $table->string('mood', 40)->nullable();
                $table->date('entry_date');
                // 'private' is the only default that is safe to get wrong.
                $table->string('visibility', 12)->default('private');
                $table->timestamp('shared_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                // The two reads the app actually makes: my own diary, and what the space shared.
                $table->index(['gallery_space_id', 'created_by', 'entry_date'], 'journal_own_idx');
                $table->index(['gallery_space_id', 'visibility', 'entry_date'], 'journal_shared_idx');
            });
        }

        if (! Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->text('body');
                // Set when a message carries something from elsewhere in the app, so a
                // shared recipe or photo keeps its link instead of becoming a bare URL.
                $table->string('attachment_type', 30)->nullable();
                $table->string('attachment_ref', 190)->nullable();
                $table->timestamp('edited_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                // Paging a conversation always means "newest in this space".
                $table->index(['gallery_space_id', 'id'], 'chat_space_id_idx');
            });
        }

        // How far each member has read. One row per member per space, not per message:
        // a conversation of ten thousand messages still costs two rows.
        if (! Schema::hasTable('chat_reads')) {
            Schema::create('chat_reads', function (Blueprint $table) {
                $table->id();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('last_read_message_id')->default(0);
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->unique(['gallery_space_id', 'user_id'], 'chat_reads_unique');
            });
        }

        // Who is looking at the conversation right now. Deliberately a plain table rather
        // than a cache entry: it survives a cache flush and needs no extra service.
        if (! Schema::hasTable('chat_presence')) {
            Schema::create('chat_presence', function (Blueprint $table) {
                $table->id();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('typing_until')->nullable();
                $table->timestamps();
                $table->unique(['gallery_space_id', 'user_id'], 'chat_presence_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_presence');
        Schema::dropIfExists('chat_reads');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('journal_entries');
    }
};
