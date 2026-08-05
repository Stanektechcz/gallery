<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FartRating extends Model
{
    protected $fillable = ['fart_id', 'user_id', 'loudness', 'aroma', 'stealth', 'timing', 'score', 'comment'];
    protected function casts(): array { return ['score' => 'float']; }

    public function user() { return $this->belongsTo(User::class); }
    public function fart() { return $this->belongsTo(Fart::class); }
}
