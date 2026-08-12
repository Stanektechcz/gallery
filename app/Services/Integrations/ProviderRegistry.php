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
            'steps' => [
                'Nic nenastavujete — běží od prvního dne.',
                'Kapacitu rozšíříte v Nastavení → Předplatné.',
            ],
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
            'steps' => [
                'Klikněte na kartu a přihlaste se svým účtem Google.',
                'Povolte přístup ke Google Disku.',
                'Galerie si vytvoří vlastní složku; do zbytku Disku nevidí.',
            ],
            'signup_url' => 'https://drive.google.com',
            'docs_url' => 'https://support.google.com/drive',
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
            'steps' => [
                'Klikněte na kartu a přihlaste se účtem Microsoft.',
                'Potvrďte přístup k souborům.',
                'Galerie si vytvoří vlastní složku.',
            ],
            'signup_url' => 'https://onedrive.live.com',
            'docs_url' => 'https://support.microsoft.com/onedrive',
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
            'steps' => [
                'Klikněte na kartu a přihlaste se do Dropboxu.',
                'Potvrďte přístup.',
                'Fotky se ukládají do složky MAKI Gallery.',
            ],
            'signup_url' => 'https://www.dropbox.com',
            'docs_url' => 'https://help.dropbox.com',
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
            'steps' => [
                'Otevřete notion.so/my-integrations a dejte New integration.',
                'Vyberte workspace, uložte a zkopírujte Internal Integration Token.',
                'Token vložte sem a potvrďte.',
                'V Notionu u stránky, kterou chcete sdílet, dejte ••• → Connections a integraci k ní připojte. Bez toho nevidí nic.',
            ],
            'signup_url' => 'https://www.notion.so/my-integrations',
            'docs_url' => 'https://developers.notion.com/docs/create-a-notion-integration',
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
            'steps' => [
                'Přihlaste se do AFFiNE a otevřete nastavení účtu.',
                'V integracích vytvořte MCP credential.',
                'Zkopírovanou hodnotu vložte sem.',
            ],
            'signup_url' => 'https://app.affine.pro',
            'docs_url' => 'https://docs.affine.pro',
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
            'steps' => [
                'V Discordu otevřete kanál, kam se mají zprávy posílat.',
                'Nastavení kanálu → Integrace → Webhooky → Nový webhook.',
                'Zkopírujte adresu webhooku a vložte ji sem.',
            ],
            'signup_url' => 'https://discord.com',
            'docs_url' => 'https://support.discord.com/hc/cs/articles/228383668',
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
            'steps' => [
                'Klikněte na kartu a přihlaste se na Facebooku.',
                'Povolte přístup ke svým fotkám.',
            ],
            'signup_url' => 'https://www.facebook.com',
            'docs_url' => 'https://developers.facebook.com/docs/permissions',
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

        // Clouds ask the resolver, which reads the administration first and the
        // environment second — so a key entered in the interface takes effect there.
        if (in_array($code, \App\Services\Storage\StorageResolver::CLOUDS, true)) {
            return app(\App\Services\Storage\StorageResolver::class)->configured($code);
        }

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
