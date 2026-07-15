<?php

namespace App\Services\Media;

use App\Models\Album;
use App\Models\MediaItem;
use App\Services\Storage\DriveConnectionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Explainable, deterministic album curation and storage-health assistant.
 * It deliberately uses only the couple's own ratings and media metadata.
 */
class AlbumCurationAssistantService
{
    public function __construct(
        private readonly SmartAlbumService $smartAlbums,
        private readonly DriveConnectionResolver $driveConnections,
    ) {}

    public function snapshot(Album $album, ?int $viewerId = null): array
    {
        $health = $this->health($album);
        $query = $this->mediaQuery($album);

        $candidates = (clone $query)
            ->with([
                'variants',
                'stacks:id,cover_media_id',
                'userRatings:id,name',
            ])
            ->orderByDesc('is_favorite')
            ->orderByDesc('rating')
            ->orderByRaw('(COALESCE(width, 0) * COALESCE(height, 0)) DESC')
            ->limit(600)
            ->get()
            ->map(fn (MediaItem $media) => $this->score($media))
            ->sortByDesc('score')
            ->values();

        $shortlist = $this->diverseShortlist($candidates, 12);
        $cover = $candidates->where('media_type', 'photo')->first()
            ?? $candidates->first();
        return [
            'album' => [
                'id' => $album->id,
                'uuid' => $album->uuid,
                'title' => $album->title,
                'trip_id' => $album->trip_id,
                'current_cover_media_id' => $album->cover_media_id,
            ],
            'summary' => $health['summary'] + ['candidate_limit_reached' => $health['summary']['media_count'] > 600],
            'cover_recommendation' => $cover,
            'shortlist' => $shortlist,
            'backup' => $health['backup'],
            'quality' => $health['quality'],
            'board' => $this->boardPayload($album, $viewerId),
        ];
    }

    /** Lightweight version for dashboard, trip and memory summaries. */
    public function health(Album $album): array
    {
        $query = $this->mediaQuery($album);
        $stats = (clone $query)->selectRaw(<<<'SQL'
            COUNT(*) AS media_total,
            SUM(CASE WHEN media_type = 'photo' THEN 1 ELSE 0 END) AS photo_total,
            SUM(CASE WHEN media_type = 'video' THEN 1 ELSE 0 END) AS video_total,
            SUM(CASE WHEN drive_file_id IS NOT NULL THEN 1 ELSE 0 END) AS cloud_total,
            SUM(CASE WHEN drive_file_id IS NULL AND storage_status IN ('pending', 'uploading', 'syncing') THEN 1 ELSE 0 END) AS uploading_total,
            SUM(CASE WHEN last_verified_at IS NOT NULL THEN 1 ELSE 0 END) AS verified_total,
            SUM(CASE WHEN processing_error IS NOT NULL THEN 1 ELSE 0 END) AS failed_total,
            SUM(CASE WHEN taken_at IS NULL THEN 1 ELSE 0 END) AS missing_date_total,
            SUM(CASE WHEN width IS NULL OR height IS NULL THEN 1 ELSE 0 END) AS missing_dimensions_total
        SQL)->first();
        $total = (int) ($stats?->media_total ?? 0);
        $photos = (int) ($stats?->photo_total ?? 0);
        $videos = (int) ($stats?->video_total ?? 0);
        $cloudBacked = (int) ($stats?->cloud_total ?? 0);
        $uploading = (int) ($stats?->uploading_total ?? 0);
        $missingOriginal = (clone $query)->whereNull('drive_file_id')->whereDoesntHave('variants', fn (Builder $variants) => $variants->where('type', 'original'))->count();
        $missingPreview = (clone $query)->whereDoesntHave('variants', fn (Builder $variants) => $variants->whereIn('type', ['thumbnail', 'small', 'video_poster']))->count();
        $missingLarge = (clone $query)->where('media_type', 'photo')->whereDoesntHave('variants', fn (Builder $variants) => $variants->whereIn('type', ['large', 'original']))->count();
        $coverage = $total > 0 ? (int) round(($cloudBacked / $total) * 100) : 100;

        return [
            'summary' => ['media_count' => $total, 'photos' => $photos, 'videos' => $videos],
            'backup' => [
                'status' => match (true) {
                    $total === 0 => 'empty',
                    $missingOriginal > 0 => 'critical',
                    $cloudBacked === $total => 'safe',
                    default => 'attention',
                },
                'coverage_percent' => $coverage,
                'cloud_backed' => $cloudBacked,
                'local_only' => max(0, $total - $cloudBacked - $uploading),
                'uploading' => $uploading,
                'missing_original' => $missingOriginal,
                'verified' => (int) ($stats?->verified_total ?? 0),
                'can_sync' => $this->driveConnections->forSpace($album->gallery_space_id) !== null,
            ],
            'quality' => [
                'missing_preview' => $missingPreview,
                'missing_large' => $missingLarge,
                'processing_failed' => (int) ($stats?->failed_total ?? 0),
                'missing_taken_at' => (int) ($stats?->missing_date_total ?? 0),
                'missing_dimensions' => (int) ($stats?->missing_dimensions_total ?? 0),
            ],
        ];
    }

