<?php

namespace App\Services;

use App\Models\Album;
use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AlbumService
{
    public function create(GallerySpace $space, array $data, User $user): Album
    {
        $parent = isset($data['parent_id']) ? Album::find($data['parent_id']) : null;

        $album = Album::create([
            'gallery_space_id' => $space->id,
            'parent_id'        => $parent?->id,
            'title'            => $data['title'],
            'slug'             => Str::slug($data['title']),
            'description'      => $data['description'] ?? null,
            'color'            => $data['color'] ?? null,
            'icon'             => $data['icon'] ?? null,
            'event_date_start' => $data['event_date_start'] ?? null,
            'event_date_end'   => $data['event_date_end'] ?? null,
            'visibility'       => $data['visibility'] ?? 'private',
            'sort_mode'        => $data['sort_mode'] ?? 'date_taken',
            'sort_direction'   => $data['sort_direction'] ?? 'asc',
            'created_by'       => $user->id,
            'updated_by'       => $user->id,
            'sync_status'      => 'pending',
        ]);

        // Rebuild paths after creation (closure table is auto-populated in model boot)
        $album->rebuildPaths();

        AuditLog::record('album.create', $album, ['title' => $album->title, 'parent_id' => $parent?->id]);

        return $album;
    }


    public function createEventAlbum(GallerySpace $space, array $data, User $user, Collection $media): Album
    {
        $allowed = ['trip_id','title','slug','description','cover_media_id','event_date_start','event_date_end','story_mode','event_mode','event_start_at','event_end_at','event_place_name','visibility','sort_mode','sort_direction','sync_status','album_type','icon','color'];
        $attributes = array_merge([
            'gallery_space_id' => $space->id, 'created_by' => $user->id, 'updated_by' => $user->id,
            'visibility' => 'shared', 'sort_mode' => 'date_taken', 'sort_direction' => 'asc', 'sync_status' => 'pending',
        ], array_intersect_key($data, array_flip($allowed)));
        $album = Album::create($attributes);
        $album->rebuildPaths();
        foreach ($media->values() as $index => $item) \DB::table('album_media')->insertOrIgnore(['album_id' => $album->id, 'media_item_id' => $item->id, 'sort_order' => $index, 'is_cover' => $index === 0, 'added_at' => now(), 'added_by' => $user->id]);
        AuditLog::record('album.create', $album, ['title' => $album->title, 'source' => 'event_context', 'media_count' => $media->count()]);
        return $album;
    }
    public function update(Album $album, array $data, User $user): Album
    {
        $updateData = array_filter([
            'title'            => $data['title'] ?? null,
            'description'      => $data['description'] ?? null,
            'color'            => $data['color'] ?? null,
            'icon'             => $data['icon'] ?? null,
            'event_date_start' => $data['event_date_start'] ?? null,
            'event_date_end'   => $data['event_date_end'] ?? null,
            'visibility'       => $data['visibility'] ?? null,
            'sort_mode'        => $data['sort_mode'] ?? null,
            'sort_direction'   => $data['sort_direction'] ?? null,
            'cover_media_id'   => $data['cover_media_id'] ?? null,
            'updated_by'       => $user->id,
        ], fn($v) => $v !== null);

        $album->update($updateData);

        if (isset($data['title'])) {
            $album->update(['slug' => Str::slug($data['title'])]);
            $album->rebuildPaths();
        }

        AuditLog::record('album.update', $album, $updateData);

        return $album->fresh();
    }

    public function softDelete(Album $album, User $user): void
    {
        AuditLog::record('album.delete', $album, ['title' => $album->title]);
        $album->delete();
    }

    /**
     * Build a nested tree from a flat collection of albums.
     */
    public function buildTree(Collection $albums, ?int $parentId = null): array
    {
        $tree = [];

        foreach ($albums->where('parent_id', $parentId) as $album) {
            $node = $album->toArray();
            $node['children'] = $this->buildTree($albums, $album->id);
            $tree[] = $node;
        }

        return $tree;
    }

    /**
     * Get all albums in a subtree (including self).
     */
    public function getSubtree(Album $album): Collection
    {
        $ids = \DB::table('album_closure')
            ->where('ancestor_id', $album->id)
            ->pluck('descendant_id');

        return Album::whereIn('id', $ids)->get();
    }
}
