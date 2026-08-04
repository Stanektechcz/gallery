<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoiceNoteListen extends Model
{
    public $timestamps = false;
    protected $fillable = ['voice_note_id', 'user_id', 'listened_at'];
    protected function casts(): array { return ['listened_at' => 'datetime']; }
}
