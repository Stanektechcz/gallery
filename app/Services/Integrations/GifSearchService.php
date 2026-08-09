<?php

namespace App\Services\Integrations;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * GIF search, from whichever provider is configured.
 *
 * Two are supported because getting a key is the hard part, not the integration: Tenor
 * needs a Google Cloud project with the API switched on, Giphy needs only an account.
 * Whichever is enabled is used; if both are, Tenor goes first and Giphy stands in when it
 * answers with nothing.
 *
 * Both are free and neither requires a billing account. Only the search passes through
 * us — the GIFs themselves come from the provider's CDN straight to the browser, so a
 * conversation full of them costs the space no storage.
 */
class GifSearchService
{
    private const CACHE_MINUTES = 60;

    /** Hosts we will render a GIF from. Keep in step with ChatController::GIF_HOSTS. */
    public const HOSTS = [
        'media.tenor.com', 'c.tenor.com', 'tenor.com',
        'media.giphy.com', 'media0.giphy.com', 'media1.giphy.com',
        'media2.giphy.com', 'media3.giphy.com', 'media4.giphy.com', 'i.giphy.com',
    ];

    public function configured(): bool
    {
        return $this->key('tenor') !== null || $this->key('giphy') !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 24): array
    {
        $query = trim($query);
        $limit = max(1, min($limit, 50));

        return Cache::remember(
            'gifs:' . md5(mb_strtolower($query) . ':' . $limit),
            now()->addMinutes(self::CACHE_MINUTES),
            function () use ($query, $limit) {
                $results = $this->key('tenor') ? $this->tenor($query, $limit) : [];

                return $results ?: ($this->key('giphy') ? $this->giphy($query, $limit) : []);
            },
        );
    }

    /** @return list<array<string, mixed>> */
    private function tenor(string $query, int $limit): array
    {
        $response = Http::timeout(6)->retry(1, 200)->get(
            'https://tenor.googleapis.com/v2/' . ($query === '' ? 'featured' : 'search'),
            array_filter([
                'key' => $this->key('tenor'),
                'q' => $query ?: null,
                'limit' => $limit,
                'client_key' => 'maki_gallery',
                'country' => 'CZ',
                'locale' => 'cs_CZ',
                'contentfilter' => 'medium',
                'media_filter' => 'tinygif,gif',
            ]),
        );

        if (! $response->successful()) return [];

        return collect($response->json('results') ?? [])->map(function (array $result) {
            $full = $result['media_formats']['gif'] ?? null;
            $preview = $result['media_formats']['tinygif'] ?? $full;
            if (! $full || ! $preview) return null;

            return [
                'id' => 'tenor:' . ($result['id'] ?? ''),
                'description' => (string) ($result['content_description'] ?? 'GIF'),
                'preview' => $preview['url'],
                'url' => $full['url'],
                'width' => (int) ($full['dims'][0] ?? 0),
                'height' => (int) ($full['dims'][1] ?? 0),
            ];
        })->filter()->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function giphy(string $query, int $limit): array
    {
        $response = Http::timeout(6)->retry(1, 200)->get(
            'https://api.giphy.com/v1/gifs/' . ($query === '' ? 'trending' : 'search'),
            array_filter([
                'api_key' => $this->key('giphy'),
                'q' => $query ?: null,
                'limit' => $limit,
                'lang' => 'cs',
                // Giphy's own moderation; this is a couples' app, not a search engine.
                'rating' => 'pg-13',
            ]),
        );

        if (! $response->successful()) return [];

        return collect($response->json('data') ?? [])->map(function (array $result) {
            $full = $result['images']['downsized'] ?? $result['images']['original'] ?? null;
            $preview = $result['images']['fixed_width_small'] ?? $full;
            if (! $full || ! $preview) return null;

            return [
                'id' => 'giphy:' . ($result['id'] ?? ''),
                'description' => (string) ($result['title'] ?: 'GIF'),
                'preview' => $preview['url'],
                'url' => $full['url'],
                'width' => (int) ($full['width'] ?? 0),
                'height' => (int) ($full['height'] ?? 0),
            ];
        })->filter()->values()->all();
    }

    private function key(string $provider): ?string
    {
        $setting = IntegrationSetting::where('provider', $provider)->where('is_enabled', true)->first();
        $key = $setting?->config()['api_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }
}
