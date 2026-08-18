<?php

namespace App\Http\Controllers\Storage;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\StorageConnection;
use App\Services\Storage\StorageResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Joining a OneDrive account to the gallery.
 *
 * Deliberately the same shape as the Dropbox controller. The differences are an endpoint,
 * a scope string and where the account's name lives in the reply; making them look alike
 * means a fix to one is obviously needed in the other, which is not true of two designs
 * that merely do the same job.
 *
 * `offline_access` is the scope that earns a refresh token. Without it Microsoft issues an
 * hour's access and no way to renew, and the connection dies before the day is out.
 */
class OneDriveOAuthController extends Controller
{
    private const AUTHORISE = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    private const TOKEN = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    private const ME = 'https://graph.microsoft.com/v1.0/me';

    private const SCOPES = 'offline_access Files.ReadWrite User.Read';

    public function __construct(private readonly StorageResolver $resolver)
    {
    }

    public function start(Request $request): RedirectResponse
    {
        abort_unless($this->resolver->configured('onedrive'), 503, 'OneDrive zatím není nastavený.');

        $space = $this->space($request);
        abort_unless($this->resolver->mayManage($space, $request->user()->id), 403,
            'Úložiště prostoru může připojit jen vlastník tarifu.');

        $state = Str::random(40);
        $request->session()->put('onedrive.state', $state);

        return redirect()->away(self::AUTHORISE . '?' . http_build_query([
            'client_id' => $this->resolver->credentials('onedrive')['client_id'],
            'redirect_uri' => $this->resolver->credentials('onedrive')['redirect'],
            'response_type' => 'code',
            'response_mode' => 'query',
            'scope' => self::SCOPES,
            'state' => $state,
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless($this->resolver->configured('onedrive'), 503, 'OneDrive zatím není nastavený.');

        $expected = $request->session()->pull('onedrive.state');

        if (! $expected || ! $request->filled('state') || ! hash_equals($expected, $request->string('state')->toString())) {
            return redirect()->route('connections')
                ->with('error', 'Přihlášení k OneDrive se nepodařilo ověřit. Zkuste to prosím znovu.');
        }

        if ($request->filled('error')) {
            return redirect()->route('connections')
                ->with('error', 'OneDrive přístup nepovolil: ' . $request->string('error_description')->toString());
        }

        abort_unless($request->filled('code'), 400);

        $credentials = $this->resolver->credentials('onedrive');

        $exchange = Http::asForm()->post(self::TOKEN, [
            'code' => $request->string('code')->toString(),
            'grant_type' => 'authorization_code',
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'redirect_uri' => $credentials['redirect'],
            'scope' => self::SCOPES,
        ]);

        if ($exchange->failed()) {
            return redirect()->route('connections')
                ->with('error', 'OneDrive odmítl výměnu kódu: ' . $exchange->json('error_description', 'neznámá chyba'));
        }

        $tokens = $exchange->json();

        if (empty($tokens['refresh_token'])) {
            return redirect()->route('connections')
                ->with('error', 'OneDrive nevrátil obnovovací token. Zkontrolujte, že aplikace žádá o oprávnění offline_access.');
        }

        $me = Http::withToken($tokens['access_token'])->get(self::ME);

        // Keyed by space as well as provider: one customer connecting their OneDrive must
        // not redirect another customer's photographs into it.
        StorageConnection::updateOrCreate(
            ['provider' => 'onedrive', 'gallery_space_id' => $this->space($request)->id],
            [
                'owner_user_id' => $request->user()->id,
                // Graph returns a work address in mail and a personal one in userPrincipalName.
                'account_email' => $me->json('mail') ?: $me->json('userPrincipalName'),
                'encrypted_access_token' => Crypt::encryptString($tokens['access_token']),
                'encrypted_refresh_token' => Crypt::encryptString($tokens['refresh_token']),
                'token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
                'granted_scopes_json' => $tokens['scope'] ?? null,
                'connection_status' => StorageConnection::STATUS_HEALTHY,
                'last_successful_request_at' => now(),
                'last_error_at' => null, 'last_error_code' => null, 'last_error_message' => null,
            ]
        );

        AuditLog::record('storage.onedrive.connected', null, ['account' => $me->json('userPrincipalName')]);

        return redirect()->route('connections')->with('success', 'OneDrive připojen.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $space = $this->space($request);
        abort_unless($this->resolver->mayManage($space, $request->user()->id), 403,
            'Úložiště prostoru může odpojit jen vlastník tarifu.');

        // The row goes; the files stay in OneDrive. New photographs go to the local disk,
        // which is the floor rather than a failure.
        StorageConnection::where('provider', 'onedrive')->where('gallery_space_id', $space->id)->delete();
        AuditLog::record('storage.onedrive.disconnected');

        return redirect()->route('connections')->with('success', 'OneDrive odpojen. Soubory v OneDrive zůstávají.');
    }

    private function space(Request $request): GallerySpace
    {
        $space = $request->user()->gallerySpaces()->orderByDesc('is_default')->first();
        abort_unless($space, 404, 'Prostor nebyl nalezen.');

        return $space;
    }
}
