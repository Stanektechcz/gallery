<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaEdit extends Model
{
    public $timestamps = false;
    protected $table = 'media_edits';

    protected $fillable = ['media_item_id', 'version', 'operations_json', 'is_current', 'created_by'];

    protected function casts(): array
    {
        return [
            'operations_json' => 'array',
            'is_current'      => 'boolean',
            'created_at'      => 'datetime',
        ];
    }

    public function mediaItem() { return $this->belongsTo(MediaItem::class); }
    public function creator()   { return $this->belongsTo(User::class, 'created_by'); }
}
