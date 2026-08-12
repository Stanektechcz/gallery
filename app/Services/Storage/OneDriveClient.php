<?php

namespace App\Services\Storage;

use App\Models\StorageConnection;
use Illuminate\Support\Facades\Http;

/**
 * Talking to a connected OneDrive.
 *
 * The Dropbox client's twin. Graph puts the destination in the URL where Dropbox puts it
 * in a header, and reports quota under a different name; everything else — refreshing
 * first, returning failures rather than throwing, refusing files too large for the simple
 * upload — is the same, and is the same on purpose.
 */
class OneDriveClient
{
    private const ME = 'https://graph.microsoft.com/v1.0/me';
    private const DRIVE = 'https://graph.microsoft.com/v1.0/me/drive';
    private const ROOT = 'https://graph.microsoft.com/v1.0/me/drive/root:';

    /** Graph wants an upload session above 4 MB; this refuses rather than sending a truncated file. */
    private const SIMPLE_UPLOAD_LIMIT = 4 * 1024 * 1024;

    public function __construct(private readonly TokenRefresher $refresher)
    {
    }

    /** @return array{ok: bool, account?: ?string, used_bytes?: ?int, allocated_bytes?: ?int, error?: string} */
    public function probe(StorageConnection $connection): array
    {
        $token = $this->refresher->accessToken($connection);
        if (! $token) {
            return ['ok' => false, 'error' => $connection->last_error_message ?? 'Přístup se nepodařilo obnovit.'];
        }

        $me = Http::withToken($token)->get(self::ME);
        if ($me->failed()) {
            return ['ok' => false, 'error' => (string) $me->json('error.message', 'Microsoft neodpověděl.')];
        }

        $drive = Http::withToken($token)->get(self::DRIVE);

        $connection->forceFill([
            'connection_status' => 'connected',
            'last_successful_request_at' => now(),
            'last_error_at' => null, 'last_error_code' => null, 'last_error_message' => null,
        ])->save();

        return [
            'ok' => true,
            'account' => $me->json('mail') ?: $me->json('userPrincipalName'),
            'used_bytes' => $drive->json('quota.used'),
            'allocated_bytes' => $drive->json('quota.total'),
        ];
    }

    /**
     * Puts one file in the space's folder.
     *
     * `@microsoft.graph.conflictBehavior=rename`, never replace: two photographs taken in
     * the same second can share a name, and overwriting one with the other loses a picture
     * nobody misses until years later.
     *
     * @return array{ok: bool, path?: string, size?: int, error?: string}
     */
    public function upload(StorageConnection $connection, string $remotePath, string $contents): array
    {
        if (strlen($contents) > self::SIMPLE_UPLOAD_LIMIT) {
            return ['ok' => false, 'error' => 'Soubor je nad 4 MB; nahrávání po částech zatím není hotové.'];
        }

        $token = $this->refresher->accessToken($connection);
        if (! $token) {
            return ['ok' => false, 'error' => $connection->last_error_message ?? 'Přístup se nepodařilo obnovit.'];
        }

        // Each segment encoded separately: encoding the whole path would escape the
        // slashes that make it a path.
        $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim($remotePath, '/'))));

        $response = Http::withToken($token)
            ->withBody($contents, 'application/octet-stream')
            ->put(self::ROOT . '/' . $encoded . ':/content?@microsoft.graph.conflictBehavior=rename');

        if ($response->failed()) {
            $reason = (string) $response->json('error.message', 'Nahrání do OneDrive selhalo.');

            $connection->forceFill([
                'connection_status' => 'error',
                'last_error_at' => now(),
                'last_error_code' => 'upload_failed',
                'last_error_message' => $reason,
            ])->save();

            return ['ok' => false, 'error' => $reason];
        }

        return ['ok' => true, 'path' => $response->json('name') ?? $remotePath, 'size' => $response->json('size')];
    }

    public function folderFor(StorageConnection $connection): string
    {
        return '/MAKI Gallery/prostor-' . $connection->gallery_space_id;
    }
}
