<?php

namespace App\Services\Entertainment;

use App\Models\EntertainmentTitle;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CinemaCityProgramService
{
    public const CINEMA_CODE = '1035';

    public const CINEMA_NAME = 'Cinema City Velký Špalíček Brno';

    public const CINEMA_URL = 'https://www.cinemacity.cz/cinemas/velkyspalicek/1035';

    private const TENANT = '10101';

    private const API_BASE = 'https://www.cinemacity.cz/cz/data-api-service/v1/quickbook';

    public function sync(Carbon $from, int $days = 7): array
    {
        abort_unless(
            Schema::hasTable('cinema_showings') && Schema::hasTable('cinema_sync_runs'),
            503,
            'Pro program kina nejprve dokončete databázové migrace.'
        );
        $days = max(1, min(14, $days));
        $runId = DB::table('cinema_sync_runs')->insertGetId([
            'provider' => 'cinema_city', 'cinema_code' => self::CINEMA_CODE, 'from_date' => $from->toDateString(),
            'to_date' => $from->copy()->addDays($days - 1)->toDateString(), 'status' => 'running', 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $result = Cache::lock('cinema-city:'.self::CINEMA_CODE, 180)->block(5, function () use ($from, $days) {
                $count = 0;
                $successfulDays = 0;
                $warnings = [];
                for ($offset = 0; $offset < $days; $offset++) {
                    $day = $from->copy()->addDays($offset)->toDateString();
                    try {
                        $body = $this->fetchDay($day);
                        $dayCount = DB::transaction(fn () => $this->storeDay($body));
                        $count += $dayCount;
                        $successfulDays++;
                    } catch (Throwable $exception) {
                        report($exception);
                        $warnings[] = $day.': '.$this->safeFailure($exception);
                    }
                }
                if ($successfulDays === 0) {
                    throw new RuntimeException($warnings[0] ?? 'Cinema City nevrátilo žádný dostupný den programu.');
                }
                DB::table('cinema_showings')->where('provider', 'cinema_city')->where('cinema_code', self::CINEMA_CODE)->where('starts_at', '<', now()->subDays(7))->delete();

                return ['count' => $count, 'successful_days' => $successfulDays, 'warnings' => array_slice($warnings, 0, 5)];
            });
            $status = $result['warnings'] === [] ? 'completed' : 'partial';
            DB::table('cinema_sync_runs')->where('id', $runId)->update([
                'status' => $status,
                'showings_count' => $result['count'],
                'last_error' => $result['warnings'] === [] ? null : mb_substr(implode("\n", $result['warnings']), 0, 2000),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'status' => $status,
                'count' => $result['count'],
                'successful_days' => $result['successful_days'],
                'warnings' => $result['warnings'],
                'from' => $from->toDateString(),
                'days' => $days,
            ];
        } catch (Throwable $exception) {
            DB::table('cinema_sync_runs')->where('id', $runId)->update(['status' => 'failed', 'last_error' => mb_substr($this->safeFailure($exception), 0, 2000), 'finished_at' => now(), 'updated_at' => now()]);
            throw $exception;
        }
    }

    private function fetchDay(string $day): array
    {
        $url = self::API_BASE.'/'.self::TENANT.'/film-events/in-cinema/'.self::CINEMA_CODE.'/at-date/'.$day;
        $payload = Http::acceptJson()->withHeaders([
            'Accept-Language' => 'cs-CZ,cs;q=0.9,en;q=0.5',
            'Referer' => self::CINEMA_URL,
            'User-Agent' => 'Mozilla/5.0 (compatible; StanektechGallery/1.0; +https://gallery.stanektech.cz)',
        ])->timeout(15)->retry(2, 350)->get($url, ['attr' => ''])->throw()->json();
        $body = is_array($payload['body'] ?? null) ? $payload['body'] : $payload;
        if (! is_array($body) || (! array_key_exists('films', $body) && ! array_key_exists('events', $body))) {
            throw new RuntimeException('Cinema City vrátilo neznámý formát programu.');
        }

        return ['films' => is_array($body['films'] ?? null) ? $body['films'] : [], 'events' => is_array($body['events'] ?? null) ? $body['events'] : []];
    }

    private function storeDay(array $body): int
    {
        $films = collect($body['films'])->keyBy(fn ($film) => (string) ($film['id'] ?? ''));
        $count = 0;
        foreach ($body['events'] as $event) {
            $film = $films->get((string) ($event['filmId'] ?? ''), []);
            if (empty($event['id']) || empty($event['eventDateTime'])) {
                continue;
            }
            $attributes = collect(is_array($event['attributeIds'] ?? null) ? $event['attributeIds'] : [])->map(fn ($value) => (string) $value)->filter()->values()->all();
            $title = mb_substr((string) ($film['name'] ?? 'Film bez názvu'), 0, 255);
            $releaseYear = is_numeric($film['releaseYear'] ?? null) ? max(1888, min(65535, (int) $film['releaseYear'])) : null;
            $runtime = is_numeric($film['length'] ?? null) ? max(1, min(65535, (int) $film['length'])) : null;
            $matched = $this->matchTitle($title, $releaseYear);
            $eventId = mb_substr((string) $event['id'], 0, 80);
            // Cinema City returns a Prague wall-clock value without an offset
            // (for example 2026-07-16T11:00:00). Store all instants in UTC so
            // the API can safely emit ISO-8601 and the browser does not add the
            // Prague offset for a second time.
            $startsAt = $this->startsAt((string) $event['eventDateTime']);
            $existingUuid = DB::table('cinema_showings')->where('provider', 'cinema_city')->where('cinema_code', self::CINEMA_CODE)->where('external_event_id', $eventId)->value('uuid');
            DB::table('cinema_showings')->updateOrInsert(
                ['provider' => 'cinema_city', 'cinema_code' => self::CINEMA_CODE, 'external_event_id' => $eventId],
                [
                    'uuid' => $existingUuid ?: (string) Str::uuid(),
                    'entertainment_title_id' => $matched?->id,
                    'cinema_name' => self::CINEMA_NAME,
                    'external_film_id' => isset($event['filmId']) ? mb_substr((string) $event['filmId'], 0, 80) : null,
                    'title' => $title,
                    'release_year' => $releaseYear,
                    'runtime_minutes' => $runtime,
                    'poster_url' => $this->https($film['posterLink'] ?? null),
                    'starts_at' => $startsAt,
                    'auditorium' => filled($event['auditorium'] ?? null) ? mb_substr((string) $event['auditorium'], 0, 80) : null,
                    'format' => $this->displayFormat($attributes),
                    'original_language' => $this->language(data_get($event, 'languages.original')),
                    'dubbed_language' => $this->language(data_get($event, 'languages.dubbed')),
                    'subtitles_language' => $this->language(data_get($event, 'languages.subtitles')),
                    'sold_out' => (bool) ($event['soldOut'] ?? false),
                    'availability_ratio' => isset($event['availabilityRatio']) ? max(0, min(1, (float) $event['availabilityRatio'])) : null,
                    'booking_url' => $this->bookingUrl($event, $eventId),
                    'source_url' => self::CINEMA_URL,
                    'attributes' => json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'fetched_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $count++;
        }

        return $count;
    }

    private function matchTitle(string $name, mixed $year): ?EntertainmentTitle
    {
        $needle = Str::lower(Str::ascii(trim($name)));

        return EntertainmentTitle::where('media_type', 'movie')->when($year, fn ($query) => $query->where('release_year', (int) $year))
            ->get()->first(fn ($title) => Str::lower(Str::ascii(trim($title->title))) === $needle || Str::lower(Str::ascii(trim($title->original_title ?? ''))) === $needle);
    }

    private function https(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }
        if (str_starts_with($url, '/')) {
            $url = 'https://www.cinemacity.cz'.$url;
        }

        return str_starts_with($url, 'https://') ? $url : null;
    }

    private function displayFormat(array $attributes): ?string
    {
        $labels = [
            '2d' => '2D',
            '3d' => '3D',
            '4dx' => '4DX',
            'imax' => 'IMAX',
            'screenx' => 'ScreenX',
            'vip' => 'VIP',
            'dolby-atmos' => 'Dolby Atmos',
            'laser-barco' => 'Laser',
        ];
        $formats = collect($attributes)
            ->map(fn (string $attribute) => $labels[Str::lower($attribute)] ?? null)
            ->filter()
            ->unique()
            ->values();

        return $formats->isEmpty() ? null : mb_substr($formats->implode(' · '), 0, 80);
    }

    public static function programUrl(Carbon|string|null $startsAt = null): string
    {
        if (! $startsAt) {
            return self::CINEMA_URL;
        }
        $day = ($startsAt instanceof Carbon ? $startsAt->copy() : Carbon::parse($startsAt, 'UTC'))
            ->timezone('Europe/Prague');

        // The order API and booking-router are protected by Cinema City's anti-bot layer.
        // The public programme is stable and lets the visitor choose the already selected time.
        return self::CINEMA_URL.'#/buy-tickets-by-cinema?in-cinema='.self::CINEMA_CODE.'&at='.$day->toDateString().'&view-mode=list';
    }

    public static function bookingRouterUrl(string $eventId): string
    {
        return 'https://www.cinemacity.cz/cz/booking-router/launch/'.rawurlencode($eventId).'?lang=cs';
    }

    private function startsAt(string $value): Carbon
    {
        // Values with an explicit offset are respected. Offset-less values are
        // defined by Cinema City as local cinema time (Europe/Prague).
        $hasOffset = (bool) preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $value);

        return Carbon::parse($value, $hasOffset ? null : 'Europe/Prague')->utc();
    }

    private function bookingUrl(array $event, string $eventId): ?string
    {
        if ((bool) ($event['soldOut'] ?? false) || (bool) data_get($event, 'compositeBookingLink.blockOnlineSales', false)) {
            return null;
        }

        $provided = $this->https($event['bookingRouterLaunchLink'] ?? null);
        if ($provided) {
            $parts = parse_url($provided);
            $host = Str::lower((string) ($parts['host'] ?? ''));
            $path = (string) ($parts['path'] ?? '');
            if ($host === 'www.cinemacity.cz' && preg_match('#^/cz/booking-router/launch/[^/]+$#', $path)) {
                return $provided;
            }
        }

        return self::bookingRouterUrl($eventId);
    }

    private function language(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = implode(',', array_filter(array_map('strval', $value)));
        }

        return filled($value) ? mb_substr((string) $value, 0, 16) : null;
    }

    private function safeFailure(Throwable $exception): string
    {
        if ($exception instanceof ConnectionException) {
            return 'oficiální program je ze serveru dočasně nedostupný';
        }
        if ($exception instanceof RequestException) {
            return 'oficiální program odpověděl HTTP '.$exception->response->status();
        }
        if ($exception instanceof QueryException) {
            return 'program se nepodařilo uložit; zkontrolujte databázové migrace a schéma';
        }
        if ($exception instanceof RuntimeException && filled($exception->getMessage())) {
            return mb_substr($exception->getMessage(), 0, 500);
        }

        return 'synchronizace skončila technickou chybou';
    }
}
