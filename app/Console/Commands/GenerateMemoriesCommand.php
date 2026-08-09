<?php

namespace App\Console\Commands;

use App\Models\GallerySpace;
use App\Models\GeneratedMemory;
use App\Notifications\GalleryNotification;
use App\Services\Memories\MemoryGeneratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Fills the memories tab, and tells people when there is something in it.
 *
 * Run daily. It builds tomorrow as well as today, so the tab is never empty for somebody
 * who opens the app just after midnight and before the schedule fires.
 *
 * Notifications are sent once per card and only for the strongest of the day — a person
 * who photographs a lot would otherwise be told about eleven memories every morning,
 * which is the fastest way to make them stop reading notifications at all.
 */
class GenerateMemoriesCommand extends Command
{
    protected $signature = 'gallery:memories
        {--space= : Jen jeden prostor podle id}
        {--day= : Datum ve tvaru RRRR-MM-DD, výchozí je dnes}
        {--no-notify : Vygenerovat, ale nikoho neupozorňovat}';

    protected $description = 'Vytvoří vzpomínky pro dnešek a zítřek a upozorní na tu nejsilnější';

    public function handle(MemoryGeneratorService $generator): int
    {
        if (! Schema::hasTable('generated_memories')) {
            $this->error('Chybí tabulka generated_memories. Spusťte migrace.');

            return self::FAILURE;
        }

        $day = $this->option('day') ? Carbon::parse($this->option('day')) : now();

        $spaces = GallerySpace::when($this->option('space'), fn ($query) => $query->whereKey($this->option('space')))->get();

        $made = 0;
        $notified = 0;

        foreach ($spaces as $space) {
            // Today and tomorrow: the tab must already hold something at midnight.
            foreach ([$day, $day->copy()->addDay()] as $target) {
                $made += count($generator->generate($space, $target));
            }

            if (! $this->option('no-notify')) {
                $notified += $this->notify($space, $day);
            }
        }

        $this->info("Prostorů: {$spaces->count()}, vzpomínek: {$made}, upozornění: {$notified}");

        return self::SUCCESS;
    }

    /** One notification per space per day, about the card most worth opening. */
    private function notify(GallerySpace $space, Carbon $day): int
    {
        $best = GeneratedMemory::where('gallery_space_id', $space->id)
            ->whereDate('occurs_on', $day->toDateString())
            ->whereNull('notified_at')
            ->whereNull('dismissed_at')
            ->orderByDesc('score')
            ->first();

        if (! $best) return 0;

        $members = $space->members()->get();
        if ($members->isEmpty()) return 0;

        foreach ($members as $member) {
            $member->notify(new GalleryNotification(
                type: 'memories.ready',
                message: $best->subtitle ? "{$best->title} — {$best->subtitle}" : $best->title,
                link: $best->link ?? '/memories',
                icon: $best->icon ?? '📸',
                extra: ['memory_uuid' => $best->uuid, 'kind' => $best->kind],
            ));
        }

        // Marked before anyone else runs, so a retry cannot notify twice.
        $best->forceFill(['notified_at' => now()])->save();

        return $members->count();
    }
}
