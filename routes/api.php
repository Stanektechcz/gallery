<?php

use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\TimelineController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {

    // Timeline
    Route::prefix('timeline')->name('api.timeline.')->group(function () {
        Route::get('/',         [TimelineController::class, 'index'])->name('index');
        Route::get('/buckets',  [TimelineController::class, 'buckets'])->name('buckets');
        Route::get('/map',      [TimelineController::class, 'mapPoints'])->name('map');
        Route::get('/memories', [TimelineController::class, 'memories'])->name('memories');
        Route::get('/calendar', [TimelineController::class, 'calendar'])->name('calendar');
    });

    // Search
    Route::get('/search', [SearchController::class, 'search'])->name('api.search');

    // Resumable upload
    Route::prefix('uploads')->name('api.uploads.')->group(function () {
        Route::post('/',                             [UploadController::class, 'initiate'])->name('initiate');
        Route::get('/{uuid}',                        [UploadController::class, 'status'])->name('status');
        Route::put('/{uuid}/chunks/{index}',         [UploadController::class, 'uploadChunk'])->name('chunk');
        Route::post('/{uuid}/complete',              [UploadController::class, 'complete'])->name('complete');
        Route::delete('/{uuid}',                     [UploadController::class, 'cancel'])->name('cancel');
    });

    // Albums API
    Route::prefix('albums')->name('api.albums.')->group(function () {
        Route::get('/',         [App\Http\Controllers\AlbumController::class, 'index'])->name('index');
        Route::get('/tree',     [App\Http\Controllers\AlbumController::class, 'tree'])->name('tree');
        Route::get('/{uuid}',   [App\Http\Controllers\AlbumController::class, 'show'])->name('show');
    });

    // Media API
    Route::prefix('media')->name('api.media.')->group(function () {
        Route::get('/{uuid}',           [App\Http\Controllers\MediaController::class, 'apiShow'])->name('show');
        Route::patch('/{uuid}',         [App\Http\Controllers\MediaController::class, 'update'])->name('update');
        Route::post('/bulk',            [App\Http\Controllers\MediaController::class, 'bulkAction'])->name('bulk');
    });

    // People
    Route::apiResource('people', App\Http\Controllers\Api\PersonController::class)->except(['destroy']);
    Route::delete('people/{id}', [App\Http\Controllers\Api\PersonController::class, 'destroy']);

    // Tags
    Route::apiResource('tags', App\Http\Controllers\Api\TagController::class)->except(['destroy']);
    Route::delete('tags/{id}', [App\Http\Controllers\Api\TagController::class, 'destroy']);

    // Places
    Route::apiResource('places', App\Http\Controllers\Api\PlaceController::class);

    // Notifications
    Route::get('/notifications', fn() => request()->user()->notifications()->paginate(20));
    Route::post('/notifications/{id}/read', fn(string $id) => request()->user()->notifications()->findOrFail($id)->markAsRead());
    Route::post('/notifications/read-all', fn() => request()->user()->unreadNotifications->markAsRead());

    // Saved searches
    Route::apiResource('saved-searches', App\Http\Controllers\Api\SavedSearchController::class);

    // Export
    Route::post('/exports', [App\Http\Controllers\Api\ExportController::class, 'create'])->name('api.exports.create');
    Route::get('/exports/{id}', [App\Http\Controllers\Api\ExportController::class, 'status'])->name('api.exports.status');
    Route::get('/exports/{id}/download', [App\Http\Controllers\Api\ExportController::class, 'download'])->name('api.exports.download');

    // Favorites API (Sanctum stateful — works from browser Axios)
    Route::post('/favorites/{uuid}/toggle', [App\Http\Controllers\FavoritesController::class, 'toggle'])->name('api.favorites.toggle');

    // Trash API
    Route::post('/trash/{uuid}/restore', [App\Http\Controllers\TrashController::class, 'restore'])->name('api.trash.restore');
    Route::post('/trash/bulk-restore',   [App\Http\Controllers\TrashController::class, 'bulkRestore'])->name('api.trash.bulk-restore');
    Route::delete('/trash/{uuid}/purge', [App\Http\Controllers\TrashController::class, 'purge'])->name('api.trash.purge');
    Route::delete('/trash/empty',        [App\Http\Controllers\TrashController::class, 'emptyTrash'])->name('api.trash.empty');

    // Archive API
    Route::post('/archive/{uuid}/unarchive', [App\Http\Controllers\ArchiveController::class, 'unarchive'])->name('api.archive.unarchive');
    Route::post('/archive/bulk-unarchive',   [App\Http\Controllers\ArchiveController::class, 'bulkUnarchive'])->name('api.archive.bulk-unarchive');

    // Shares API
    Route::get('/shares',       [App\Http\Controllers\ShareController::class, 'index'])->name('api.shares.index');
    Route::post('/shares',      [App\Http\Controllers\ShareController::class, 'store'])->name('api.shares.store');
    Route::delete('/shares/{id}', [App\Http\Controllers\ShareController::class, 'destroy'])->name('api.shares.destroy');
});
