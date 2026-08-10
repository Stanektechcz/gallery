<?php

namespace App\Providers;

use App\Models\Album;
use App\Models\MediaItem;
use App\Policies\AlbumPolicy;
use App\Policies\MediaPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request, so its caches are shared by every caller rather than
        // each resolution starting cold and re-reading the plan and add-ons.
        $this->app->singleton(\App\Services\Billing\EntitlementService::class);

        // Also a singleton, and for a sharper reason: its re-entry guard is per instance,
        // and a fresh instance per resolution would let a rule trigger itself.
        $this->app->singleton(\App\Services\Automation\AutomationEngine::class);
    }

    /**
     * Fires the authored automations from the things people actually do.
     *
     * Model events rather than calls scattered through controllers: a rule about a new
     * calendar entry should hold however the entry arrived — the form, an import, the
     * assistant — and one hook is easier to keep honest than six call sites.
     *
     * Nothing here may throw. Each handler is wrapped by the engine, which logs and
     * carries on: an automation that breaks saving a photo is worse than one that
     * silently does not run.
     */
    private function registerAutomationTriggers(): void
    {
        // No table check here. This runs on every request, Schema::hasTable is a query,
        // and the engine already checks before it reads anything — registering a closure
        // costs nothing until something actually fires it.
        $engine = fn () => app(\App\Services\Automation\AutomationEngine::class);

        \App\Models\CalendarEvent::created(function ($event) use ($engine): void {
            $space = \App\Models\GallerySpace::find($event->gallery_space_id);
            if (! $space) return;

            $engine()->fire('event.created', $space, [
                'title' => $event->title,
                'location' => $event->location,
                'days_ahead' => $event->starts_at ? (int) now()->startOfDay()->diffInDays($event->starts_at, false) : null,
            ]);
        });

        \App\Models\MediaItem::created(function ($item) use ($engine): void {
            $space = \App\Models\GallerySpace::find($item->gallery_space_id);
            if (! $space) return;

            $engine()->fire('media.uploaded', $space, [
                'filename' => $item->original_filename,
                'media_type' => $item->media_type,
            ]);
        });

        \App\Models\SharedTodo::updated(function ($todo) use ($engine): void {
            // Only the moment it becomes done — every other save of a finished task
            // would otherwise fire the rule again.
            if (! $todo->wasChanged('status') || $todo->status !== 'done') return;

            $space = \App\Models\GallerySpace::find($todo->gallery_space_id);
            if (! $space) return;

            $engine()->fire('todo.completed', $space, ['title' => $todo->title]);
        });
    }

    public function boot(): void
    {
        $this->registerAutomationTriggers();

        // Register policies
        Gate::policy(Album::class,     AlbumPolicy::class);
        Gate::policy(MediaItem::class, MediaPolicy::class);

        // Admin gate
        Gate::define('admin', fn($user) => $user->isAdmin());
        Gate::define('owner', fn($user) => $user->isOwner());

        // Sanctum token abilities
        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);
    }
}