    public function mediaQuery(Album $album): Builder
    {
        if (($album->album_type ?? 'physical') === 'smart' && $album->smart_rules) {
            return $this->smartAlbums->buildQuery($album, $album->gallery_space_id)
                ->whereIn('status', ['ready', 'received']);
        }

        return MediaItem::query()
            ->where('gallery_space_id', $album->gallery_space_id)
            ->where(fn (Builder $query) => $query
                ->where('primary_album_id', $album->id)
                ->orWhereHas('albums', fn (Builder $albums) => $albums->where('albums.id', $album->id)))
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
            ->whereIn('status', ['ready', 'received']);
    }

    public function boardPayload(Album $album, ?int $viewerId = null): ?array
    {
        if (! Schema::hasTable('curation_boards') || ! Schema::hasColumn('curation_boards', 'album_id')) {
            return null;
        }

        $board = DB::table('curation_boards')
            ->where('album_id', $album->id)
            ->where('purpose', 'album_selection')
            ->first();
        if (! $board) {
            return null;
        }

        $items = DB::table('curation_board_items as item')
            ->join('media_items as media', 'media.id', '=', 'item.media_item_id')
            ->where('item.curation_board_id', $board->id)
            ->whereNull('media.trashed_at')
            ->orderBy('item.sort_order')
            ->get(['item.id', 'item.status', 'item.note', 'item.sort_order', 'media.id as media_id', 'media.uuid as media_uuid', 'media.display_title', 'media.original_filename', 'media.media_type']);
        $media = MediaItem::whereIn('id', $items->pluck('media_id'))->with('variants')->get()->keyBy('id');
        $votes = DB::table('curation_board_votes')->whereIn('curation_board_item_id', $items->pluck('id'))->get()->groupBy('curation_board_item_id');

        return [
            'id' => $board->id,
            'uuid' => $board->uuid,
            'title' => $board->title,
            'items' => $items->map(function (object $item) use ($media, $votes, $viewerId): array {
                $voteRows = $votes->get($item->id, collect());
                $model = $media->get($item->media_id);
                return [
                    'id' => $item->id,
                    'media_uuid' => $item->media_uuid,
                    'title' => $item->display_title ?: $item->original_filename,
                    'media_type' => $item->media_type,
                    'thumbnail_url' => $model ? $this->previewUrl($model) : null,
                    'status' => $item->status,
                    'note' => $item->note,
                    'votes' => [
                        'selected' => $voteRows->where('is_selected', true)->count(),
                        'not_selected' => $voteRows->where('is_selected', false)->count(),
                        'my_vote' => $viewerId ? $voteRows->firstWhere('user_id', $viewerId)?->is_selected : null,
                    ],
                ];
            })->values(),
        ];
    }

