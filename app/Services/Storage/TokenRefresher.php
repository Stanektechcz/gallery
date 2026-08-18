<?php

namespace App\Services\Storage;

use App\Models\StorageConnection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a cloud connection alive.
 *
 * Dropbox tokens last four hours, Google's one. Without this, every connection works
 * perfectly on the day it is made and is dead by the next morning — the failure people
 * describe as "it just stopped", which is the hardest kind to diagnose because nothing
 * happened at the moment it broke.
 *
 * One refresher for every provider rather than one each. The exchange differs only in an
 * endpoint and a credential, and two copies of this logic would drift the first time one
 * of them was fixed.
 */
class TokenRefresher
{
    private const ENDPOINTS = [
        'google_drive' => 'https://oauth2.googleapis.com/token',
        'dropbox' => 'https://api.dropboxapi.com/oauth2/token',
        'onedrive' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
    ];

    /** Refresh this far ahead of expiry, so a long upload does not start on a dying token. */
    private const MARGIN_MINUTES = 10;

    public function __construct(private readonly StorageResolver $resolver)
    {
    }

    /**
     * A usable access token, refreshing first if the stored one is close to expiring.
     *
     * Returns null rather than throwing when the connection cannot be revived. The caller
     * is usually saving somebody's photograph, and an exception there would lose the
     * upload over a problem the person cannot act on mid-gesture.
     */
    public function accessToken(StorageConnection $connection): ?string
    {
        if (! isset(self::ENDPOINTS[$connection->provider])) return null;

        $fresh = $connection->token_expires_at
            && $connection->token_expires_at->isAfter(now()->addMinutes(self::MARGIN_MINUTES));

        if ($fresh && $connection->encrypted_access_token) {
            return $this->decrypt($connection->encrypted_access_token);
        }

        return $this->refresh($connection);
    }

    public function refresh(StorageConnection $connection): ?string
    {
        $refreshToken = $connection->encrypted_refresh_token
            ? $this->decrypt($connection->encrypted_refresh_token)
            : null;

        if (! $refreshToken) {
            $this->fail($connection, 'no_refresh_token',
                'Připojení nemá obnovovací token. Připojte úložiště znovu.');

            return null;
        }

        $credentials = $this->resolver->credentials($connection->provider);

        $response = Http::asForm()->post(self::ENDPOINTS[$connection->provider], [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
        ]);

        if ($response->failed()) {
            // A revoked grant is not a transient error and will not fix itself, so it is
            // recorded as a state the screen can explain rather than retried forever.
            $this->fail($connection, (string) $response->json('error', 'refresh_failed'),
                (string) $response->json('error_description', 'Obnovení přístupu selhalo.'));

            return null;
        }

        $tokens = $response->json();
        $access = $tokens['access_token'] ?? null;
        if (! $access) {
            $this->fail($connection, 'no_access_token', 'Služba nevrátila přístupový token.');

            return null;
        }

        $connection->forceFill([
            'encrypted_access_token' => Crypt::encryptString($access),
            'token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
            'connection_status' => StorageConnection::STATUS_HEALTHY,
            'last_successful_request_at' => now(),
            'last_error_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ]);

        // Some providers rotate the refresh token on use. Keeping the old one would work
        // once and then lock the account out.
        if (! empty($tokens['refresh_token'])) {
            $connection->forceFill(['encrypted_refresh_token' => Crypt::encryptString($tokens['refresh_token'])]);
        }

        $connection->save();

        return $access;
    }

    private function fail(StorageConnection $connection, string $code, string $message): void
    {
        $connection->forceFill([
            'connection_status' => StorageConnection::STATUS_ERROR,
            'last_error_at' => now(),
            'last_error_code' => $code,
            'last_error_message' => $message,
        ])->save();

        Log::warning('Obnovení úložiště selhalo', [
            'provider' => $connection->provider, 'space' => $connection->gallery_space_id, 'code' => $code,
        ]);
    }

    /** A token encrypted under a rotated key is unreadable, not a reason to fall over. */
    private function decrypt(string $value): ?string
    {
        try { return Crypt::decryptString($value); }
        catch (\Throwable) { return null; }
    }
}
