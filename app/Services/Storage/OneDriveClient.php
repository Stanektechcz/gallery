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

    private const ITEMS = 'https://graph.microsoft.com/v1.0/me/drive/items';

    /** Odkaz na kopii podle id položky v Graphu, ne podle cesty — viz `odkazNaNahrane()`. */
    public const PREDPONA_ID = 'id:';

    /** Graph wants an upload session above 4 MB; this refuses rather than sending a truncated file. */
    private const SIMPLE_UPLOAD_LIMIT = 4 * 1024 * 1024;

    public function __construct(private readonly TokenRefresher $refresher) {}

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
            'connection_status' => StorageConnection::STATUS_HEALTHY,
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

        $response = Http::withToken($token)
            ->withBody($contents, 'application/octet-stream')
            ->put(self::ROOT.'/'.$this->zakodujCestu($remotePath).':/content?@microsoft.graph.conflictBehavior=rename');

        if ($response->failed()) {
            $reason = (string) $response->json('error.message', 'Nahrání do OneDrive selhalo.');

            $connection->forceFill([
                'connection_status' => StorageConnection::STATUS_ERROR,
                'last_error_at' => now(),
                'last_error_code' => 'upload_failed',
                'last_error_message' => $reason,
            ])->save();

            return ['ok' => false, 'error' => $reason];
        }

        return ['ok' => true, 'path' => $this->odkazNaNahrane($response->json(), $remotePath), 'size' => $response->json('size')];
    }

    /**
     * Smaže jeden soubor — po trvalém smazání fotky v galerii.
     *
     * Graph položku přesune do koše OneDrivu (obnovitelná jako u Disku
     * a Dropboxu). 404 je úspěch: soubor tam už není a opakovat to nemá smysl.
     *
     * `$ref` je, co uložilo nahrání: `id:…` u nových kopií, u starších jen jméno
     * souboru (dřív se ukládalo jen `name`) — to se složí se složkou prostoru.
     *
     * @return array{ok: bool, error?: string}
     */
    public function delete(StorageConnection $connection, string $ref): array
    {
        $token = $this->refresher->accessToken($connection);
        if (! $token) {
            return ['ok' => false, 'error' => $connection->last_error_message ?? 'Přístup se nepodařilo obnovit.'];
        }

        $adresa = str_starts_with($ref, self::PREDPONA_ID)
            ? self::ITEMS.'/'.rawurlencode(substr($ref, strlen(self::PREDPONA_ID)))
            : self::ROOT.'/'.$this->zakodujCestu(str_contains($ref, '/') ? $ref : $this->folderFor($connection).'/'.$ref);

        $response = Http::withToken($token)->delete($adresa);

        if ($response->successful() || $response->status() === 404) {
            return ['ok' => true];
        }

        return ['ok' => false, 'error' => (string) $response->json('error.message', 'OneDrive odpověděl HTTP '.$response->status().'.')];
    }

    public function folderFor(StorageConnection $connection): string
    {
        return '/MAKI Gallery/prostor-'.$connection->gallery_space_id;
    }

    /**
     * Jak kopii najít znovu.
     *
     * Id položky, ne jméno: Graph při kolizi jméno přejmenuje („x 1.jpg")
     * a uživatel může soubor v OneDrivu přesunout — id přežije obojí. Dřív se
     * ukládalo jen `name`, takže smazat kopii nešlo ani podle cesty.
     *
     * @param  array<string, mixed>|null  $odpoved
     */
    private function odkazNaNahrane(?array $odpoved, string $remotePath): string
    {
        if (filled($odpoved['id'] ?? null)) {
            return self::PREDPONA_ID.$odpoved['id'];
        }

        return filled($odpoved['name'] ?? null)
            ? rtrim(dirname($remotePath), '/').'/'.$odpoved['name']
            : $remotePath;
    }

    /**
     * Každý díl zvlášť: zakódovat celou cestu by zakódovalo i lomítka, která
     * z ní dělají cestu.
     */
    private function zakodujCestu(string $cesta): string
    {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($cesta, '/'))));
    }
}