    private function score(MediaItem $media): array
    {
        $reasons = [];
        $risks = [];
        $score = $media->media_type === 'photo' ? 12 : 4;
        $rating = max(0, min(5, (int) ($media->rating ?? 0)));
        if ($rating > 0) {
            $score += $rating * 12;
            $reasons[] = "hodnocení {$rating}/5";
        }
        if ($media->is_favorite) {
            $score += 18;
            $reasons[] = 'označeno jako oblíbené';
        }

        $partnerRatings = $media->userRatings->pluck('pivot.rating')->filter(fn ($value) => $value !== null);
        if ($partnerRatings->isNotEmpty()) {
            $average = (float) $partnerRatings->avg();
            $score += (int) round(($average / 5) * 10);
            $reasons[] = $partnerRatings->count() > 1
                ? 'shoda partnerů ' . number_format($average, 1, ',', '') . '/5'
                : 'partnerské hodnocení ' . number_format($average, 1, ',', '') . '/5';
        }

        $megapixels = ((int) $media->width * (int) $media->height) / 1_000_000;
        if ($megapixels > 0) {
            $score += (int) min(20, round($megapixels));
            $reasons[] = number_format($megapixels, 1, ',', '') . ' Mpx';
            if ($megapixels < 2) {
                $score -= 12;
                $risks[] = 'nižší rozlišení';
            }
        } else {
            $risks[] = 'chybí rozměry';
        }

        if ($media->taken_at) {
            $score += 5;
        } else {
            $risks[] = 'chybí datum pořízení';
        }
        if ($media->hasGps()) {
            $score += 4;
            $reasons[] = 'má polohu';
        }
        if ($media->camera_make || $media->camera_model) {
            $score += 3;
        }
        if ($media->drive_file_id) {
            $score += 4;
        } else {
            $risks[] = 'originál zatím jen lokálně';
        }
        if ($media->status === 'ready') {
            $score += 8;
        } else {
            $score -= 10;
            $risks[] = 'zpracování není dokončeno';
        }
        if ($media->processing_error) {
            $score -= 25;
            $risks[] = 'chyba zpracování';
        }

        $preview = $this->previewUrl($media);
        if ($preview) {
            $score += 8;
        } else {
            $score -= 18;
            $risks[] = 'chybí náhled';
        }
        if ($media->variants->contains(fn ($variant) => in_array($variant->type, ['large', 'original'], true))) {
            $score += 3;
        }

        $stack = $media->stacks->first();
        if ($stack && $stack->cover_media_id && (int) $stack->cover_media_id !== $media->id) {
            $score -= 10;
            $risks[] = 'alternativní záběr ze série';
        }

        return [
            'id' => $media->id,
            'uuid' => $media->uuid,
            'title' => $media->display_title ?: $media->original_filename,
            'media_type' => $media->media_type,
            'taken_at' => $media->taken_at?->toIso8601String(),
            'thumbnail_url' => $preview,
            'width' => $media->width,
            'height' => $media->height,
            'score' => max(0, $score),
            'reasons' => array_values(array_unique($reasons)),
            'risks' => array_values(array_unique($risks)),
            'stack_key' => $stack ? 'stack:' . $stack->id : 'media:' . $media->id,
        ];
    }

    private function diverseShortlist(Collection $candidates, int $limit): array
    {
        $seenStacks = [];
        $selected = [];
        foreach ($candidates as $candidate) {
            if (isset($seenStacks[$candidate['stack_key']])) {
                continue;
            }
            $seenStacks[$candidate['stack_key']] = true;
            unset($candidate['stack_key']);
            $selected[] = $candidate;
            if (count($selected) >= $limit) {
                break;
            }
        }
        return $selected;
    }

    private function previewUrl(MediaItem $media): ?string
    {
        $variant = $media->variants->firstWhere('type', 'thumbnail')
            ?? $media->variants->firstWhere('type', 'video_poster')
            ?? $media->variants->firstWhere('type', 'small');
        return $variant?->url;
    }
}
