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
        //
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
