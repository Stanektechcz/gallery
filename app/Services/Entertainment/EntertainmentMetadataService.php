<?php

namespace App\Services\Entertainment;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class EntertainmentMetadataService
{
    public function configured(): bool
    {
        $setting = IntegrationSetting::where('provider', 'tmdb')->first();
        return (bool) ($setting?->is_enabled && filled($setting->config()['api_key'] ?? null));
    }

    public function search(string $query, string $type = 'multi'): array
    {
        if (! $this->configured()) return [];
        $type = in_array($type, ['movie', 'tv'], true) ? $type : 'multi';
        $key = IntegrationSetting::where('provider', 'tmdb')->firstOrFail()->config()['api_key'];

        return Cache::remember('tmdb:search:' . sha1($type . '|' . mb_strtolower($query)), now()->addMinutes(20), function () use ($query, $type, $key) {
            $response = Http::acceptJson()->timeout(8)->retry(1, 200)
                ->get("https://api.themoviedb.org/3/search/{$type}", [
                    'api_key' => $key, 'query' => $query, 'language' => 'cs-CZ', 'include_adult' => 'false', 'page' => 1,
                ])->throw()->json('results', []);

            return collect($response)->filter(fn ($item) => in_array($item['media_type'] ?? $type, ['movie', 'tv'], true))
                ->take(12)->map(fn ($item) => $this->normalize($item, $type))->values()->all();
        });
    }

    public function details(string $type, int $id): array
    {
        abort_unless($this->configured(), 424, 'Pro globální našeptávač nastavte v administraci TMDB API klíč.');
        abort_unless(in_array($type, ['movie', 'tv'], true), 422);
        $key = IntegrationSetting::where('provider', 'tmdb')->firstOrFail()->config()['api_key'];
        return Cache::remember("tmdb:details:{$type}:{$id}", now()->addHours(6), function () use ($type, $id, $key) {
            $item = Http::acceptJson()->timeout(8)->retry(1, 200)
                ->get("https://api.themoviedb.org/3/{$type}/{$id}", ['api_key' => $key, 'language' => 'cs-CZ', 'append_to_response' => 'videos'])
                ->throw()->json();
            return $this->normalize($item + ['media_type' => $type], $type, true);
        });
    }

    private function normalize(array $item, string $fallbackType, bool $detailed = false): array
    {
        $type = ($item['media_type'] ?? $fallbackType) === 'tv' ? 'series' : 'movie';
        $releaseDate = $item['release_date'] ?? $item['first_air_date'] ?? null;
        $videos = collect($item['videos']['results'] ?? [])->first(fn ($video) => ($video['site'] ?? '') === 'YouTube' && in_array($video['type'] ?? '', ['Trailer', 'Teaser'], true));
        return [
            'external_source' => 'tmdb', 'external_id' => (string) ($item['id'] ?? ''), 'media_type' => $type,
            'title' => $item['title'] ?? $item['name'] ?? 'Bez názvu',
            'original_title' => $item['original_title'] ?? $item['original_name'] ?? null,
            'overview' => $item['overview'] ?? null, 'release_date' => $releaseDate ?: null,
            'release_year' => $releaseDate ? (int) substr($releaseDate, 0, 4) : null,
            'runtime_minutes' => $item['runtime'] ?? (collect($item['episode_run_time'] ?? [])->first()),
            'seasons_count' => $item['number_of_seasons'] ?? null,
            'poster_url' => ! empty($item['poster_path']) ? 'https://image.tmdb.org/t/p/w500' . $item['poster_path'] : null,
            'backdrop_url' => ! empty($item['backdrop_path']) ? 'https://image.tmdb.org/t/p/w780' . $item['backdrop_path'] : null,
            'trailer_url' => $videos ? 'https://www.youtube.com/watch?v=' . $videos['key'] : null,
            'original_language' => $item['original_language'] ?? null,
            'genres' => $detailed ? collect($item['genres'] ?? [])->pluck('name')->values()->all() : [],
            'community_rating' => isset($item['vote_average']) ? round((float) $item['vote_average'], 1) : null,
        ];
    }
}
