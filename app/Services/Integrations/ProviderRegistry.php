<?php

namespace App\Services\Integrations;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Schema;

/**
 * Every outside service this app can be joined to, described once.
 *
 * The list lived in the browser as a pair of labels, so the screen could offer a service
 * the server had never heard of and the server could accept one the screen never showed.
 * It is authored here now and travels with the page.
 *
 * Three fields decide what the screen does with each entry:
 *
 *   auth   token | oauth | invite | builtin — how a credential is obtained
 *   mode   page | redirect | modal | none   — what a click on the card should do
 *   kind   storage | service                — which half of the screen it belongs to
 *
 * `available` is the honest field. Several services people reasonably expect cannot be
 * connected at all, and the reason is theirs rather than ours — no public API, or one that
 * demands a password we must never ask for. A card that says so is worth more than a
 * button that fails after somebody has tried twice.
 */
class ProviderRegistry
{
    public const PROVIDERS = [
        // ─── Storage ────────────────────────────────────────────────
        'server' => [
            'name' => 'Server galerie',
            'kind' => 'storage',
            'auth' => 'builtin',
            'mode' => 'none',
            'brand' => '#7c8cf8',
            'summary' => 'Základní úložiště na našem disku. Funguje hned, bez připojování.',
            'help' => 'Kapacitu rozšíříte v předplatném.',
            'scopes' => ['shared'],
            'available' => true,
        ],
        'google_drive' => [
            'name' => 'Google Drive',
            'kind' => 'storage',
            'auth' => 'oauth',
            // Its own page: re-syncing a library and rebuilding a folder tree are not
            // things to put behind a modal somebody can dismiss halfway through.
            'mode' => 'page',
            'url' => '/settings/storage/google',
            'brand' => '#1fa463',
            'summary' => 'Fotky a videa ve vašem Disku.',
            'help' => 'Připojí se přes Google účet a platí pro celý prostor.',
            'scopes' => ['shared'],
            'available' => true,
        ],
        'onedrive' => [
            'name' => 'OneDrive',
            'kind' => 'storage',
            'auth' => 'oauth',
            'mode' => 'redirect',
            'brand' => '#0f6cbd',
            'summary' => 'Úložiště Microsoftu přes Graph API.',
            'help' => 'Vyžaduje registrovanou aplikaci v Azure a souhlas účtu.',
            'scopes' => ['shared'],
            'available' => true,
        ],
        'dropbox' => [
            'name' => 'Dropbox',
            'kind' => 'storage',
            'auth' => 'oauth',
            'mode' => 'redirect',
            'brand' => '#0061fe',
            'summary' => 'Úložiště Dropboxu přes jejich API.',
            'help' => 'Vyžaduje registrovanou aplikaci v Dropbox App Console.',
            'scopes' => ['shared'],
            'available' => true,
        ],
        'mega' => [
            'name' => 'MEGA',
            'kind' => 'storage',
            'auth' => 'none',
            'mode' => 'none',
            'brand' => '#d9272e',
            'summary' => 'Napojit nelze.',
            'help' => 'MEGA nemá OAuth. Přihlášení vyžaduje e-mail a heslo k účtu, '
                . 'a heslo k cizí službě od vás nikdy chtít nebudeme.',
            'scopes' => [],
            'available' => false,
        ],
        'proton_drive' => [
            'name' => 'Proton Drive',
            'kind' => 'storage',
            'auth' => 'none',
            'mode' => 'none',
            'brand' => '#6d4aff',
            'summary' => 'Napojit nelze.',
            'help' => 'Proton Drive nemá veřejné API. Existující nástroje obcházejí '
                . 'protokol zpětným inženýrstvím, což není základ, na kterém chceme mít vaše fotky.',
            'scopes' => [],
            'available' => false,
        ],
        'icloud' => [
            'name' => 'iCloud Drive',
            'kind' => 'storage',
            'auth' => 'none',
            'mode' => 'none',
            'brand' => '#8e8e93',
            'summary' => 'Napojit nelze.',
            'help' => 'Apple nedává aplikacím třetích stran přístup k iCloud Drive. '
                . 'CloudKit umí jen vlastní úložiště aplikace, ne vaše soubory.',
            'scopes' => [],
            'available' => false,
        ],

        // ─── Services ───────────────────────────────────────────────
        'notion' => [
            'name' => 'Notion',
            'kind' => 'service',
            'auth' => 'token',
            'mode' => 'modal',
            'brand' => '#e8e8e8',
            'summary' => 'Čtení a zápis stránek.',
            'help' => 'Vytvořte si integraci na notion.so/my-integrations, vložte její token '
                . 'a nasdílejte jí stránky — bez nasdílení nevidí nic.',
            'scopes' => ['personal', 'shared'],
            'available' => true,
        ],
        'affine' => [
            'name' => 'AFFiNE',
            'kind' => 'service',
            'auth' => 'token',
            'mode' => 'modal',
            'brand' => '#1e96eb',
            'summary' => 'Poznámky a whiteboardy.',
            'help' => 'V AFFiNE si vytvořte MCP credential a vložte jej sem.',
            'scopes' => ['personal', 'shared'],
            'available' => true,
        ],
        'discord' => [
            'name' => 'Discord',
            'kind' => 'service',
            'auth' => 'invite',
            'mode' => 'modal',
            'brand' => '#5865f2',
            'summary' => 'Zprávy na kanál přes webhook.',
            'help' => 'V nastavení kanálu vytvořte webhook a vložte jeho adresu.',
            'scopes' => ['personal', 'shared'],
            'available' => true,
            // Presence needs a gateway connection and a privileged intent, which needs a
            // daemon this app does not run. Saying so beats a status that never updates.
            'caveat' => 'Stav uživatele (online/offline) přes webhook číst nelze.',
        ],
        'facebook' => [
            'name' => 'Facebook',
            'kind' => 'service',
            'auth' => 'oauth',
            'mode' => 'redirect',
            'brand' => '#0866ff',
            'summary' => 'Import fotek z alb.',
            'help' => 'Vyžaduje registrovanou aplikaci a schválení oprávnění od Meta.',
            'scopes' => ['personal'],
            'available' => true,
        ],
    ];

