<?php

namespace App\Services\Integrations;

use App\Models\IntegrationSetting;
use App\Models\UserIntegration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Discord, through OAuth2 plus incoming webhooks.
 *
 * What this covers and what it deliberately does not is written out in
 * docs/DISCORD.md; the short version is that everything Discord exposes over HTTP is
 * here, and live presence is not, because Discord does not serve presence over HTTP at
 * all. It arrives only on the Gateway websocket, only to a bot that shares a server with
 * the person and holds the privileged GUILD_PRESENCES intent — a permanently running
 * process, which this app (PHP-FPM, no daemon) has nowhere to put.
 *
 * Rather than pretend, the app records its own presence — who is in the conversation
 * right now — which is the thing the product actually wanted from "sledování stavu".
 */
class DiscordClient
{
    private const API = 'https://discord.com/api/v10';
    private const AUTHORIZE = 'https://discord.com/oauth2/authorize';

    /**
     * identify     — who they are: id, username, avatar
     * email        — their address, so we can match the account to ours
     * guilds       — which servers they are on (names and icons, not members)
     * connections  — their linked Steam, Spotify, GitHub and so on
     *
     * Everything here is read-only. No scope is requested that could post as them or
     * change anything in their account.
     *
     * @var list<string>
     */
    public const SCOPES = ['identify', 'email', 'guilds', 'connections'];

    public function configured(): bool
    {
        $config = $this->config();

        return ($config['client_id'] ?? '') !== '' && ($config['client_secret'] ?? '') !== '';
    }

