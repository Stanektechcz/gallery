<?php

namespace App\Services\Planning;

use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\Place;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CoupleExperienceRecommendationService
{
    /**
     * Recommend places from the couple's own history and saved wishes.
     *
     * The score is intentionally explainable: no opaque external profile is
     * created and no private review leaves the application.
     */
    public function recommend(GallerySpace $space, array $filters = [], int $limit = 12): Collection
    {
        $theme = $filters['theme'] ?? 'any';
        $places = Place::query()->where('gallery_space_id', $space->id);

        if ($theme === 'rain') {
            $places->where('is_rain_friendly', true);
        } elseif ($theme === 'photo') {
            $places->where('is_photogenic', true);
        } elseif ($theme === 'early') {
            $places->where('opens_early', true);
        } elseif ($theme === 'budget') {
            $places->whereNotNull('price_level')->where('price_level', '<=', 2);
        }

        $places = $places->limit(500)->get();
        if ($places->isEmpty()) {
            return collect();
        }

        $placeIds = $places->pluck('id');
        $reviewStats = collect();
        $latestReviewNotes = collect();
        $topItems = collect();

        if (Schema::hasTable('place_reviews')) {
            $reviewStats = DB::table('place_reviews')
                ->whereIn('place_id', $placeIds)
                ->where('status', 'published')
                ->selectRaw('place_id, COUNT(*) AS review_count, COUNT(DISTINCT author_user_id) AS reviewers_count, AVG(overall_rating) AS review_average, SUM(CASE WHEN would_return = 1 THEN 1 ELSE 0 END) AS return_yes, SUM(CASE WHEN would_return IS NOT NULL THEN 1 ELSE 0 END) AS return_answers, MAX(visited_at) AS last_visited_at')
                ->groupBy('place_id')
                ->get()
                ->keyBy('place_id');

            $latestReviewNotes = DB::table('place_reviews')
                ->whereIn('place_id', $placeIds)
                ->where('status', 'published')
                ->where(function ($query) {
                    $query->whereNotNull('next_time_note')->orWhereNotNull('notes');
                })
                ->orderByDesc('visited_at')
                ->get(['place_id', 'next_time_note', 'notes'])
                ->unique('place_id')
                ->keyBy('place_id');
        }

        if (Schema::hasTable('place_review_items') && Schema::hasTable('place_reviews')) {
            $topItems = DB::table('place_review_items as item')
                ->join('place_reviews as review', 'review.id', '=', 'item.place_review_id')
                ->whereIn('review.place_id', $placeIds)
                ->where('review.status', 'published')
                ->where('item.would_order_again', true)
                ->selectRaw('review.place_id, item.category, item.name, COUNT(*) AS ratings_count, AVG(item.overall_rating) AS item_average')
                ->groupBy('review.place_id', 'item.category', 'item.name')
                ->get()
                ->sortByDesc(fn ($item) => ((int) $item->ratings_count * 10) + (float) ($item->item_average ?? 0))
                ->groupBy('place_id')
                ->map->first();
        }

        $plannedPlaceIds = Schema::hasTable('place_plans')
            ? DB::table('place_plans')->where('gallery_space_id', $space->id)->where('state', 'planned')
                ->where(fn ($query) => $query->whereNull('planned_for')->orWhere('planned_for', '>=', now()->subDay()->toDateString()))
                ->pluck('place_id')->flip()
            : collect();

        $wishlistPriorities = collect();
        if (Schema::hasTable('travel_wishlists') && Schema::hasTable('travel_wishlist_items') && Schema::hasColumn('travel_wishlist_items', 'place_id')) {
            $wishlistPriorities = DB::table('travel_wishlist_items as item')
                ->join('travel_wishlists as wishlist', 'wishlist.id', '=', 'item.wishlist_id')
                ->where('wishlist.gallery_space_id', $space->id)
                ->where('item.status', 'open')
                ->whereIn('item.place_id', $placeIds)
                ->selectRaw('item.place_id, MIN(item.priority) AS priority')
                ->groupBy('item.place_id')
                ->pluck('priority', 'place_id');
        }

        return $places->map(function (Place $place) use ($space, $filters, $reviewStats, $latestReviewNotes, $topItems, $plannedPlaceIds, $wishlistPriorities) {
            if ($plannedPlaceIds->has($place->id)) {
                return null;
            }

            $stats = $reviewStats->get($place->id);
            $reviewCount = (int) ($stats->review_count ?? 0);
            $reviewersCount = (int) ($stats->reviewers_count ?? 0);
            $average = $stats?->review_average !== null ? round((float) $stats->review_average, 1) : null;
            $returnAnswers = (int) ($stats->return_answers ?? 0);
            $returnPercent = $returnAnswers > 0 ? (int) round(((int) $stats->return_yes / $returnAnswers) * 100) : null;
            $lastVisitedAt = $stats?->last_visited_at ? Carbon::parse($stats->last_visited_at) : null;
            $topItem = $topItems->get($place->id);
            $wishlistPriority = $wishlistPriorities->get($place->id);
            $score = 24.0;
            $reasons = [];

            if ($average !== null) {
                $score += $average * 12;
                $reasons[] = "vaše hodnocení {$average}/5";
            } elseif ($place->personal_rating) {
                $score += (float) $place->personal_rating * 8;
                $reasons[] = "hodnocení {$place->personal_rating}/5";
            } else {
                $reasons[] = 'místo, které jste si společně uložili';
            }

            if ($reviewersCount >= 2 && $average !== null && $average >= 4) {
                $score += 14;
                array_unshift($reasons, 'shodli jste se na něm oba');
            }
            if ($returnPercent !== null) {
                $score += ($returnPercent / 100) * 18;
                if ($returnPercent >= 75) {
                    $reasons[] = 'chcete se sem vrátit';
                } elseif ($returnPercent < 50) {
                    $score -= 30;
                    $reasons[] = 'návrat má mezi vámi smíšené hodnocení';
                }
            }
            if ($topItem) {
                $score += 8;
                $reasons[] = "znovu si dát {$topItem->name}";
            }
            if ($wishlistPriority !== null) {
                $score += max(4, 16 - ((int) $wishlistPriority * 2));
                $reasons[] = 'je ve společném přání';
            }
            if ($lastVisitedAt) {
                $daysSince = $lastVisitedAt->diffInDays(now());
                if ($daysSince >= 180) {
                    $score += 12;
                    $reasons[] = 'dlouho jste tu nebyli';
                } elseif ($daysSince < 30) {
                    $score -= 15;
                }
            } else {
                $score += 10;
                if (!$place->personal_rating) $reasons[] = 'čeká na první společnou návštěvu';
            }
            if ($place->is_rain_friendly) {
                $score += ($filters['theme'] ?? 'any') === 'rain' ? 12 : 2;
                if (($filters['theme'] ?? null) === 'rain') {
                    if ($reviewCount > 0) array_unshift($reasons, 'vhodné na déšť');
                    else $reasons[] = 'vhodné na déšť';
                }
            }
            if ($place->is_photogenic) {
                $score += ($filters['theme'] ?? 'any') === 'photo' ? 10 : 2;
                $reasons[] = ($filters['theme'] ?? null) === 'photo' && $reviewCount > 0 ? 'hodí se pro nové společné fotky' : 'fotogenické';
            }
            if ($place->price_level && $place->price_level <= 2) {
                $score += ($filters['theme'] ?? 'any') === 'budget' ? 12 : 2;
                if (($filters['theme'] ?? null) === 'budget') array_unshift($reasons, 'odpovídá low-cost filtru');
            }

            $note = $latestReviewNotes->get($place->id);
            $nextTimeNote = $note?->next_time_note ?: $place->next_time_note ?: null;
            $suggestedStart = $this->suggestStart($space, $place, $filters['date'] ?? null);

            return [
                'id' => $place->id,
                'title' => $place->name,
                'place_name' => collect([$place->city, $place->country])->filter()->join(', '),
                'type' => $place->type,
                'kind' => $reviewCount > 0 ? 'return' : 'discover',
                'score' => round($score, 1),
                'reason' => collect($reasons)->unique()->take(5)->implode(' · '),
                'reasons' => collect($reasons)->unique()->values()->all(),
                'review_average' => $average,
                'review_count' => $reviewCount,
                'reviewers_count' => $reviewersCount,
                'return_percent' => $returnPercent,
                'last_visited_at' => $lastVisitedAt?->toIso8601String(),
                'price_level' => $place->price_level,
                'estimated_visit_minutes' => $place->estimated_visit_minutes ?: 120,
                'suggested_starts_at' => $suggestedStart->toIso8601String(),
                'suggested_ends_at' => $suggestedStart->copy()->addMinutes($place->estimated_visit_minutes ?: 120)->toIso8601String(),
                'top_item' => $topItem ? ['category' => $topItem->category, 'name' => $topItem->name, 'average' => $topItem->item_average !== null ? round((float) $topItem->item_average, 1) : null] : null,
                'next_time_note' => $nextTimeNote,
            ];
        })->filter()->sortByDesc('score')->take($limit)->values();
    }

    private function suggestStart(GallerySpace $space, Place $place, ?string $requestedDate): Carbon
    {
        $duration = $place->estimated_visit_minutes ?: 120;
        $base = $requestedDate ? Carbon::parse($requestedDate)->startOfDay() : now()->addDay()->startOfDay();
        if ($base->isPast()) $base = now()->addDay()->startOfDay();

        for ($offset = 0; $offset < 28; $offset++) {
            $day = $base->copy()->addDays($offset);
            if (!$requestedDate && !in_array($day->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY, Carbon::SUNDAY], true)) continue;
            $hour = in_array($place->type, ['restaurant', 'bar', 'cafe', 'coffee'], true) ? 18 : 10;
            if (in_array($place->type, ['cafe', 'coffee'], true)) $hour = 15;
            $start = $day->copy()->setTime($hour, 0);
            if ($start->lte(now())) continue;
            $end = $start->copy()->addMinutes($duration);
            $busy = CalendarEvent::query()->where('gallery_space_id', $space->id)->whereNotIn('status', ['cancelled'])
                ->where('starts_at', '<', $end)
                ->where(fn ($query) => $query->whereNull('ends_at')->where('starts_at', '>=', $start)->orWhere('ends_at', '>', $start))
                ->exists();
            if (!$busy) return $start;
            if ($requestedDate) break;
        }

        return $base->copy()->addWeek()->setTime(18, 0);
    }
}
