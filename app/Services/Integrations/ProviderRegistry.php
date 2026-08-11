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
 * Each entry says how it authorises, because that decides what the screen must ask for:
 *
 *   token    a personal token the person pastes — nothing to configure, works immediately
 *   oauth    a redirect, which needs an application registered by the operator first
 *   invite   no account at all; the app posts to a URL the person supplies
 *
 * `ready` is the honest part. A provider whose OAuth application has not been registered
 * cannot be connected however inviting the button looks, so the screen says so rather than
 * sending somebody to a page that will refuse them.
 */
class ProviderRegistry
{
    /**
     * Personal and shared both, unless stated. "Personal" means the credential is the
     * person's own and nobody else in the space can use it; "shared" means the space can.
     */
    public const PROVIDERS = [
        'notion' => [
            'name' => 'Notion',
            'auth' => 'token',
            'summary' => 'Čtení a zápis stránek. Osobní i sdílený prostor zvlášť.',
            'help' => 'Vytvořte si integraci na notion.so/my-integrations a vložte její token.',
            'scopes' => ['personal', 'shared'],
        ],
        'affine' => [
            'name' => 'AFFiNE',
            'auth' => 'token',
            'summary' => 'Poznámky a whiteboardy. Přístup je přes MCP přihlašovací údaj.',
            'help' => 'V AFFiNE si vytvořte MCP credential a vložte jej sem.',
            'scopes' => ['personal', 'shared'],
        ],
        'discord' => [
            'name' => 'Discord',
            'auth' => 'invite',
            'summary' => 'Odesílání zpráv na kanál přes webhook.',
            'help' => 'V nastavení kanálu vytvořte webhook a vložte jeho adresu.',
            'scopes' => ['personal', 'shared'],
            // Presence needs a gateway connection and a privileged intent, which needs a
            // daemon this app does not run. Saying so beats a status that never updates.
            'caveat' => 'Stav uživatele (online/offline) přes webhook nelze číst.',
        ],
        'google_drive' => [
            'name' => 'Google Drive',
            'auth' => 'oauth',
            'summary' => 'Úložiště fotek a videí galerie.',
            'help' => 'Připojuje se přes Google účet a platí pro celý prostor.',
            // One Drive per space, not one per person: the gallery's files live in it, and
            // two accounts holding half the photographs each is not a gallery.
            'scopes' => ['shared'],
            'managed_by' => 'storage',
        ],
        'facebook' => [
            'name' => 'Facebook',
            'auth' => 'oauth',
            'summary' => 'Import fotek z alb.',
            'help' => 'Vyžaduje registrovanou aplikaci a schválení oprávnění od Meta.',
            'scopes' => ['personal'],
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
     * A token or a webhook needs nothing from the operator — the person supplies the
     * credential themselves. A redirect needs an application registered first, and without
     * one the button leads to an error page with somebody else's problem written on it.
     */
    private function ready(string $code, array $provider): bool
    {
        if (($provider['auth'] ?? '') !== 'oauth') return true;

        if ($code === 'google_drive') {
            return (bool) config('services.google.client_id');
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
