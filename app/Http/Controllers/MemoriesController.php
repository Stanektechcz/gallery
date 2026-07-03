<?php

namespace App\Http\Controllers;

use App\Models\MediaItem;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MemoriesController extends Controller
{
    public function index(Request $request): Response
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();
        $today = now();

        // Build memories: same day across past years
        $memories = [];

        // Check last 10 years
        $currentYear = $today->year;
        for ($yearOffset = 1; $yearOffset <= 10; $yearOffset++) {
            $year  = $currentYear - $yearOffset;
            $start = $today->copy()->setYear($year)->startOfDay();
            $end   = $today->copy()->setYear($year)->endOfDay();

            $items = MediaItem::query()
                ->where('gallery_space_id', $space->id)
                ->whereNull('trashed_at')
                ->where('is_archived', false)
                ->where('status', 'ready')
                ->whereBetween('taken_at', [$start, $end])
                ->with(['variants' => fn($q) => $q->whereIn('type', ['thumbnail', 'placeholder'])])
                ->orderBy('taken_at')
                ->limit(20)
                ->get();

            if ($items->isNotEmpty()) {
                $memories[] = [
                    'year'       => $year,
                    'label'      => $this->yearLabel($yearOffset),
                    'date_label' => $start->translatedFormat('j. n. Y'),
                    'count'      => $items->count(),
                    'items'      => $items->map(fn($m) => $this->formatItem($m)),
                ];
            }
        }

        return Inertia::render('Memories/Index', [
            'memories'     => $memories,
            'today_label'  => $today->translatedFormat('j. F'),
            'has_memories' => count($memories) > 0,
        ]);
    }

    private function yearLabel(int $offset): string
    {
        return match ($offset) {
            1 => 'Před rokem',
            2 => 'Před 2 lety',
            3 => 'Před 3 lety',
            4 => 'Před 4 lety',
            5 => 'Před 5 lety',
            default => "Před {$offset} lety",
        };
    }

    private function formatItem(MediaItem $m): array
    {
        return [
            'id'         => $m->id,
            'uuid'       => $m->uuid,
            'media_type' => $m->media_type,
            'taken_at'   => $m->taken_at?->toIso8601String(),
            'width'      => $m->width,
            'height'     => $m->height,
            'is_favorite' => $m->is_favorite,
            'variants'   => $m->variants->map(fn($v) => [
                'type'           => $v->type,
                'url'            => asset('storage/' . $v->path),
                'dominant_color' => $v->dominant_color,
                'aspect_ratio'   => $v->aspect_ratio,
            ]),
        ];
    }
}
