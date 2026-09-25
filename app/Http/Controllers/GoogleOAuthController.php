<?php

namespace App\Http\Controllers;

use App\Jobs\Media\EnqueueDriveMediaSyncJob;
use App\Models\AuditLog;
use App\Models\StorageConnection;
use App\Models\User;
use App\Services\Auth\PristupDoGalerie;
use App\Services\Storage\DriveStructureService;
use App\Services\Storage\GoogleOAuthService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GoogleOAuthController extends Controller
{
    /** Klíč sezení s náhodným stavem OAuth — viz `redirect()` a `callback()`. */
    private const STATE_KEY = 'oauth.google.state';

    /** Poskytovatel, pod kterým `GoogleOAuthService::handleCallback()` ukládá připojení. */
    private const PROVIDER = 'google_drive';

    public function __construct(private readonly GoogleOAuthService $oauthService) {}

    /**
     * GET /settings/storage/google/connect
     * Show Drive connection status page.
     */
    public function showConnect(Request $request): Response
    {
        $user = $request->user();
        $connection = $this->pripojeni($user)->first();

        return Inertia::render('Settings/Storage/Google', [
            'connection' => $connection ? [
                'status' => $connection->connection_status,
                'account_email' => $connection->account_email,
                'root_folder' => $connection->root_folder_name,
                'quota_total' => $connection->quota_total,
                'quota_used' => $connection->quota_used,
                'connected_at' => $connection->connected_at,
                'last_ok' => $connection->last_successful_request_at,
                'last_error' => $connection->last_error_message,
            ] : null,
            'client_configured' => ! empty(config('services.google.client_id')),
        ]);
    }

    /**
     * GET /oauth/google/redirect
     * Redirect user to Google OAuth consent.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $user = $request->user();
        $connection = $this->pripojeni($user)->first();

        // Force consent only if no refresh token or explicitly requested
        $forceConsent = $request->boolean('force') || ! $connection?->getRefreshToken();

        // Náhodný stav v sezení, ověřený při návratu — stejně jako u Discordu
        // a Dropboxu. Viz `callback()`.
        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        $url = $this->oauthService->getAuthorizationUrl($forceConsent, $state);

        return redirect()->away($url);
    }

    /**
     * GET /oauth/google/callback
     * Handle OAuth callback, store tokens.
     */
    public function callback(Request $request): RedirectResponse
    {
        /*
         * Návrat patří k přesměrování, které začalo tady.
         *
         * Bez toho šlo přihlášené oběti podstrčit odkaz s kódem od útočníkova
         * Googlu: server by kód vyměnil, uložil **jeho** Disk k jejímu účtu
         * a hned zařadil synchronizaci všech jejích galerií — celá knihovna
         * by odtekla na cizí Disk. Stav se z sezení vytahuje (`pull`), takže
         * platí jednou, a porovnává se v konstantním čase.
         *
         * Ověřuje se **před** `?error=`: chybový návrat bez platného stavu je
         * cizí odkaz jako každý jiný a nemá dostat vlastní hlášku.
         */
        $ocekavany = $request->session()->pull(self::STATE_KEY);
        $prisly = $request->query('state');

        if (! is_string($ocekavany) || ! is_string($prisly) || ! hash_equals($ocekavany, $prisly)) {
            Log::warning('Google OAuth callback bez platného state', ['user_id' => $request->user()->id]);

            return redirect()->route('settings.storage.google')
                ->with('error', 'Připojení Google Disku nepatří k tomuhle přihlášení. Spusťte ho prosím znovu z nastavení.');
        }

        /*
         * Pevná hláška, ne `error_description`.
         *
         * Ten text jde z adresy a poslat ji může kdokoli — na naší doméně by
         * se z něj stala důvěryhodně vypadající zpráva („účet zablokován,
         * volejte…"). Do logu jde jen krátký kód chyby.
         */
        if ($request->has('error')) {
            Log::warning('Google OAuth error', ['error' => Str::limit((string) $request->input('error'), 64, '')]);

            return redirect()->route('settings.storage.google')
                ->with('error', 'Autorizace Google byla zrušena nebo odmítnuta. Připojení můžete spustit znovu.');
        }

        $code = $request->input('code');
        if (! $code) {
            return redirect()->route('settings.storage.google')
                ->with('error', 'Chybí autorizační kód od Google.');
        }

        try {
            $connection = $this->oauthService->handleCallback($code, $request->user());

            // Initialize Drive root structure
            $driveService = $this->struktura($connection);
            $structure = $driveService->initializeRootStructure();

            // A connection may be added after years of local uploads. Queue
            // those originals immediately instead of synchronising only files
            // uploaded after the OAuth callback.
            foreach ($this->prostoryDvojice($request->user()) as $spaceId) {
                EnqueueDriveMediaSyncJob::dispatch((int) $spaceId)->onQueue('drive');
            }

            AuditLog::record('storage.google.connect', $connection, [
                'account' => $connection->account_email,
                'root_id' => $structure['root_id'],
            ]);

            return redirect()->route('settings.storage.google')
                ->with('success', "Google Drive připojen. Účet: {$connection->account_email}. Existující média byla zařazena k synchronizaci.");
        } catch (\Throwable $e) {
            Log::error('Google OAuth callback failed', ['error' => $e->getMessage()]);

            return redirect()->route('settings.storage.google')
                ->with('error', 'Připojení Google Drive selhalo: '.$e->getMessage());
        }
    }

    /**
     * POST /settings/storage/google/disconnect
     *
     * Když Google odvolání nepotvrdí, připojení se u nás stejně přestane
     * používat — ale hláška to řekne. Dřív tvrdila „odpojeno" i tehdy, když
     * aplikace k Disku přístup dál měla a zrušit ho šlo jen v účtu Google.
     */
    public function disconnect(Request $request): RedirectResponse
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }

        $connection = $this->pripojeni($request->user())->firstOrFail();
        $odvolano = $this->oauthService->revokeToken($connection);

        if (! $odvolano) {
            $connection->markStatus('revoked');
            $connection->update(['revoked_at' => now()]);
        }

        AuditLog::record('storage.google.disconnect', $connection, ['google_confirmed' => $odvolano]);

        // Klíč `error`, ne `warning`: rozvržení ukazuje jen `success` a `error`.
        if (! $odvolano) {
            return back()->with('error', 'Galerie Google Disk přestala používat, ale Google odvolání přístupu nepotvrdil. '
                .'Přístup aplikace můžete odebrat sami v nastavení svého účtu Google, v části Zabezpečení.');
        }

        return back()->with('success', 'Google Drive byl odpojen.');
    }

    /**
     * POST /settings/storage/google/reconnect
     */
    public function reconnect(Request $request): RedirectResponse
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }

        $connection = $this->pripojeni($request->user())->firstOrFail();
        $refreshed = $this->oauthService->refreshToken($connection);

        if ($refreshed) {
            AuditLog::record('storage.google.reconnect', $connection);

            return back()->with('success', 'Token byl obnoven.');
        }

        return redirect()->route('oauth.google.redirect', ['force' => 1])
            ->with('warning', 'Token nelze obnovit. Proveďte novou autorizaci.');
    }

    /**
     * POST /settings/storage/google/test
     * Run connectivity test.
     */
    public function test(Request $request): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }

        $connection = $this->pripojeni($request->user())->firstOrFail();
        $service = $this->struktura($connection);
        $results = $service->runDiagnosticTest();

        return response()->json(['tests' => $results]);
    }

    /** Requeue existing local originals after a repaired/reconnected Drive. */
    public function syncExisting(Request $request): RedirectResponse
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }

        $connection = $this->pripojeni($request->user())
            ->where('connection_status', 'healthy')
            ->firstOrFail();

        $spaceIds = $this->prostoryDvojice($request->user());
        foreach ($spaceIds as $spaceId) {
            EnqueueDriveMediaSyncJob::dispatch((int) $spaceId)->onQueue('drive');
        }

        AuditLog::record('storage.google.sync_existing', $connection, ['space_count' => $spaceIds->count()]);

        return back()->with('success', 'Existující média byla zařazena do bezpečné synchronizace Google Drive.');
    }

    /**
     * POST /settings/storage/google/init-structure
     * (Re-)initialize Drive root folder structure when root_folder_id is missing.
     */
    public function initStructure(Request $request): RedirectResponse
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }

        $connection = $this->pripojeni($request->user())->firstOrFail();

        try {
            $driveService = $this->struktura($connection);
            $structure = $driveService->initializeRootStructure();

            AuditLog::record('storage.google.init_structure', $connection, [
                'root_id' => $structure['root_id'],
            ]);

            return back()->with('success', 'Struktura Google Drive byla inicializována. Root ID: '.$structure['root_id']);
        } catch (\Throwable $e) {
            Log::error('Drive initStructure failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Inicializace selhala: '.$e->getMessage());
        }
    }

    /**
     * Připojení Google Disku tohoto účtu — jen Google, ne jiný poskytovatel.
     *
     * Dropbox a OneDrive ukládají řádky pro téhož vlastníka. Dotaz jen podle
     * vlastníka tak vzal první z nich: „Odpojit Google Disk" odvolal Dropbox,
     * test i obnova tokenu šly na cizí připojení a přesměrování ke Googlu
     * podle refresh tokenu Dropboxu vynechalo souhlas.
     *
     * @return Builder<StorageConnection>
     */
    private function pripojeni(User $user): Builder
    {
        return StorageConnection::where('owner_user_id', $user->id)
            ->where('provider', self::PROVIDER);
    }

    /**
     * Prostory, jejichž originály smí jít na Disk tohoto účtu.
     *
     * Jen ty, kde je účet vlastníkem nebo členem dvojice — ne hostem.
     * Host, který si připojil vlastní Disk, by jinak zařadil synchronizaci
     * originálů galerie, do které jen nahlíží. Stejné pravidlo drží
     * `DriveConnectionResolver`.
     *
     * @return Collection<int, int>
     */
    private function prostoryDvojice(User $user): Collection
    {
        return $user->gallerySpaces()
            ->where(fn ($q) => $q->whereIn('gallery_space_user.role', PristupDoGalerie::ROLE_DVOJICE)
                ->orWhere('gallery_spaces.owner_id', $user->id))
            ->pluck('gallery_spaces.id');
    }

    /** Přes kontejner, aby šla služba v testech nahradit bez volání Googlu. */
    private function struktura(StorageConnection $connection): DriveStructureService
    {
        return app()->makeWith(DriveStructureService::class, ['connection' => $connection]);
    }
}
