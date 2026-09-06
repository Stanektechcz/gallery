<?php

use App\Http\Controllers\AlbumController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AlbumCurationController;
use App\Http\Controllers\Api\AlbumEventController;
use App\Http\Controllers\Api\AlbumStoryController;
use App\Http\Controllers\Api\AlbumSuggestionController;
use App\Http\Controllers\Api\AutomationRegistryController;
use App\Http\Controllers\Api\AutomationRuleController;
use App\Http\Controllers\Api\AvatarController;
use App\Http\Controllers\Api\BankingController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BillingMatrixController;
use App\Http\Controllers\Api\BurpController;
use App\Http\Controllers\Api\CalendarAutomationController;
use App\Http\Controllers\Api\CalendarPlanningController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ChatGameController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\CurationBoardController;
use App\Http\Controllers\Api\CycleController;
use App\Http\Controllers\Api\DailyMomentController;
use App\Http\Controllers\Api\DateIdeaController;
use App\Http\Controllers\Api\EntertainmentController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\FartController;
use App\Http\Controllers\Api\FinanceBudgetController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\FinanceEntryController;
use App\Http\Controllers\Api\FinanceSetupController;
use App\Http\Controllers\Api\GeoController;
use App\Http\Controllers\Api\GiftBudgetController;
use App\Http\Controllers\Api\GuestUploadController;
use App\Http\Controllers\Api\IcsCalendarImportController;
use App\Http\Controllers\Api\IntegrationConnectionController;
use App\Http\Controllers\Api\ItineraryController;
use App\Http\Controllers\Api\JournalController;
use App\Http\Controllers\Api\JourneyController;
use App\Http\Controllers\Api\MealPlanController;
use App\Http\Controllers\Api\MediaStackController;
use App\Http\Controllers\Api\MediaThumbnailController;
use App\Http\Controllers\Api\MemoryController;
use App\Http\Controllers\Api\MemoryEveningController;
use App\Http\Controllers\Api\NavigationController;
use App\Http\Controllers\Api\NotificationCenterController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\PartnerCoordinationController;
use App\Http\Controllers\Api\PartnerDecisionController;
use App\Http\Controllers\Api\PersonController;
use App\Http\Controllers\Api\PhotoBookController;
use App\Http\Controllers\Api\PlaceController;
use App\Http\Controllers\Api\PlaceReviewController;
use App\Http\Controllers\Api\PlanningExpansionController;
use App\Http\Controllers\Api\PrivateMemoryNoteController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReactionController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\RecipeCookingController;
use App\Http\Controllers\Api\RelationshipAnniversaryController;
use App\Http\Controllers\Api\RelationshipAnniversaryRecapController;
use App\Http\Controllers\Api\RelationshipMilestoneController;
use App\Http\Controllers\Api\ReminderActionController;
use App\Http\Controllers\Api\RevisitSuggestionController;
use App\Http\Controllers\Api\SavedSearchController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SharedExpenseController;
use App\Http\Controllers\Api\SharedMemoryMomentController;
use App\Http\Controllers\Api\SharedTodoController;
use App\Http\Controllers\Api\SmartAlbumController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\TimelineController;
use App\Http\Controllers\Api\TravelDataController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Api\TripIntelligenceController;
use App\Http\Controllers\Api\TripJournalRecordingController;
use App\Http\Controllers\Api\TripPlanController;
use App\Http\Controllers\Api\TripReservationController;
use App\Http\Controllers\Api\TripTravelController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\UserPreferenceController;
use App\Http\Controllers\Api\VoiceNoteController;
use App\Http\Controllers\Api\WorkspaceAssistantController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Billing\CheckoutController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\FavoritesController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\RecoveryController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\TrashController;
use App\Http\Middleware\ProtectVaultMedia;
use Illuminate\Support\Facades\Route;

