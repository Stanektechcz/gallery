<?php

namespace App\Services\Taxonomy;

use App\Models\GallerySpace;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Shared tag assignments for domain records outside the legacy media/album pivots. */
class UniversalTagService
{
    private const ENTITIES = [
        'calendar_event' => ['table' => 'calendar_events', 'title' => 'title', 'uuid' => 'uuid', 'label' => 'Kalendář'],
        'trip' => ['table' => 'trips', 'title' => 'name', 'uuid' => null, 'label' => 'Cesty'],
        'recipe' => ['table' => 'recipes', 'title' => 'title', 'uuid' => 'uuid', 'label' => 'Kuchařka'],
        'entertainment' => ['table' => 'entertainment_titles', 'title' => 'title', 'uuid' => 'uuid', 'label' => 'Filmy a seriály'],
        'todo' => ['table' => 'shared_todos', 'title' => 'title', 'uuid' => 'uuid', 'label' => 'Plánování'],
        'expense' => ['table' => 'shared_expenses', 'title' => 'title', 'uuid' => 'uuid', 'label' => 'Finance'],
        'gift' => ['table' => 'gift_ideas', 'title' => 'title', 'uuid' => 'uuid', 'label' => 'Dárky'],
        'milestone' => ['table' => 'relationship_milestones', 'title' => 'title', 'uuid' => 'uuid', 'label' => 'Výročí'],
        'travel_inbox' => ['table' => 'travel_inbox_items', 'title' => 'title', 'uuid' => 'uuid', 'label' => 'Cestovní inbox'],
        'album' => ['table' => 'albums', 'title' => 'title', 'uuid' => 'uuid', 'label' => 'Alba'],
    ];

    /** @return Collection<int, Tag> */
    public function assignNames(GallerySpace $space, User $actor, string $entityType, int $entityId, array $names): Collection
    {
        if (! Schema::hasTable('tag_assignments')) return collect();
        $this->assertEntityBelongsToSpace($space, $actor, $entityType, $entityId);
        $names = collect($names)->map(fn ($name) => trim((string) $name, " #\t\n\r\0\x0B"))
            ->filter(fn (string $name) => $name !== '')
            ->map(fn (string $name) => Str::limit($name, 80, ''))
            ->unique(fn (string $name) => mb_strtolower($name))
            ->take(12)->values();

        return $names->map(function (string $name) use ($space, $actor, $entityType, $entityId): Tag {
            $slug = Str::slug($name);
            $tag = Tag::firstOrCreate(
                ['gallery_space_id' => $space->id, 'slug' => $slug],
                ['name' => $name, 'depth' => 0, 'materialized_path' => '', 'created_by' => $actor->id]
            );
            DB::table('tag_assignments')->updateOrInsert(
                ['tag_id' => $tag->id, 'entity_type' => $entityType, 'entity_id' => $entityId],
                ['assigned_by' => $actor->id, 'updated_at' => now(), 'created_at' => now()]
            );
            return $tag;
        });
    }

    public function attach(Tag $tag, GallerySpace $space, User $actor, string $entityType, int $entityId): void
    {
        abort_unless((int) $tag->gallery_space_id === (int) $space->id, 404);
        $this->assignNames($space, $actor, $entityType, $entityId, [$tag->name]);
    }

