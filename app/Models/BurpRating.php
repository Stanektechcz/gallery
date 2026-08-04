<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BurpRating extends Model
{
    protected $fillable = ['burp_id', 'user_id', 'loudness', 'length', 'artistry', 'surprise', 'score', 'comment'];
    protected function casts(): array { return ['score' => 'float']; }
    public function user() { return $this->belongsTo(User::class); }
    public function burp() { return $this->belongsTo(Burp::class); }
}
