<?php

namespace App\Http\Controllers\Storage;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StorageConnection;
use App\Services\Storage\StorageResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Joining a Dropbox account to the gallery.
 *
 * The authorisation half only: this obtains a refreshable credential and records whose it
 * is. Putting files there is a separate piece of work, and shipping the two together would
 * mean neither could be verified on its own.
 *
 * `token_access_type=offline` is not optional. Without it Dropbox issues a token that
 * expires in four hours and no way to renew it, which would work perfectly on the day it
 * was connected and fail silently the next morning.
 */
class DropboxOAuthController extends Controller
{
    private const AUTHORISE = 'https://www.dropbox.com/oauth2/authorize';
    private const TOKEN = 'https://api.dropboxapi.com/oauth2/token';
    private const ACCOUNT = 'https://api.dropboxapi.com/2/users/get_current_account';

    public function __construct(private readonly StorageResolver $resolver)
    {
    }

    public function start(Request $request): RedirectResponse
    {
        abort_unless($this->configured(), 503, 'Dropbox zatím není nastavený.');

        $space = $this->space($request);
        abort_unless($this->resolver->mayManage($space, $request->user()->id), 403,
            'Úložiště prostoru může připojit jen vlastník tarifu.');

        // A one-time value tied to this session. Without it, anyone could hand somebody a
        // callback link and attach their own Dropbox to the victim's gallery.
        $state = Str::random(40);
        $request->session()->put('dropbox.state', $state);

        return redirect()->away(self::AUTHORISE . '?' . http_build_query([
            'client_id' => $this->resolver->credentials('dropbox')['client_id'],
            'redirect_uri' => $this->resolver->credentials('dropbox')['redirect'],
            'response_type' => 'code',
            'token_access_type' => 'offline',
            'state' => $state,
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless($this->configured(), 503, 'Dropbox zatím není nastavený.');

        $expected = $request->session()->pull('dropbox.state');

        // Compared with hash_equals and refused when either side is missing: a callback
        // arriving without a state is exactly the request this check exists to stop.
        if (! $expected || ! $request->filled('state') || ! hash_equals($expected, $request->string('state')->toString())) {
            return redirect()->route('connections')->with('error', 'Přihlášení k Dropboxu se nepodařilo ověřit. Zkuste to prosím znovu.');
        }

        if ($request->filled('error')) {
            return redirect()->route('connections')
                ->with('error', 'Dropbox přístup nepovolil: ' . $request->string('error_description')->toString());
        }

        abort_unless($request->filled('code'), 400);

        $exchange = Http::asForm()->post(self::TOKEN, [
            'code' => $request->string('code')->toString(),
            'grant_type' => 'authorization_code',
            'client_id' => $this->resolver->credentials('dropbox')['client_id'],
            'client_secret' => $this->resolver->credentials('dropbox')['client_secret'],
            'redirect_uri' => $this->resolver->credentials('dropbox')['redirect'],
        ]);

        if ($exchange->failed()) {
            return redirect()->route('connections')
                ->with('error', 'Dropbox odmítl výměnu kódu: ' . $exchange->json('error_description', 'neznámá chyba'));
        }

        $tokens = $exchange->json();

        // A connection without a refresh token is one that stops working overnight, so it
        // is refused here rather than stored to fail later.
        if (empty($tokens['refresh_token'])) {
            return redirect()->route('connections')
                ->with('error', 'Dropbox nevrátil obnovovací token. Odpojte aplikaci v nastavení Dropboxu a zkuste to znovu.');
        }

        $account = Http::withToken($tokens['access_token'])->post(self::ACCOUNT);

        // Keyed by space as well as provider: one customer connecting their Dropbox must
        // not redirect another customer's photographs into it.
        StorageConnection::updateOrCreate(
            ['provider' => 'dropbox', 'gallery_space_id' => $this->space($request)->id],
            [
                'owner_user_id' => $request->user()->id,
                'account_email' => $account->json('email'),
                'encrypted_access_token' => Crypt::encryptString($tokens['access_token']),
                'encrypted_refresh_token' => Crypt::encryptString($tokens['refresh_token']),
                'token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 14400)),
                'granted_scopes_json' => $tokens['scope'] ?? null,
                'connection_status' => 'connected',
                'last_successful_request_at' => now(),
                'last_error_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ]
        );

        AuditLog::record('storage.dropbox.connected', null, ['account' => $account->json('email')]);

        return redirect()->route('connections')->with('success', 'Dropbox připojen.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $space = $this->space($request);
        abort_unless($this->resolver->mayManage($space, $request->user()->id), 403,
            'Úložiště prostoru může odpojit jen vlastník tarifu.');

        // The row goes; the files do not. Nothing here reaches into somebody's Dropbox to
        // delete what it finds. Photographs saved from here on go to the local disk, which
        // is the floor rather than a failure.
        StorageConnection::where('provider', 'dropbox')->where('gallery_space_id', $space->id)->delete();
        AuditLog::record('storage.dropbox.disconnected');

        return redirect()->route('connections')->with('success', 'Dropbox odpojen. Soubory v Dropboxu zůstávají.');
    }

    private function configured(): bool
    {
        return $this->resolver->configured('dropbox');
    }

    private function space(Request $request): \App\Models\GallerySpace
    {
        $space = $request->user()->gallerySpaces()->orderByDesc('is_default')->first();
        abort_unless($space, 404, 'Prostor nebyl nalezen.');

        return $space;
    }
}
