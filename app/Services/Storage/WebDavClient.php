<?php

namespace App\Services\Storage;

use App\Models\StorageConnection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Somebody's own storage, over WebDAV.
 *
 * The answer to "let me use my account" for every service that has no OAuth: Nextcloud,
 * ownCloud, Koofr, pCloud, Box, a NAS in somebody's flat. One protocol instead of one
 * integration each, and the person keeps their files on hardware or a provider they chose.
 *
 * The credential is an app password, never the account password. Every service worth
 * connecting issues them, they can be revoked without changing anything else, and they
 * usually cannot be used to sign in to the account itself. The screen says so; this refuses
 * to pretend the difference does not matter.
 *
 * There is no refresh: an app password does not expire, which is why this needs no
 * TokenRefresher and why a connection that stops working means somebody revoked it.
 */
class WebDavClient
{
    /** Anything larger goes as one PUT anyway; this only stops us buffering a film in memory. */
    private const MAX_BYTES = 200 * 1024 * 1024;

    /** @return array{ok: bool, error?: string} */
    public function probe(StorageConnection $connection): array
    {
        $credentials = $this->credentials($connection);
        if (! $credentials) return ['ok' => false, 'error' => 'Přihlašovací údaje se nepodařilo přečíst.'];

        // PROPFIND with depth 0 asks "does this exist and may I see it", which is the
        // cheapest question that proves both the address and the password.
        $response = Http::withBasicAuth($credentials['user'], $credentials['pass'])
            ->withHeaders(['Depth' => '0'])
            ->send('PROPFIND', $credentials['url']);

        if ($response->status() === 401) {
            $this->fail($connection, 'unauthorized', 'Přihlášení odmítnuto. Zkontrolujte uživatele a heslo aplikace.');

            return ['ok' => false, 'error' => 'Přihlášení odmítnuto.'];
        }

        if ($response->failed()) {
            $this->fail($connection, 'unreachable', 'Server neodpověděl (HTTP ' . $response->status() . ').');

            return ['ok' => false, 'error' => 'Server neodpověděl (HTTP ' . $response->status() . ').'];
        }

        $connection->forceFill([
            'connection_status' => 'connected',
            'last_successful_request_at' => now(),
            'last_error_at' => null, 'last_error_code' => null, 'last_error_message' => null,
        ])->save();

        return ['ok' => true];
    }

    /** @return array{ok: bool, path?: string, size?: int, error?: string} */
    public function upload(StorageConnection $connection, string $remotePath, string $contents): array
    {
        if (strlen($contents) > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'Soubor je nad 200 MB.'];
        }

        $credentials = $this->credentials($connection);
        if (! $credentials) return ['ok' => false, 'error' => 'Přihlašovací údaje se nepodařilo přečíst.'];

        $base = rtrim($credentials['url'], '/');
        $target = $base . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($remotePath, '/'))));

        // The folder has to exist first; WebDAV will not make one on the way. MKCOL on a
        // folder that is already there answers 405, which is a success for our purposes.
        $this->ensureFolder($credentials, $base, dirname(ltrim($remotePath, '/')));

        $response = Http::withBasicAuth($credentials['user'], $credentials['pass'])
            ->withBody($contents, 'application/octet-stream')
            ->put($target);

        if ($response->failed()) {
            $reason = 'Nahrání selhalo (HTTP ' . $response->status() . ').';
            $this->fail($connection, 'upload_failed', $reason);

            return ['ok' => false, 'error' => $reason];
        }

        return ['ok' => true, 'path' => $remotePath, 'size' => strlen($contents)];
    }

    public function folderFor(StorageConnection $connection): string
    {
        return 'MAKI Gallery/prostor-' . $connection->gallery_space_id;
    }

    /** Creates each level in turn; a level that exists answers 405 and is stepped over. */
    private function ensureFolder(array $credentials, string $base, string $folder): void
    {
        $walked = '';

        foreach (array_filter(explode('/', $folder)) as $segment) {
            $walked .= ($walked ? '/' : '') . rawurlencode($segment);

            Http::withBasicAuth($credentials['user'], $credentials['pass'])
                ->send('MKCOL', $base . '/' . $walked);
        }
    }

    /** @return array{url: string, user: string, pass: string}|null */
    private function credentials(StorageConnection $connection): ?array
    {
        try {
            $raw = json_decode(Crypt::decryptString($connection->encrypted_access_token ?? ''), true);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($raw) || ! filled($raw['url'] ?? null)) return null;

        return ['url' => $raw['url'], 'user' => $raw['user'] ?? '', 'pass' => $raw['pass'] ?? ''];
    }

    private function fail(StorageConnection $connection, string $code, string $message): void
    {
        $connection->forceFill([
            'connection_status' => 'error',
            'last_error_at' => now(),
            'last_error_code' => $code,
            'last_error_message' => $message,
        ])->save();
    }
}
