<?php

namespace App\Console\Commands;

use App\Models\GallerySpace;
use App\Services\Planning\AutomationRegistryService;
use App\Services\Planning\CalendarEventLifecycleService;
use Illuminate\Console\Command;

class CloseElapsedCalendarEventsCommand extends Command
{
    protected $signature = 'gallery:close-elapsed-events';
    protected $description = 'Automatically complete elapsed calendar plans and their unfinished preparation tasks.';

    public function handle(CalendarEventLifecycleService $lifecycle, AutomationRegistryService $automations): int
    {
        $spaces = GallerySpace::query()->get()->filter(fn (GallerySpace $space) => $automations->enabled($space));
        $completed = $lifecycle->completeElapsedPlans($spaces->pluck('id')->all());
        $spaces->each(fn (GallerySpace $space) => $automations->markRan($space));
        $this->info("Uzavřeno starších akcí: {$completed}.");

        return self::SUCCESS;
    }
}