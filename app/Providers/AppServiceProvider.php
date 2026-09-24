<?php

namespace App\Providers;

use App\Http\Controllers\Api\Galerie\DataController;
use App\Listeners\ZaznamenejBehUlohy;
use App\Models\Album;
use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\PersonalAccessToken;
use App\Models\SharedTodo;
use App\Policies\AlbumPolicy;
use App\Policies\MediaPolicy;
use App\Services\Automation\AutomationEngine;
use App\Services\Billing\EntitlementService;
use App\Services\Obsah\Poskytovatele;
use App\Support\Tabulky;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request, so its caches are shared by every caller rather than
        // each resolution starting cold and re-reading the plan and add-ons.
        $this->app->singleton(EntitlementService::class);

        // Also a singleton, and for a sharper reason: its re-entry guard is per instance,
        // and a fresh instance per resolution would let a rule trigger itself.
        $this->app->singleton(AutomationEngine::class);

        /*
         * Skupiny obsahu, které prototyp kreslí.
         *
         * Přidat další znamená napsat poskytovatele a dopsat ho sem — kontroler
         * ani routa se nemění, takže se nedá zapomenout na půlku.
         */
        $this->app->when(DataController::class)
            ->needs('$poskytovatele')
            ->give(fn () => Poskytovatele::vsichni());
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
        $engine = fn () => app(AutomationEngine::class);

        CalendarEvent::created(function ($event) use ($engine): void {
            $space = GallerySpace::find($event->gallery_space_id);
            if (! $space) {
                return;
            }

            $engine()->fire('event.created', $space, [
                'title' => $event->title,
                'location' => $event->location,
                'days_ahead' => $event->starts_at ? (int) now()->startOfDay()->diffInDays($event->starts_at, false) : null,
            ]);
        });

        MediaItem::created(function ($item) use ($engine): void {
            $space = GallerySpace::find($item->gallery_space_id);
            if (! $space) {
                return;
            }

            $engine()->fire('media.uploaded', $space, [
                'filename' => $item->original_filename,
                'media_type' => $item->media_type,
            ]);
        });

        SharedTodo::updated(function ($todo) use ($engine): void {
            /*
             * Only the moment it becomes done — every other save of a finished task
             * would otherwise fire the rule again.
             *
             * The status this checked for was 'done', which nothing ever writes:
             * SharedTodoService::complete() sets 'completed', and so does every
             * validator in the module. The rule therefore never fired — an
             * automation the couple had switched on quietly did nothing.
             */
            if (! $todo->wasChanged('status') || $todo->status !== 'completed') {
                return;
            }

            $space = GallerySpace::find($todo->gallery_space_id);
            if (! $space) {
                return;
            }

            $engine()->fire('todo.completed', $space, ['title' => $todo->title]);
        });
    }

    /**
     * Zapisuje běhy plánovaných úloh.
     *
     * Aplikace o plánovači dosud věděla jedinou věc — že tepe. Jestli noční
     * záloha proběhla a jak dopadla, se nedalo zjistit odnikud, takže chybující
     * úloha byla přesně ta, o které se člověk dozví měsíc po tom, co přestala
     * fungovat. Administrace to teď ukazuje ze skutečných dat.
     */
    private function registerScheduleLogging(): void
    {
        $listener = ZaznamenejBehUlohy::class;

        Event::listen(ScheduledTaskStarting::class, [$listener, 'zacal']);
        Event::listen(ScheduledTaskFinished::class, [$listener, 'skoncil']);
        /*
         * Úloha na pozadí (`runInBackground`) hlásí skutečný konec až touhle
         * událostí — `ScheduledTaskFinished` u ní přijde hned po odštěpení
         * procesu, s nulovou dobou běhu a bez návratového kódu. Bez tohohle
         * řádku se `gallery:doctor` a vyprazdňování fronty zapisovaly vždy
         * jako úspěšné, i když vracely chybu.
         */
        Event::listen(ScheduledBackgroundTaskFinished::class, [$listener, 'skoncilNaPozadi']);
        Event::listen(ScheduledTaskFailed::class, [$listener, 'selhal']);
        Event::listen(ScheduledTaskSkipped::class, [$listener, 'preskocen']);
    }

    public function boot(): void
    {
        // Paměť o schématu platí jen v rámci běhu — v testech se tím čistí mezi testy.
        Tabulky::zapomen();

        $this->registerAutomationTriggers();
        $this->registerScheduleLogging();

        // Register policies
        Gate::policy(Album::class, AlbumPolicy::class);
        Gate::policy(MediaItem::class, MediaPolicy::class);

        // Admin gate
        Gate::define('admin', fn ($user) => $user->isAdmin());
        Gate::define('owner', fn ($user) => $user->isOwner());

        // Sanctum token abilities
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        /*
         * Nečinný klíč přestane platit.
         *
         * Tokeny neměly žádnou platnost: telefon ztracený před rokem nebo klíč
         * zapomenutý ve starém skriptu otevíraly galerii dál. Pevná expirace by
         * odhlašovala i ty, kdo aplikaci používají denně — počítá se proto od
         * posledního použití (administrace takový klíč už označuje jako nečinný).
         */
        Sanctum::authenticateAccessTokensUsing(function ($token, bool $platny): bool {
            $dni = (int) config('gallery.token_idle_days', 90);
            $naposledy = $token->last_used_at ?? $token->created_at;

            return $platny && ($dni <= 0 || $naposledy === null || $naposledy->gt(now()->subDays($dni)));
        });
    }
}
