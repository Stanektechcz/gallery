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
        Route::post('/check-duplicate',              [UploadController::class, 'checkDuplicate'])->name('check-duplicate');
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

        // Album story blocks
        Route::get('/{uuid}/story',                    [App\Http\Controllers\Api\AlbumStoryController::class, 'index'])->name('story.index');
        Route::post('/{uuid}/story',                   [App\Http\Controllers\Api\AlbumStoryController::class, 'store'])->name('story.store');
        Route::put('/{uuid}/story/reorder',            [App\Http\Controllers\Api\AlbumStoryController::class, 'reorder'])->name('story.reorder');
        Route::patch('/{uuid}/story/{blockId}',        [App\Http\Controllers\Api\AlbumStoryController::class, 'update'])->name('story.update');
        Route::delete('/{uuid}/story/{blockId}',       [App\Http\Controllers\Api\AlbumStoryController::class, 'destroy'])->name('story.destroy');
        Route::patch('/{uuid}/story-mode',             [App\Http\Controllers\Api\AlbumStoryController::class, 'toggleStoryMode'])->name('story-mode');

        // Album event mode
        Route::get('/{uuid}/event',                    [App\Http\Controllers\Api\AlbumEventController::class, 'show'])->name('event.show');
        Route::patch('/{uuid}/event',                  [App\Http\Controllers\Api\AlbumEventController::class, 'update'])->name('event.update');
        Route::get('/{uuid}/event-media',              [App\Http\Controllers\Api\AlbumEventController::class, 'detectMedia'])->name('event.detect');
        Route::post('/{uuid}/event-collect',           [App\Http\Controllers\Api\AlbumEventController::class, 'collect'])->name('event.collect');
    });

    // Media API
    Route::prefix('media')->name('api.media.')->group(function () {
        Route::get('/compare',            [App\Http\Controllers\MediaController::class, 'compare'])->name('compare');
        Route::get('/{uuid}',             [App\Http\Controllers\MediaController::class, 'apiShow'])->name('show');
        Route::patch('/{uuid}',           [App\Http\Controllers\MediaController::class, 'update'])->name('update');
        Route::post('/bulk',              [App\Http\Controllers\MediaController::class, 'bulkAction'])->name('bulk');
        Route::get('/{uuid}/reactions',   [App\Http\Controllers\Api\ReactionController::class, 'index'])->name('reactions');
        Route::post('/{uuid}/react',      [App\Http\Controllers\Api\ReactionController::class, 'react'])->name('react');
        Route::get('/{uuid}/ratings',     [App\Http\Controllers\MediaController::class, 'ratings'])->name('ratings');
        Route::get('/{uuid}/comments',    [App\Http\Controllers\Api\CommentController::class, 'index'])->name('comments.index');
        Route::post('/{uuid}/comments',   [App\Http\Controllers\Api\CommentController::class, 'store'])->name('comments.store');
        Route::delete('/{uuid}/comments/{id}', [App\Http\Controllers\Api\CommentController::class, 'destroy'])->name('comments.destroy');
    });

    // People
    Route::apiResource('people', App\Http\Controllers\Api\PersonController::class)->except(['destroy']);
    Route::delete('people/{id}', [App\Http\Controllers\Api\PersonController::class, 'destroy']);

    // Tags
    Route::apiResource('tags', App\Http\Controllers\Api\TagController::class)->except(['destroy']);
    Route::delete('tags/{id}', [App\Http\Controllers\Api\TagController::class, 'destroy']);

    // Places
    Route::apiResource('places', App\Http\Controllers\Api\PlaceController::class)->except(['destroy']);
    Route::delete('places/{place}',          [App\Http\Controllers\Api\PlaceController::class, 'destroy']);
    Route::get('places/{place}/media',       [App\Http\Controllers\Api\PlaceController::class, 'media'])->name('api.places.media');
    Route::get('places/{place}/albums',      [App\Http\Controllers\Api\PlaceController::class, 'albums'])->name('api.places.albums');
    Route::post('places/{place}/auto-link',  [App\Http\Controllers\Api\PlaceController::class, 'autoLink'])->name('api.places.auto-link');

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

    // Journey (Naše cesta) — static routes before {id} wildcard
    Route::get('/journey/auto-suggest',  [App\Http\Controllers\Api\JourneyController::class, 'autoSuggest'])->name('api.journey.auto-suggest');
    Route::post('/journey/auto-import',  [App\Http\Controllers\Api\JourneyController::class, 'autoImport'])->name('api.journey.auto-import');
    Route::get('/journey',               [App\Http\Controllers\Api\JourneyController::class, 'index'])->name('api.journey.index');
    Route::post('/journey',              [App\Http\Controllers\Api\JourneyController::class, 'store'])->name('api.journey.store');
    Route::patch('/journey/{id}',        [App\Http\Controllers\Api\JourneyController::class, 'update'])->name('api.journey.update');
    Route::delete('/journey/{id}',       [App\Http\Controllers\Api\JourneyController::class, 'destroy'])->name('api.journey.destroy');
    Route::get('/journey/{id}/photos',   [App\Http\Controllers\Api\JourneyController::class, 'photos'])->name('api.journey.photos');

    // Itinerary (světový itinerář) — static routes before {id} wildcard
    Route::get('/itinerary/search',         [App\Http\Controllers\Api\ItineraryController::class, 'search'])->name('api.itinerary.search');
    Route::post('/itinerary/check-visited', [App\Http\Controllers\Api\ItineraryController::class, 'checkVisited'])->name('api.itinerary.check-visited');
    Route::get('/itinerary',                [App\Http\Controllers\Api\ItineraryController::class, 'index'])->name('api.itinerary.index');
    Route::post('/itinerary',               [App\Http\Controllers\Api\ItineraryController::class, 'store'])->name('api.itinerary.store');
    Route::patch('/itinerary/{id}',         [App\Http\Controllers\Api\ItineraryController::class, 'update'])->name('api.itinerary.update');
    Route::delete('/itinerary/{id}',        [App\Http\Controllers\Api\ItineraryController::class, 'destroy'])->name('api.itinerary.destroy');

    // Photo books (Fotokniha / výběry k tisku)
    Route::prefix('books')->name('api.books.')->group(function () {
        Route::get('/',                          [App\Http\Controllers\Api\PhotoBookController::class, 'index'])->name('index');
        Route::post('/',                         [App\Http\Controllers\Api\PhotoBookController::class, 'store'])->name('store');
        Route::get('/{uuid}',                    [App\Http\Controllers\Api\PhotoBookController::class, 'show'])->name('show');
        Route::patch('/{uuid}',                  [App\Http\Controllers\Api\PhotoBookController::class, 'update'])->name('update');
        Route::delete('/{uuid}',                 [App\Http\Controllers\Api\PhotoBookController::class, 'destroy'])->name('destroy');
        Route::post('/{uuid}/items',             [App\Http\Controllers\Api\PhotoBookController::class, 'addItems'])->name('items.add');
        Route::delete('/{uuid}/items/{itemId}',  [App\Http\Controllers\Api\PhotoBookController::class, 'removeItem'])->name('items.remove');
        Route::put('/{uuid}/items/reorder',      [App\Http\Controllers\Api\PhotoBookController::class, 'reorder'])->name('items.reorder');
        Route::get('/{uuid}/export/zip',         [App\Http\Controllers\Api\PhotoBookController::class, 'exportZip'])->name('export.zip');
        Route::get('/{uuid}/export/filelist',    [App\Http\Controllers\Api\PhotoBookController::class, 'exportFileList'])->name('export.filelist');
        Route::get('/{uuid}/export/contact',     [App\Http\Controllers\Api\PhotoBookController::class, 'contactSheetData'])->name('export.contact');
    });

    // Trips (Cesty a výlety) — static sub-routes first
    Route::get('/trips/{id}/suggest-media',          [App\Http\Controllers\Api\TripController::class, 'suggestMedia'])->name('api.trips.suggest-media');
    Route::get('/trips/{id}/media',                  [App\Http\Controllers\Api\TripController::class, 'media'])->name('api.trips.media');
    Route::post('/trips/{id}/media',                 [App\Http\Controllers\Api\TripController::class, 'addMedia'])->name('api.trips.add-media');
    Route::delete('/trips/{id}/media/{mediaId}',     [App\Http\Controllers\Api\TripController::class, 'removeMedia'])->name('api.trips.remove-media');
    Route::put('/trips/{id}/waypoints/reorder',      [App\Http\Controllers\Api\TripController::class, 'reorderWaypoints'])->name('api.trips.waypoints.reorder');
    Route::post('/trips/{id}/waypoints',             [App\Http\Controllers\Api\TripController::class, 'addWaypoint'])->name('api.trips.waypoints.add');
    Route::delete('/trips/{id}/waypoints/{wpId}',    [App\Http\Controllers\Api\TripController::class, 'removeWaypoint'])->name('api.trips.waypoints.remove');
    Route::get('/trips',                             [App\Http\Controllers\Api\TripController::class, 'index'])->name('api.trips.index');
    Route::post('/trips',                            [App\Http\Controllers\Api\TripController::class, 'store'])->name('api.trips.store');
    Route::get('/trips/{id}',                        [App\Http\Controllers\Api\TripController::class, 'show'])->name('api.trips.show');
    Route::patch('/trips/{id}',                      [App\Http\Controllers\Api\TripController::class, 'update'])->name('api.trips.update');
    Route::delete('/trips/{id}',                     [App\Http\Controllers\Api\TripController::class, 'destroy'])->name('api.trips.destroy');

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
