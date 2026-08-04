<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class VoiceNote extends Model
{
    use \App\Models\Concerns\BelongsToGallerySpace;

    protected $fillable = ['uuid', 'gallery_space_id', 'created_by', 'title', 'path', 'mime_type', 'size_bytes', 'duration_ms', 'transcript', 'recorded_at'];
    protected function casts(): array { return ['recorded_at' => 'datetime']; }
    protected static function booted(): void { static::creating(fn (self $note) => $note->uuid ??= (string) Str::uuid()); }
    public function author() { return $this->belongsTo(User::class, 'created_by'); }
    public function listens() { return $this->hasMany(VoiceNoteListen::class); }
}