// The pricing page is public, so the catalogue it renders has to be reachable without a session.
Route::get('v1/public/billing/catalogue', [BillingController::class, 'catalogue'])
    ->name('api.public.billing.catalogue');

Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {

    // Timeline
    Route::prefix('timeline')->name('api.timeline.')->group(function () {
        Route::get('/', [TimelineController::class, 'index'])->name('index');
        Route::get('/buckets', [TimelineController::class, 'buckets'])->name('buckets');
        Route::get('/map', [TimelineController::class, 'mapPoints'])->name('map');
        Route::get('/memories', [TimelineController::class, 'memories'])->name('memories');
        Route::get('/calendar', [TimelineController::class, 'calendar'])->name('calendar');
    });

    // Search
    Route::get('/search', [SearchController::class, 'search'])->name('api.search');
    Route::get('/search/suggestions', [SearchController::class, 'suggestions'])->name('api.search.suggestions');
    Route::post('/assistant/preview', [WorkspaceAssistantController::class, 'preview']);
    Route::post('/assistant/apply', [WorkspaceAssistantController::class, 'apply']);
    Route::get('/travel-data/weather', [TravelDataController::class, 'weather'])->name('api.travel-data.weather');
    Route::get('/travel-data/exchange-rate', [TravelDataController::class, 'exchangeRate'])->name('api.travel-data.exchange-rate');
    Route::post('/travel-data/route', [TravelDataController::class, 'route'])->name('api.travel-data.route');

    // Resumable upload
    Route::prefix('uploads')->name('api.uploads.')->group(function () {
        Route::post('/check-duplicate', [UploadController::class, 'checkDuplicate'])->name('check-duplicate');
        Route::post('/', [UploadController::class, 'initiate'])->name('initiate');
        Route::get('/{uuid}', [UploadController::class, 'status'])->name('status');
        Route::put('/{uuid}/chunks/{index}', [UploadController::class, 'uploadChunk'])->name('chunk');
        Route::post('/{uuid}/complete', [UploadController::class, 'complete'])->name('complete');
        Route::delete('/{uuid}', [UploadController::class, 'cancel'])->name('cancel');
    });

    // The account itself, as opposed to how the app looks.
    // Each person's own arrangement of the menu, stored as a difference.
    Route::get('/navigace', [NavigationController::class, 'show'])->name('api.navigation.show');
    Route::put('/navigace', [NavigationController::class, 'update'])->name('api.navigation.update');
    Route::delete('/navigace', [NavigationController::class, 'reset'])->name('api.navigation.reset');
    // Rights rather than features: take your data, or end the account.
    Route::get('/ucet/export', [AccountController::class, 'export'])->name('api.account.export');
    Route::post('/ucet/zruseni', [AccountController::class, 'scheduleDeletion'])->name('api.account.delete');
    Route::delete('/ucet/zruseni', [AccountController::class, 'cancelDeletion'])->name('api.account.delete.cancel');
    Route::get('/profil', [ProfileController::class, 'show'])->name('api.profile.show');
    Route::patch('/profil', [ProfileController::class, 'update'])->name('api.profile.update');
    Route::put('/profil/heslo', [ProfileController::class, 'password'])->name('api.profile.password');
    Route::patch('/user-preferences', [UserPreferenceController::class, 'update'])->name('api.user-preferences.update');
    Route::get('/automations', [AutomationRegistryController::class, 'index'])->name('api.automations.index');
    Route::patch('/automations/{key}', [AutomationRegistryController::class, 'update'])->name('api.automations.update');

    // Read-only banking and persistent Revolut history
    Route::post('/shared-expenses', [SharedExpenseController::class, 'store'])->name('api.shared-expenses.store');
    Route::patch('/shared-expenses/{uuid}', [SharedExpenseController::class, 'update'])->name('api.shared-expenses.update');
    Route::delete('/shared-expenses/{uuid}', [SharedExpenseController::class, 'destroy'])->name('api.shared-expenses.destroy');
    Route::get('/banking', [BankingController::class, 'overview'])->name('api.banking.overview');
    Route::get('/banking/dashboard', [BankingController::class, 'dashboard'])->name('api.banking.dashboard');
    Route::get('/banking/institutions', [BankingController::class, 'institutions'])->name('api.banking.institutions');
    Route::post('/banking/connections', [BankingController::class, 'connect'])->name('api.banking.connections.store');
    Route::post('/banking/connections/{uuid}/sync', [BankingController::class, 'sync'])->name('api.banking.connections.sync');
    Route::delete('/banking/connections/{uuid}', [BankingController::class, 'disconnect'])->name('api.banking.connections.destroy');
    Route::post('/banking/imports', [BankingController::class, 'import'])->name('api.banking.imports.store');
    Route::post('/banking/rules', [BankingController::class, 'storeRule'])->name('api.banking.rules.store');
    Route::delete('/banking/rules/{uuid}', [BankingController::class, 'destroyRule'])->name('api.banking.rules.destroy');
    Route::patch('/banking/transactions/{uuid}', [BankingController::class, 'updateTransaction'])->name('api.banking.transactions.update');
    Route::post('/banking/transactions/{uuid}/trip', [BankingController::class, 'linkTransactionToTrip'])->name('api.banking.transactions.trip');
    Route::get('/trips/{tripId}/banking-finance', [BankingController::class, 'trip'])->name('api.trips.banking-finance');
    Route::patch('/trips/{tripId}/banking-finance/{linkId}', [BankingController::class, 'updateTripLink'])->name('api.trips.banking-finance.update');

    // A thumbnail the browser drew, for formats this server cannot open (HEIC above all).
    Route::post('/media/{uuid}/thumbnail', [MediaThumbnailController::class, 'store'])->name('api.media.thumbnail');

    // Hledání míst — vlastní napřed, Nominatim potom; a adresa pro bod na mapě.
    Route::get('/mista/napoveda', [GeoController::class, 'suggest'])->name('api.geo.suggest');
    Route::get('/mista/adresa', [GeoController::class, 'reverse'])->name('api.geo.reverse');
    Route::post('/mista/vlastni', [GeoController::class, 'store'])->name('api.geo.store');

    // Menstruační kalendář. Zdravotní údaj — co uvidí partner, rozhoduje majitelka.
    Route::prefix('cyklus')->name('api.cycle.')->group(function () {
        Route::get('/', [CycleController::class, 'index'])->name('index');
        Route::post('/den', [CycleController::class, 'storeDay'])->name('day.store');
        Route::delete('/den/{day}', [CycleController::class, 'destroyDay'])->name('day.destroy');
        Route::post('/historie', [CycleController::class, 'backfill'])->name('backfill');
        Route::get('/statistika', [CycleController::class, 'statistics'])->name('statistics');
        Route::patch('/nastaveni', [CycleController::class, 'updateSettings'])->name('settings');
    });

    /*
     * Účetní kniha pro víc subjektů, měn a peněženek.
     *
     * Vedle `rozpocty`, ne místo nich: párový rozpočet řeší jinou úlohu a funguje dál.
     */
    /*
     * Modul Rozpočet — společné finance Adriho a Maki.
     *
     * Stojí na téže knize jako `/kniha`, ale ptá se jinak: kniha eviduje, rozpočet
     * odpovídá na „kolik nám zbývá do konce cesty".
     */
    Route::prefix('rozpocet')->name('api.finance.')->group(function () {
        Route::get('/ciselniky', [FinanceController::class, 'lookups'])->name('lookups');
        Route::get('/prehled', [FinanceController::class, 'dashboard'])->name('dashboard');
        Route::get('/transakce', [FinanceController::class, 'transactions'])->name('transactions');
        Route::get('/smeny', [FinanceController::class, 'exchanges'])->name('exchanges');
        Route::get('/navrh-kategorie', [FinanceController::class, 'suggestCategory'])->name('suggest.category');
        Route::get('/statistiky', [FinanceController::class, 'statistics'])->name('statistics');
        Route::post('/transakce', [FinanceEntryController::class, 'store'])->name('entries.store');
        Route::patch('/transakce/{uuid}', [FinanceEntryController::class, 'update'])->name('entries.update');
        Route::delete('/transakce/{uuid}', [FinanceEntryController::class, 'destroy'])->name('entries.destroy');

        // Účty. Zůstatek se nepřepisuje ručně — vzniká z pohybů a opravit ho jde jen
        // zapsanou korekcí s důvodem.
        Route::post('/ucty', [FinanceSetupController::class, 'storeWallet'])->name('wallets.store');
        Route::patch('/ucty/{uuid}', [FinanceSetupController::class, 'updateWallet'])->name('wallets.update');
        Route::post('/ucty/{uuid}/korekce', [FinanceSetupController::class, 'correctWallet'])->name('wallets.correct');
        Route::get('/ucty/{uuid}', [FinanceSetupController::class, 'walletDetail'])->name('wallets.detail');
        Route::delete('/ucty/{uuid}', [FinanceSetupController::class, 'destroyWallet'])->name('wallets.destroy');

        Route::get('/cesty', [FinanceSetupController::class, 'trips'])->name('trips');
        Route::post('/cesty', [FinanceSetupController::class, 'storeTrip'])->name('trips.store');
        Route::patch('/cesty/{uuid}', [FinanceSetupController::class, 'updateTrip'])->name('trips.update');
        Route::post('/cesty/{uuid}/aktivovat', [FinanceSetupController::class, 'activateTrip'])->name('trips.activate');
        Route::post('/cesty/{uuid}/ukoncit', [FinanceSetupController::class, 'closeTrip'])->name('trips.close');
        Route::get('/cesty/{uuid}/detail', [FinanceSetupController::class, 'tripDetail'])->name('trips.detail');
        Route::get('/cesty/{uuid}/shrnuti', [FinanceSetupController::class, 'tripSummary'])->name('trips.summary');
        Route::delete('/cesty/{uuid}', [FinanceSetupController::class, 'destroyTrip'])->name('trips.destroy');
        Route::post('/cesty/{uuid}/sdileni', [FinanceSetupController::class, 'shareTrip'])->name('trips.share');

        // Předvolby modulu — vystavené jsou jen ty, které něco doopravdy dělají.
        Route::get('/nastaveni', [FinanceSetupController::class, 'settings'])->name('settings');
        Route::patch('/nastaveni', [FinanceSetupController::class, 'updateSettings'])->name('settings.update');

        // Zdvojené kategorie a účty: napřed náhled, teprve pak sloučení.
        Route::get('/duplicity', [FinanceSetupController::class, 'duplicates'])->name('duplicates');
        Route::post('/sloucit', [FinanceSetupController::class, 'merge'])->name('merge');

        Route::get('/kategorie', [FinanceSetupController::class, 'categories'])->name('categories');
        Route::post('/kategorie', [FinanceSetupController::class, 'storeCategory'])->name('categories.store');
        Route::patch('/kategorie/{uuid}', [FinanceSetupController::class, 'updateCategory'])->name('categories.update');
        Route::delete('/kategorie/{uuid}', [FinanceSetupController::class, 'destroyCategory'])->name('categories.destroy');

        Route::post('/partneri', [FinanceSetupController::class, 'storePartner'])->name('partners.store');

        // Šablony předvyplní formulář vším kromě částky.
        // Pravidelné platby — nájem se zapíše jednou a chodí sám.
        Route::get('/pravidelne', [FinanceSetupController::class, 'recurring'])->name('recurring');
        Route::post('/pravidelne', [FinanceSetupController::class, 'storeRecurring'])->name('recurring.store');
        Route::patch('/pravidelne/{uuid}', [FinanceSetupController::class, 'updateRecurring'])->name('recurring.update');
        Route::delete('/pravidelne/{uuid}', [FinanceSetupController::class, 'destroyRecurring'])->name('recurring.destroy');
        Route::get('/sablony', [FinanceSetupController::class, 'templates'])->name('templates');
        Route::post('/sablony', [FinanceSetupController::class, 'storeTemplate'])->name('templates.store');
        Route::post('/sablony/{uuid}/pouzito', [FinanceSetupController::class, 'useTemplate'])->name('templates.use');
        Route::delete('/sablony/{uuid}', [FinanceSetupController::class, 'destroyTemplate'])->name('templates.destroy');

        // Rozpočty jsou strop nad knihou, ne vlastní evidence útrat.
        Route::get('/rozpocty', [FinanceBudgetController::class, 'index'])->name('budgets');
        Route::post('/rozpocty', [FinanceBudgetController::class, 'store'])->name('budgets.store');
        Route::patch('/rozpocty/{uuid}', [FinanceBudgetController::class, 'update'])->name('budgets.update');
        Route::delete('/rozpocty/{uuid}', [FinanceBudgetController::class, 'destroy'])->name('budgets.destroy');
        Route::post('/rozpocty/{uuid}/sdileni', [FinanceBudgetController::class, 'share'])->name('budgets.share');

        // Úprava jedné vyhrazené částky a přerozdělení podle skutečného tempa.
        Route::patch('/rozpocty/{uuid}/vyhrazeni', [FinanceBudgetController::class, 'setLimit'])->name('budgets.limit');
        Route::post('/rozpocty/{uuid}/prerozdelit', [FinanceBudgetController::class, 'redistribute'])->name('budgets.redistribute');
        Route::post('/rozpocty/{uuid}/puvodni-plan', [FinanceBudgetController::class, 'resetPlan'])->name('budgets.reset');
    });

    // Zároveň — the day's shared moment.
    Route::get('/daily-moment', [DailyMomentController::class, 'show'])->name('api.daily-moment.show');
    Route::post('/daily-moment', [DailyMomentController::class, 'store'])->name('api.daily-moment.store');
    Route::get('/daily-moment/history', [DailyMomentController::class, 'history'])->name('api.daily-moment.history');

    // Albums API
    Route::get('/album-suggestions', [AlbumSuggestionController::class, 'index'])->name('api.album-suggestions.index');
    Route::post('/album-suggestions/{fingerprint}/accept', [AlbumSuggestionController::class, 'accept'])->name('api.album-suggestions.accept');
    Route::post('/album-suggestions/{fingerprint}/dismiss', [AlbumSuggestionController::class, 'dismiss'])->name('api.album-suggestions.dismiss');
    Route::prefix('albums')->name('api.albums.')->group(function () {
        Route::get('/', [AlbumController::class, 'index'])->name('index');
        Route::get('/tree', [AlbumController::class, 'tree'])->name('tree');
        Route::get('/{uuid}', [AlbumController::class, 'show'])->name('show');

        // Smart album rules management
        Route::get('/{uuid}/smart-rules', [SmartAlbumController::class, 'getRules'])->name('smart.rules');
        Route::put('/{uuid}/smart-rules', [SmartAlbumController::class, 'updateRules'])->name('smart.update');
        Route::get('/{uuid}/smart-preview', [SmartAlbumController::class, 'preview'])->name('smart.preview');

        // Album story blocks
        Route::get('/{uuid}/story', [AlbumStoryController::class, 'index'])->name('story.index');
        Route::post('/{uuid}/story', [AlbumStoryController::class, 'store'])->name('story.store');
        Route::put('/{uuid}/story/reorder', [AlbumStoryController::class, 'reorder'])->name('story.reorder');
        Route::patch('/{uuid}/story/{blockId}', [AlbumStoryController::class, 'update'])->name('story.update');
        Route::delete('/{uuid}/story/{blockId}', [AlbumStoryController::class, 'destroy'])->name('story.destroy');
        Route::patch('/{uuid}/story-mode', [AlbumStoryController::class, 'toggleStoryMode'])->name('story-mode');

        // Album event mode
        Route::get('/{uuid}/event', [AlbumEventController::class, 'show'])->name('event.show');
        Route::patch('/{uuid}/event', [AlbumEventController::class, 'update'])->name('event.update');
        Route::get('/{uuid}/event-media', [AlbumEventController::class, 'detectMedia'])->name('event.detect');
        Route::post('/{uuid}/event-collect', [AlbumEventController::class, 'collect'])->name('event.collect');

        // Explainable selection, partner voting, preview repair and backup health
        Route::get('/{uuid}/curation-assistant', [AlbumCurationController::class, 'show'])->name('curation.show');
        Route::put('/{uuid}/cover', [AlbumCurationController::class, 'setCover'])->name('cover.update');
        Route::post('/{uuid}/poradi', [AlbumController::class, 'reorder'])->name('reorder');
        Route::post('/{uuid}/poloha', [AlbumController::class, 'applyLocation'])->name('location.apply');
        Route::post('/{uuid}/curation-shortlist', [AlbumCurationController::class, 'createShortlist'])->name('curation.shortlist');
        Route::post('/{uuid}/backup', [AlbumCurationController::class, 'syncBackup'])->name('backup.store');
        Route::post('/{uuid}/repair-previews', [AlbumCurationController::class, 'repairPreviews'])->name('previews.repair');
    });

    // Media API
    Route::prefix('media')->name('api.media.')->middleware(ProtectVaultMedia::class)->group(function () {
        Route::get('/compare', [MediaController::class, 'compare'])->name('compare');
        Route::get('/{uuid}/event-suggestions', [MediaController::class, 'eventSuggestions'])->name('event-suggestions');
        Route::get('/{uuid}', [MediaController::class, 'apiShow'])->name('show');
        Route::patch('/{uuid}', [MediaController::class, 'update'])->name('update');
        Route::post('/bulk', [MediaController::class, 'bulkAction'])->name('bulk');
        Route::get('/{uuid}/reactions', [ReactionController::class, 'index'])->name('reactions');
        Route::post('/{uuid}/react', [ReactionController::class, 'react'])->name('react');
        Route::get('/{uuid}/ratings', [MediaController::class, 'ratings'])->name('ratings');
        Route::get('/{uuid}/comments', [CommentController::class, 'index'])->name('comments.index');
        Route::post('/{uuid}/comments', [CommentController::class, 'store'])->name('comments.store');
        Route::delete('/{uuid}/comments/{id}', [CommentController::class, 'destroy'])->name('comments.destroy');
        Route::get('/{uuid}/private-note', [PrivateMemoryNoteController::class, 'show'])->name('private-note.show');
        Route::put('/{uuid}/private-note', [PrivateMemoryNoteController::class, 'update'])->name('private-note.update');
        Route::get('/{uuid}/revisit-suggestions', [RevisitSuggestionController::class, 'show'])->name('revisit-suggestions.show');
        Route::post('/{uuid}/revisit-suggestions', [RevisitSuggestionController::class, 'schedule'])->name('revisit-suggestions.schedule');
    });

    // Automatic RAW/burst stacks
    Route::get('/media-stacks/preview', [MediaStackController::class, 'preview'])->name('api.media-stacks.preview');
    Route::post('/media-stacks/apply', [MediaStackController::class, 'apply'])->name('api.media-stacks.apply');
    Route::get('/media-stacks/{uuid}', [MediaStackController::class, 'show'])->name('api.media-stacks.show');
    Route::patch('/media-stacks/{uuid}/cover', [MediaStackController::class, 'setCover'])->name('api.media-stacks.cover');
    Route::delete('/media-stacks/{uuid}', [MediaStackController::class, 'destroy'])->name('api.media-stacks.destroy');

    // People
    Route::apiResource('people', PersonController::class)->except(['destroy']);
    Route::get('people/{person}/notes', [PersonController::class, 'notes']);
    Route::put('people/{person}/notes', [PersonController::class, 'saveNotes']);
    Route::delete('people/{id}', [PersonController::class, 'destroy']);

    // Tags
    Route::apiResource('tags', TagController::class)->except(['destroy']);
    Route::delete('tags/{id}', [TagController::class, 'destroy']);
    Route::get('tags/{id}/connections', [TagController::class, 'connections']);
    Route::post('tags/{id}/connections', [TagController::class, 'attach']);
    Route::delete('tags/{id}/connections/{entityType}/{entityId}', [TagController::class, 'detach']);

    // Places
    Route::post('places/plan-selection', [PlaceController::class, 'planSelection'])->name('api.places.plan-selection');
    Route::apiResource('places', PlaceController::class)->except(['destroy'])->names([
        'index' => 'api.places.index',
        'store' => 'api.places.store',
        'show' => 'api.places.show',
        'update' => 'api.places.update',
    ]);
    Route::delete('places/{place}', [PlaceController::class, 'destroy'])->name('api.places.destroy');
    Route::get('places/{place}/notes', [PlaceController::class, 'notes'])->name('api.places.notes');
    Route::put('places/{place}/notes', [PlaceController::class, 'saveNotes'])->name('api.places.notes.save');
    Route::get('places/{place}/media', [PlaceController::class, 'media'])->name('api.places.media');
    Route::get('places/{place}/albums', [PlaceController::class, 'albums'])->name('api.places.albums');
    Route::post('places/{place}/auto-link', [PlaceController::class, 'autoLink'])->name('api.places.auto-link');
    Route::post('places/{place}/trip-activities', [PlaceController::class, 'addToTripPlan'])->name('api.places.trip-activities.store');
    Route::post('places/{place}/wishlist-items', [PlaceController::class, 'addToWishlist'])->name('api.places.wishlist-items.store');
    Route::get('places/{place}/plans', [PlaceController::class, 'plans'])->name('api.places.plans.index');
    Route::post('places/{place}/plans', [PlaceController::class, 'storePlan'])->name('api.places.plans.store');
    Route::patch('places/{place}/plans/{uuid}', [PlaceController::class, 'updatePlan'])->name('api.places.plans.update');
    Route::post('places/{place}/plans/{uuid}/shared-memory', [PlaceController::class, 'createPlanMemory'])->name('api.places.plans.shared-memory.store');
    Route::get('places/{place}/reviews', [PlaceReviewController::class, 'index'])->name('api.places.reviews.index');
    Route::post('places/{place}/reviews', [PlaceReviewController::class, 'store'])->name('api.places.reviews.store');
    Route::put('places/{place}/reviews/{uuid}', [PlaceReviewController::class, 'update'])->name('api.places.reviews.update');
    Route::delete('places/{place}/reviews/{uuid}', [PlaceReviewController::class, 'destroy'])->name('api.places.reviews.destroy');
    Route::post('places/{place}/review-album', [PlaceReviewController::class, 'ensureAlbum'])->name('api.places.review-album.store');

    // Shared recipe book, cooking mode and cooking journal
    Route::get('/recipes', [RecipeController::class, 'index'])->name('api.recipes.index');
    Route::post('/recipes/import', [RecipeController::class, 'import'])->name('api.recipes.import');
    Route::post('/recipes', [RecipeController::class, 'store'])->name('api.recipes.store');
    Route::get('/recipes/{uuid}', [RecipeController::class, 'show'])->name('api.recipes.show');
    Route::put('/recipes/{uuid}', [RecipeController::class, 'update'])->name('api.recipes.update');
    Route::patch('/recipes/{uuid}/favorite', [RecipeController::class, 'toggleFavorite'])->name('api.recipes.favorite');
    Route::delete('/recipes/{uuid}', [RecipeController::class, 'destroy'])->name('api.recipes.destroy');
    Route::get('/recipes/{uuid}/shopping-list', [RecipeController::class, 'shoppingList'])->name('api.recipes.shopping-list');
    Route::post('/recipes/{uuid}/album', [RecipeController::class, 'ensureAlbum'])->name('api.recipes.album');
    Route::post('/recipes/{uuid}/media', [RecipeController::class, 'attachMedia'])->name('api.recipes.media');
    Route::post('/recipes/{uuid}/cooking-sessions/schedule', [RecipeCookingController::class, 'schedule'])->name('api.recipes.cooking.schedule');
    Route::post('/recipes/{uuid}/cooking-sessions/start', [RecipeCookingController::class, 'start'])->name('api.recipes.cooking.start');
    Route::put('/recipes/{uuid}/cooking-sessions/{sessionUuid}/complete', [RecipeCookingController::class, 'complete'])->name('api.recipes.cooking.complete');
    Route::delete('/recipes/{uuid}/cooking-sessions/{sessionUuid}', [RecipeCookingController::class, 'cancel'])->name('api.recipes.cooking.cancel');
    Route::delete('/planned-meals/{uuid}', [MealPlanController::class, 'destroy'])->name('api.planned-meals.destroy');

    // One shared coordination layer over calendar, trips, documents, gifts and the planning inbox
    Route::get('/coordination/pulse', [PartnerCoordinationController::class, 'index'])->name('api.coordination.pulse');
    Route::patch('/coordination/actions/{type}/{key}', [PartnerCoordinationController::class, 'updateAction'])->name('api.coordination.actions.update');
    Route::put('/coordination/check-in', [PartnerCoordinationController::class, 'checkIn'])->name('api.coordination.check-in');
    Route::get('/coordination/decisions', [PartnerDecisionController::class, 'index'])->name('api.coordination.decisions');
    Route::put('/coordination/decisions/{type}/{key}', [PartnerDecisionController::class, 'respond'])->name('api.coordination.decisions.respond');

    // Shared todo lists are integrated into planning, calendar and the partner pulse.
    Route::get('/todos', [SharedTodoController::class, 'index'])->name('api.todos.index');
    Route::post('/todo-lists', [SharedTodoController::class, 'storeList'])->name('api.todo-lists.store');
    Route::patch('/todo-lists/{uuid}', [SharedTodoController::class, 'updateList'])->name('api.todo-lists.update');
    Route::post('/todos', [SharedTodoController::class, 'store'])->name('api.todos.store');
    Route::put('/todos/reorder', [SharedTodoController::class, 'reorder'])->name('api.todos.reorder');
    Route::patch('/todos/{uuid}', [SharedTodoController::class, 'update'])->name('api.todos.update');
    Route::post('/todos/{uuid}/schedule', [SharedTodoController::class, 'schedule'])->name('api.todos.schedule');
    Route::post('/todos/{uuid}/comments', [SharedTodoController::class, 'comment'])->name('api.todos.comments.store');
    Route::delete('/todos/{uuid}', [SharedTodoController::class, 'destroy'])->name('api.todos.destroy');

    // First-run checklist for a newly registered customer.
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('api.onboarding.show');
    Route::post('/onboarding/dismiss', [OnboardingController::class, 'dismiss'])->name('api.onboarding.dismiss');

    // Plan, add-on modules and the customer's own feature choices.
    Route::get('/billing/overview', [BillingController::class, 'overview'])->name('api.billing.overview');
    Route::get('/billing/faktury', [InvoiceController::class, 'index'])->name('api.billing.invoices');
    Route::put('/billing/plan', [BillingController::class, 'setPlan'])->name('api.billing.plan.set');
    Route::post('/billing/trial', [BillingController::class, 'startTrial'])->name('api.billing.trial');

    Route::get('/ucet/aktivita', [AccountController::class, 'activity'])->name('api.account.activity');
    Route::post('/ucet/2fa', [TwoFactorController::class, 'begin'])->name('api.2fa.begin');
    Route::post('/ucet/2fa/potvrdit', [TwoFactorController::class, 'confirm'])->name('api.2fa.confirm');
    Route::delete('/ucet/2fa', [TwoFactorController::class, 'disable'])->name('api.2fa.disable');

    Route::get('/automation-rules', [AutomationRuleController::class, 'index'])->name('api.automation.rules');
    Route::post('/automation-rules', [AutomationRuleController::class, 'store'])->name('api.automation.rules.store');
    Route::put('/automation-rules/{uuid}', [AutomationRuleController::class, 'update'])->name('api.automation.rules.update');
    Route::delete('/automation-rules/{uuid}', [AutomationRuleController::class, 'destroy'])->name('api.automation.rules.destroy');
    Route::put('/billing/modules/{code}', [BillingController::class, 'setModule'])->name('api.billing.modules.set');
    Route::put('/billing/features/{code}', [BillingController::class, 'setFeature'])->name('api.billing.features.set');

    // Checkout through Comgate.
    Route::get('/billing/gateway', [CheckoutController::class, 'gatewayState'])->name('api.billing.gateway');
    Route::post('/billing/checkout', [CheckoutController::class, 'start'])->name('api.billing.checkout');
    Route::get('/billing/payments/{reference}', [CheckoutController::class, 'status'])->name('api.billing.payment.status');

    // Operator-only: which features each plan contains.
    Route::get('/admin/billing/matrix', [BillingMatrixController::class, 'show'])->name('api.admin.billing.matrix');
    Route::put('/admin/billing/matrix', [BillingMatrixController::class, 'update'])->name('api.admin.billing.matrix.update');
    Route::put('/admin/billing/plans/{code}', [BillingMatrixController::class, 'updatePlan'])->name('api.admin.billing.plan.update');
    Route::get('/admin/billing/revenue', [BillingMatrixController::class, 'revenue'])->name('api.admin.billing.revenue');

    // Voice messages between members. Included in every plan.
    // Outside services a person connects: their own, or shared with the space.
    Route::get('/propojeni', [IntegrationConnectionController::class, 'index'])->name('api.integrations.index');
    Route::post('/propojeni/notion', [IntegrationConnectionController::class, 'connectNotion'])->name('api.integrations.notion');
    Route::post('/propojeni/token/{provider}', [IntegrationConnectionController::class, 'connectToken'])->name('api.integrations.token');
    Route::post('/propojeni/{uuid}/synchronizace', [IntegrationConnectionController::class, 'sync'])->name('api.integrations.sync');
    Route::post('/propojeni/{uuid}/zapis', [IntegrationConnectionController::class, 'push'])->name('api.integrations.push');
    Route::get('/propojeni/{uuid}/discord', [IntegrationConnectionController::class, 'discordProfile'])->name('api.integrations.discord');
    Route::put('/propojeni/{uuid}/webhook', [IntegrationConnectionController::class, 'discordWebhook'])->name('api.integrations.webhook');
    Route::put('/propojeni/{uuid}/viditelnost', [IntegrationConnectionController::class, 'updateVisibility'])->name('api.integrations.visibility');
    Route::delete('/propojeni/{uuid}', [IntegrationConnectionController::class, 'destroy'])->name('api.integrations.destroy');
    Route::get('/dokumenty/{uuid}', [IntegrationConnectionController::class, 'document'])->name('api.integrations.document');

    // Diary. Entries are private until their author shares them, one at a time.
    Route::get('/journal', [JournalController::class, 'index'])->name('api.journal.index');
    Route::post('/journal', [JournalController::class, 'store'])->name('api.journal.store');
    Route::patch('/journal/{uuid}', [JournalController::class, 'update'])->name('api.journal.update');
    Route::put('/journal/{uuid}/sdileni', [JournalController::class, 'visibility'])->name('api.journal.visibility');
    Route::delete('/journal/{uuid}', [JournalController::class, 'destroy'])->name('api.journal.destroy');

    // Conversations: one direct chat per person, and as many groups as anyone makes.
    Route::get('/konverzace', [ConversationController::class, 'index'])->name('api.conversations.index');
    Route::post('/konverzace/primy', [ConversationController::class, 'direct'])->name('api.conversations.direct');
    Route::post('/konverzace/skupina', [ConversationController::class, 'storeGroup'])->name('api.conversations.group');
    Route::post('/konverzace/kanal', [ConversationController::class, 'storeChannel'])->name('api.conversations.channel');
    Route::patch('/konverzace/kanal/{uuid}', [ConversationController::class, 'updateChannel'])->name('api.conversations.channel.update');
    Route::post('/konverzace/kategorie', [ConversationController::class, 'storeCategory'])->name('api.conversations.category');
    Route::post('/konverzace/stitky', [ConversationController::class, 'storeTag'])->name('api.conversations.tag');
    Route::patch('/konverzace/{uuid}', [ConversationController::class, 'updateGroup'])->name('api.conversations.update');
    Route::post('/konverzace/{uuid}/odejit', [ConversationController::class, 'leave'])->name('api.conversations.leave');

    // Avatars: preset, upload, or the generated initial.
    Route::get('/avatar/moznosti', [AvatarController::class, 'options'])->name('api.avatar.options');
    Route::post('/avatar', [AvatarController::class, 'update'])->name('api.avatar.update');
    Route::get('/avatar/{uuid}', [AvatarController::class, 'show'])->name('api.avatar.show');

    // Chat. `poll` carries the whole live view: new messages, presence, typing.
    Route::get('/chat', [ChatController::class, 'poll'])->name('api.chat.poll');
    Route::post('/chat', [ChatController::class, 'store'])->name('api.chat.store');
    Route::post('/chat/pise', [ChatController::class, 'typing'])->name('api.chat.typing');
    // Mentions: "@10.8" answers with that day's plans, a word searches the space.
    Route::get('/chat/hledat', [ChatController::class, 'search'])->name('api.chat.search');
    Route::get('/chat/zminky', [ChatController::class, 'mentions'])->name('api.chat.mentions');

    // Turn-based games, played through the same polling as the conversation.
    Route::get('/hry', [ChatGameController::class, 'options'])->name('api.games.options');
    Route::post('/hry', [ChatGameController::class, 'store'])->name('api.games.store');
    Route::get('/hry/{uuid}', [ChatGameController::class, 'show'])->name('api.games.show');
    Route::post('/hry/{uuid}/tah', [ChatGameController::class, 'move'])->name('api.games.move');
    Route::get('/chat/gify', [ChatController::class, 'gifs'])->name('api.chat.gifs');
    Route::get('/chat/{uuid}/obrazek', [ChatController::class, 'media'])->name('api.chat.media');
    Route::get('/chat/{uuid}/detail', [ChatController::class, 'detail'])->name('api.chat.detail');
    Route::post('/chat/{uuid}/reakce', [ChatController::class, 'react'])->name('api.chat.react');
    Route::patch('/chat/{uuid}', [ChatController::class, 'update'])->name('api.chat.update');
    Route::delete('/chat/{uuid}', [ChatController::class, 'destroy'])->name('api.chat.destroy');

    Route::get('/voice-notes', [VoiceNoteController::class, 'index'])->name('api.voice-notes.index');
    Route::post('/voice-notes', [VoiceNoteController::class, 'store'])->name('api.voice-notes.store');
    Route::post('/voice-notes/pripnout/{messageUuid}', [VoiceNoteController::class, 'pinFromChat'])->name('api.voice-notes.pin');
    Route::get('/voice-notes/{uuid}/stream', [VoiceNoteController::class, 'stream'])->name('api.voice-notes.stream');
    Route::post('/voice-notes/{uuid}/listened', [VoiceNoteController::class, 'markListened'])->name('api.voice-notes.listened');
    Route::patch('/voice-notes/{uuid}', [VoiceNoteController::class, 'update'])->name('api.voice-notes.update');
    Route::delete('/voice-notes/{uuid}', [VoiceNoteController::class, 'destroy'])->name('api.voice-notes.destroy');

    // Companion module with its own criteria, gated separately from burps.
    Route::middleware('module:farts')->group(function () {
        Route::get('/farts', [FartController::class, 'index'])->name('api.farts.index');
        Route::post('/farts', [FartController::class, 'store'])->name('api.farts.store');
        Route::get('/farts/{uuid}/stream', [FartController::class, 'stream'])->name('api.farts.stream');
        Route::put('/farts/{uuid}/rating', [FartController::class, 'rate'])->name('api.farts.rate');
        Route::delete('/farts/{uuid}', [FartController::class, 'destroy'])->name('api.farts.destroy');
    });

    // First paid add-on: everything below is gated by the entitlement middleware.
    Route::middleware('module:burps')->group(function () {
        Route::get('/burps', [BurpController::class, 'index'])->name('api.burps.index');
        Route::post('/burps', [BurpController::class, 'store'])->name('api.burps.store');
        Route::get('/burps/{uuid}/stream', [BurpController::class, 'stream'])->name('api.burps.stream');
        Route::put('/burps/{uuid}/rating', [BurpController::class, 'rate'])->name('api.burps.rate');
        Route::delete('/burps/{uuid}', [BurpController::class, 'destroy'])->name('api.burps.destroy');
    });

    // One shared watchlist with votes, free evenings, cinema showings and calendar events.
    Route::get('/entertainment', [EntertainmentController::class, 'index'])->name('api.entertainment.index');
    Route::get('/entertainment/search', [EntertainmentController::class, 'search'])->name('api.entertainment.search');
    Route::post('/entertainment', [EntertainmentController::class, 'store'])->name('api.entertainment.store');
    // A stale client used to open the synchronization URL as a page. Keep GET
    // side-effect free and return the user to the dedicated watchlist instead.
    Route::get('/entertainment/cinema/sync', fn () => redirect('/watchlist', 303))->name('api.entertainment.cinema.sync.legacy');
    Route::post('/entertainment/cinema/sync', [EntertainmentController::class, 'syncCinema'])->name('api.entertainment.cinema.sync');
    Route::post('/entertainment/cinema/showings/{showingUuid}', [EntertainmentController::class, 'importShowing'])->name('api.entertainment.cinema.showings.import');
    Route::patch('/entertainment/{uuid}', [EntertainmentController::class, 'update'])->name('api.entertainment.update');
    Route::post('/entertainment/{uuid}/refresh-metadata', [EntertainmentController::class, 'refreshMetadata'])->name('api.entertainment.refresh-metadata');
    Route::delete('/entertainment/{uuid}', [EntertainmentController::class, 'destroy'])->name('api.entertainment.destroy');
    Route::put('/entertainment/{uuid}/vote', [EntertainmentController::class, 'vote'])->name('api.entertainment.vote');
    Route::get('/entertainment/{uuid}/date-suggestions', [EntertainmentController::class, 'dateSuggestions'])->name('api.entertainment.date-suggestions');
    Route::post('/entertainment/{uuid}/date-proposals', [EntertainmentController::class, 'proposeDate'])->name('api.entertainment.date-proposals.store');
    Route::post('/entertainment/{uuid}/sessions', [EntertainmentController::class, 'recordSession'])->name('api.entertainment.sessions.store');
    Route::put('/entertainment/date-proposals/{proposalUuid}/vote', [EntertainmentController::class, 'voteDate'])->name('api.entertainment.date-proposals.vote');
    Route::post('/entertainment/date-proposals/{proposalUuid}/select', [EntertainmentController::class, 'selectDate'])->name('api.entertainment.date-proposals.select');

    // Notifications
    Route::get('/notifications', [NotificationCenterController::class, 'index'])->name('api.notifications.index');
    Route::get('/notifications/preferences', [NotificationCenterController::class, 'preferences'])->name('api.notifications.preferences');
    Route::patch('/notifications/preferences', [NotificationCenterController::class, 'updatePreferences'])->name('api.notifications.preferences.update');
    Route::post('/notifications/read-all', [NotificationCenterController::class, 'readAll'])->name('api.notifications.read-all');
    Route::post('/notifications/{id}/read', [NotificationCenterController::class, 'read'])->name('api.notifications.read');
    Route::post('/notifications/{id}/snooze', [NotificationCenterController::class, 'snooze'])->name('api.notifications.snooze');
    Route::post('/notifications/{id}/archive', [NotificationCenterController::class, 'archive'])->name('api.notifications.archive');
    Route::post('/reminders/events/{eventUuid}', [ReminderActionController::class, 'store'])->name('api.reminders.store');
    Route::post('/reminders/{reminderId}/snooze', [ReminderActionController::class, 'snooze'])->whereNumber('reminderId')->name('api.reminders.snooze');
    Route::post('/reminders/{reminderId}/acknowledge', [ReminderActionController::class, 'acknowledge'])->whereNumber('reminderId')->name('api.reminders.acknowledge');
    Route::post('/reminders/{reminderId}/dismiss', [ReminderActionController::class, 'dismiss'])->whereNumber('reminderId')->name('api.reminders.dismiss');

    // Personalized memories and feedback
    Route::get('/memories', [MemoryController::class, 'index'])->name('api.memories.index');
    Route::post('/memories/interactions', [MemoryController::class, 'interact'])->name('api.memories.interact');
    Route::get('/memories/preferences', [MemoryController::class, 'preferences'])->name('api.memories.preferences');
    Route::patch('/memories/preferences', [MemoryController::class, 'updatePreferences'])->name('api.memories.preferences.update');
    Route::get('/shared-memory-moments', [SharedMemoryMomentController::class, 'index'])->name('api.shared-memories.index');
    Route::post('/shared-memory-moments', [SharedMemoryMomentController::class, 'store'])->name('api.shared-memories.store');
    Route::put('/shared-memory-moments/{uuid}/reflection', [SharedMemoryMomentController::class, 'upsertReflection'])->name('api.shared-memories.reflection.update');
    Route::delete('/shared-memory-moments/{uuid}/reflection', [SharedMemoryMomentController::class, 'destroyReflection'])->name('api.shared-memories.reflection.destroy');
    Route::delete('/shared-memory-moments/{uuid}', [SharedMemoryMomentController::class, 'destroy'])->name('api.shared-memories.destroy');

    // Memory ritual: gallery selection -> shared calendar -> partner votes -> album and shared memory.
    Route::get('/memory-evenings', [MemoryEveningController::class, 'index'])->name('api.memory-evenings.index');
    Route::post('/memory-evenings', [MemoryEveningController::class, 'store'])->name('api.memory-evenings.store');
    Route::get('/memory-evenings/{uuid}', [MemoryEveningController::class, 'show'])->name('api.memory-evenings.show');
    Route::post('/memory-evenings/{uuid}/start', [MemoryEveningController::class, 'start'])->name('api.memory-evenings.start');
    Route::put('/memory-evenings/{uuid}/media/{mediaUuid}', [MemoryEveningController::class, 'voteMedia'])->name('api.memory-evenings.media.vote');
    Route::put('/memory-evenings/{uuid}/reflection', [MemoryEveningController::class, 'reflection'])->name('api.memory-evenings.reflection');
    Route::post('/memory-evenings/{uuid}/complete', [MemoryEveningController::class, 'complete'])->name('api.memory-evenings.complete');
    Route::delete('/memory-evenings/{uuid}', [MemoryEveningController::class, 'cancel'])->name('api.memory-evenings.cancel');

    // Recovery
    Route::get('/recovery/duplicates', [RecoveryController::class, 'findDuplicates'])->name('api.recovery.duplicates');
    Route::get('/recovery/cleanup', [RecoveryController::class, 'cleanupSuggestions'])->name('api.recovery.cleanup');
    Route::delete('/recovery/duplicates/trash', [RecoveryController::class, 'trashDuplicates'])->name('api.recovery.trash');

    // Saved searches
    Route::apiResource('saved-searches', SavedSearchController::class);

    Route::prefix('relationship-milestones')->name('api.relationship-milestones.')->group(function () {
        Route::get('/', [RelationshipMilestoneController::class, 'index'])->name('index');
        Route::get('/upcoming', [RelationshipMilestoneController::class, 'upcoming'])->name('upcoming');
        Route::get('/relationship-anniversary', [RelationshipAnniversaryController::class, 'show'])->name('relationship-anniversary.show');
        Route::put('/relationship-anniversary', [RelationshipAnniversaryController::class, 'update'])->name('relationship-anniversary.update');
        Route::get('/relationship-anniversary/recap', [RelationshipAnniversaryRecapController::class, 'show'])->name('relationship-anniversary.recap.show');
        Route::post('/relationship-anniversary/recap', [RelationshipAnniversaryRecapController::class, 'store'])->name('relationship-anniversary.recap.store');
        Route::post('/', [RelationshipMilestoneController::class, 'store'])->name('store');
        Route::post('/{uuid}/celebration', [RelationshipMilestoneController::class, 'scheduleCelebration'])->name('celebration.store');
        Route::patch('/{uuid}', [RelationshipMilestoneController::class, 'update'])->name('update');
        Route::delete('/{uuid}', [RelationshipMilestoneController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('curation-boards')->name('api.curation-boards.')->group(function () {
        Route::get('/', [CurationBoardController::class, 'index'])->name('index');
        Route::post('/', [CurationBoardController::class, 'store'])->name('store');
        Route::get('/{uuid}', [CurationBoardController::class, 'show'])->name('show');
        Route::patch('/{uuid}', [CurationBoardController::class, 'update'])->name('update');
        Route::delete('/{uuid}', [CurationBoardController::class, 'destroy'])->name('destroy');
        Route::post('/{uuid}/items', [CurationBoardController::class, 'addItems'])->name('items.store');
        Route::patch('/{uuid}/items/{itemId}', [CurationBoardController::class, 'updateItem'])->name('items.update');
        Route::delete('/{uuid}/items/{itemId}', [CurationBoardController::class, 'removeItem'])->name('items.destroy');
        Route::put('/{uuid}/items/{itemId}/vote', [CurationBoardController::class, 'vote'])->name('items.vote');
    });

    // Shared calendar, preparation and memories workflow — static routes before {uuid}
    Route::prefix('date-ideas')->name('api.date-ideas.')->group(function () {
        Route::get('/', [DateIdeaController::class, 'index'])->name('index');
        Route::post('/generate', [DateIdeaController::class, 'generate'])->name('generate');
        Route::patch('/{uuid}/reaction', [DateIdeaController::class, 'react'])->name('reaction');
        Route::post('/{uuid}/plan', [DateIdeaController::class, 'plan'])->name('plan');
    });

    Route::prefix('calendar')->name('api.calendar.')->group(function () {
        Route::get('/events', [CalendarPlanningController::class, 'index'])->name('events.index');
        Route::post('/events', [CalendarPlanningController::class, 'store'])->name('events.store');
        Route::post('/ics-import', [IcsCalendarImportController::class, 'store'])->name('ics.import');
        Route::get('/ics-export', [CalendarPlanningController::class, 'exportIcs'])->name('ics.export');
        Route::post('/holiday-plan', [CalendarPlanningController::class, 'planHoliday'])->name('holiday-plan.store');
        Route::get('/date-ideas', [CalendarPlanningController::class, 'dateIdeas'])->name('date-ideas');
        Route::get('/shared-slots', [CalendarPlanningController::class, 'sharedSlots'])->name('shared-slots');
        Route::get('/weekly-overview', [CalendarPlanningController::class, 'weeklyOverview'])->name('weekly-overview');
        Route::post('/memory-evening', [CalendarPlanningController::class, 'scheduleMemoryEvening'])->name('memory-evening.store');
        Route::get('/gifts', [CalendarAutomationController::class, 'gifts'])->name('gifts.index');
        Route::post('/gifts', [CalendarAutomationController::class, 'storeGift'])->name('gifts.store');
        Route::patch('/gifts/{uuid}', [CalendarAutomationController::class, 'updateGift'])->name('gifts.update');
        Route::get('/gift-budgets', [GiftBudgetController::class, 'index'])->name('gift-budgets.index');
        Route::post('/gift-budgets', [GiftBudgetController::class, 'store'])->name('gift-budgets.store');
        Route::patch('/gift-budgets/{uuid}', [GiftBudgetController::class, 'update'])->name('gift-budgets.update');
        Route::delete('/gift-budgets/{uuid}', [GiftBudgetController::class, 'destroy'])->name('gift-budgets.destroy');
        Route::get('/day-note', [CalendarAutomationController::class, 'dayNote'])->name('day-note.show');
        Route::put('/day-note', [CalendarAutomationController::class, 'updateDayNote'])->name('day-note.update');
        Route::get('/inbox', [CalendarPlanningController::class, 'inbox'])->name('inbox.index');
        Route::post('/inbox', [CalendarPlanningController::class, 'storeInbox'])->name('inbox.store');
        Route::patch('/inbox/{uuid}', [CalendarPlanningController::class, 'updateInbox'])->name('inbox.update');
        Route::delete('/inbox/{uuid}', [CalendarPlanningController::class, 'destroyInbox'])->name('inbox.destroy');
        Route::get('/time-capsules', [CalendarPlanningController::class, 'timeCapsules'])->name('time-capsules.index');
        Route::post('/time-capsules', [CalendarPlanningController::class, 'storeTimeCapsule'])->name('time-capsules.store');
        Route::post('/push-subscriptions', [CalendarPlanningController::class, 'storePushSubscription'])->name('push.store');
        Route::delete('/push-subscriptions', [CalendarPlanningController::class, 'destroyPushSubscription'])->name('push.destroy');
        Route::get('/events/{uuid}', [CalendarPlanningController::class, 'show'])->name('events.show');
        Route::get('/events/{uuid}/history', [CalendarPlanningController::class, 'history'])->name('events.history');
        Route::post('/events/{uuid}/history/{revisionUuid}/restore', [CalendarPlanningController::class, 'restoreRevision'])->name('events.history.restore');
        Route::patch('/events/{uuid}', [CalendarPlanningController::class, 'update'])->name('events.update');
        Route::delete('/events/{uuid}', [CalendarPlanningController::class, 'destroy'])->name('events.destroy');
        Route::get('/events/{uuid}/meal-plan', [MealPlanController::class, 'eventIndex'])->name('events.meal-plan.index');
        Route::post('/events/{uuid}/meal-plan', [MealPlanController::class, 'eventStore'])->name('events.meal-plan.store');
        Route::patch('/events/{uuid}/meal-shopping/{key}', [MealPlanController::class, 'eventShopping'])->name('events.meal-shopping.update');
        Route::post('/events/{uuid}/response', [CalendarPlanningController::class, 'respond'])->name('events.response');
        Route::post('/events/{uuid}/tasks', [CalendarPlanningController::class, 'storeTask'])->name('tasks.store');
        Route::patch('/events/{uuid}/tasks/{taskId}', [CalendarPlanningController::class, 'updateTask'])->name('tasks.update');
        Route::delete('/events/{uuid}/tasks/{taskId}', [CalendarPlanningController::class, 'destroyTask'])->name('tasks.destroy');
        Route::post('/events/{uuid}/attachments', [CalendarPlanningController::class, 'storeAttachment'])->name('attachments.store');
        Route::delete('/events/{uuid}/attachments/{attachmentId}', [CalendarPlanningController::class, 'destroyAttachment'])->name('attachments.destroy');
        Route::get('/events/{uuid}/media-suggestions', [CalendarPlanningController::class, 'mediaSuggestions'])->name('media-suggestions');
        Route::post('/events/{uuid}/experience-album', [CalendarPlanningController::class, 'ensureExperienceAlbum'])->name('experience-album.ensure');
        Route::post('/events/{uuid}/media-suggestions', [CalendarPlanningController::class, 'applyMediaSuggestions'])->name('media-suggestions.apply');
        Route::post('/events/{uuid}/shared-memory', [CalendarPlanningController::class, 'createSharedMemory'])->name('shared-memory.store');
        Route::get('/events/{uuid}/reflection', [CalendarPlanningController::class, 'reflection'])->name('reflection.show');
        Route::put('/events/{uuid}/reflection', [CalendarPlanningController::class, 'updateReflection'])->name('reflection.update');
        Route::post('/events/{uuid}/revisit', [CalendarPlanningController::class, 'scheduleRevisit'])->name('revisit.store');
        Route::post('/events/{uuid}/trip', [CalendarPlanningController::class, 'createTrip'])->name('trip.store');
        Route::post('/events/{uuid}/story', [CalendarPlanningController::class, 'story'])->name('story');

        // Personal and shared planning tools
        Route::get('/availability', [PlanningExpansionController::class, 'availability'])->name('availability');
        Route::put('/availability', [PlanningExpansionController::class, 'updateAvailability'])->name('availability.update');
        Route::get('/templates', [PlanningExpansionController::class, 'templates'])->name('templates.index');
        Route::post('/templates', [PlanningExpansionController::class, 'storeTemplate'])->name('templates.store');
        Route::post('/templates/{uuid}/apply', [PlanningExpansionController::class, 'applyTemplate'])->name('templates.apply');
        Route::get('/wishlists', [PlanningExpansionController::class, 'wishlists'])->name('wishlists.index');
        Route::post('/wishlists', [PlanningExpansionController::class, 'storeWishlist'])->name('wishlists.store');
        Route::post('/wishlists/{uuid}/items', [PlanningExpansionController::class, 'storeWishlistItem'])->name('wishlists.items.store');
        Route::get('/wishlists/{uuid}/suggestions', [PlanningExpansionController::class, 'wishlistSuggestions'])->name('wishlists.suggestions');
        Route::post('/wishlists/{uuid}/items/{itemId}/plan', [PlanningExpansionController::class, 'planWishlistItem'])->name('wishlists.items.plan');
        Route::get('/polls', [PlanningExpansionController::class, 'polls'])->name('polls.index');
        Route::post('/polls', [PlanningExpansionController::class, 'storePoll'])->name('polls.store');
        Route::post('/polls/{uuid}/vote', [PlanningExpansionController::class, 'vote'])->name('polls.vote');
        Route::post('/polls/{uuid}/options/{optionId}/plan', [PlanningExpansionController::class, 'planPollOption'])->name('polls.options.plan');
        Route::get('/partner-rules', [PlanningExpansionController::class, 'partnerRules'])->name('partner-rules.index');
        Route::post('/partner-rules', [PlanningExpansionController::class, 'storePartnerRule'])->name('partner-rules.store');
        Route::get('/partner-rules/{uuid}/preview', [PlanningExpansionController::class, 'previewPartnerRule'])->name('partner-rules.preview');
        Route::get('/events/{uuid}/exceptions', [PlanningExpansionController::class, 'exceptions'])->name('exceptions.index');
        Route::post('/events/{uuid}/exceptions', [PlanningExpansionController::class, 'storeException'])->name('exceptions.store');
        Route::get('/events/{uuid}/ics', [PlanningExpansionController::class, 'exportIcs'])->name('events.ics');
    });

    Route::prefix('trips/{tripId}')->name('api.trip-planning.')->group(function () {
        Route::get('/planning', [CalendarPlanningController::class, 'tripPlanning'])->name('index');
        Route::get('/meal-plan', [MealPlanController::class, 'tripIndex'])->name('meal-plan.index');
        Route::post('/meal-plan', [MealPlanController::class, 'tripStore'])->name('meal-plan.store');
        Route::patch('/meal-shopping/{key}', [MealPlanController::class, 'tripShopping'])->name('meal-shopping.update');
        Route::get('/travel-choices', [TripTravelController::class, 'choices'])->name('travel-choices.index');
        Route::post('/booking-search', [TripTravelController::class, 'bookingSearch'])->name('booking-search');
        Route::post('/travel-choices/transport', [TripTravelController::class, 'storeTransport'])->name('travel-choices.transport');
        Route::post('/travel-choices/accommodation', [TripTravelController::class, 'storeAccommodation'])->name('travel-choices.accommodation');
        Route::post('/expenses', [CalendarPlanningController::class, 'storeExpense'])->name('expenses.store');
        Route::patch('/expenses/{expenseId}', [CalendarPlanningController::class, 'updateExpense'])->name('expenses.update');
        Route::delete('/expenses/{expenseId}', [CalendarPlanningController::class, 'destroyExpense'])->name('expenses.destroy');
        Route::post('/route-variants', [CalendarPlanningController::class, 'storeRouteVariant'])->name('variants.store');
        Route::post('/route-variants/{variantId}/select', [CalendarPlanningController::class, 'selectRouteVariant'])->name('variants.select');
        Route::get('/emergency-card', [PlanningExpansionController::class, 'emergencyCard'])->name('emergency-card');
        Route::put('/emergency-card', [PlanningExpansionController::class, 'updateEmergencyCard'])->name('emergency-card.update');
        Route::get('/readiness', [TripIntelligenceController::class, 'readiness'])->name('readiness');
        Route::get('/preparation-timeline', [TripIntelligenceController::class, 'preparationTimeline'])->name('preparation-timeline');
        Route::post('/preparation-timeline/sync', [TripIntelligenceController::class, 'syncPreparationTimeline'])->name('preparation-timeline.sync');
        Route::get('/budget-advisor', [TripIntelligenceController::class, 'budgetAdvisor'])->name('budget-advisor');
        Route::put('/budget-plan', [TripIntelligenceController::class, 'updateBudgetPlan'])->name('budget-plan.update');
        Route::put('/budget-limits', [TripIntelligenceController::class, 'upsertBudgetLimit'])->name('budget-limits.upsert');
        Route::post('/documents', [TripIntelligenceController::class, 'storeDocument'])->name('documents.store');
        Route::get('/reservation-imports', [TripReservationController::class, 'index'])->name('reservation-imports.index');
        Route::post('/reservation-imports', [TripReservationController::class, 'store'])->name('reservation-imports.store');
        Route::put('/reservation-imports/{uuid}/confirm', [TripReservationController::class, 'confirm'])->name('reservation-imports.confirm');
        Route::get('/reservation-imports/{uuid}/download', [TripReservationController::class, 'download'])->name('reservation-imports.download');
        Route::delete('/reservation-imports/{uuid}', [TripReservationController::class, 'destroy'])->name('reservation-imports.destroy');
        Route::post('/settlements', [TripIntelligenceController::class, 'proposeSettlement'])->name('settlements.store');
        Route::post('/settlements/{settlementId}/settle', [TripIntelligenceController::class, 'settle'])->name('settlements.settle');
        Route::delete('/settlements/{settlementId}', [TripIntelligenceController::class, 'destroySettlement'])->name('settlements.destroy');
        Route::get('/finance-summary', [TripIntelligenceController::class, 'financeSummary'])->name('finance-summary');
        Route::put('/savings-goal', [TripIntelligenceController::class, 'upsertSavingsGoal'])->name('savings-goal.upsert');
        Route::post('/location-consent', [TripIntelligenceController::class, 'locationConsent'])->name('location-consent.store');
        Route::post('/track-points', [TripIntelligenceController::class, 'storeTrackPoint'])->name('track-points.store');
        Route::get('/packing-items', [TripIntelligenceController::class, 'packingItems'])->name('packing.index');
        Route::get('/packing-members', [TripIntelligenceController::class, 'packingMembers'])->name('packing.members');
        Route::post('/packing-items', [TripIntelligenceController::class, 'storePackingItem'])->name('packing.store');
        Route::patch('/packing-items/{itemId}', [TripIntelligenceController::class, 'updatePackingItem'])->name('packing.update');
        Route::delete('/packing-items/{itemId}', [TripIntelligenceController::class, 'destroyPackingItem'])->name('packing.destroy');
        Route::post('/packing-items/apply-template', [TripIntelligenceController::class, 'applyPackingTemplate'])->name('packing.template');
        Route::get('/vehicle-costs', [TripIntelligenceController::class, 'vehicleCosts'])->name('vehicle-costs.index');
        Route::post('/vehicle-costs', [TripIntelligenceController::class, 'storeVehicleCost'])->name('vehicle-costs.store');
        Route::patch('/vehicle-costs/{costId}', [TripIntelligenceController::class, 'updateVehicleCost'])->name('vehicle-costs.update');
        Route::delete('/vehicle-costs/{costId}', [TripIntelligenceController::class, 'destroyVehicleCost'])->name('vehicle-costs.destroy');
        Route::get('/watchlist', [TripIntelligenceController::class, 'tripWatchlist'])->name('watchlist.index');
        Route::post('/watchlist', [TripIntelligenceController::class, 'storeTripWatchlist'])->name('watchlist.store');
        Route::patch('/watchlist/{itemId}', [TripIntelligenceController::class, 'updateTripWatchlist'])->name('watchlist.update');
        Route::delete('/watchlist/{itemId}', [TripIntelligenceController::class, 'destroyTripWatchlist'])->name('watchlist.destroy');
        Route::get('/offline-package', [TripIntelligenceController::class, 'offlinePackage'])->name('offline-package');
    });

    Route::get('/transport-routes', [TripIntelligenceController::class, 'savedTransportRoutes'])->name('api.transport-routes.index');
    Route::post('/transport-routes', [TripIntelligenceController::class, 'saveTransportRoute'])->name('api.transport-routes.store');
    Route::post('/currency-rates', [TripIntelligenceController::class, 'storeCurrencyRate'])->name('api.currency-rates.store');

    // Export
    Route::post('/exports', [ExportController::class, 'create'])->name('api.exports.create');
    Route::get('/exports/{id}', [ExportController::class, 'status'])->name('api.exports.status');
    Route::get('/exports/{id}/download', [ExportController::class, 'download'])->name('api.exports.download');

    // Journey (Naše cesta) — static routes before {id} wildcard
    Route::get('/journey/auto-suggest', [JourneyController::class, 'autoSuggest'])->name('api.journey.auto-suggest');
    Route::post('/journey/auto-import', [JourneyController::class, 'autoImport'])->name('api.journey.auto-import');
    Route::get('/journey', [JourneyController::class, 'index'])->name('api.journey.index');
    Route::post('/journey', [JourneyController::class, 'store'])->name('api.journey.store');
    Route::patch('/journey/{id}', [JourneyController::class, 'update'])->name('api.journey.update');
    Route::delete('/journey/{id}', [JourneyController::class, 'destroy'])->name('api.journey.destroy');
    Route::get('/journey/{id}/photos', [JourneyController::class, 'photos'])->name('api.journey.photos');

    // Itinerary (světový itinerář) — static routes before {id} wildcard
    Route::get('/itinerary/search', [ItineraryController::class, 'search'])->name('api.itinerary.search');
    Route::post('/itinerary/check-visited', [ItineraryController::class, 'checkVisited'])->name('api.itinerary.check-visited');
    Route::get('/itinerary', [ItineraryController::class, 'index'])->name('api.itinerary.index');
    Route::post('/itinerary', [ItineraryController::class, 'store'])->name('api.itinerary.store');
    Route::patch('/itinerary/{id}', [ItineraryController::class, 'update'])->name('api.itinerary.update');
    Route::get('/itinerary/{id}/photos', [ItineraryController::class, 'placePhotos'])->name('api.itinerary.photos');
    Route::delete('/itinerary/{id}', [ItineraryController::class, 'destroy'])->name('api.itinerary.destroy');

    // Photo books (Fotokniha / výběry k tisku)
    Route::prefix('books')->name('api.books.')->group(function () {
        Route::get('/', [PhotoBookController::class, 'index'])->name('index');
        Route::post('/', [PhotoBookController::class, 'store'])->name('store');
        Route::get('/{uuid}', [PhotoBookController::class, 'show'])->name('show');
        Route::patch('/{uuid}', [PhotoBookController::class, 'update'])->name('update');
        Route::delete('/{uuid}', [PhotoBookController::class, 'destroy'])->name('destroy');
        Route::post('/{uuid}/items', [PhotoBookController::class, 'addItems'])->name('items.add');
        Route::delete('/{uuid}/items/{itemId}', [PhotoBookController::class, 'removeItem'])->name('items.remove');
        Route::put('/{uuid}/items/reorder', [PhotoBookController::class, 'reorder'])->name('items.reorder');
        Route::get('/{uuid}/export/zip', [PhotoBookController::class, 'exportZip'])->name('export.zip');
        Route::get('/{uuid}/export/filelist', [PhotoBookController::class, 'exportFileList'])->name('export.filelist');
        Route::get('/{uuid}/export/contact', [PhotoBookController::class, 'contactSheetData'])->name('export.contact');
    });

    // Trips (Cesty a výlety) — static sub-routes first
    Route::get('/trips/{id}/suggest-media', [TripController::class, 'suggestMedia'])->name('api.trips.suggest-media');
    Route::get('/trips/{id}/plan', [TripPlanController::class, 'show'])->name('api.trips.plan');
    Route::get('/trips/{id}/now', [TripPlanController::class, 'now'])->name('api.trips.now');
    Route::post('/trips/{id}/journal', [TripPlanController::class, 'addJournalEntry'])->name('api.trips.journal.add');
    Route::post('/trips/{id}/journal-recordings', [TripJournalRecordingController::class, 'store'])->name('api.trips.journal.recordings.store');
    Route::get('/trips/{id}/journal/{entryId}/recording', [TripJournalRecordingController::class, 'show'])->name('api.trips.journal.recordings.show');
    Route::patch('/trips/{id}/journal/{entryId}', [TripPlanController::class, 'updateJournalEntry'])->name('api.trips.journal.update');
    Route::delete('/trips/{id}/journal/{entryId}', [TripPlanController::class, 'removeJournalEntry'])->name('api.trips.journal.remove');
    Route::patch('/trips/{id}/plan/days/{dayId}', [TripPlanController::class, 'updateDay'])->name('api.trips.plan.days.update');
    Route::post('/trips/{id}/plan/days/{dayId}/activities', [TripPlanController::class, 'addActivity'])->name('api.trips.plan.activities.add');
    Route::post('/trips/{id}/plan/days/{dayId}/inbox/{uuid}/promote', [TripPlanController::class, 'promoteInboxItem'])->name('api.trips.plan.inbox.promote');
    Route::put('/trips/{id}/plan/days/{dayId}/activities/reorder', [TripPlanController::class, 'reorderActivities'])->name('api.trips.plan.activities.reorder');
    Route::patch('/trips/{id}/plan/activities/{activityId}', [TripPlanController::class, 'updateActivity'])->name('api.trips.plan.activities.update');
    Route::delete('/trips/{id}/plan/activities/{activityId}', [TripPlanController::class, 'removeActivity'])->name('api.trips.plan.activities.remove');
    Route::get('/trips/{id}/media', [TripController::class, 'media'])->name('api.trips.media');
    Route::post('/trips/{id}/media', [TripController::class, 'addMedia'])->name('api.trips.add-media');
    Route::delete('/trips/{id}/media/{mediaId}', [TripController::class, 'removeMedia'])->name('api.trips.remove-media');
    Route::post('/trips/{id}/shared-memory', [TripController::class, 'createSharedMemory'])->name('api.trips.shared-memory.store');
    Route::get('/trips/{id}/recap-album', [TripController::class, 'recapAlbum'])->name('api.trips.recap-album.show');
    Route::post('/trips/{id}/recap-album', [TripController::class, 'createRecapAlbum'])->name('api.trips.recap-album.store');
    Route::get('/trips/{id}/reflection', [TripController::class, 'reflection'])->name('api.trips.reflection');
    Route::put('/trips/{id}/reflection', [TripController::class, 'upsertReflection'])->name('api.trips.reflection.upsert');
    Route::post('/trips/{id}/revisit', [TripController::class, 'scheduleRevisit'])->name('api.trips.revisit.store');
    Route::put('/trips/{id}/waypoints/reorder', [TripController::class, 'reorderWaypoints'])->name('api.trips.waypoints.reorder');
    Route::post('/trips/{id}/waypoints', [TripController::class, 'addWaypoint'])->name('api.trips.waypoints.add');
    Route::patch('/trips/{id}/waypoints/{wpId}', [TripController::class, 'updateWaypoint'])->name('api.trips.waypoints.update');
    Route::delete('/trips/{id}/waypoints/{wpId}', [TripController::class, 'removeWaypoint'])->name('api.trips.waypoints.remove');
    Route::get('/trips/route-distance', [TripController::class, 'routeDistance'])->name('api.trips.route-distance');
    Route::get('/trips/transport-prices', [TripController::class, 'transportPrices'])->name('api.trips.transport-prices');
    Route::get('/trips', [TripController::class, 'index'])->name('api.trips.index');

    // Unified ticket search (RegioJet + FlixBus + IDOS)
    Route::get('/tickets/search', [TicketController::class, 'search'])->name('api.tickets.search');
    Route::post('/trips', [TripController::class, 'store'])->name('api.trips.store');
    Route::get('/trips/{id}', [TripController::class, 'show'])->name('api.trips.show');
    Route::patch('/trips/{id}', [TripController::class, 'update'])->name('api.trips.update');
    Route::delete('/trips/{id}', [TripController::class, 'destroy'])->name('api.trips.destroy');

    // Favorites API (Sanctum stateful — works from browser Axios)
    Route::post('/favorites/{uuid}/toggle', [FavoritesController::class, 'toggle'])->name('api.favorites.toggle');

    // Trash API
    Route::post('/trash/{uuid}/restore', [TrashController::class, 'restore'])->name('api.trash.restore');
    Route::post('/trash/bulk-restore', [TrashController::class, 'bulkRestore'])->name('api.trash.bulk-restore');
    Route::delete('/trash/{uuid}/purge', [TrashController::class, 'purge'])->name('api.trash.purge');
    Route::delete('/trash/empty', [TrashController::class, 'emptyTrash'])->name('api.trash.empty');

    // Archive API
    Route::post('/archive/{uuid}/unarchive', [ArchiveController::class, 'unarchive'])->name('api.archive.unarchive');
    Route::post('/archive/bulk-unarchive', [ArchiveController::class, 'bulkUnarchive'])->name('api.archive.bulk-unarchive');

    // Shares API
    Route::get('/shares', [ShareController::class, 'index'])->name('api.shares.index');
    Route::post('/shares', [ShareController::class, 'store'])->name('api.shares.store');
    Route::delete('/shares/{id}', [ShareController::class, 'destroy'])->name('api.shares.destroy');
    Route::get('/guest-uploads', [GuestUploadController::class, 'index'])->name('api.guest-uploads.index');
    Route::post('/guest-uploads/{uuid}/approve', [GuestUploadController::class, 'approve'])->name('api.guest-uploads.approve');
    Route::post('/guest-uploads/{uuid}/reject', [GuestUploadController::class, 'reject'])->name('api.guest-uploads.reject');
});
