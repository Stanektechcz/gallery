<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What turns the conversation into a messenger: pictures and GIFs, and reactions.
 *
 * Uploads live on the private disk and are streamed through an authorised controller,
 * exactly like voice notes — a photo sent in a chat is no less private than one in the
 * gallery, and a guessable public URL would make it so.
 *
 * A GIF picked from a provider is not copied to our disk; only its URL is kept. The
 * provider already hosts it, and re-uploading someone else's file would cost storage
 * from the space's quota for no gain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_messages', 'media_path')) {
                $table->string('media_path', 400)->nullable()->after('attachment_ref');
                $table->string('media_mime', 80)->nullable()->after('media_path');
                $table->unsignedBigInteger('media_size')->nullable()->after('media_mime');
                // Set for a GIF chosen from a provider, where we keep the link, not the file.
                $table->string('media_remote_url', 600)->nullable()->after('media_size');
                $table->unsignedSmallInteger('media_width')->nullable()->after('media_remote_url');
                $table->unsignedSmallInteger('media_height')->nullable()->after('media_width');
            }
        });

        if (! Schema::hasTable('chat_reactions')) {
            Schema::create('chat_reactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('chat_message_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // The emoji itself, not an id: it needs no lookup table to render.
                $table->string('emoji', 16);
                $table->timestamps();

                // One person, one reaction of a given kind, per message.
                $table->unique(['chat_message_id', 'user_id', 'emoji'], 'chat_reactions_unique');
                $table->index('chat_message_id', 'chat_reactions_message_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_reactions');

        Schema::table('chat_messages', function (Blueprint $table) {
            foreach (['media_path', 'media_mime', 'media_size', 'media_remote_url', 'media_width', 'media_height'] as $column) {
                if (Schema::hasColumn('chat_messages', $column)) $table->dropColumn($column);
            }
        });
    }
};
