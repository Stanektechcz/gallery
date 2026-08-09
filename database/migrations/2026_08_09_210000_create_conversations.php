<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations with explicit participants.
 *
 * Until now a chat was the space: every message belonged to a gallery_space_id and
 * "who is in this chat" was inferred from who happened to be a member. That inference is
 * why the roster could come back empty, and it made groups impossible to express at all.
 *
 * A conversation now names its participants:
 *
 *   direct — exactly two people, at most one such pair per space; created on demand
 *   group  — any subset of the space, named, as many as anyone cares to make
 *
 * Messages move under a conversation. Existing ones are swept into a single group per
 * space so nothing is lost and no conversation starts empty where one already existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('conversations')) {
            Schema::create('conversations', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('kind', 10)->default('direct');      // direct | group
                $table->string('title', 120)->nullable();           // groups only
                $table->string('icon', 16)->nullable();             // one emoji
                // Cheap ordering for the list: touched on every message.
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['gallery_space_id', 'last_message_at'], 'conversations_recent_idx');
            });
        }

        if (! Schema::hasTable('conversation_participants')) {
            Schema::create('conversation_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('role', 10)->default('member');       // member | owner
                $table->unsignedBigInteger('last_read_message_id')->default(0);
                $table->timestamp('last_read_at')->nullable();
                $table->timestamp('muted_until')->nullable();
                $table->timestamps();

                $table->unique(['conversation_id', 'user_id'], 'conversation_participants_unique');
                $table->index('user_id', 'conversation_participants_user_idx');
            });
        }

        if (Schema::hasTable('chat_messages') && ! Schema::hasColumn('chat_messages', 'conversation_id')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->foreignId('conversation_id')->nullable()->after('gallery_space_id')
                    ->constrained()->cascadeOnDelete();
                $table->index(['conversation_id', 'id'], 'chat_messages_conversation_idx');
            });

            $this->adoptExistingMessages();
        }
    }

    /**
     * Gives every space that already has messages one group holding them, with everyone
     * in the space as a participant. Without this the conversation view would look empty
     * to people who have been talking for weeks.
     */
    private function adoptExistingMessages(): void
    {
        $spaces = \Illuminate\Support\Facades\DB::table('chat_messages')
            ->whereNull('conversation_id')->distinct()->pluck('gallery_space_id');

        foreach ($spaces as $spaceId) {
            $conversationId = \Illuminate\Support\Facades\DB::table('conversations')->insertGetId([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'gallery_space_id' => $spaceId,
                'kind' => 'group',
                'title' => 'Společná konverzace',
                'icon' => '💬',
                'last_message_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $members = \Illuminate\Support\Facades\DB::table('gallery_space_user')
                ->where('gallery_space_id', $spaceId)->pluck('user_id');

            foreach ($members as $userId) {
                \Illuminate\Support\Facades\DB::table('conversation_participants')->insert([
                    'conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'role' => 'member',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            \Illuminate\Support\Facades\DB::table('chat_messages')
                ->where('gallery_space_id', $spaceId)->whereNull('conversation_id')
                ->update(['conversation_id' => $conversationId]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_messages', 'conversation_id')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropForeign(['conversation_id']);
                $table->dropColumn('conversation_id');
            });
        }

        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
