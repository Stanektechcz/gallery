<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntertainmentVote extends Model
{
    protected $fillable = ['entertainment_title_id', 'user_id', 'interest', 'cinema_preferred', 'note'];
    protected function casts(): array { return ['cinema_preferred' => 'boolean']; }
    public function user() { return $this->belongsTo(User::class); }
}
