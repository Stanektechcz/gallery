<?php

namespace App\Services\Storage;

use App\Models\GallerySpace;
use App\Models\IntegrationSetting;
use App\Models\StorageConnection;
use Illuminate\Support\Facades\Schema;

/**
 * Two questions with one answer each.
 *
 *   Where do a provider's application credentials come from?
 *   Which storage does this space actually write to?
 *
 * Both used to be answered in several places at once — credentials from the environment,
 * storage from whichever row happened to be first — and neither answer was the same twice.
 */
class StorageResolver
{
    /** Providers whose files this app can hold. Local is not one of them; it is the floor. */
    public const CLOUDS = ['google_drive', 'dropbox', 'onedrive', 'webdav'];

    /**
     * A provider's client id and secret, from the administration first.
     *
     * The operator registers each application once, in the system settings, and it applies
     * to every customer — these are the app's own credentials, not anybody's account. The
     * environment stays a fallback so an install configured before this screen existed
     * keeps working, but the database wins: an operator who changes a key in the interface
     * and sees the old one still used would be right to distrust the interface.
     *
     * @return array{client_id: ?string, client_secret: ?string, redirect: ?string}
     */
    public function credentials(string $provider): array
    {
        $stored = [];

        if (Schema::hasTable('integration_settings')) {
            $row = IntegrationSetting::where('provider', $provider)->first();
            if ($row && $row->is_enabled) $stored = $row->config();
        }

        // config/services keys the Drive under "google" for historical reasons.
        $key = $provider === 'google_drive' ? 'google' : $provider;

        return [
            'client_id' => $stored['client_id'] ?? config("services.$key.client_id"),
            'client_secret' => $stored['client_secret'] ?? config("services.$key.client_secret"),
            'redirect' => $stored['redirect'] ?? config("services.$key.redirect"),
        ];
    }

    public function configured(string $provider): bool
    {
        $credentials = $this->credentials($provider);

        return (bool) $credentials['client_id'] && (bool) $credentials['client_secret'];
    }

    /**
     * The cloud this space writes to, or null when it writes locally.
     *
     * Local storage is not a fallback that happens when something breaks — it is the floor
     * every gallery stands on. A space with no connected cloud is not misconfigured, it is
     * simply using what it was given, and everything downstream should treat null as an
     * ordinary answer rather than a missing one.
     *
     * A connection that has stopped working does not silently divert new photographs
     * elsewhere: it stays selected and shows its error, because moving a library's writes
     * to a second location without saying so is how a gallery ends up in two halves.
     */
    public function activeConnection(GallerySpace $space): ?StorageConnection
    {
        if (! Schema::hasTable('storage_connections')) return null;
        if (! Schema::hasColumn('storage_connections', 'gallery_space_id')) return null;

        return StorageConnection::where('gallery_space_id', $space->id)
            ->whereIn('provider', self::CLOUDS)
            ->orderByRaw("CASE WHEN connection_status = ? THEN 0 ELSE 1 END", [StorageConnection::STATUS_HEALTHY])
            ->first();
    }

    /** 'local' or the provider code, for anything that needs to name the destination. */
    public function activeProvider(GallerySpace $space): string
    {
        return $this->activeConnection($space)?->provider ?? 'local';
    }

    /**
     * Who may connect or disconnect a space's storage.
     *
     * The person who holds the plan. Everybody in the space stores their photographs in
     * whatever that account provides, so moving it is not a decision to leave with whoever
     * happens to open the screen.
     */
    public function mayManage(GallerySpace $space, ?int $userId): bool
    {
        if (! $userId) return false;

        $owner = $space->members()->wherePivot('role', 'owner')->first()
            ?? $space->members()->where('users.role', 'owner')->first();

        return $owner?->id === $userId;
    }
}
