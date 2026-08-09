<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One person's place in one conversation, including how far they have read.
 *
 * Read state lives here rather than in a separate table because it is per conversation
 * per person and nothing else ever needs it — one row, updated in place, however long
 * the conversation runs.
 */
class ConversationParticipant extends Model
{
    protected $fillable = [
        'conversation_id', 'user_id', 'role', 'last_read_message_id', 'last_read_at', 'muted_until',
    ];

    protected function casts(): array
    {
        return ['last_read_at' => 'datetime', 'muted_until' => 'datetime'];
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
