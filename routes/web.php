<?php

use App\Http\Controllers\AlbumController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\FavoritesController;
use App\Http\Controllers\GoogleOAuthController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MobileAppController;
use App\Http\Controllers\MemoriesController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\VaultController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\StorageRiskController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ── Public ─────────────────────────────────────────────
Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');

// Reached signed out, holding nothing but an id in the session.
Route::get('/login/overeni', [App\Http\Controllers\Auth\TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
Route::post('/login/overeni', [App\Http\Controllers\Auth\TwoFactorController::class, 'verify'])->name('two-factor.verify');
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

// Invitation-only registration
Route::get('/invite/{token}', [InvitationController::class, 'show'])->name('invitation.show');
Route::post('/invite/{token}', [InvitationController::class, 'accept'])->name('invitation.accept');

// Password reset
Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'email'])->name('password.email');
Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');

// Public marketing site. Both pages read the same catalogue the app bills from.
Route::get('/sluzba', fn() => Inertia::render('Landing/Index'))->name('landing');
Route::get('/cenik', fn() => Inertia::render('Pricing/Index'))->name('pricing');

// Comgate. The notification is server-to-server and is what actually grants a
// subscription; the return URL is only where the payer's browser lands.
Route::post('/platby/comgate/notifikace', [App\Http\Controllers\Billing\CheckoutController::class, 'notify'])->name('billing.comgate.notify');
Route::get('/platby/comgate/navrat', [App\Http\Controllers\Billing\CheckoutController::class, 'return'])->name('billing.comgate.return');

// Public sign-up, gated by config('gallery.registration_open').
Route::get('/registrace', [App\Http\Controllers\Auth\RegistrationController::class, 'show'])->name('register');
Route::post('/registrace', [App\Http\Controllers\Auth\RegistrationController::class, 'store'])->name('register.store');

// Public mobile application centre and stable direct Android download link.
Route::get('/app', [MobileAppController::class, 'index'])->name('mobile-app.index');
Route::redirect('/aplikace', '/app')->name('mobile-app.cs');
Route::get('/app/android/download', [MobileAppController::class, 'download'])->name('mobile-app.android.download');
Route::get('/.well-known/assetlinks.json', [MobileAppController::class, 'assetLinks'])->name('mobile-app.asset-links');

// Public shared links
Route::get('/s/{token}', [ShareController::class, 'show'])->name('share.show');
Route::post('/s/{token}/verify', [ShareController::class, 'verify'])->name('share.verify');
Route::post('/s/{token}/upload', [ShareController::class, 'guestUpload'])->name('share.guest-upload');
Route::get('/s/{token}/media/{uuid}/download', [ShareController::class, 'download'])->name('share.download');

// Share Target (PWA Web Share Target)
Route::post('/share-target',              [MediaController::class, 'shareTarget'])->name('share-target')
    ->middleware(['auth']);
Route::get('/share-target',               [MediaController::class, 'showShareTarget'])->name('share-target.show')
    ->middleware(['auth']);
Route::get('/share-target/file/{index}',  [MediaController::class, 'serveShareFile'])->name('share-target.file')
    ->middleware(['auth']);
Route::delete('/share-target',            [MediaController::class, 'clearShareTarget'])->name('share-target.clear')
    ->middleware(['auth']);

/*
 | The front door. A visitor sees what the service is; a member goes straight to their
 | gallery. Previously "/" was behind auth, so anyone arriving at the domain met a login
 | form and never saw the offering at all.
 |
 | app()->call() is used rather than calling the controller directly so its injected
 | services are resolved from the container exactly as normal routing would.
 */
Route::get('/', function (Illuminate\Http\Request $request) {
    if (! $request->user()) return Inertia::render('Landing/Index');

    return app()->call([app(App\Http\Controllers\DashboardController::class), 'index']);
})->name('dashboard');

