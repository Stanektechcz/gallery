<?php

use App\Http\Controllers\AlbumController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\FavoritesController;
use App\Http\Controllers\GoogleOAuthController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MemoriesController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\StorageRiskController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ── Public ─────────────────────────────────────────────
Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

// Invitation-only registration
Route::get('/invite/{token}', [InvitationController::class, 'show'])->name('invitation.show');
Route::post('/invite/{token}', [InvitationController::class, 'accept'])->name('invitation.accept');

// Password reset
Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'email'])->name('password.email');
Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');

// Public shared links
Route::get('/s/{token}', [ShareController::class, 'show'])->name('share.show');
Route::post('/s/{token}/verify', [ShareController::class, 'verify'])->name('share.verify');
Route::post('/s/{token}/upload', [ShareController::class, 'guestUpload'])->name('share.guest-upload');

// Share Target (PWA Web Share Target)
Route::post('/share-target', [MediaController::class, 'shareTarget'])->name('share-target')
    ->middleware(['auth']);

// ── Authenticated ───────────────────────────────────────
Route::middleware(['auth'])->group(function () {

    // Dashboard / Timeline
    Route::get('/',         [App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');
    Route::get('/home',     [App\Http\Controllers\DashboardController::class, 'index'])->name('home');
    Route::get('/timeline', fn() => Inertia::render('Timeline/Index'))->name('timeline.index');

    // Albums
    Route::prefix('albums')->name('albums.')->group(function () {
        Route::get('/',            [AlbumController::class, 'index'])->name('index');
        Route::post('/',           [AlbumController::class, 'store'])->name('store');
        Route::get('/tree',        [AlbumController::class, 'tree'])->name('tree');
        Route::get('/create',      [AlbumController::class, 'create'])->name('create');
        Route::get('/{uuid}',      [AlbumController::class, 'show'])->name('show');
        Route::patch('/{uuid}',    [AlbumController::class, 'update'])->name('update');
        Route::post('/{uuid}/move', [AlbumController::class, 'move'])->name('move');
        Route::delete('/{uuid}',   [AlbumController::class, 'destroy'])->name('destroy');
    });

    // Media
    Route::prefix('media')->name('media.')->group(function () {
        Route::get('/{uuid}',           [MediaController::class, 'show'])->name('show');
        Route::patch('/{uuid}',         [MediaController::class, 'update'])->name('update');
        Route::delete('/{uuid}',        [MediaController::class, 'trash'])->name('trash');
        Route::post('/{uuid}/restore',  [MediaController::class, 'restore'])->name('restore');
        Route::delete('/{uuid}/purge',  [MediaController::class, 'purge'])->name('purge');
        Route::get('/{uuid}/download',  [MediaController::class, 'download'])->name('download');
        Route::get('/{uuid}/full',      [MediaController::class, 'full'])->name('full');
        Route::get('/{uuid}/stream',    [MediaController::class, 'stream'])->name('stream');
        Route::post('/{uuid}/favorite', [MediaController::class, 'toggleFavorite'])->name('favorite');
        Route::post('/{uuid}/archive',  [MediaController::class, 'archive'])->name('archive');
        Route::post('/{uuid}/edit',     [MediaController::class, 'applyEdit'])->name('edit');
    });

    // Map
    Route::get('/map',      fn() => Inertia::render('Map/Index'))->name('map');

    // Search
    Route::get('/search',   fn() => Inertia::render('Search/Index'))->name('search');

    // Calendar
    Route::get('/calendar', fn() => Inertia::render('Calendar/Index'))->name('calendar');

    // Stats
    Route::get('/stats', [App\Http\Controllers\StatsController::class, 'index'])->name('stats');

    // Inbox (unboxed media)
    Route::get('/inbox', [App\Http\Controllers\InboxController::class, 'index'])->name('inbox');

    // Favorites
    Route::get('/favorites', [FavoritesController::class, 'index'])->name('favorites');
    Route::post('/favorites/{uuid}/toggle', [FavoritesController::class, 'toggle'])->name('favorites.toggle');

    // Trash
    Route::get('/trash', [TrashController::class, 'index'])->name('trash');
    Route::post('/trash/{uuid}/restore', [TrashController::class, 'restore'])->name('trash.restore');
    Route::post('/trash/bulk-restore', [TrashController::class, 'bulkRestore'])->name('trash.bulk-restore');
    Route::delete('/trash/{uuid}/purge', [TrashController::class, 'purge'])->name('trash.purge');
    Route::delete('/trash/empty', [TrashController::class, 'emptyTrash'])->name('trash.empty');

    // Archive
    Route::get('/archive', [ArchiveController::class, 'index'])->name('archive');
    Route::post('/archive/{uuid}/unarchive', [ArchiveController::class, 'unarchive'])->name('archive.unarchive');
    Route::post('/archive/bulk-unarchive', [ArchiveController::class, 'bulkUnarchive'])->name('archive.bulk-unarchive');

    // Memories
    Route::get('/memories', [MemoriesController::class, 'index'])->name('memories');

    // Shared Links
    Route::prefix('shares')->name('shares.')->group(function () {
        Route::get('/',        [ShareController::class, 'index'])->name('index');
        Route::post('/',       [ShareController::class, 'store'])->name('store');
        Route::delete('/{id}', [ShareController::class, 'destroy'])->name('destroy');
    });

    // Settings
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/storage/google', [GoogleOAuthController::class, 'showConnect'])->name('storage.google');
    });

    // Admin only
    Route::middleware(['can:admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/',              [AdminController::class, 'dashboard'])->name('dashboard');
        Route::get('/storage-risk',  [StorageRiskController::class, 'index'])->name('storage-risk');
        Route::get('/users',         [AdminController::class, 'users'])->name('users');
        Route::post('/users/invite', [AdminController::class, 'invite'])->name('users.invite');
        Route::get('/jobs',          [AdminController::class, 'jobs'])->name('jobs');
        Route::get('/audit',         [AdminController::class, 'audit'])->name('audit');
        Route::get('/health',        [AdminController::class, 'health'])->name('health');
    });
});

// ── Google OAuth ────────────────────────────────────────
Route::middleware(['auth'])->group(function () {
    Route::get('/oauth/google/redirect',  [GoogleOAuthController::class, 'redirect'])->name('oauth.google.redirect');
    Route::get('/oauth/google/callback',  [GoogleOAuthController::class, 'callback'])->name('oauth.google.callback');
    Route::post('/settings/storage/google/disconnect', [GoogleOAuthController::class, 'disconnect'])->name('storage.google.disconnect');
    Route::post('/settings/storage/google/reconnect',  [GoogleOAuthController::class, 'reconnect'])->name('storage.google.reconnect');
    Route::post('/settings/storage/google/test',            [GoogleOAuthController::class, 'test'])->name('storage.google.test');
    Route::post('/settings/storage/google/init-structure',  [GoogleOAuthController::class, 'initStructure'])->name('storage.google.init-structure');
});

// ── Google Drive Webhook ────────────────────────────────
Route::post('/webhooks/google-drive', [App\Http\Controllers\Webhooks\GoogleDriveWebhookController::class, 'handle'])
    ->name('webhooks.google-drive');

// ── Health ──────────────────────────────────────────────
Route::get('/health/live',  [App\Http\Controllers\HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [App\Http\Controllers\HealthController::class, 'ready'])->name('health.ready');

// ── Media file proxy (bypasses Apache symlink issues) ───
// Serves files from storage/app/public directly via Laravel
Route::get('/files/{path}', [App\Http\Controllers\MediaFileController::class, 'serve'])
    ->where('path', '.*')
    ->name('media.file');
