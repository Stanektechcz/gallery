<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatReaction extends Model
{
    protected $fillable = ['chat_message_id', 'user_id', 'emoji'];

    public function message()
    {
        return $this->belongsTo(ChatMessage::class, 'chat_message_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
