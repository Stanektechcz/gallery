<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Small games played inside a conversation.
 *
 * Turn-based only, and that is the whole design constraint: the chat reaches the server
 * by polling, so a game whose state changes between turns works perfectly while one that
 * needs continuous motion does not. Noughts and crosses and rock-paper-scissors both fit;
 * anything with a clock does not, and pretending otherwise would ship something that
 * feels broken on a slow connection.
 *
 * State is a JSON document rather than columns because each game shapes it differently,
 * and the server is the only thing that ever writes it — a client cannot post a board,
 * only a move to be judged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chat_games')) {
            Schema::create('chat_games', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('gallery_space_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->string('kind', 20);                       // piskvorky | kamen
                $table->string('status', 12)->default('playing');  // playing | finished | abandoned
                $table->json('state');
                $table->foreignId('turn_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('winner_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('is_draw')->default(false);
                $table->timestamps();

                $table->index(['conversation_id', 'status'], 'chat_games_conversation_idx');
            });
        }

        // A game announces itself in the conversation, so it is found where it was started.
        if (Schema::hasTable('chat_messages') && ! Schema::hasColumn('chat_messages', 'game_id')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->foreignId('game_id')->nullable()->after('conversation_id')
                    ->constrained('chat_games')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_messages', 'game_id')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropForeign(['game_id']);
                $table->dropColumn('game_id');
            });
        }

        Schema::dropIfExists('chat_games');
    }
};
