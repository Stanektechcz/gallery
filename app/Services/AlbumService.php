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