    /**
     * The catalogue as the screen needs it, with each provider's readiness resolved.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(): array
    {
        return collect(self::PROVIDERS)
            ->map(fn (array $provider, string $code) => $provider + [
                'code' => $code,
                'ready' => $this->ready($code, $provider),
            ])
            ->values()->all();
    }

    /**
     * Can somebody actually connect this today?
     *
     * A service we cannot integrate is never ready, whatever is configured. A token or a
     * webhook needs nothing from the operator — the person supplies the credential. A
     * redirect needs an application registered first, and without one the button leads to
     * an error page with somebody else's problem written on it.
     */
    private function ready(string $code, array $provider): bool
    {
        if (($provider['available'] ?? true) === false) return false;
        if (($provider['auth'] ?? '') === 'builtin') return true;
        if (($provider['auth'] ?? '') !== 'oauth') return true;

        if ($code === 'google_drive') return (bool) config('services.google.client_id');
        if ($code === 'onedrive') return (bool) config('services.onedrive.client_id');
        if ($code === 'dropbox') return (bool) config('services.dropbox.client_id');

        if (! Schema::hasTable('integration_settings')) return false;

        return IntegrationSetting::where('provider', $code)->where('is_enabled', true)->exists();
    }

    public function has(string $code): bool
    {
        return isset(self::PROVIDERS[$code]);
    }

    /** Which visibilities a provider allows, so a request cannot ask for one it does not. */
    public function scopes(string $code): array
    {
        return self::PROVIDERS[$code]['scopes'] ?? ['personal'];
    }
}
