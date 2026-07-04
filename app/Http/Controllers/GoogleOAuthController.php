<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\StorageConnection;
use App\Services\Storage\DriveStructureService;
use App\Services\Storage\GoogleOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class GoogleOAuthController extends Controller
{
    public function __construct(private readonly GoogleOAuthService $oauthService) {}

    /**
     * GET /settings/storage/google/connect
     * Show Drive connection status page.
     */
    public function showConnect(Request $request): Response
    {
        $user       = $request->user();
        $connection = StorageConnection::where('owner_user_id', $user->id)
            ->where('provider', 'google_drive')
            ->first();

        return Inertia::render('Settings/Storage/Google', [
            'connection' => $connection ? [
                'status'       => $connection->connection_status,
                'account_email' => $connection->account_email,
                'root_folder'  => $connection->root_folder_name,
                'quota_total'  => $connection->quota_total,
                'quota_used'   => $connection->quota_used,
                'connected_at' => $connection->connected_at,
                'last_ok'      => $connection->last_successful_request_at,
                'last_error'   => $connection->last_error_message,
            ] : null,
            'client_configured' => !empty(config('services.google.client_id')),
        ]);
    }

    /**
     * GET /oauth/google/redirect
     * Redirect user to Google OAuth consent.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $user       = $request->user();
        $connection = StorageConnection::where('owner_user_id', $user->id)->first();

        // Force consent only if no refresh token or explicitly requested
        $forceConsent = $request->boolean('force') || !$connection?->getRefreshToken();

        $url = $this->oauthService->getAuthorizationUrl($forceConsent);

        return redirect()->away($url);
    }

    /**
     * GET /oauth/google/callback
     * Handle OAuth callback, store tokens.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            Log::warning('Google OAuth error', ['error' => $request->input('error')]);
            return redirect()->route('settings.storage.google')
                ->with('error', 'Autorizace Google byla zrušena: ' . $request->input('error_description', 'neznámá chyba'));
        }

        $code = $request->input('code');
        if (!$code) {
            return redirect()->route('settings.storage.google')
                ->with('error', 'Chybí autorizační kód od Google.');
        }

        try {
            $connection = $this->oauthService->handleCallback($code, $request->user());

            // Initialize Drive root structure
            $driveService = new DriveStructureService($connection);
            $structure    = $driveService->initializeRootStructure();

            AuditLog::record('storage.google.connect', $connection, [
                'account'   => $connection->account_email,
                'root_id'   => $structure['root_id'],
            ]);

            return redirect()->route('settings.storage.google')
                ->with('success', "Google Drive připojen. Účet: {$connection->account_email}");
        } catch (\Throwable $e) {
            Log::error('Google OAuth callback failed', ['error' => $e->getMessage()]);
            return redirect()->route('settings.storage.google')
                ->with('error', 'Připojení Google Drive selhalo: ' . $e->getMessage());
        }
    }

    /**
     * POST /settings/storage/google/disconnect
     */
    public function disconnect(Request $request): RedirectResponse
    {
        if (!$request->user()->isAdmin()) {
            abort(403);
        }

        $connection = StorageConnection::where('owner_user_id', $request->user()->id)->firstOrFail();
        $this->oauthService->revokeToken($connection);

        AuditLog::record('storage.google.disconnect', $connection);

        return back()->with('success', 'Google Drive byl odpojen.');
    }

    /**
     * POST /settings/storage/google/reconnect
     */
    public function reconnect(Request $request): RedirectResponse
    {
        if (!$request->user()->isAdmin()) abort(403);

        $connection = StorageConnection::where('owner_user_id', $request->user()->id)->firstOrFail();
        $refreshed  = $this->oauthService->refreshToken($connection);

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
    public function test(Request $request): \Illuminate\Http\JsonResponse
    {
        if (!$request->user()->isAdmin()) abort(403);

        $connection = StorageConnection::where('owner_user_id', $request->user()->id)->firstOrFail();
        $service    = new DriveStructureService($connection);
        $results    = $service->runDiagnosticTest();

        return response()->json(['tests' => $results]);
    }

    /**
     * POST /settings/storage/google/init-structure
     * (Re-)initialize Drive root folder structure when root_folder_id is missing.
     */
    public function initStructure(Request $request): RedirectResponse
    {
        if (!$request->user()->isAdmin()) abort(403);

        $connection = StorageConnection::where('owner_user_id', $request->user()->id)->firstOrFail();

        try {
            $driveService = new DriveStructureService($connection);
            $structure    = $driveService->initializeRootStructure();

            AuditLog::record('storage.google.init_structure', $connection, [
                'root_id' => $structure['root_id'],
            ]);

            return back()->with('success', 'Struktura Google Drive byla inicializována. Root ID: ' . $structure['root_id']);
        } catch (\Throwable $e) {
            Log::error('Drive initStructure failed', ['error' => $e->getMessage()]);
            return back()->with('error', 'Inicializace selhala: ' . $e->getMessage());
        }
    }
}