// ── Authenticated ───────────────────────────────────────
Route::middleware(['auth'])->group(function () {
    Route::get('/banking/callback', [App\Http\Controllers\BankingOAuthController::class, 'callback'])->name('banking.callback');
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
    Route::prefix('media')->name('media.')->middleware(\App\Http\Middleware\ProtectVaultMedia::class)->group(function () {
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

    // Compare (Porovnání fotografií)
    Route::get('/compare', fn() => Inertia::render('Compare/Index'))->name('compare');

    // TV Mode (fullscreen slideshow for TV/family display)
    Route::get('/tv', fn() => Inertia::render('TV/Index'))->name('tv');

    // Print selections / Photo books
    Route::get('/print',               fn() => Inertia::render('Print/Index'))->name('print');
    Route::get('/books/{uuid}/print',  fn() => Inertia::render('Print/ContactSheet'))->name('books.print');
    Route::get('/curation',            fn() => Inertia::render('Curation/Index'))->name('curation');

    // Trips (Cesty a výlety)
    Route::get('/trips',    fn() => Inertia::render('Trips/Index'))->name('trips');
    Route::get('/trips/{id}/plan', fn(int $id) => Inertia::render('Trips/Plan', ['tripId' => $id]))->name('trips.plan');
    Route::get('/trips/{id}/now', fn(int $id) => Inertia::render('Trips/Now', ['tripId' => $id]))->name('trips.now');

    // Tickets (vyhledávání jízdenek)
    Route::get('/tickets', [App\Http\Controllers\TicketController::class, 'index'])->name('tickets');
    Route::get('/jizdenky', [App\Http\Controllers\TicketController::class, 'index'])->name('tickets.cs');

    // Map
    Route::get('/map',      fn() => Inertia::render('Map/Index'))->name('map');

    // Search
    Route::get('/search',   fn() => Inertia::render('Search/Index'))->name('search');

    // Calendar
    Route::get('/calendar', fn() => Inertia::render('Calendar/Index', [
        'today' => now('Europe/Prague')->toDateString(),
    ]))->name('calendar');
    Route::get('/calendar/events/{uuid}', fn(string $uuid) => Inertia::render('Calendar/Show', ['eventUuid' => $uuid]))->name('calendar.events.show');
    // Voice messages, the burp module and the space's own plan overview.
    Route::get('/settings/menu', fn() => Inertia::render('Settings/Navigation'))->name('navigation.settings');
    Route::get('/settings/propojeni', fn() => Inertia::render('Settings/Connections'))->name('connections');

    /**
     * Where a card marked "redirect" sends people.
     *
     * Google Drive keeps its own established flow. The rest are catalogued but their OAuth
     * exchange is not written yet, so this says so and sends them back — the card already
     * refuses the click when the application is unregistered, and this is the second line
     * for the case where it is registered but the exchange still is not.
     */
    Route::get('/oauth/dropbox/callback', [\App\Http\Controllers\Storage\DropboxOAuthController::class, 'callback'])->name('storage.dropbox.callback');
    Route::post('/settings/storage/dropbox/disconnect', [\App\Http\Controllers\Storage\DropboxOAuthController::class, 'disconnect'])->name('storage.dropbox.disconnect');
    Route::post('/settings/storage/dropbox/test', [\App\Http\Controllers\Storage\DropboxOAuthController::class, 'test'])->name('storage.dropbox.test');

    Route::get('/settings/faktury', fn () => Inertia::render('Settings/Invoices'))->name('invoices');
    Route::get('/faktury/{uuid}', [\App\Http\Controllers\Billing\InvoiceController::class, 'show'])->name('invoice.show');

    Route::get('/oauth/onedrive/callback', [\App\Http\Controllers\Storage\OneDriveOAuthController::class, 'callback'])->name('storage.onedrive.callback');
    Route::post('/settings/storage/onedrive/disconnect', [\App\Http\Controllers\Storage\OneDriveOAuthController::class, 'disconnect'])->name('storage.onedrive.disconnect');

    Route::get('/settings/propojeni/{provider}/start', function (string $provider) {
        if ($provider === 'google_drive') return redirect()->route('storage.google');
        if ($provider === 'dropbox') return app(\App\Http\Controllers\Storage\DropboxOAuthController::class)->start(request());
        if ($provider === 'onedrive') return app(\App\Http\Controllers\Storage\OneDriveOAuthController::class)->start(request());

        $registry = app(\App\Services\Integrations\ProviderRegistry::class);
        abort_unless($registry->has($provider), 404);

        $name = \App\Services\Integrations\ProviderRegistry::PROVIDERS[$provider]['name'];

        return redirect()->route('connections')
            ->with('error', $name . ': přihlášení přes tuto službu zatím není dokončené.');
    })->name('connections.start');
    Route::get('/discord/pripojit', [App\Http\Controllers\DiscordOAuthController::class, 'redirect'])->name('discord.connect');
    Route::get('/discord/zpet', [App\Http\Controllers\DiscordOAuthController::class, 'callback'])->name('discord.callback');
    Route::get('/denik', fn() => Inertia::render('Journal/Index'))->name('journal');
    Route::get('/chat', fn() => Inertia::render('Chat/Index'))->name('chat');
    Route::get('/hlasovky', fn() => Inertia::render('VoiceNotes/Index'))->name('voice-notes');
    Route::get('/zaroven', fn() => Inertia::render('TogetherNow/Index'))->name('together-now');
    Route::get('/krkance', fn() => Inertia::render('Burps/Index'))->name('burps');
    Route::get('/prdy', fn() => Inertia::render('Farts/Index'))->name('farts');
    Route::get('/settings/vzhled', fn() => Inertia::render('Settings/Appearance'))->name('settings.appearance');
    Route::get('/settings/predplatne', fn() => Inertia::render('Settings/Subscription'))->name('settings.subscription');
    Route::get('/travel-inbox', fn() => Inertia::render('TravelInbox/Index'))->name('travel-inbox');
    Route::get('/weekly', fn() => Inertia::render('Weekly/Index'))->name('weekly');
    Route::get('/planning', fn() => Inertia::render('Planning/Index'))->name('planning');
    Route::get('/finances', fn() => Inertia::render('Finance/Index'))->name('finances');
    Route::get('/finance', fn() => Inertia::render('Finance/Index'))->name('finance');
    Route::get('/watchlist', fn() => Inertia::render('Watchlist/Index'))->name('watchlist');
    Route::get('/watchlist/movies', fn() => Inertia::render('Watchlist/Index'))->name('watchlist.movies');
    Route::get('/watchlist/series', fn() => Inertia::render('Watchlist/Index'))->name('watchlist.series');
    Route::get('/watchlist/movies/tierlist', fn() => Inertia::render('Watchlist/Index'))->name('watchlist.movies.tierlist');
    Route::get('/watchlist/series/tierlist', fn() => Inertia::render('Watchlist/Index'))->name('watchlist.series.tierlist');
    Route::get('/date-ideas', fn() => Inertia::render('DateIdeas/Index'))->name('date-ideas');
    Route::get('/anniversary-album', fn() => Inertia::render('AnniversaryAlbum/Index'))->name('anniversary-album');
    Route::get('/gifts-anniversaries', fn() => Inertia::render('GiftsAnniversaries/Index'))->name('gifts-anniversaries');
    Route::get('/recipes', fn() => Inertia::render('Recipes/Index'))->name('recipes.index');
    Route::get('/recipes/{uuid}', fn(string $uuid) => Inertia::render('Recipes/Show', ['recipeUuid' => $uuid]))->name('recipes.show');
    Route::get('/milestones', fn() => Inertia::render('Milestones/Index'))->name('milestones');
    Route::get('/shared-memories', fn() => Inertia::render('SharedMemories/Index'))->name('shared-memories');

    // Stats
    Route::get('/stats',    [App\Http\Controllers\StatsController::class,    'index'])->name('stats');

    // Inbox (unboxed media)
    Route::get('/inbox',    [App\Http\Controllers\InboxController::class,    'index'])->name('inbox');

    // People
    Route::get('/people',   fn() => Inertia::render('People/Index'))->name('people');
    Route::get('/people/{person}', fn() => Inertia::render('People/Show'))->name('people.show-page');

    // Places (Místa jako plnohodnotné stránky)
    Route::get('/places',      fn() => Inertia::render('Places/Index'))->name('places');
    Route::get('/places/{id}', fn() => Inertia::render('Places/Show'))->name('places.show-page');

    // Activity
    Route::get('/activity', [App\Http\Controllers\ActivityController::class, 'index'])->name('activity');

    // Journey (Naše cesta)
    Route::get('/journey',    fn() => Inertia::render('Journey/Index'))->name('journey');

    // Itinerary (světový itinerář)
    Route::get('/itinerary',  fn() => Inertia::render('Itinerary/Index'))->name('itinerary');

    // Tags
    Route::get('/tags', fn() => Inertia::render('Tags/Index'))->name('tags');

    // Recovery Center
    Route::get('/recovery', [App\Http\Controllers\RecoveryController::class, 'index'])->name('recovery');
    Route::get('/privacy', [App\Http\Controllers\PrivacyController::class, 'index'])->name('privacy');
    Route::patch('/privacy/legacy', [App\Http\Controllers\PrivacyController::class, 'updateLegacy'])->name('privacy.legacy');

    // Export
    Route::post('/export/download', [App\Http\Controllers\ExportController::class, 'download'])->name('export.download');

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

    // Private vault (15-minute re-authenticated session)
    Route::get('/vault', [VaultController::class, 'index'])->name('vault.index');
    Route::post('/vault/unlock', [VaultController::class, 'unlock'])->middleware('throttle:5,1')->name('vault.unlock');
    Route::post('/vault/lock', [VaultController::class, 'lock'])->name('vault.lock');
    Route::post('/vault/media/{uuid}/toggle', [VaultController::class, 'toggle'])->name('vault.toggle');

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
        Route::get('/security', [App\Http\Controllers\SecuritySessionController::class, 'index'])->name('security');
        Route::get('/automations', fn() => Inertia::render('Settings/Automations'))->name('automations');
        Route::delete('/security/sessions/{sessionId}', [App\Http\Controllers\SecuritySessionController::class, 'destroy'])->name('security.sessions.destroy');
        Route::post('/security/sessions/revoke-others', [App\Http\Controllers\SecuritySessionController::class, 'destroyOthers'])->name('security.sessions.revoke-others');
    });

    // Admin only
    Route::middleware(['can:admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/',              [AdminController::class, 'dashboard'])->name('dashboard');
        Route::get('/storage-risk',  [StorageRiskController::class, 'index'])->name('storage-risk');
        Route::get('/tarify',        fn() => Inertia::render('Admin/PlanMatrix'))->name('plan-matrix');
        Route::get('/users',         [AdminController::class, 'users'])->name('users');
        Route::post('/users/invite', [AdminController::class, 'invite'])->name('users.invite');
        Route::get('/jobs',          [AdminController::class, 'jobs'])->name('jobs');
        Route::get('/audit',         [AdminController::class, 'audit'])->name('audit');
        Route::get('/health',        [AdminController::class, 'health'])->name('health');
        Route::get('/integrations',  [App\Http\Controllers\Admin\IntegrationController::class, 'index'])->name('integrations.index');
        Route::put('/integrations/{provider}', [App\Http\Controllers\Admin\IntegrationController::class, 'update'])->name('integrations.update');
        Route::post('/integrations/{provider}/test', [App\Http\Controllers\Admin\IntegrationController::class, 'test'])->name('integrations.test');
    });
});

// ── Google OAuth ────────────────────────────────────────
Route::middleware(['auth'])->group(function () {
    Route::get('/oauth/google/redirect',  [GoogleOAuthController::class, 'redirect'])->name('oauth.google.redirect');
    Route::get('/oauth/google/callback',  [GoogleOAuthController::class, 'callback'])->name('oauth.google.callback');
    Route::post('/settings/storage/google/disconnect', [GoogleOAuthController::class, 'disconnect'])->name('storage.google.disconnect');
    Route::post('/settings/storage/google/reconnect',  [GoogleOAuthController::class, 'reconnect'])->name('storage.google.reconnect');
    Route::post('/settings/storage/google/test',            [GoogleOAuthController::class, 'test'])->name('storage.google.test');
    Route::post('/settings/storage/google/sync-existing',   [GoogleOAuthController::class, 'syncExisting'])->name('storage.google.sync-existing');
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