    /** @return array<int, array{entity_type:string,label:string,items:array<int, array{id:int,title:string,url:string}>}> */
    public function connections(Tag $tag, User $viewer): array
    {
        $groups = collect();
        if (Schema::hasTable('tag_assignments')) {
            $assignments = DB::table('tag_assignments')->where('tag_id', $tag->id)->get()->groupBy('entity_type');
            foreach ($assignments as $type => $rows) {
                if (! isset(self::ENTITIES[$type])) continue;
                $config = self::ENTITIES[$type];
                if (! Schema::hasTable($config['table'])) continue;
                $columns = array_values(array_filter(['id', $config['title'], $config['uuid']]));
                $itemQuery = DB::table($config['table'])->where('gallery_space_id', $tag->gallery_space_id)->whereIn('id', $rows->pluck('entity_id'));
                if ($type === 'gift' && Schema::hasColumn('gift_ideas', 'visibility') && Schema::hasColumn('gift_ideas', 'private_to_user_id')) { $itemQuery->where(fn ($visible) => $visible->where('visibility', 'shared')->orWhere('private_to_user_id', $viewer->id)); }
                $items = $itemQuery->get($columns)
                    ->map(fn (object $item) => ['id' => (int) $item->id, 'title' => (string) $item->{$config['title']}, 'url' => $this->urlFor($type, $item)])
                    ->values()->all();
                if ($items) $groups->push(['entity_type' => $type, 'label' => $config['label'], 'items' => $items]);
            }
        }
        $media = $tag->media()->where('media_items.gallery_space_id', $tag->gallery_space_id)->limit(24)->get(['media_items.id', 'media_items.display_title', 'media_items.original_filename', 'media_items.uuid'])
            ->map(fn ($item) => ['id' => (int) $item->id, 'title' => $item->display_title ?: $item->original_filename, 'url' => '/timeline?media=' . $item->uuid])->values()->all();
        if ($media) $groups->push(['entity_type' => 'media', 'label' => 'Fotografie a videa', 'items' => $media]);
        $albums = $tag->albums()->where('albums.gallery_space_id', $tag->gallery_space_id)->limit(24)->get(['albums.id', 'albums.title', 'albums.uuid'])
            ->map(fn ($item) => ['id' => (int) $item->id, 'title' => $item->title, 'url' => '/albums/' . $item->uuid])->values()->all();
        if ($albums) $groups->push(['entity_type' => 'album', 'label' => 'Alba', 'items' => $albums]);

        return $groups->values()->all();
    }

    public function detach(Tag $tag, GallerySpace $space, User $actor, string $entityType, int $entityId): void
    {
        abort_unless((int) $tag->gallery_space_id === (int) $space->id, 404);
        abort_unless(isset(self::ENTITIES[$entityType]), 422, 'Tento typ záznamu nelze štítkovat.');
        $this->assertEntityBelongsToSpace($space, $actor, $entityType, $entityId);
        if (Schema::hasTable('tag_assignments')) DB::table('tag_assignments')->where(['tag_id' => $tag->id, 'entity_type' => $entityType, 'entity_id' => $entityId])->delete();
    }

    private function assertEntityBelongsToSpace(GallerySpace $space, User $viewer, string $entityType, int $entityId): void
    {
        abort_unless(isset(self::ENTITIES[$entityType]), 422, 'Tento typ záznamu nelze štítkovat.');
        $config = self::ENTITIES[$entityType];
        abort_unless(Schema::hasTable($config['table']) && DB::table($config['table'])->where('id', $entityId)->where('gallery_space_id', $space->id)->exists(), 404);
        if ($entityType === 'gift' && Schema::hasColumn('gift_ideas', 'visibility') && Schema::hasColumn('gift_ideas', 'private_to_user_id')) {
            abort_unless(DB::table('gift_ideas')->where('id', $entityId)->where('gallery_space_id', $space->id)->where(fn ($visible) => $visible->where('visibility', 'shared')->orWhere('private_to_user_id', $viewer->id))->exists(), 404);
            return;
        }
    }

    private function urlFor(string $type, object $item): string
    {
        return match ($type) {
            'calendar_event' => '/calendar/events/' . $item->uuid,
            'trip' => '/trips?trip=' . $item->id,
            'recipe' => '/recipes/' . $item->uuid,
            'entertainment' => '/watchlist',
            'todo' => '/planning#todos',
            'expense' => '/finances',
            'gift' => '/gifts',
            'milestone' => '/milestones',
            'travel_inbox' => '/trips',
            'album' => '/albums/' . $item->uuid,
        };
    }
}