    /** Where to send the browser to start linking. `state` is the caller's CSRF guard. */
    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return self::AUTHORIZE . '?' . http_build_query([
            'client_id' => $this->config()['client_id'] ?? '',
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            // Always ask, so a person can switch accounts instead of being silently
            // re-linked to whichever one their browser happens to hold.
            'prompt' => 'consent',
        ]);
    }

    /**
     * Trades the one-time code for tokens.
     *
     * @return array{ok: bool, credentials?: array<string, mixed>, expires_at?: \Illuminate\Support\Carbon, error?: string}
     */
    public function exchange(string $code, string $redirectUri): array
    {
        $config = $this->config();

        $response = Http::asForm()->timeout(12)->post(self::API . '/oauth2/token', [
            'client_id' => $config['client_id'] ?? '',
            'client_secret' => $config['client_secret'] ?? '',
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        if (! $response->successful()) {
            return ['ok' => false, 'error' => $this->readableError($response->json(), $response->status())];
        }

        $body = $response->json();

        return [
            'ok' => true,
            'credentials' => [
                'access_token' => $body['access_token'] ?? '',
                'refresh_token' => $body['refresh_token'] ?? null,
                'scope' => $body['scope'] ?? '',
            ],
            'expires_at' => now()->addSeconds((int) ($body['expires_in'] ?? 604800)),
        ];
    }

    /** Discord's access tokens are short-lived; the refresh token keeps the link alive. */
    public function refresh(UserIntegration $integration): bool
    {
        $refreshToken = $integration->credentials()['refresh_token'] ?? null;
        if (! $refreshToken) return false;

        $config = $this->config();

        $response = Http::asForm()->timeout(12)->post(self::API . '/oauth2/token', [
            'client_id' => $config['client_id'] ?? '',
            'client_secret' => $config['client_secret'] ?? '',
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if (! $response->successful()) {
            $integration->markFailed($this->readableError($response->json(), $response->status()));

            return false;
        }

        $body = $response->json();
        $integration->setCredentials([
            'access_token' => $body['access_token'] ?? '',
            'refresh_token' => $body['refresh_token'] ?? $refreshToken,
            'scope' => $body['scope'] ?? '',
        ]);
        $integration->expires_at = now()->addSeconds((int) ($body['expires_in'] ?? 604800));
        $integration->status = 'active';
        $integration->save();

        return true;
    }

    /** @return array<string, mixed>|null */
    public function me(UserIntegration $integration): ?array
    {
        $response = $this->authorised($integration, '/users/@me');
        if (! $response) return null;

        return [
            'id' => (string) ($response['id'] ?? ''),
            'username' => (string) ($response['username'] ?? ''),
            'global_name' => $response['global_name'] ?? null,
            'email' => $response['email'] ?? null,
            'avatar' => isset($response['avatar'], $response['id'])
                ? "https://cdn.discordapp.com/avatars/{$response['id']}/{$response['avatar']}.png?size=128"
                : null,
        ];
    }

    /**
     * The servers they belong to. Names and icons only — Discord does not hand a
     * user-scoped token any member list, and we do not ask for one.
     *
     * @return list<array<string, mixed>>
     */
    public function guilds(UserIntegration $integration): array
    {
        $response = $this->authorised($integration, '/users/@me/guilds');
        if (! is_array($response)) return [];

        return collect($response)->map(fn (array $guild) => [
            'id' => (string) ($guild['id'] ?? ''),
            'name' => (string) ($guild['name'] ?? ''),
            'icon' => isset($guild['icon'], $guild['id'])
                ? "https://cdn.discordapp.com/icons/{$guild['id']}/{$guild['icon']}.png?size=64"
                : null,
            'owner' => (bool) ($guild['owner'] ?? false),
        ])->values()->all();
    }

    /**
     * Their linked accounts — Spotify, Steam, GitHub and the rest.
     *
     * This is the closest thing to "status" that Discord will serve over HTTP: what a
     * person has connected, not what they are doing right now.
     *
     * @return list<array<string, mixed>>
     */
    public function connections(UserIntegration $integration): array
    {
        $response = $this->authorised($integration, '/users/@me/connections');
        if (! is_array($response)) return [];

        return collect($response)
            // Only what they chose to show on their profile; the rest is not ours to list.
            ->filter(fn (array $item) => (bool) ($item['visibility'] ?? 0))
            ->map(fn (array $item) => [
                'type' => (string) ($item['type'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'verified' => (bool) ($item['verified'] ?? false),
            ])->values()->all();
    }

    /**
     * Posts a message to a channel through an incoming webhook.
     *
     * A webhook URL is created by the person inside their own server and grants nothing
     * beyond posting to that one channel — no token, no bot, nothing to revoke centrally.
     * It is the whole reason notifications can reach Discord without a daemon.
     */
    public function notify(string $webhookUrl, string $content, ?array $embed = null): bool
    {
        if (! $this->isWebhookUrl($webhookUrl)) return false;

        $response = Http::timeout(8)->post($webhookUrl, array_filter([
            'content' => Str::limit($content, 1900),
            'embeds' => $embed ? [$embed] : null,
            // Never let content turn into a mass ping; a notification is not an @everyone.
            'allowed_mentions' => ['parse' => []],
        ]));

        return $response->successful();
    }

    public function isWebhookUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        return str_starts_with(strtolower((string) parse_url($url, PHP_URL_SCHEME)), 'https')
            && in_array($host, ['discord.com', 'discordapp.com', 'ptb.discord.com', 'canary.discord.com'], true)
            && str_starts_with($path, '/api/webhooks/');
    }

    /** GET with the user's token, refreshing once if it has just expired. */
    private function authorised(UserIntegration $integration, string $path): mixed
    {
        $token = $integration->credentials()['access_token'] ?? null;
        if (! $token) return null;

        $response = Http::withToken($token)->timeout(10)->get(self::API . $path);

        if ($response->status() === 401 && $this->refresh($integration)) {
            $token = $integration->fresh()->credentials()['access_token'] ?? null;
            $response = Http::withToken($token)->timeout(10)->get(self::API . $path);
        }

        if (! $response->successful()) {
            $integration->markFailed($this->readableError($response->json(), $response->status()));

            return null;
        }

        $integration->markUsed();

        return $response->json();
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return IntegrationSetting::where('provider', 'discord')->where('is_enabled', true)->first()?->config() ?? [];
    }

    private function readableError(mixed $body, int $status): string
    {
        $message = is_array($body) ? ($body['error_description'] ?? $body['message'] ?? null) : null;

        return match (true) {
            $status === 401 => 'Propojení s Discordem vypršelo. Připojte účet znovu.',
            $status === 429 => 'Discord dočasně omezil počet požadavků. Zkuste to za chvíli.',
            is_string($message) && $message !== '' => Str::limit($message, 300),
            default => "Discord odpověděl chybou {$status}.",
        };
    }
}
