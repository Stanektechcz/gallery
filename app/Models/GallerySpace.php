<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class GallerySpace extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid', 'name', 'slug', 'description', 'owner_id', 'is_default',
        'drive_root_folder_id', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'settings'   => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (GallerySpace $space) {
            $space->uuid ??= (string) Str::uuid();
            $space->slug ??= Str::slug($space->name);
        });
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'gallery_space_user')
            ->withPivot(['role', 'can_delete', 'can_share', 'can_download', 'show_in_timeline', 'joined_at'])
            ->withTimestamps();
    }

    public function albums()
    {
        return $this->hasMany(Album::class);
    }

    public function rootAlbums()
    {
        return $this->hasMany(Album::class)->whereNull('parent_id');
    }

    public function mediaItems()
    {
        return $this->hasMany(MediaItem::class);
    }

    public function tags()
    {
        return $this->hasMany(Tag::class);
    }

    public function people()
    {
        return $this->hasMany(Person::class);
    }

    public function storageConnections()
    {
        return $this->hasManyThrough(StorageConnection::class, User::class, 'id', 'owner_user_id', 'owner_id', 'id');
    }
}
