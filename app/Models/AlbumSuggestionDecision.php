<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlbumSuggestionDecision extends Model
{
    protected $fillable = [
        'gallery_space_id', 'decided_by', 'fingerprint', 'action', 'album_id', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function album()
    {
        return $this->belongsTo(Album::class);
    }
}
