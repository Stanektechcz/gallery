<?php

namespace App\Services\Memories;

use App\Jobs\Drive\CreateDriveFolderJob;
use App\Models\Album;
use App\Models\AuditLog;
use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class RelationshipAnniversaryRecapService
{
    public function available(): bool
    {
        return Schema::hasColumn('albums', 'anniversary_year')
            && Schema::hasColumn('shared_memory_moments', 'album_id');
    }

    public function overview(GallerySpace $space): array
    {
        if (! $this->available()) return ['available' => false, 'reason' => 'migration_required', 'candidates' => []];
        $period = $this->period($space);
        if (! $period) return ['available' => false, 'reason' => 'relationship_start_required', 'candidates' => []];

        $album = Album::query()->where('gallery_space_id', $space->id)
            ->where('anniversary_year', $period['year'])->with('cover.variants')->first();
        $selectedIds = $album ? $album->media()->pluck('media_items.id')->map(fn ($id) => (int) $id) : collect();
        $candidates = $this->candidates($space, $period);
        $suggestedIds = $this->suggestedIds($candidates);

        return [
            'available' => true,
            'year' => $period['year'],
            'title' => $this->defaultTitle($period['year']),
            'period' => [
                'starts_on' => $period['start']->toDateString(),
                'ends_on' => $period['end']->toDateString(),
                'anniversary_on' => $period['anniversary']->toDateString(),
                'is_complete' => $period['complete'],
            ],
            'suggested_count' => $suggestedIds->count(),
            'candidates' => $candidates->take(120)->map(fn (MediaItem $media) => $this->mediaPayload(
                $media, $suggestedIds->contains($media->id), $selectedIds->contains($media->id)
            ))->values(),
            'album' => $album ? $this->albumPayload($album) : null,
        ];
    }

    public function prompt(GallerySpace $space): ?array
    {
        if (! $this->available() || ! ($period = $this->period($space))) return null;
        if (Album::where('gallery_space_id', $space->id)->where('anniversary_year', $period['year'])->exists()) return null;
        $candidateCount = $this->mediaQuery($space, $period)->count();
        if ($candidateCount === 0) return null;
        return [
            'year' => $period['year'], 'title' => $this->defaultTitle($period['year']),
            'candidate_count' => $candidateCount, 'anniversary_on' => $period['anniversary']->toDateString(),
            'is_complete' => $period['complete'],
        ];
    }

    /** @return array{album:array<string,mixed>, memory:array<string,mixed>, created:bool, story_created:bool} */
    public function save(GallerySpace $space, User $user, array $data): array
    {
        abort_unless($this->available(), 503, 'Pro výroční album dokončete databázové migrace aplikace.');
        $period = $this->period($space);
        abort_unless($period, 422, 'Nejprve nastavte začátek vztahu.');

        $requested = collect($data['media_uuids'])->unique()->values();
        $allowed = $this->mediaQuery($space, $period)->whereIn('uuid', $requested)->with('variants')->get()->keyBy('uuid');
        abort_unless($allowed->count() === $requested->count(), 422, 'Výroční album může obsahovat jen dostupná média z daného období vztahu.');
        $ordered = $requested->map(fn ($uuid) => $allowed->get($uuid));
        if (! empty($data['cover_media_uuid'])) {
            abort_unless($requested->contains($data['cover_media_uuid']), 422, 'Titulní fotografie musí být součástí výběru.');
            $cover = $allowed->get($data['cover_media_uuid']);
        } else {
            $cover = $ordered->sortByDesc(fn (MediaItem $media) => $this->score($media))->first();
        }

        $created = false;
        $storyCreated = false;
        [$album, $memory] = DB::transaction(function () use ($space, $user, $data, $period, $ordered, $cover, &$created, &$storyCreated) {
            GallerySpace::whereKey($space->id)->lockForUpdate()->firstOrFail();
            $album = Album::withTrashed()->where('gallery_space_id', $space->id)->where('anniversary_year', $period['year'])->first();
            $created = ! $album;
            $title = trim((string) ($data['title'] ?? '')) ?: $this->defaultTitle($period['year']);
            $note = trim((string) ($data['note'] ?? '')) ?: "Výběr společných okamžiků z {$period['year']}. roku našeho vztahu.";
            $albumData = [
                'anniversary_year' => $period['year'], 'title' => $title,
                'slug' => Str::slug($title . '-' . $period['year']), 'description' => $note,
                'cover_media_id' => $cover->id, 'event_date_start' => $period['start']->toDateString(),
                'event_date_end' => $period['end']->toDateString(), 'story_mode' => true,
                'event_mode' => true, 'event_start_at' => $period['start'], 'event_end_at' => $period['end']->copy()->endOfDay(),
                'visibility' => 'shared', 'sort_mode' => 'date_taken', 'sort_direction' => 'asc',
                'updated_by' => $user->id, 'sync_status' => 'pending', 'album_type' => 'physical',
            ];
            if ($album) {
                if ($album->trashed()) $album->restore();
                $album->update($albumData);
            } else {
                $album = Album::create($albumData + [
                    'gallery_space_id' => $space->id, 'created_by' => $user->id,
                    'icon' => '❤️', 'color' => '#ec4899',
                ]);
            }
            $album->rebuildPaths();

            $sync = [];
            foreach ($ordered as $index => $media) {
                $sync[$media->id] = ['sort_order' => $index, 'is_cover' => $media->id === $cover->id, 'added_at' => now(), 'added_by' => $user->id];
            }
            $album->media()->sync($sync);
            $album->update(['media_count' => count($sync), 'total_size_bytes' => $ordered->sum('size_bytes')]);
            $permissions = DB::table('gallery_space_user')->where('gallery_space_id', $space->id)->pluck('user_id')->map(fn ($userId) => [
                'album_id' => $album->id, 'user_id' => $userId, 'role' => 'editor', 'inherited' => false,
                'created_at' => now(), 'updated_at' => now(),
            ])->all();
            if ($permissions) DB::table('album_user_permissions')->upsert($permissions, ['album_id', 'user_id'], ['role', 'inherited', 'updated_at']);

            $storyCreated = DB::table('album_story_blocks')->where('album_id', $album->id)->doesntExist();
            if ($storyCreated) $this->createStory($album, $ordered, $user->id, $period, $note);

            $memoryRow = [
                'gallery_space_id' => $space->id, 'created_by' => $user->id, 'album_id' => $album->id,
                'title' => $title, 'note' => $note,
                'happened_on' => ($period['complete'] ? $period['anniversary'] : $period['end'])->toDateString(),
                'media_item_ids' => json_encode($ordered->take(30)->pluck('id')->values()->all()),
                'is_favorite' => true, 'updated_at' => now(),
            ];
            $memory = DB::table('shared_memory_moments')->where('album_id', $album->id)->first();
            if ($memory) {
                DB::table('shared_memory_moments')->where('id', $memory->id)->update($memoryRow);
            } else {
                DB::table('shared_memory_moments')->insert($memoryRow + ['uuid' => (string) Str::uuid(), 'created_at' => now()]);
            }
            $memory = DB::table('shared_memory_moments')->where('album_id', $album->id)->firstOrFail();

            $annualId = data_get($space->settings, 'relationship_anniversary.event_ids.annual');
            if ($annualId && ($event = CalendarEvent::whereKey($annualId)->where('gallery_space_id', $space->id)->first())) {
                $metadata = $event->metadata ?? [];
                $metadata['anniversary_album_uuid'] = $album->uuid;
                $metadata['anniversary_year'] = $period['year'];
                $event->update(['album_id' => $album->id, 'metadata' => $metadata]);
            }

            AuditLog::record($created ? 'anniversary.recap.create' : 'anniversary.recap.sync', $album, [
                'anniversary_year' => $period['year'], 'media_count' => count($sync), 'story_created' => $storyCreated,
            ]);
            return [$album->fresh('cover.variants'), $memory];
        });

        if ($created) CreateDriveFolderJob::dispatch($album);

        return [
            'album' => $this->albumPayload($album),
            'memory' => ['uuid' => $memory->uuid, 'title' => $memory->title],
            'created' => $created,
            'story_created' => $storyCreated,
        ];
    }

    /** @return array{year:int,start:Carbon,end:Carbon,anniversary:Carbon,complete:bool}|null */
    private function period(GallerySpace $space): ?array
    {
        $startedOn = data_get($space->settings, 'relationship_anniversary.started_on');
        if (! $startedOn) return null;
        $started = Carbon::parse($startedOn)->startOfDay();
        if ($started->isFuture()) return null;
        $now = now();
        $completedYears = $now->year - $started->year;
        if ($started->copy()->addYearsNoOverflow($completedYears)->gt($now)) $completedYears--;
        $year = max(1, $completedYears);
        $anniversary = $started->copy()->addYearsNoOverflow($year);
        $start = $started->copy()->addYearsNoOverflow($year - 1);
        $complete = $anniversary->lte($now);
        $end = ($complete ? $anniversary : $now->copy())->endOfDay();
        return compact('year', 'start', 'end', 'anniversary', 'complete');
    }

    private function mediaQuery(GallerySpace $space, array $period)
    {
        return MediaItem::query()->where('gallery_space_id', $space->id)->whereNull('trashed_at')->where('is_hidden', false)
            ->whereIn('media_type', ['photo', 'video'])
            ->where(function ($query) use ($period) {
                $query->whereBetween('taken_at', [$period['start'], $period['end']])
                    ->orWhere(fn ($fallback) => $fallback->whereNull('taken_at')->whereBetween('uploaded_at', [$period['start'], $period['end']]));
            });
    }

    /** @return Collection<int,MediaItem> */
    private function candidates(GallerySpace $space, array $period): Collection
    {
        $all = $this->mediaQuery($space, $period)->with('variants')->limit(600)->get();
        $ranked = $all->sortByDesc(fn (MediaItem $media) => $this->score($media));
        $monthly = $all->groupBy(fn (MediaItem $media) => ($media->taken_at ?? $media->uploaded_at)?->format('Y-m'))
            ->map(fn (Collection $month) => $month->sortByDesc(fn (MediaItem $media) => $this->score($media))->first());
        return $ranked->take(110)->concat($monthly)->unique('id')
            ->sortBy(fn (MediaItem $media) => ($media->taken_at ?? $media->uploaded_at)?->timestamp ?? 0)->values();
    }

    private function suggestedIds(Collection $candidates): Collection
    {
        $monthly = $candidates->groupBy(fn (MediaItem $media) => ($media->taken_at ?? $media->uploaded_at)?->format('Y-m'))
            ->map(fn (Collection $month) => $month->sortByDesc(fn (MediaItem $media) => $this->score($media))->first()?->id)->filter();
        return $candidates->sortByDesc(fn (MediaItem $media) => $this->score($media))->take(30)->pluck('id')
            ->concat($monthly)->unique()->take(40)->values();
    }

    private function score(MediaItem $media): int
    {
        return ($media->is_favorite ? 100000 : 0) + ((int) $media->rating * 10000)
            + ($media->media_type === 'photo' ? 1000 : 500)
            + min(999, (int) floor(((int) $media->width * (int) $media->height) / 100000));
    }

    private function mediaPayload(MediaItem $media, bool $suggested, bool $selected): array
    {
        $reasons = [];
        if ($media->is_favorite) $reasons[] = 'oblíbená';
        if ($media->rating) $reasons[] = 'hodnocení ' . $media->rating . '/5';
        if (! $reasons) $reasons[] = 'zastupuje ' . ($media->taken_at ?? $media->uploaded_at)?->translatedFormat('F Y');
        return [
            'uuid' => $media->uuid, 'title' => $media->display_title ?: $media->original_filename,
            'media_type' => $media->media_type, 'thumbnail_url' => $media->thumbnail_url,
            'taken_at' => ($media->taken_at ?? $media->uploaded_at)?->toIso8601String(),
            'is_favorite' => (bool) $media->is_favorite, 'rating' => $media->rating,
            'suggested' => $suggested, 'selected' => $selected, 'reasons' => $reasons,
        ];
    }

    private function createStory(Album $album, Collection $media, int $userId, array $period, string $note): void
    {
        $blocks = [];
        $add = function (string $type, array $content) use (&$blocks, $album, $userId): void {
            $blocks[] = ['album_id' => $album->id, 'created_by' => $userId, 'type' => $type,
                'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort_order' => count($blocks), 'created_at' => now(), 'updated_at' => now()];
        };
        $add('heading', ['text' => $album->title, 'level' => 1]);
        $add('text', ['body' => $period['start']->translatedFormat('j. F Y') . ' – ' . $period['end']->translatedFormat('j. F Y') . "\n\n" . $note]);
        foreach ($media->groupBy(fn (MediaItem $item) => ($item->taken_at ?? $item->uploaded_at)?->format('Y-m')) as $month => $items) {
            $date = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            $add('heading', ['text' => ucfirst($date->translatedFormat('F Y')), 'level' => 2]);
            $photos = $items->where('media_type', 'photo')->pluck('uuid')->values()->all();
            if ($photos) $add('photo', ['media_uuids' => $photos, 'layout' => count($photos) > 1 ? 'grid' : 'full']);
            foreach ($items->where('media_type', 'video') as $video) $add('video', ['media_uuid' => $video->uuid]);
        }
        if ($blocks) DB::table('album_story_blocks')->insert($blocks);
    }

    private function albumPayload(Album $album): array
    {
        $album->loadMissing('cover.variants');
        $memory = DB::table('shared_memory_moments')->where('album_id', $album->id)->first(['uuid', 'title']);
        return [
            'uuid' => $album->uuid, 'title' => $album->title, 'description' => $album->description,
            'anniversary_year' => (int) $album->anniversary_year,
            'media_count' => (int) $album->media_count, 'cover_url' => $album->cover?->thumbnail_url,
            'story_blocks_count' => DB::table('album_story_blocks')->where('album_id', $album->id)->count(),
            'memory' => $memory ? ['uuid' => $memory->uuid, 'title' => $memory->title] : null,
            'updated_at' => $album->updated_at?->toIso8601String(),
        ];
    }

    private function defaultTitle(int $year): string
    {
        return "Náš {$year}. rok spolu";
    }
}
