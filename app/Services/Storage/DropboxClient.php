<?php

namespace App\Services\Storage;

use App\Models\StorageConnection;
use Illuminate\Support\Facades\Http;

/**
 * Talking to a connected Dropbox.
 *
 * Every call goes through the refresher rather than reading the stored token directly, so
 * a connection that expired overnight repairs itself on first use instead of failing until
 * somebody reconnects by hand.
 *
 * Failures are returned, not thrown. A caller here is usually part of saving somebody's
 * photograph, and the useful outcome is "this did not work and here is why" rather than an
 * exception that loses the upload.
 */
class DropboxClient
{
    private const ACCOUNT = 'https://api.dropboxapi.com/2/users/get_current_account';
    private const SPACE = 'https://api.dropboxapi.com/2/users/get_space_usage';
    private const UPLOAD = 'https://content.dropboxapi.com/2/files/upload';

    /** Dropbox switches to a chunked protocol above 150 MB; this refuses rather than truncates. */
    private const SIMPLE_UPLOAD_LIMIT = 140 * 1024 * 1024;

    public function __construct(private readonly TokenRefresher $refresher)
    {
    }

    /**
     * Is this connection actually usable right now?
     *
     * Asks Dropbox rather than reading our own status column. The column records what
     * happened last time, which is not the same question and is exactly the thing somebody
     * pressing "test" is doubting.
     *
     * @return array{ok: bool, account?: ?string, used_bytes?: ?int, allocated_bytes?: ?int, error?: string}
     */
    public function probe(StorageConnection $connection): array
    {
        $token = $this->refresher->accessToken($connection);
        if (! $token) {
            return ['ok' => false, 'error' => $connection->last_error_message ?? 'Přístup se nepodařilo obnovit.'];
        }

        $account = Http::withToken($token)->post(self::ACCOUNT);
        if ($account->failed()) {
            return ['ok' => false, 'error' => (string) $account->json('error_summary', 'Dropbox neodpověděl.')];
        }

        $space = Http::withToken($token)->post(self::SPACE);

        $connection->forceFill([
            'connection_status' => 'connected',
            'last_successful_request_at' => now(),
            'last_error_at' => null, 'last_error_code' => null, 'last_error_message' => null,
        ])->save();

        return [
            'ok' => true,
            'account' => $account->json('email'),
            'used_bytes' => $space->json('used'),
            'allocated_bytes' => $space->json('allocation.allocated'),
        ];
    }

    /**
     * Puts one file in the space's folder.
     *
     * `add` with autorename, never `overwrite`: two photographs taken in the same second
     * can carry the same name, and silently replacing one with the other loses a picture
     * in a way nobody notices until they look for it years later.
     *
     * @return array{ok: bool, path?: string, size?: int, error?: string}
     */
    public function upload(StorageConnection $connection, string $remotePath, string $contents): array
    {
        if (strlen($contents) > self::SIMPLE_UPLOAD_LIMIT) {
            return ['ok' => false, 'error' => 'Soubor je nad 140 MB; nahrávání po částech zatím není hotové.'];
        }

        $token = $this->refresher->accessToken($connection);
        if (! $token) {
            return ['ok' => false, 'error' => $connection->last_error_message ?? 'Přístup se nepodařilo obnovit.'];
        }

        $response = Http::withToken($token)
            ->withBody($contents, 'application/octet-stream')
            ->withHeaders(['Dropbox-API-Arg' => json_encode([
                'path' => $remotePath,
                'mode' => 'add',
                'autorename' => true,
                'mute' => true,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)])
            ->post(self::UPLOAD);

        if ($response->failed()) {
            $reason = (string) $response->json('error_summary', 'Nahrání do Dropboxu selhalo.');

            $connection->forceFill([
                'connection_status' => 'error',
                'last_error_at' => now(),
                'last_error_code' => 'upload_failed',
                'last_error_message' => $reason,
            ])->save();

            return ['ok' => false, 'error' => $reason];
        }

        return ['ok' => true, 'path' => $response->json('path_lower'), 'size' => $response->json('size')];
    }

    /**
     * Where a space's files live inside somebody's Dropbox.
     *
     * Namespaced by space so two galleries sharing one account do not interleave, and kept
     * under one visible folder so a person can find their photographs without this app.
     */
    public function folderFor(StorageConnection $connection): string
    {
        return '/MAKI Gallery/prostor-' . $connection->gallery_space_id;
    }
}
