<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SharedLink extends Model
{
    protected $fillable = [
        'uuid', 'token', 'created_by', 'gallery_space_id', 'target_type', 'target_id',
        'name', 'description', 'password_hash', 'allow_download', 'allow_guest_upload',
        'show_metadata', 'hide_gps', 'upload_limit_bytes', 'max_uses', 'use_count',
        'expires_at', 'is_active',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'expires_at'        => 'datetime',
            'allow_download'    => 'boolean',
            'allow_guest_upload'=> 'boolean',
            'show_metadata'     => 'boolean',
            'hide_gps'          => 'boolean',
            'is_active'         => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SharedLink $link) {
            $link->uuid  ??= (string) Str::uuid();
            $link->token ??= Str::random(40);
        });
    }

    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }
    public function gallerySpace() { return $this->belongsTo(GallerySpace::class); }

    public function mediaItems()
    {
        return $this->belongsToMany(MediaItem::class, 'shared_link_media');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isUsageLimitReached(): bool
    {
        return $this->max_uses !== null && $this->use_count >= $this->max_uses;
    }

    public function isAccessible(): bool
    {
        return $this->is_active && !$this->isExpired() && !$this->isUsageLimitReached();
    }

    public function verifyPassword(string $password): bool
    {
        if ($this->password_hash === null) return true;
        return password_verify($password, $this->password_hash);
    }
}
