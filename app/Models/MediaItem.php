<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MediaItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'gallery_space_id',
        'owner_user_id',
        'uploaded_by',
        'primary_album_id',
        'drive_file_id',
        'drive_parent_folder_id',
        'original_filename',
        'safe_filename',
        'display_title',
        'extension',
        'mime_type',
        'media_type',
        'size_bytes',
        'sha256',
        'md5',
        'perceptual_hash',
        'perceptual_hash_bits',
        'width',
        'height',
        'duration_ms',
        'bitrate',
        'frame_rate',
        'video_codec',
        'audio_codec',
        'taken_at',
        'taken_at_timezone',
        'uploaded_at',
        'imported_at',
        'latitude',
        'longitude',
        'altitude',
        'orientation',
        'camera_make',
        'camera_model',
        'lens_model',
        'iso',
        'aperture',
        'shutter_speed',
        'focal_length',
        'rating',
        'description',
        'caption',
        'notes',
        'status',
        'processing_stage',
        'processing_progress',
        'storage_status',
        'is_favorite',
        'is_archived',
        'is_hidden',
        // Extended media format fields
        'is_panorama',
        'is_360',
        'panorama_projection',
        'is_raw',
        'raw_format',
        'live_photo_content_id',
        'live_photo_role',
        'live_photo_pair_id',
        'trashed_at',
        'purge_after',
        'last_verified_at',
        'processing_error',
        'search_text',
    ];

    protected function casts(): array
    {
        return [
            'taken_at'       => 'datetime',
            'uploaded_at'    => 'datetime',
            'imported_at'    => 'datetime',
            'trashed_at'     => 'datetime',
            'purge_after'    => 'datetime',
            'last_verified_at' => 'datetime',
            'is_favorite'    => 'boolean',
            'is_archived'    => 'boolean',
            'is_hidden'      => 'boolean',
            'is_panorama'    => 'boolean',
            'is_360'         => 'boolean',
            'is_raw'         => 'boolean',
            'latitude'       => 'float',
            'longitude'      => 'float',
            'altitude'       => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn(MediaItem $m) => $m->uuid ??= (string) Str::uuid());
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->whereNull('trashed_at')->where('is_hidden', false);
    }
    public function scopePhotos($query)
    {
        return $query->where('media_type', 'photo');
    }
    public function scopeVideos($query)
    {
        return $query->where('media_type', 'video');
    }
    public function scopeFavorites($query)
    {
        return $query->where('is_favorite', true);
    }
    public function scopeArchived($query)
    {
        return $query->where('is_archived', true);
    }
    public function scopeTrashed($query)
    {
        return $query->whereNotNull('trashed_at');
    }
    public function scopeNotTrashed($query)
    {
        return $query->whereNull('trashed_at');
    }
    public function scopeReady($query)
    {
        return $query->where('status', 'ready');
    }

    // Relations
    public function gallerySpace()
    {
        return $this->belongsTo(GallerySpace::class);
    }
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
    public function primaryAlbum()
    {
        return $this->belongsTo(Album::class, 'primary_album_id');
    }

    public function albums()
    {
        return $this->belongsToMany(Album::class, 'album_media')
            ->withPivot(['sort_order', 'is_cover', 'added_at', 'added_by']);
    }

    public function variants()
    {
        return $this->hasMany(MediaVariant::class);
    }

    public function getVariant(string $type): ?MediaVariant
    {
        return $this->variants->firstWhere('type', $type);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        $variant = $this->getVariant('thumbnail') ?? $this->getVariant('small');
        return $variant ? $variant->url : null;
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'media_tag')
            ->withPivot(['tagged_by', 'created_at']);
    }

    public function people()
    {
        return $this->belongsToMany(Person::class, 'media_person')
            ->withPivot(['tagged_by', 'created_at']);
    }

    public function places()
    {
        return $this->belongsToMany(Place::class, 'media_place')
            ->withPivot('is_primary');
    }

    public function edits()
    {
        return $this->hasMany(MediaEdit::class);
    }

    public function currentEdit()
    {
        return $this->hasOne(MediaEdit::class)->where('is_current', true);
    }

    public function stacks()
    {
        return $this->belongsToMany(MediaStack::class, 'media_stack_items')
            ->withPivot(['sort_order', 'is_cover']);
    }

    public function userFavoritedBy()
    {
        return $this->belongsToMany(User::class, 'user_favorites')->withTimestamps();
    }

    public function userRatings()
    {
        return $this->belongsToMany(User::class, 'user_ratings')
            ->withPivot('rating')
            ->withTimestamps();
    }

    public function hasGps(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function isSoftTrashed(): bool
    {
        return $this->trashed_at !== null;
    }

    public function rebuildSearchText(): void
    {
        $parts = array_filter([
            $this->original_filename,
            $this->display_title,
            $this->description,
            $this->caption,
            $this->notes,
            $this->camera_make,
            $this->camera_model,
            $this->lens_model,
            $this->primaryAlbum?->full_display_path,
            $this->tags->pluck('name')->implode(' '),
            $this->people->pluck('name')->implode(' '),
            $this->places->pluck('name')->implode(' '),
            $this->places->pluck('city')->filter()->implode(' '),
            $this->places->pluck('country')->filter()->implode(' '),
        ]);

        $this->updateQuietly(['search_text' => implode(' ', $parts)]);
    }
}
