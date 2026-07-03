<?php

namespace App\Console\Commands;

use App\Models\DuplicateGroup;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Services\Media\PerceptualHashService;
use Illuminate\Console\Command;

class GalleryScanDuplicatesCommand extends Command
{
    protected $signature   = 'gallery:scan-duplicates {--space= : Gallery space ID}';
    protected $description = 'Scan for duplicate media using hash-based detection (no AI)';

    public function handle(PerceptualHashService $hashService): int
    {
        $this->info('Scanning for duplicates...');

        $spaceId = $this->option('space');
        $query   = MediaItem::where('status', 'ready')->whereNull('trashed_at');
        if ($spaceId) $query->where('gallery_space_id', $spaceId);

        $media = $query->get();
        $this->info("Checking {$media->count()} items...");

        // Level 1: Exact SHA-256 duplicates
        $exactDups = $media->groupBy('sha256')
            ->filter(fn($group) => $group->count() > 1);

        $found = 0;
        foreach ($exactDups as $hash => $group) {
            $existing = DuplicateGroup::whereHas('mediaItems', fn($q) => $q->where('sha256', $hash))->first();
            if (!$existing) {
                $group_model = DuplicateGroup::create([
                    'uuid'             => \Illuminate\Support\Str::uuid(),
                    'gallery_space_id' => $group->first()->gallery_space_id,
                    'match_type'       => 'exact',
                    'detected_at'      => now(),
                ]);
                $group_model->mediaItems()->attach($group->pluck('id'));
                $found++;
            }
        }

        $this->info("Found {$found} exact duplicate groups.");

        // Level 2: Perceptual hash similarity (for photos only)
        $photos = $media->where('media_type', 'photo')->whereNotNull('perceptual_hash');
        $processed = 0;
        $similar   = 0;

        $photosArr = $photos->values();
        for ($i = 0; $i < count($photosArr); $i++) {
            for ($j = $i + 1; $j < count($photosArr); $j++) {
                $a = $photosArr[$i];
                $b = $photosArr[$j];

                if ($a->sha256 === $b->sha256) continue; // Already caught as exact

                if ($hashService->areSimilar($a->perceptual_hash, $b->perceptual_hash, 8)) {
                    $existing = DuplicateGroup::whereHas('mediaItems', fn($q) => $q->whereIn('media_items.id', [$a->id, $b->id]))
                        ->where('match_type', 'similar')
                        ->first();
                    if (!$existing) {
                        $group = DuplicateGroup::create([
                            'uuid'             => \Illuminate\Support\Str::uuid(),
                            'gallery_space_id' => $a->gallery_space_id,
                            'match_type'       => 'similar',
                            'detected_at'      => now(),
                        ]);
                        $group->mediaItems()->attach([$a->id, $b->id]);
                        $similar++;
                    }
                }
                $processed++;
            }
        }

        $this->info("Found {$similar} perceptually similar pairs.");

        return Command::SUCCESS;
    }
}
