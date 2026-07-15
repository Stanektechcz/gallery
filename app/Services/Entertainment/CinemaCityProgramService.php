<?php

namespace App\Services\Entertainment;

use App\Models\EntertainmentTitle;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CinemaCityProgramService
{
    public const CINEMA_CODE = '1035';
    public const CINEMA_NAME = 'Cinema City Velký Špalíček Brno';
    public const CINEMA_URL = 'https://www.cinemacity.cz/cinemas/velkyspalicek/1035';
    private const TENANT = '10101';

    public function sync(Carbon $from, int $days = 7): array
    {
        $days = max(1, min(14, $days));
        $runId = DB::table('cinema_sync_runs')->insertGetId([
            'provider' => 'cinema_city', 'cinema_code' => self::CINEMA_CODE, 'from_date' => $from->toDateString(),
            'to_date' => $from->copy()->addDays($days - 1)->toDateString(), 'status' => 'running', 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $count = Cache::lock('cinema-city:' . self::CINEMA_CODE, 120)->block(5, function () use ($from, $days) {
                $count = 0;
                for ($offset = 0; $offset < $days; $offset++) {
                    $day = $from->copy()->addDays($offset)->toDateString();
                    $url = 'https://www.cinemacity.cz/cz/data-api-service/v1/quickbook/' . self::TENANT . '/film-events/in-cinema/' . self::CINEMA_CODE . '/at-date/' . $day;
                    $body = Http::acceptJson()->withHeaders(['User-Agent' => 'StanektechGallery/1.0 cinema-planner'])
                        ->timeout(12)->retry(2, 300)->get($url, ['attr' => ''])->throw()->json('body', []);
                    $films = collect($body['films'] ?? [])->keyBy(fn ($film) => (string) ($film['id'] ?? ''));
                    foreach ($body['events'] ?? [] as $event) {
                        $film = $films->get((string) ($event['filmId'] ?? ''), []);
                        if (empty($event['id']) || empty($event['eventDateTime'])) continue;
                        $title = (string) ($film['name'] ?? 'Film bez názvu');
                        $releaseYear = $film['releaseYear'] ?? null;
                        $matched = $this->matchTitle($title, $releaseYear);
                        DB::table('cinema_showings')->updateOrInsert(
                            ['provider' => 'cinema_city', 'cinema_code' => self::CINEMA_CODE, 'external_event_id' => (string) $event['id']],
                            [
                                'uuid' => DB::table('cinema_showings')->where('provider', 'cinema_city')->where('cinema_code', self::CINEMA_CODE)->where('external_event_id', (string) $event['id'])->value('uuid') ?: (string) Str::uuid(),
                                'entertainment_title_id' => $matched?->id, 'cinema_name' => self::CINEMA_NAME,
                                'external_film_id' => isset($event['filmId']) ? (string) $event['filmId'] : null,
                                'title' => $title, 'release_year' => $releaseYear ?: null, 'runtime_minutes' => $film['length'] ?? null,
                                'poster_url' => $this->https($film['posterLink'] ?? null), 'starts_at' => Carbon::parse($event['eventDateTime']),
                                'auditorium' => $event['auditorium'] ?? null, 'format' => implode(', ', $event['attributeIds'] ?? []),
                                'original_language' => $this->language(data_get($event, 'languages.original')), 'dubbed_language' => $this->language(data_get($event, 'languages.dubbed')),
                                'subtitles_language' => $this->language(data_get($event, 'languages.subtitles')), 'sold_out' => (bool) ($event['soldOut'] ?? false),
                                'availability_ratio' => isset($event['availabilityRatio']) ? max(0, min(1, (float) $event['availabilityRatio'])) : null,
                                'booking_url' => $this->https($event['bookingLink'] ?? $event['bookingRouterLaunchLink'] ?? null),
                                'source_url' => self::CINEMA_URL, 'attributes' => json_encode($event['attributeIds'] ?? []),
                                'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                            ]
                        );
                        $count++;
                    }
                }
                DB::table('cinema_showings')->where('provider', 'cinema_city')->where('cinema_code', self::CINEMA_CODE)->where('starts_at', '<', now()->subDays(7))->delete();
                return $count;
            });
            DB::table('cinema_sync_runs')->where('id', $runId)->update(['status' => 'completed', 'showings_count' => $count, 'finished_at' => now(), 'updated_at' => now()]);
            return ['status' => 'completed', 'count' => $count, 'from' => $from->toDateString(), 'days' => $days];
        } catch (\Throwable $exception) {
            DB::table('cinema_sync_runs')->where('id', $runId)->update(['status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 2000), 'finished_at' => now(), 'updated_at' => now()]);
            throw $exception;
        }
    }

    private function matchTitle(string $name, mixed $year): ?EntertainmentTitle
    {
        $needle = Str::lower(Str::ascii(trim($name)));
        return EntertainmentTitle::where('media_type', 'movie')->when($year, fn ($query) => $query->where('release_year', (int) $year))
            ->get()->first(fn ($title) => Str::lower(Str::ascii(trim($title->title))) === $needle || Str::lower(Str::ascii(trim($title->original_title ?? ''))) === $needle);
    }

    private function https(?string $url): ?string
    {
        if (! $url) return null;
        if (str_starts_with($url, '//')) $url = 'https:' . $url;
        if (str_starts_with($url, '/')) $url = 'https://www.cinemacity.cz' . $url;
        return str_starts_with($url, 'https://') ? $url : null;
    }

    private function language(mixed $value): ?string
    {
        if (is_array($value)) $value = implode(',', array_filter(array_map('strval', $value)));
        return filled($value) ? mb_substr((string) $value, 0, 16) : null;
    }
}
