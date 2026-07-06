<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Album extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'gallery_space_id',
        'parent_id',
        'title',
        'slug',
        'depth',
        'materialized_path',
        'full_display_path',
        'drive_folder_id',
        'drive_parent_folder_id',
        'cover_media_id',
        'description',
        'event_date_start',
        'event_date_end',
        'default_place_id',
        'color',
        'icon',
        'sort_mode',
        'sort_direction',
        'manual_sort_order',
        'visibility',
        'inherit_permissions',
        'created_by',
        'updated_by',
        'sync_status',
        'last_drive_sync_at',
        'media_count',
        'descendant_count',
        'total_size_bytes',
        'story_mode',
        'album_type',
        'smart_rules',
        'event_mode',
        'event_start_at',
        'event_end_at',
        'event_place_name',
        'event_latitude',
        'event_longitude',
        'event_gps_radius',
    ];

    protected function casts(): array
    {
        return [
            'event_date_start'    => 'date',
            'event_date_end'      => 'date',
            'event_start_at'      => 'datetime',
            'event_end_at'        => 'datetime',
            'last_drive_sync_at'  => 'datetime',
            'inherit_permissions' => 'boolean',
            'story_mode'          => 'boolean',
            'event_mode'          => 'boolean',
            'event_latitude'      => 'float',
            'event_longitude'     => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Album $album) {
            $album->uuid ??= (string) Str::uuid();
        });

        static::created(function (Album $album) {
            $album->insertClosureRows();
        });

        static::deleting(function (Album $album) {
            // Only clean closure table on hard delete, not soft delete
            if ($album->isForceDeleting()) {
                DB::table('album_closure')
                    ->where('descendant_id', $album->id)
                    ->orWhere('ancestor_id', $album->id)
                    ->delete();
            }
        });
    }

    // Relations
    public function gallerySpace()
    {
        return $this->belongsTo(GallerySpace::class);
    }
    public function parent()
    {
        return $this->belongsTo(Album::class, 'parent_id');
    }
    public function children()
    {
        return $this->hasMany(Album::class, 'parent_id');
    }
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    public function cover()
    {
        return $this->belongsTo(MediaItem::class, 'cover_media_id');
    }

    public function media()
    {
        return $this->belongsToMany(MediaItem::class, 'album_media')
            ->withPivot(['sort_order', 'is_cover', 'added_at', 'added_by'])
            ->orderByPivot('sort_order');
    }

    public function primaryMedia()
    {
        return $this->hasMany(MediaItem::class, 'primary_album_id');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'album_tag');
    }

    public function people()
    {
        return $this->belongsToMany(Person::class, 'album_person');
    }

    public function places()
    {
        return $this->belongsToMany(Place::class, 'album_place')
            ->withPivot('is_primary');
    }

    public function userPermissions()
    {
        return $this->hasMany(AlbumUserPermission::class);
    }

    // Closure table helpers
    public function ancestors()
    {
        return $this->belongsToMany(Album::class, 'album_closure', 'descendant_id', 'ancestor_id')
            ->withPivot('depth')
            ->wherePivot('depth', '>', 0)
            ->orderByPivot('depth', 'desc');
    }

    public function descendants()
    {
        return $this->belongsToMany(Album::class, 'album_closure', 'ancestor_id', 'descendant_id')
            ->withPivot('depth')
            ->wherePivot('depth', '>', 0)
            ->orderByPivot('depth');
    }

    public function insertClosureRows(): void
    {
        // Self-reference
        \DB::table('album_closure')->insertOrIgnore([
            'ancestor_id'   => $this->id,
            'descendant_id' => $this->id,
            'depth'         => 0,
        ]);

        // Inherit from parent's closure
        if ($this->parent_id) {
            $rows = \DB::table('album_closure')
                ->where('descendant_id', $this->parent_id)
                ->get()
                ->map(fn($row) => [
                    'ancestor_id'   => $row->ancestor_id,
                    'descendant_id' => $this->id,
                    'depth'         => $row->depth + 1,
                ])
                ->toArray();

            if (!empty($rows)) {
                \DB::table('album_closure')->insertOrIgnore($rows);
            }
        }
    }

    /**
     * Move this album to a new parent. Prevents circular moves.
     */
    public function moveTo(?int $newParentId): void
    {
        if ($newParentId !== null) {
            // Prevent moving into own descendant
            $isDescendant = DB::table('album_closure')
                ->where('ancestor_id', $this->id)
                ->where('descendant_id', $newParentId)
                ->where('depth', '>', 0)
                ->exists();

            if ($isDescendant || $newParentId === $this->id) {
                throw new \InvalidArgumentException('Cannot move album into its own descendant.');
            }
        }

        DB::transaction(function () use ($newParentId) {
            // Remove old closure rows for this subtree (except self-referencing rows)
            $descendantIds = DB::table('album_closure')
                ->where('ancestor_id', $this->id)
                ->pluck('descendant_id')
                ->toArray();

            DB::table('album_closure')
                ->whereIn('descendant_id', $descendantIds)
                ->where('ancestor_id', '!=', DB::raw('descendant_id'))
                ->delete();

            $this->parent_id = $newParentId;
            $this->save();

            // Rebuild closure for all descendants
            foreach ($descendantIds as $descendantId) {
                $descendant = static::find($descendantId);
                if ($descendant) {
                    $descendant->insertClosureRows();
                }
            }

            $this->rebuildPaths();
        });
    }

    public function rebuildPaths(): void
    {
        $ancestors = $this->ancestors()->orderByPivot('depth', 'desc')->get();
        $pathIds   = $ancestors->pluck('id')->concat([$this->id])->implode('/');
        $pathNames = $ancestors->pluck('title')->concat([$this->title])->implode(' / ');

        $this->update([
            'depth'              => $ancestors->count(),
            'materialized_path'  => $pathIds,
            'full_display_path'  => $pathNames,
        ]);

        // Rebuild paths for children
        foreach ($this->children as $child) {
            $child->rebuildPaths();
        }
    }

    public function getBreadcrumbAttribute(): array
    {
        return $this->ancestors()
            ->orderByPivot('depth', 'desc')
            ->get(['id', 'uuid', 'title', 'slug'])
            ->push($this->only(['id', 'uuid', 'title', 'slug']))
            ->toArray();
    }
}
