<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replying to a specific message.
 *
 * The last thing missing that people actually reach for: in a conversation with more
 * than one thread running, "ano" answers nothing unless it says what it answers.
 *
 * nullOnDelete rather than cascade — deleting the message someone quoted must not delete
 * their reply. The quote then reads as "zpráva byla smazána", which is the truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_messages') && ! Schema::hasColumn('chat_messages', 'reply_to_id')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->foreignId('reply_to_id')->nullable()->after('conversation_id')
                    ->constrained('chat_messages')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_messages', 'reply_to_id')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropForeign(['reply_to_id']);
                $table->dropColumn('reply_to_id');
            });
        }
    }
};
