<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventAttachment extends Model
{
    protected $fillable = ['event_id', 'media_item_id', 'label', 'external_url', 'reference_code', 'kind'];
    public function media() { return $this->belongsTo(MediaItem::class, 'media_item_id'); }
}
