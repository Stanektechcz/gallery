<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a message said before it was edited.
 *
 * Editing is the author's right; erasing the record is not. Each edit files the previous
 * wording here, encrypted like the message itself.
 */
class ChatMessageRevision extends Model
{
    protected $fillable = ['chat_message_id', 'edited_by', 'body', 'replaced_at'];

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'replaced_at' => 'datetime'];
    }

    public function message()
    {
        return $this->belongsTo(ChatMessage::class, 'chat_message_id');
    }
}
