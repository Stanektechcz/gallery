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
    Route::get('/search/suggestions', [SearchController::class, 'suggestions'])->name('api.search.suggestions');
    Route::get('/travel-data/weather', [App\Http\Controllers\Api\TravelDataController::class, 'weather'])->name('api.travel-data.weather');
    Route::get('/travel-data/exchange-rate', [App\Http\Controllers\Api\TravelDataController::class, 'exchangeRate'])->name('api.travel-data.exchange-rate');
    Route::post('/travel-data/route', [App\Http\Controllers\Api\TravelDataController::class, 'route'])->name('api.travel-data.route');

    // Resumable upload
    Route::prefix('uploads')->name('api.uploads.')->group(function () {
        Route::post('/check-duplicate',              [UploadController::class, 'checkDuplicate'])->name('check-duplicate');
        Route::post('/',                             [UploadController::class, 'initiate'])->name('initiate');
        Route::get('/{uuid}',                        [UploadController::class, 'status'])->name('status');
        Route::put('/{uuid}/chunks/{index}',         [UploadController::class, 'uploadChunk'])->name('chunk');
        Route::post('/{uuid}/complete',              [UploadController::class, 'complete'])->name('complete');
        Route::delete('/{uuid}',                     [UploadController::class, 'cancel'])->name('cancel');
    });

    // Read-only banking and persistent Revolut history
    Route::get('/banking', [App\Http\Controllers\Api\BankingController::class, 'overview'])->name('api.banking.overview');
    Route::get('/banking/institutions', [App\Http\Controllers\Api\BankingController::class, 'institutions'])->name('api.banking.institutions');
    Route::post('/banking/connections', [App\Http\Controllers\Api\BankingController::class, 'connect'])->name('api.banking.connections.store');
    Route::post('/banking/connections/{uuid}/sync', [App\Http\Controllers\Api\BankingController::class, 'sync'])->name('api.banking.connections.sync');
    Route::delete('/banking/connections/{uuid}', [App\Http\Controllers\Api\BankingController::class, 'disconnect'])->name('api.banking.connections.destroy');
    Route::post('/banking/imports', [App\Http\Controllers\Api\BankingController::class, 'import'])->name('api.banking.imports.store');
    Route::post('/banking/rules', [App\Http\Controllers\Api\BankingController::class, 'storeRule'])->name('api.banking.rules.store');
    Route::delete('/banking/rules/{uuid}', [App\Http\Controllers\Api\BankingController::class, 'destroyRule'])->name('api.banking.rules.destroy');
    Route::get('/trips/{tripId}/banking-finance', [App\Http\Controllers\Api\BankingController::class, 'trip'])->name('api.trips.banking-finance');
    Route::patch('/trips/{tripId}/banking-finance/{linkId}', [App\Http\Controllers\Api\BankingController::class, 'updateTripLink'])->name('api.trips.banking-finance.update');

    // Albums API
    Route::get('/album-suggestions', [App\Http\Controllers\Api\AlbumSuggestionController::class, 'index'])->name('api.album-suggestions.index');
    Route::post('/album-suggestions/{fingerprint}/accept', [App\Http\Controllers\Api\AlbumSuggestionController::class, 'accept'])->name('api.album-suggestions.accept');
    Route::post('/album-suggestions/{fingerprint}/dismiss', [App\Http\Controllers\Api\AlbumSuggestionController::class, 'dismiss'])->name('api.album-suggestions.dismiss');
    Route::prefix('albums')->name('api.albums.')->group(function () {
        Route::get('/',         [App\Http\Controllers\AlbumController::class, 'index'])->name('index');
        Route::get('/tree',     [App\Http\Controllers\AlbumController::class, 'tree'])->name('tree');
        Route::get('/{uuid}',   [App\Http\Controllers\AlbumController::class, 'show'])->name('show');

        // Smart album rules management
        Route::get('/{uuid}/smart-rules',    [App\Http\Controllers\Api\SmartAlbumController::class, 'getRules'])->name('smart.rules');
        Route::put('/{uuid}/smart-rules',    [App\Http\Controllers\Api\SmartAlbumController::class, 'updateRules'])->name('smart.update');
        Route::get('/{uuid}/smart-preview',  [App\Http\Controllers\Api\SmartAlbumController::class, 'preview'])->name('smart.preview');

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

        // Explainable selection, partner voting, preview repair and backup health
        Route::get('/{uuid}/curation-assistant',       [App\Http\Controllers\Api\AlbumCurationController::class, 'show'])->name('curation.show');
        Route::put('/{uuid}/cover',                    [App\Http\Controllers\Api\AlbumCurationController::class, 'setCover'])->name('cover.update');
        Route::post('/{uuid}/curation-shortlist',      [App\Http\Controllers\Api\AlbumCurationController::class, 'createShortlist'])->name('curation.shortlist');
        Route::post('/{uuid}/backup',                  [App\Http\Controllers\Api\AlbumCurationController::class, 'syncBackup'])->name('backup.store');
        Route::post('/{uuid}/repair-previews',         [App\Http\Controllers\Api\AlbumCurationController::class, 'repairPreviews'])->name('previews.repair');
    });

    // Media API
    Route::prefix('media')->name('api.media.')->middleware(\App\Http\Middleware\ProtectVaultMedia::class)->group(function () {
        Route::get('/compare',            [App\Http\Controllers\MediaController::class, 'compare'])->name('compare');
        Route::get('/{uuid}/event-suggestions', [App\Http\Controllers\MediaController::class, 'eventSuggestions'])->name('event-suggestions');
        Route::get('/{uuid}',             [App\Http\Controllers\MediaController::class, 'apiShow'])->name('show');
        Route::patch('/{uuid}',           [App\Http\Controllers\MediaController::class, 'update'])->name('update');
        Route::post('/bulk',              [App\Http\Controllers\MediaController::class, 'bulkAction'])->name('bulk');
        Route::get('/{uuid}/reactions',   [App\Http\Controllers\Api\ReactionController::class, 'index'])->name('reactions');
        Route::post('/{uuid}/react',      [App\Http\Controllers\Api\ReactionController::class, 'react'])->name('react');
        Route::get('/{uuid}/ratings',     [App\Http\Controllers\MediaController::class, 'ratings'])->name('ratings');
        Route::get('/{uuid}/comments',    [App\Http\Controllers\Api\CommentController::class, 'index'])->name('comments.index');
        Route::post('/{uuid}/comments',   [App\Http\Controllers\Api\CommentController::class, 'store'])->name('comments.store');
        Route::delete('/{uuid}/comments/{id}', [App\Http\Controllers\Api\CommentController::class, 'destroy'])->name('comments.destroy');
        Route::get('/{uuid}/private-note', [App\Http\Controllers\Api\PrivateMemoryNoteController::class, 'show'])->name('private-note.show');
        Route::put('/{uuid}/private-note', [App\Http\Controllers\Api\PrivateMemoryNoteController::class, 'update'])->name('private-note.update');
        Route::get('/{uuid}/revisit-suggestions', [App\Http\Controllers\Api\RevisitSuggestionController::class, 'show'])->name('revisit-suggestions.show');
        Route::post('/{uuid}/revisit-suggestions', [App\Http\Controllers\Api\RevisitSuggestionController::class, 'schedule'])->name('revisit-suggestions.schedule');
    });

    // Automatic RAW/burst stacks
    Route::get('/media-stacks/preview', [App\Http\Controllers\Api\MediaStackController::class, 'preview'])->name('api.media-stacks.preview');
    Route::post('/media-stacks/apply', [App\Http\Controllers\Api\MediaStackController::class, 'apply'])->name('api.media-stacks.apply');
    Route::get('/media-stacks/{uuid}', [App\Http\Controllers\Api\MediaStackController::class, 'show'])->name('api.media-stacks.show');
    Route::patch('/media-stacks/{uuid}/cover', [App\Http\Controllers\Api\MediaStackController::class, 'setCover'])->name('api.media-stacks.cover');
    Route::delete('/media-stacks/{uuid}', [App\Http\Controllers\Api\MediaStackController::class, 'destroy'])->name('api.media-stacks.destroy');

    // People
    Route::apiResource('people', App\Http\Controllers\Api\PersonController::class)->except(['destroy']);
    Route::delete('people/{id}', [App\Http\Controllers\Api\PersonController::class, 'destroy']);

    // Tags
    Route::apiResource('tags', App\Http\Controllers\Api\TagController::class)->except(['destroy']);
    Route::delete('tags/{id}', [App\Http\Controllers\Api\TagController::class, 'destroy']);

    // Places
    Route::post('places/plan-selection', [App\Http\Controllers\Api\PlaceController::class, 'planSelection'])->name('api.places.plan-selection');
    Route::apiResource('places', App\Http\Controllers\Api\PlaceController::class)->except(['destroy'])->names([
        'index'   => 'api.places.index',
        'store'   => 'api.places.store',
        'show'    => 'api.places.show',
        'update'  => 'api.places.update',
    ]);
    Route::delete('places/{place}',          [App\Http\Controllers\Api\PlaceController::class, 'destroy'])->name('api.places.destroy');
    Route::get('places/{place}/media',       [App\Http\Controllers\Api\PlaceController::class, 'media'])->name('api.places.media');
    Route::get('places/{place}/albums',      [App\Http\Controllers\Api\PlaceController::class, 'albums'])->name('api.places.albums');
    Route::post('places/{place}/auto-link',  [App\Http\Controllers\Api\PlaceController::class, 'autoLink'])->name('api.places.auto-link');
    Route::post('places/{place}/trip-activities', [App\Http\Controllers\Api\PlaceController::class, 'addToTripPlan'])->name('api.places.trip-activities.store');
    Route::post('places/{place}/wishlist-items', [App\Http\Controllers\Api\PlaceController::class, 'addToWishlist'])->name('api.places.wishlist-items.store');
    Route::get('places/{place}/plans', [App\Http\Controllers\Api\PlaceController::class, 'plans'])->name('api.places.plans.index');
    Route::post('places/{place}/plans', [App\Http\Controllers\Api\PlaceController::class, 'storePlan'])->name('api.places.plans.store');
    Route::patch('places/{place}/plans/{uuid}', [App\Http\Controllers\Api\PlaceController::class, 'updatePlan'])->name('api.places.plans.update');
    Route::post('places/{place}/plans/{uuid}/shared-memory', [App\Http\Controllers\Api\PlaceController::class, 'createPlanMemory'])->name('api.places.plans.shared-memory.store');
    Route::get('places/{place}/reviews', [App\Http\Controllers\Api\PlaceReviewController::class, 'index'])->name('api.places.reviews.index');
    Route::post('places/{place}/reviews', [App\Http\Controllers\Api\PlaceReviewController::class, 'store'])->name('api.places.reviews.store');
    Route::put('places/{place}/reviews/{uuid}', [App\Http\Controllers\Api\PlaceReviewController::class, 'update'])->name('api.places.reviews.update');
    Route::delete('places/{place}/reviews/{uuid}', [App\Http\Controllers\Api\PlaceReviewController::class, 'destroy'])->name('api.places.reviews.destroy');
    Route::post('places/{place}/review-album', [App\Http\Controllers\Api\PlaceReviewController::class, 'ensureAlbum'])->name('api.places.review-album.store');

    // Shared recipe book, cooking mode and cooking journal
    Route::get('/recipes', [App\Http\Controllers\Api\RecipeController::class, 'index'])->name('api.recipes.index');
    Route::post('/recipes', [App\Http\Controllers\Api\RecipeController::class, 'store'])->name('api.recipes.store');
    Route::get('/recipes/{uuid}', [App\Http\Controllers\Api\RecipeController::class, 'show'])->name('api.recipes.show');
    Route::put('/recipes/{uuid}', [App\Http\Controllers\Api\RecipeController::class, 'update'])->name('api.recipes.update');
    Route::patch('/recipes/{uuid}/favorite', [App\Http\Controllers\Api\RecipeController::class, 'toggleFavorite'])->name('api.recipes.favorite');
    Route::delete('/recipes/{uuid}', [App\Http\Controllers\Api\RecipeController::class, 'destroy'])->name('api.recipes.destroy');
    Route::get('/recipes/{uuid}/shopping-list', [App\Http\Controllers\Api\RecipeController::class, 'shoppingList'])->name('api.recipes.shopping-list');
    Route::post('/recipes/{uuid}/album', [App\Http\Controllers\Api\RecipeController::class, 'ensureAlbum'])->name('api.recipes.album');
    Route::post('/recipes/{uuid}/media', [App\Http\Controllers\Api\RecipeController::class, 'attachMedia'])->name('api.recipes.media');
    Route::post('/recipes/{uuid}/cooking-sessions/schedule', [App\Http\Controllers\Api\RecipeCookingController::class, 'schedule'])->name('api.recipes.cooking.schedule');
    Route::post('/recipes/{uuid}/cooking-sessions/start', [App\Http\Controllers\Api\RecipeCookingController::class, 'start'])->name('api.recipes.cooking.start');
    Route::put('/recipes/{uuid}/cooking-sessions/{sessionUuid}/complete', [App\Http\Controllers\Api\RecipeCookingController::class, 'complete'])->name('api.recipes.cooking.complete');
    Route::delete('/recipes/{uuid}/cooking-sessions/{sessionUuid}', [App\Http\Controllers\Api\RecipeCookingController::class, 'cancel'])->name('api.recipes.cooking.cancel');
    Route::delete('/planned-meals/{uuid}', [App\Http\Controllers\Api\MealPlanController::class, 'destroy'])->name('api.planned-meals.destroy');

    // One shared coordination layer over calendar, trips, documents, gifts and the planning inbox
    Route::get('/coordination/pulse', [App\Http\Controllers\Api\PartnerCoordinationController::class, 'index'])->name('api.coordination.pulse');
    Route::patch('/coordination/actions/{type}/{key}', [App\Http\Controllers\Api\PartnerCoordinationController::class, 'updateAction'])->name('api.coordination.actions.update');
    Route::put('/coordination/check-in', [App\Http\Controllers\Api\PartnerCoordinationController::class, 'checkIn'])->name('api.coordination.check-in');

    // Shared todo lists are integrated into planning, calendar and the partner pulse.
    Route::get('/todos', [App\Http\Controllers\Api\SharedTodoController::class, 'index'])->name('api.todos.index');
    Route::post('/todo-lists', [App\Http\Controllers\Api\SharedTodoController::class, 'storeList'])->name('api.todo-lists.store');
    Route::patch('/todo-lists/{uuid}', [App\Http\Controllers\Api\SharedTodoController::class, 'updateList'])->name('api.todo-lists.update');
    Route::post('/todos', [App\Http\Controllers\Api\SharedTodoController::class, 'store'])->name('api.todos.store');
    Route::put('/todos/reorder', [App\Http\Controllers\Api\SharedTodoController::class, 'reorder'])->name('api.todos.reorder');
    Route::patch('/todos/{uuid}', [App\Http\Controllers\Api\SharedTodoController::class, 'update'])->name('api.todos.update');
    Route::post('/todos/{uuid}/schedule', [App\Http\Controllers\Api\SharedTodoController::class, 'schedule'])->name('api.todos.schedule');
    Route::post('/todos/{uuid}/comments', [App\Http\Controllers\Api\SharedTodoController::class, 'comment'])->name('api.todos.comments.store');
    Route::delete('/todos/{uuid}', [App\Http\Controllers\Api\SharedTodoController::class, 'destroy'])->name('api.todos.destroy');

    // One shared watchlist with votes, free evenings, cinema showings and calendar events.
    Route::get('/entertainment', [App\Http\Controllers\Api\EntertainmentController::class, 'index'])->name('api.entertainment.index');
    Route::get('/entertainment/search', [App\Http\Controllers\Api\EntertainmentController::class, 'search'])->name('api.entertainment.search');
    Route::post('/entertainment', [App\Http\Controllers\Api\EntertainmentController::class, 'store'])->name('api.entertainment.store');
    Route::post('/entertainment/cinema/sync', [App\Http\Controllers\Api\EntertainmentController::class, 'syncCinema'])->name('api.entertainment.cinema.sync');
    Route::post('/entertainment/cinema/showings/{showingUuid}', [App\Http\Controllers\Api\EntertainmentController::class, 'importShowing'])->name('api.entertainment.cinema.showings.import');
    Route::patch('/entertainment/{uuid}', [App\Http\Controllers\Api\EntertainmentController::class, 'update'])->name('api.entertainment.update');
    Route::put('/entertainment/{uuid}/vote', [App\Http\Controllers\Api\EntertainmentController::class, 'vote'])->name('api.entertainment.vote');
    Route::get('/entertainment/{uuid}/date-suggestions', [App\Http\Controllers\Api\EntertainmentController::class, 'dateSuggestions'])->name('api.entertainment.date-suggestions');
    Route::post('/entertainment/{uuid}/date-proposals', [App\Http\Controllers\Api\EntertainmentController::class, 'proposeDate'])->name('api.entertainment.date-proposals.store');
    Route::post('/entertainment/{uuid}/sessions', [App\Http\Controllers\Api\EntertainmentController::class, 'recordSession'])->name('api.entertainment.sessions.store');
    Route::put('/entertainment/date-proposals/{proposalUuid}/vote', [App\Http\Controllers\Api\EntertainmentController::class, 'voteDate'])->name('api.entertainment.date-proposals.vote');
    Route::post('/entertainment/date-proposals/{proposalUuid}/select', [App\Http\Controllers\Api\EntertainmentController::class, 'selectDate'])->name('api.entertainment.date-proposals.select');

    // Notifications
    Route::get('/notifications', fn() => request()->user()->notifications()->paginate(20));
    Route::post('/notifications/{id}/read', fn(string $id) => request()->user()->notifications()->findOrFail($id)->markAsRead());
    Route::post('/notifications/read-all', fn() => request()->user()->unreadNotifications->markAsRead());

    // Personalized memories and feedback
    Route::get('/memories', [App\Http\Controllers\Api\MemoryController::class, 'index'])->name('api.memories.index');
    Route::post('/memories/interactions', [App\Http\Controllers\Api\MemoryController::class, 'interact'])->name('api.memories.interact');
    Route::get('/memories/preferences', [App\Http\Controllers\Api\MemoryController::class, 'preferences'])->name('api.memories.preferences');
    Route::patch('/memories/preferences', [App\Http\Controllers\Api\MemoryController::class, 'updatePreferences'])->name('api.memories.preferences.update');
    Route::get('/shared-memory-moments', [App\Http\Controllers\Api\SharedMemoryMomentController::class, 'index'])->name('api.shared-memories.index');
    Route::post('/shared-memory-moments', [App\Http\Controllers\Api\SharedMemoryMomentController::class, 'store'])->name('api.shared-memories.store');
    Route::put('/shared-memory-moments/{uuid}/reflection', [App\Http\Controllers\Api\SharedMemoryMomentController::class, 'upsertReflection'])->name('api.shared-memories.reflection.update');
    Route::delete('/shared-memory-moments/{uuid}/reflection', [App\Http\Controllers\Api\SharedMemoryMomentController::class, 'destroyReflection'])->name('api.shared-memories.reflection.destroy');
    Route::delete('/shared-memory-moments/{uuid}', [App\Http\Controllers\Api\SharedMemoryMomentController::class, 'destroy'])->name('api.shared-memories.destroy');

    // Memory ritual: gallery selection -> shared calendar -> partner votes -> album and shared memory.
    Route::get('/memory-evenings', [App\Http\Controllers\Api\MemoryEveningController::class, 'index'])->name('api.memory-evenings.index');
    Route::post('/memory-evenings', [App\Http\Controllers\Api\MemoryEveningController::class, 'store'])->name('api.memory-evenings.store');
    Route::get('/memory-evenings/{uuid}', [App\Http\Controllers\Api\MemoryEveningController::class, 'show'])->name('api.memory-evenings.show');
    Route::post('/memory-evenings/{uuid}/start', [App\Http\Controllers\Api\MemoryEveningController::class, 'start'])->name('api.memory-evenings.start');
    Route::put('/memory-evenings/{uuid}/media/{mediaUuid}', [App\Http\Controllers\Api\MemoryEveningController::class, 'voteMedia'])->name('api.memory-evenings.media.vote');
    Route::put('/memory-evenings/{uuid}/reflection', [App\Http\Controllers\Api\MemoryEveningController::class, 'reflection'])->name('api.memory-evenings.reflection');
    Route::post('/memory-evenings/{uuid}/complete', [App\Http\Controllers\Api\MemoryEveningController::class, 'complete'])->name('api.memory-evenings.complete');
    Route::delete('/memory-evenings/{uuid}', [App\Http\Controllers\Api\MemoryEveningController::class, 'cancel'])->name('api.memory-evenings.cancel');

    // Recovery
    Route::get('/recovery/duplicates',       [App\Http\Controllers\RecoveryController::class, 'findDuplicates'])->name('api.recovery.duplicates');
    Route::get('/recovery/cleanup',          [App\Http\Controllers\RecoveryController::class, 'cleanupSuggestions'])->name('api.recovery.cleanup');
    Route::delete('/recovery/duplicates/trash', [App\Http\Controllers\RecoveryController::class, 'trashDuplicates'])->name('api.recovery.trash');

    // Saved searches
    Route::apiResource('saved-searches', App\Http\Controllers\Api\SavedSearchController::class);

    Route::prefix('relationship-milestones')->name('api.relationship-milestones.')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\RelationshipMilestoneController::class, 'index'])->name('index');
        Route::get('/upcoming', [App\Http\Controllers\Api\RelationshipMilestoneController::class, 'upcoming'])->name('upcoming');
        Route::get('/relationship-anniversary', [App\Http\Controllers\Api\RelationshipAnniversaryController::class, 'show'])->name('relationship-anniversary.show');
        Route::put('/relationship-anniversary', [App\Http\Controllers\Api\RelationshipAnniversaryController::class, 'update'])->name('relationship-anniversary.update');
        Route::get('/relationship-anniversary/recap', [App\Http\Controllers\Api\RelationshipAnniversaryRecapController::class, 'show'])->name('relationship-anniversary.recap.show');
        Route::post('/relationship-anniversary/recap', [App\Http\Controllers\Api\RelationshipAnniversaryRecapController::class, 'store'])->name('relationship-anniversary.recap.store');
        Route::post('/', [App\Http\Controllers\Api\RelationshipMilestoneController::class, 'store'])->name('store');
        Route::post('/{uuid}/celebration', [App\Http\Controllers\Api\RelationshipMilestoneController::class, 'scheduleCelebration'])->name('celebration.store');
        Route::patch('/{uuid}', [App\Http\Controllers\Api\RelationshipMilestoneController::class, 'update'])->name('update');
        Route::delete('/{uuid}', [App\Http\Controllers\Api\RelationshipMilestoneController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('curation-boards')->name('api.curation-boards.')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\CurationBoardController::class, 'index'])->name('index');
        Route::post('/', [App\Http\Controllers\Api\CurationBoardController::class, 'store'])->name('store');
        Route::get('/{uuid}', [App\Http\Controllers\Api\CurationBoardController::class, 'show'])->name('show');
        Route::patch('/{uuid}', [App\Http\Controllers\Api\CurationBoardController::class, 'update'])->name('update');
        Route::delete('/{uuid}', [App\Http\Controllers\Api\CurationBoardController::class, 'destroy'])->name('destroy');
        Route::post('/{uuid}/items', [App\Http\Controllers\Api\CurationBoardController::class, 'addItems'])->name('items.store');
        Route::patch('/{uuid}/items/{itemId}', [App\Http\Controllers\Api\CurationBoardController::class, 'updateItem'])->name('items.update');
        Route::delete('/{uuid}/items/{itemId}', [App\Http\Controllers\Api\CurationBoardController::class, 'removeItem'])->name('items.destroy');
        Route::put('/{uuid}/items/{itemId}/vote', [App\Http\Controllers\Api\CurationBoardController::class, 'vote'])->name('items.vote');
    });

    // Shared calendar, preparation and memories workflow — static routes before {uuid}
    Route::prefix('date-ideas')->name('api.date-ideas.')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\DateIdeaController::class, 'index'])->name('index');
        Route::post('/generate', [App\Http\Controllers\Api\DateIdeaController::class, 'generate'])->name('generate');
        Route::patch('/{uuid}/reaction', [App\Http\Controllers\Api\DateIdeaController::class, 'react'])->name('reaction');
        Route::post('/{uuid}/plan', [App\Http\Controllers\Api\DateIdeaController::class, 'plan'])->name('plan');
    });

    Route::prefix('calendar')->name('api.calendar.')->group(function () {
        Route::get('/events', [App\Http\Controllers\Api\CalendarPlanningController::class, 'index'])->name('events.index');
        Route::post('/events', [App\Http\Controllers\Api\CalendarPlanningController::class, 'store'])->name('events.store');
        Route::post('/ics-import', [App\Http\Controllers\Api\IcsCalendarImportController::class, 'store'])->name('ics.import');
        Route::post('/holiday-plan', [App\Http\Controllers\Api\CalendarPlanningController::class, 'planHoliday'])->name('holiday-plan.store');
        Route::get('/date-ideas', [App\Http\Controllers\Api\CalendarPlanningController::class, 'dateIdeas'])->name('date-ideas');
        Route::get('/shared-slots', [App\Http\Controllers\Api\CalendarPlanningController::class, 'sharedSlots'])->name('shared-slots');
        Route::get('/weekly-overview', [App\Http\Controllers\Api\CalendarPlanningController::class, 'weeklyOverview'])->name('weekly-overview');
        Route::post('/memory-evening', [App\Http\Controllers\Api\CalendarPlanningController::class, 'scheduleMemoryEvening'])->name('memory-evening.store');
        Route::get('/gifts', [App\Http\Controllers\Api\CalendarAutomationController::class, 'gifts'])->name('gifts.index');
        Route::post('/gifts', [App\Http\Controllers\Api\CalendarAutomationController::class, 'storeGift'])->name('gifts.store');
        Route::patch('/gifts/{uuid}', [App\Http\Controllers\Api\CalendarAutomationController::class, 'updateGift'])->name('gifts.update');
        Route::get('/day-note', [App\Http\Controllers\Api\CalendarAutomationController::class, 'dayNote'])->name('day-note.show');
        Route::put('/day-note', [App\Http\Controllers\Api\CalendarAutomationController::class, 'updateDayNote'])->name('day-note.update');
        Route::get('/inbox', [App\Http\Controllers\Api\CalendarPlanningController::class, 'inbox'])->name('inbox.index');
        Route::post('/inbox', [App\Http\Controllers\Api\CalendarPlanningController::class, 'storeInbox'])->name('inbox.store');
        Route::patch('/inbox/{uuid}', [App\Http\Controllers\Api\CalendarPlanningController::class, 'updateInbox'])->name('inbox.update');
        Route::delete('/inbox/{uuid}', [App\Http\Controllers\Api\CalendarPlanningController::class, 'destroyInbox'])->name('inbox.destroy');
        Route::get('/time-capsules', [App\Http\Controllers\Api\CalendarPlanningController::class, 'timeCapsules'])->name('time-capsules.index');
        Route::post('/time-capsules', [App\Http\Controllers\Api\CalendarPlanningController::class, 'storeTimeCapsule'])->name('time-capsules.store');
        Route::post('/push-subscriptions', [App\Http\Controllers\Api\CalendarPlanningController::class, 'storePushSubscription'])->name('push.store');
        Route::delete('/push-subscriptions', [App\Http\Controllers\Api\CalendarPlanningController::class, 'destroyPushSubscription'])->name('push.destroy');
        Route::get('/events/{uuid}', [App\Http\Controllers\Api\CalendarPlanningController::class, 'show'])->name('events.show');
        Route::patch('/events/{uuid}', [App\Http\Controllers\Api\CalendarPlanningController::class, 'update'])->name('events.update');
        Route::delete('/events/{uuid}', [App\Http\Controllers\Api\CalendarPlanningController::class, 'destroy'])->name('events.destroy');
        Route::get('/events/{uuid}/meal-plan', [App\Http\Controllers\Api\MealPlanController::class, 'eventIndex'])->name('events.meal-plan.index');
        Route::post('/events/{uuid}/meal-plan', [App\Http\Controllers\Api\MealPlanController::class, 'eventStore'])->name('events.meal-plan.store');
        Route::patch('/events/{uuid}/meal-shopping/{key}', [App\Http\Controllers\Api\MealPlanController::class, 'eventShopping'])->name('events.meal-shopping.update');
        Route::post('/events/{uuid}/response', [App\Http\Controllers\Api\CalendarPlanningController::class, 'respond'])->name('events.response');
        Route::post('/events/{uuid}/tasks', [App\Http\Controllers\Api\CalendarPlanningController::class, 'storeTask'])->name('tasks.store');
        Route::patch('/events/{uuid}/tasks/{taskId}', [App\Http\Controllers\Api\CalendarPlanningController::class, 'updateTask'])->name('tasks.update');
        Route::delete('/events/{uuid}/tasks/{taskId}', [App\Http\Controllers\Api\CalendarPlanningController::class, 'destroyTask'])->name('tasks.destroy');
        Route::post('/events/{uuid}/attachments', [App\Http\Controllers\Api\CalendarPlanningController::class, 'storeAttachment'])->name('attachments.store');
        Route::delete('/events/{uuid}/attachments/{attachmentId}', [App\Http\Controllers\Api\CalendarPlanningController::class, 'destroyAttachment'])->name('attachments.destroy');
        Route::get('/events/{uuid}/media-suggestions', [App\Http\Controllers\Api\CalendarPlanningController::class, 'mediaSuggestions'])->name('media-suggestions');
        Route::post('/events/{uuid}/media-suggestions', [App\Http\Controllers\Api\CalendarPlanningController::class, 'applyMediaSuggestions'])->name('media-suggestions.apply');
        Route::post('/events/{uuid}/shared-memory', [App\Http\Controllers\Api\CalendarPlanningController::class, 'createSharedMemory'])->name('shared-memory.store');
        Route::get('/events/{uuid}/reflection', [App\Http\Controllers\Api\CalendarPlanningController::class, 'reflection'])->name('reflection.show');
        Route::put('/events/{uuid}/reflection', [App\Http\Controllers\Api\CalendarPlanningController::class, 'updateReflection'])->name('reflection.update');
        Route::post('/events/{uuid}/revisit', [App\Http\Controllers\Api\CalendarPlanningController::class, 'scheduleRevisit'])->name('revisit.store');
        Route::post('/events/{uuid}/trip', [App\Http\Controllers\Api\CalendarPlanningController::class, 'createTrip'])->name('trip.store');
        Route::post('/events/{uuid}/story', [App\Http\Controllers\Api\CalendarPlanningController::class, 'story'])->name('story');

        // Personal and shared planning tools
        Route::get('/availability', [App\Http\Controllers\Api\PlanningExpansionController::class, 'availability'])->name('availability');
        Route::put('/availability', [App\Http\Controllers\Api\PlanningExpansionController::class, 'updateAvailability'])->name('availability.update');
        Route::get('/templates', [App\Http\Controllers\Api\PlanningExpansionController::class, 'templates'])->name('templates.index');
        Route::post('/templates', [App\Http\Controllers\Api\PlanningExpansionController::class, 'storeTemplate'])->name('templates.store');
        Route::post('/templates/{uuid}/apply', [App\Http\Controllers\Api\PlanningExpansionController::class, 'applyTemplate'])->name('templates.apply');
        Route::get('/wishlists', [App\Http\Controllers\Api\PlanningExpansionController::class, 'wishlists'])->name('wishlists.index');
        Route::post('/wishlists', [App\Http\Controllers\Api\PlanningExpansionController::class, 'storeWishlist'])->name('wishlists.store');
        Route::post('/wishlists/{uuid}/items', [App\Http\Controllers\Api\PlanningExpansionController::class, 'storeWishlistItem'])->name('wishlists.items.store');
        Route::get('/wishlists/{uuid}/suggestions', [App\Http\Controllers\Api\PlanningExpansionController::class, 'wishlistSuggestions'])->name('wishlists.suggestions');
        Route::post('/wishlists/{uuid}/items/{itemId}/plan', [App\Http\Controllers\Api\PlanningExpansionController::class, 'planWishlistItem'])->name('wishlists.items.plan');
        Route::get('/polls', [App\Http\Controllers\Api\PlanningExpansionController::class, 'polls'])->name('polls.index');
        Route::post('/polls', [App\Http\Controllers\Api\PlanningExpansionController::class, 'storePoll'])->name('polls.store');
        Route::post('/polls/{uuid}/vote', [App\Http\Controllers\Api\PlanningExpansionController::class, 'vote'])->name('polls.vote');
        Route::post('/polls/{uuid}/options/{optionId}/plan', [App\Http\Controllers\Api\PlanningExpansionController::class, 'planPollOption'])->name('polls.options.plan');
        Route::get('/partner-rules', [App\Http\Controllers\Api\PlanningExpansionController::class, 'partnerRules'])->name('partner-rules.index');
        Route::post('/partner-rules', [App\Http\Controllers\Api\PlanningExpansionController::class, 'storePartnerRule'])->name('partner-rules.store');
        Route::get('/partner-rules/{uuid}/preview', [App\Http\Controllers\Api\PlanningExpansionController::class, 'previewPartnerRule'])->name('partner-rules.preview');
        Route::get('/events/{uuid}/exceptions', [App\Http\Controllers\Api\PlanningExpansionController::class, 'exceptions'])->name('exceptions.index');
        Route::post('/events/{uuid}/exceptions', [App\Http\Controllers\Api\PlanningExpansionController::class, 'storeException'])->name('exceptions.store');
        Route::get('/events/{uuid}/ics', [App\Http\Controllers\Api\PlanningExpansionController::class, 'exportIcs'])->name('events.ics');
    });

    Route::prefix('trips/{tripId}')->name('api.trip-planning.')->group(function () {
        Route::get('/planning', [App\Http\Controllers\Api\CalendarPlanningController::class, 'tripPlanning'])->name('index');
        Route::get('/meal-plan', [App\Http\Controllers\Api\MealPlanController::class, 'tripIndex'])->name('meal-plan.index');
        Route::post('/meal-plan', [App\Http\Controllers\Api\MealPlanController::class, 'tripStore'])->name('meal-plan.store');
        Route::patch('/meal-shopping/{key}', [App\Http\Controllers\Api\MealPlanController::class, 'tripShopping'])->name('meal-shopping.update');
        Route::get('/travel-choices', [App\Http\Controllers\Api\TripTravelController::class, 'choices'])->name('travel-choices.index');
        Route::post('/booking-search', [App\Http\Controllers\Api\TripTravelController::class, 'bookingSearch'])->name('booking-search');
        Route::post('/travel-choices/transport', [App\Http\Controllers\Api\TripTravelController::class, 'storeTransport'])->name('travel-choices.transport');
        Route::post('/travel-choices/accommodation', [App\Http\Controllers\Api\TripTravelController::class, 'storeAccommodation'])->name('travel-choices.accommodation');
        Route::post('/expenses', [App\Http\Controllers\Api\CalendarPlanningController::class, 'storeExpense'])->name('expenses.store');
        Route::delete('/expenses/{expenseId}', [App\Http\Controllers\Api\CalendarPlanningController::class, 'destroyExpense'])->name('expenses.destroy');
        Route::post('/route-variants', [App\Http\Controllers\Api\CalendarPlanningController::class, 'storeRouteVariant'])->name('variants.store');
        Route::post('/route-variants/{variantId}/select', [App\Http\Controllers\Api\CalendarPlanningController::class, 'selectRouteVariant'])->name('variants.select');
        Route::get('/emergency-card', [App\Http\Controllers\Api\PlanningExpansionController::class, 'emergencyCard'])->name('emergency-card');
        Route::put('/emergency-card', [App\Http\Controllers\Api\PlanningExpansionController::class, 'updateEmergencyCard'])->name('emergency-card.update');
        Route::get('/readiness', [App\Http\Controllers\Api\TripIntelligenceController::class, 'readiness'])->name('readiness');
        Route::get('/preparation-timeline', [App\Http\Controllers\Api\TripIntelligenceController::class, 'preparationTimeline'])->name('preparation-timeline');
        Route::post('/preparation-timeline/sync', [App\Http\Controllers\Api\TripIntelligenceController::class, 'syncPreparationTimeline'])->name('preparation-timeline.sync');
        Route::get('/budget-advisor', [App\Http\Controllers\Api\TripIntelligenceController::class, 'budgetAdvisor'])->name('budget-advisor');
        Route::put('/budget-plan', [App\Http\Controllers\Api\TripIntelligenceController::class, 'updateBudgetPlan'])->name('budget-plan.update');
        Route::put('/budget-limits', [App\Http\Controllers\Api\TripIntelligenceController::class, 'upsertBudgetLimit'])->name('budget-limits.upsert');
        Route::post('/documents', [App\Http\Controllers\Api\TripIntelligenceController::class, 'storeDocument'])->name('documents.store');
        Route::get('/reservation-imports', [App\Http\Controllers\Api\TripReservationController::class, 'index'])->name('reservation-imports.index');
        Route::post('/reservation-imports', [App\Http\Controllers\Api\TripReservationController::class, 'store'])->name('reservation-imports.store');
        Route::put('/reservation-imports/{uuid}/confirm', [App\Http\Controllers\Api\TripReservationController::class, 'confirm'])->name('reservation-imports.confirm');
        Route::get('/reservation-imports/{uuid}/download', [App\Http\Controllers\Api\TripReservationController::class, 'download'])->name('reservation-imports.download');
        Route::delete('/reservation-imports/{uuid}', [App\Http\Controllers\Api\TripReservationController::class, 'destroy'])->name('reservation-imports.destroy');
        Route::post('/settlements', [App\Http\Controllers\Api\TripIntelligenceController::class, 'proposeSettlement'])->name('settlements.store');
        Route::post('/settlements/{settlementId}/settle', [App\Http\Controllers\Api\TripIntelligenceController::class, 'settle'])->name('settlements.settle');
        Route::get('/finance-summary', [App\Http\Controllers\Api\TripIntelligenceController::class, 'financeSummary'])->name('finance-summary');
        Route::put('/savings-goal', [App\Http\Controllers\Api\TripIntelligenceController::class, 'upsertSavingsGoal'])->name('savings-goal.upsert');
        Route::post('/location-consent', [App\Http\Controllers\Api\TripIntelligenceController::class, 'locationConsent'])->name('location-consent.store');
        Route::post('/track-points', [App\Http\Controllers\Api\TripIntelligenceController::class, 'storeTrackPoint'])->name('track-points.store');
        Route::get('/packing-items', [App\Http\Controllers\Api\TripIntelligenceController::class, 'packingItems'])->name('packing.index');
        Route::get('/packing-members', [App\Http\Controllers\Api\TripIntelligenceController::class, 'packingMembers'])->name('packing.members');
        Route::post('/packing-items', [App\Http\Controllers\Api\TripIntelligenceController::class, 'storePackingItem'])->name('packing.store');
        Route::patch('/packing-items/{itemId}', [App\Http\Controllers\Api\TripIntelligenceController::class, 'updatePackingItem'])->name('packing.update');
        Route::delete('/packing-items/{itemId}', [App\Http\Controllers\Api\TripIntelligenceController::class, 'destroyPackingItem'])->name('packing.destroy');
        Route::post('/packing-items/apply-template', [App\Http\Controllers\Api\TripIntelligenceController::class, 'applyPackingTemplate'])->name('packing.template');
        Route::get('/vehicle-costs', [App\Http\Controllers\Api\TripIntelligenceController::class, 'vehicleCosts'])->name('vehicle-costs.index');
        Route::post('/vehicle-costs', [App\Http\Controllers\Api\TripIntelligenceController::class, 'storeVehicleCost'])->name('vehicle-costs.store');
        Route::patch('/vehicle-costs/{costId}', [App\Http\Controllers\Api\TripIntelligenceController::class, 'updateVehicleCost'])->name('vehicle-costs.update');
        Route::delete('/vehicle-costs/{costId}', [App\Http\Controllers\Api\TripIntelligenceController::class, 'destroyVehicleCost'])->name('vehicle-costs.destroy');
        Route::get('/offline-package', [App\Http\Controllers\Api\TripIntelligenceController::class, 'offlinePackage'])->name('offline-package');
    });

    Route::get('/transport-routes', [App\Http\Controllers\Api\TripIntelligenceController::class, 'savedTransportRoutes'])->name('api.transport-routes.index');
    Route::post('/transport-routes', [App\Http\Controllers\Api\TripIntelligenceController::class, 'saveTransportRoute'])->name('api.transport-routes.store');
    Route::post('/currency-rates', [App\Http\Controllers\Api\TripIntelligenceController::class, 'storeCurrencyRate'])->name('api.currency-rates.store');

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
    Route::get('/itinerary/{id}/photos',    [App\Http\Controllers\Api\ItineraryController::class, 'placePhotos'])->name('api.itinerary.photos');
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
    Route::get('/trips/{id}/plan',                   [App\Http\Controllers\Api\TripPlanController::class, 'show'])->name('api.trips.plan');
    Route::get('/trips/{id}/now',                    [App\Http\Controllers\Api\TripPlanController::class, 'now'])->name('api.trips.now');
    Route::post('/trips/{id}/journal',               [App\Http\Controllers\Api\TripPlanController::class, 'addJournalEntry'])->name('api.trips.journal.add');
    Route::post('/trips/{id}/journal-recordings',    [App\Http\Controllers\Api\TripJournalRecordingController::class, 'store'])->name('api.trips.journal.recordings.store');
    Route::get('/trips/{id}/journal/{entryId}/recording', [App\Http\Controllers\Api\TripJournalRecordingController::class, 'show'])->name('api.trips.journal.recordings.show');
    Route::patch('/trips/{id}/journal/{entryId}',    [App\Http\Controllers\Api\TripPlanController::class, 'updateJournalEntry'])->name('api.trips.journal.update');
    Route::delete('/trips/{id}/journal/{entryId}',   [App\Http\Controllers\Api\TripPlanController::class, 'removeJournalEntry'])->name('api.trips.journal.remove');
    Route::patch('/trips/{id}/plan/days/{dayId}',    [App\Http\Controllers\Api\TripPlanController::class, 'updateDay'])->name('api.trips.plan.days.update');
    Route::post('/trips/{id}/plan/days/{dayId}/activities', [App\Http\Controllers\Api\TripPlanController::class, 'addActivity'])->name('api.trips.plan.activities.add');
    Route::post('/trips/{id}/plan/days/{dayId}/inbox/{uuid}/promote', [App\Http\Controllers\Api\TripPlanController::class, 'promoteInboxItem'])->name('api.trips.plan.inbox.promote');
    Route::put('/trips/{id}/plan/days/{dayId}/activities/reorder', [App\Http\Controllers\Api\TripPlanController::class, 'reorderActivities'])->name('api.trips.plan.activities.reorder');
    Route::patch('/trips/{id}/plan/activities/{activityId}', [App\Http\Controllers\Api\TripPlanController::class, 'updateActivity'])->name('api.trips.plan.activities.update');
    Route::delete('/trips/{id}/plan/activities/{activityId}', [App\Http\Controllers\Api\TripPlanController::class, 'removeActivity'])->name('api.trips.plan.activities.remove');
    Route::get('/trips/{id}/media',                  [App\Http\Controllers\Api\TripController::class, 'media'])->name('api.trips.media');
    Route::post('/trips/{id}/media',                 [App\Http\Controllers\Api\TripController::class, 'addMedia'])->name('api.trips.add-media');
    Route::delete('/trips/{id}/media/{mediaId}',     [App\Http\Controllers\Api\TripController::class, 'removeMedia'])->name('api.trips.remove-media');
    Route::post('/trips/{id}/shared-memory',         [App\Http\Controllers\Api\TripController::class, 'createSharedMemory'])->name('api.trips.shared-memory.store');
    Route::get('/trips/{id}/recap-album',            [App\Http\Controllers\Api\TripController::class, 'recapAlbum'])->name('api.trips.recap-album.show');
    Route::post('/trips/{id}/recap-album',           [App\Http\Controllers\Api\TripController::class, 'createRecapAlbum'])->name('api.trips.recap-album.store');
    Route::get('/trips/{id}/reflection',             [App\Http\Controllers\Api\TripController::class, 'reflection'])->name('api.trips.reflection');
    Route::put('/trips/{id}/reflection',             [App\Http\Controllers\Api\TripController::class, 'upsertReflection'])->name('api.trips.reflection.upsert');
    Route::post('/trips/{id}/revisit',               [App\Http\Controllers\Api\TripController::class, 'scheduleRevisit'])->name('api.trips.revisit.store');
    Route::put('/trips/{id}/waypoints/reorder',      [App\Http\Controllers\Api\TripController::class, 'reorderWaypoints'])->name('api.trips.waypoints.reorder');
    Route::post('/trips/{id}/waypoints',             [App\Http\Controllers\Api\TripController::class, 'addWaypoint'])->name('api.trips.waypoints.add');
    Route::patch('/trips/{id}/waypoints/{wpId}',     [App\Http\Controllers\Api\TripController::class, 'updateWaypoint'])->name('api.trips.waypoints.update');
    Route::delete('/trips/{id}/waypoints/{wpId}',    [App\Http\Controllers\Api\TripController::class, 'removeWaypoint'])->name('api.trips.waypoints.remove');
    Route::get('/trips/route-distance',              [App\Http\Controllers\Api\TripController::class, 'routeDistance'])->name('api.trips.route-distance');
    Route::get('/trips/transport-prices',            [App\Http\Controllers\Api\TripController::class, 'transportPrices'])->name('api.trips.transport-prices');
    Route::get('/trips',                             [App\Http\Controllers\Api\TripController::class, 'index'])->name('api.trips.index');

    // Unified ticket search (RegioJet + FlixBus + IDOS)
    Route::get('/tickets/search',                    [App\Http\Controllers\Api\TicketController::class, 'search'])->name('api.tickets.search');
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
    Route::get('/guest-uploads', [App\Http\Controllers\Api\GuestUploadController::class, 'index'])->name('api.guest-uploads.index');
    Route::post('/guest-uploads/{uuid}/approve', [App\Http\Controllers\Api\GuestUploadController::class, 'approve'])->name('api.guest-uploads.approve');
    Route::post('/guest-uploads/{uuid}/reject', [App\Http\Controllers\Api\GuestUploadController::class, 'reject'])->name('api.guest-uploads.reject');
});
