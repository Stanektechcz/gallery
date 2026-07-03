<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DuplicateGroupItem extends Model
{
    protected $table = 'duplicate_group_items';
    protected $fillable = ['duplicate_group_id', 'media_item_id', 'is_kept'];
    protected function casts(): array { return ['is_kept' => 'boolean']; }

    public function group()     { return $this->belongsTo(DuplicateGroup::class, 'duplicate_group_id'); }
    public function mediaItem() { return $this->belongsTo(MediaItem::class); }
}
