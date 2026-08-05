<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Fart extends Model
{
    use \App\Models\Concerns\BelongsToGallerySpace;

    protected $fillable = ['uuid', 'gallery_space_id', 'created_by', 'title', 'occasion', 'duration_ms', 'path', 'mime_type', 'size_bytes', 'voice_note_id', 'happened_at'];
    protected function casts(): array { return ['happened_at' => 'datetime']; }
    protected static function booted(): void { static::creating(fn (self $fart) => $fart->uuid ??= (string) Str::uuid()); }

    public function author() { return $this->belongsTo(User::class, 'created_by'); }
    public function ratings() { return $this->hasMany(FartRating::class); }
    public function voiceNote() { return $this->belongsTo(VoiceNote::class); }
}
