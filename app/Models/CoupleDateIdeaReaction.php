<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoupleDateIdeaReaction extends Model
{
    protected $fillable = ['date_idea_id', 'user_id', 'reaction', 'rating', 'note'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function idea() { return $this->belongsTo(CoupleDateIdea::class, 'date_idea_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
