<?php

namespace App\Services\Integrations;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * GIF search for the chat, through Google's Tenor API.
 *
 * Tenor is free and needs no billing account, which is why it was picked over the
 * alternatives — the key is issued from a Google Cloud project and costs nothing. When no
 * key is configured the picker simply does not appear; nothing else in the chat changes.
 *
 * Only the search runs through us. The GIFs themselves are served by Tenor's CDN
 * straight to the browser, so a conversation full of them costs the space no storage.
 */
class TenorGifClient
{
    private const ENDPOINT = 'https://tenor.googleapis.com/v2';

    /** Searches are cached: the same query from both members should cost one call. */
    private const CACHE_MINUTES = 60;

    public function configured(): bool
    {
        return $this->key() !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 24): array
    {
        $key = $this->key();
        if (! $key) return [];

        $query = trim($query);
        $limit = max(1, min($limit, 50));
        $cacheKey = 'tenor:' . md5(mb_strtolower($query) . ':' . $limit);

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_MINUTES), function () use ($key, $query, $limit) {
            $path = $query === '' ? '/featured' : '/search';

            $response = Http::timeout(6)->retry(1, 200)->get(self::ENDPOINT . $path, array_filter([
                'key' => $key,
                'q' => $query ?: null,
                'limit' => $limit,
                'client_key' => 'maki_gallery',
                'country' => 'CZ',
                'locale' => 'cs_CZ',
                // Tenor's own moderation. This is a couples' app, not a search engine.
                'contentfilter' => 'medium',
                // Only the formats we actually render, so the response stays small.
                'media_filter' => 'tinygif,gif',
            ]));

            if (! $response->successful()) return [];

            return collect($response->json('results') ?? [])
                ->map(function (array $result) {
                    $full = $result['media_formats']['gif'] ?? null;
                    $preview = $result['media_formats']['tinygif'] ?? $full;
                    if (! $full || ! $preview) return null;

                    return [
                        'id' => (string) ($result['id'] ?? ''),
                        'description' => (string) ($result['content_description'] ?? 'GIF'),
                        'preview' => $preview['url'],
                        'url' => $full['url'],
                        'width' => (int) ($full['dims'][0] ?? 0),
                        'height' => (int) ($full['dims'][1] ?? 0),
                    ];
                })
                ->filter()
                ->values()
                ->all();
        });
    }

    private function key(): ?string
    {
        $setting = IntegrationSetting::where('provider', 'tenor')->where('is_enabled', true)->first();
        $key = $setting?->config()['api_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }
}
