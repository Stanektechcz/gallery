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
    }

    public function boot(): void
    {
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
