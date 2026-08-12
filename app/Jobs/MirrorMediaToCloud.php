<?php

namespace App\Jobs;

use App\Models\MediaItem;
use App\Services\Storage\DropboxClient;
use App\Services\Storage\OneDriveClient;
use App\Services\Storage\StorageResolver;
use App\Support\SpaceContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Copies a finished upload into the space's own cloud.
 *
 * A copy, not a move. The local file stays and keeps serving every page — sending people's
 * photographs through somebody else's API on every view would be slower, would depend on a
 * service being up to show a thumbnail, and would turn a revoked token into a gallery of
 * broken images. What this adds is that the pictures are also somewhere the person
 * controls, which is what most people mean when they ask to connect their Dropbox.
 *
 * Queued, because a request that saves a photograph should end when the photograph is
 * saved. Whether a second copy reached Dropbox is not something to keep somebody waiting
 * on with the shutter still in their hand.
 */
class MirrorMediaToCloud implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Three attempts, spread out: the usual failure is a service having a bad minute. */
    public int $tries = 3;
    public array $backoff = [60, 300];

    public function __construct(public readonly int $mediaId)
    {
    }

    public function handle(StorageResolver $resolver, DropboxClient $dropbox, OneDriveClient $oneDrive): void
    {
        $media = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)->find($this->mediaId);
        if (! $media || ! $media->gallerySpace) return;

        $connection = $resolver->activeConnection($media->gallerySpace);
        if (! $connection) return;

        // The two clients answer the same three calls, so the job picks one and stops
        // caring which. A provider with no client is simply not mirrored rather than
        // being an error nobody can act on.
        $client = match ($connection->provider) {
            'dropbox' => $dropbox,
            'onedrive' => $oneDrive,
            'webdav' => app(\App\Services\Storage\WebDavClient::class),
            default => null,
        };
        if (! $client) return;

        // Already mirrored: a retried job must not produce a second copy, and both
        // providers are asked to rename on conflict, so they would happily make one.
        if ($media->variants()->where('disk', $connection->provider)->exists()) return;

        $original = $media->variants()->where('type', 'original')->first();
        if (! $original) return;

        $disk = Storage::disk($original->disk);
        if (! $disk->exists($original->path)) return;

        $remote = $client->folderFor($connection)
            . '/' . $media->uuid . '.' . ($media->extension ?: 'bin');

        $result = $client->upload($connection, $remote, $disk->get($original->path));

        if (! $result['ok']) {
            // Logged and retried rather than swallowed. The connection's own error column
            // was already written by the client, so the screen can explain it meanwhile.
            Log::warning('Kopie do cloudu selhala', ['media' => $media->uuid, 'duvod' => $result['error']]);
            $this->release(300);

            return;
        }

        $media->variants()->create([
            'type' => 'cloud_copy',
            'disk' => $connection->provider,
            'path' => $result['path'] ?? $remote,
            'size_bytes' => $result['size'] ?? null,
        ]);
    }
}
