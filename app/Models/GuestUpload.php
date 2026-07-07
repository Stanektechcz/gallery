<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class GuestUpload extends Model
{
    protected $fillable = ['uuid', 'shared_link_id', 'original_filename', 'mime_type', 'size_bytes', 'storage_path', 'contributor_name', 'status', 'reviewed_by', 'media_item_id', 'reviewed_at'];
    protected function casts(): array { return ['reviewed_at' => 'datetime']; }
    protected static function booted(): void { static::creating(fn (self $upload) => $upload->uuid ??= (string) Str::uuid()); }
    public function sharedLink() { return $this->belongsTo(SharedLink::class); }
}
