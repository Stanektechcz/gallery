<?php

namespace App\Services\Chat;

use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\JournalEntry;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Turns what someone typed after "@" into things they can put in a message.
 *
 * Two kinds of query, because people reach for two different things:
 *
 *   a date   — "10.8", "10.8.2026", "dnes", "zitra" — answers with that day's plans
 *   a word   — answers with recipes, events, diary entries and people matching it
 *
 * Everything is scoped to the space, so a mention can only ever point at something the
 * other person is already allowed to open. A mention is a link, not a copy: the message
 * stores an identifier and the reader fetches the thing itself, which means a renamed
 * recipe reads correctly in an old conversation.
 */
class MentionSearchService
{
    private const LIMIT = 8;

    /**
     * @return array{kind: string, label: ?string, items: list<array<string, mixed>>}
     */
    public function search(GallerySpace $space, User $user, string $query): array
    {
        $query = trim($query);
        $date = $this->parseDate($query);

        if ($date) {
            return [
                'kind' => 'date',
                'label' => self::czechDate($date),
                'items' => $this->onDate($space, $date),
            ];
        }

        if ($query === '') {
            $today = now()->startOfDay();

            return ['kind' => 'date', 'label' => self::czechDate($today) . ' — dnes', 'items' => $this->onDate($space, $today)];
        }

        if (mb_strlen($query) < 2) return ['kind' => 'empty', 'label' => null, 'items' => []];

        return ['kind' => 'search', 'label' => null, 'items' => $this->byWord($space, $user, $query)];
    }

    /**
     * The date in Czech, spelled out here rather than through the locale.
     *
     * translatedFormat follows config('app.locale'), which is English on this install, so
     * a Czech app was answering "10. August". The month names are a dozen words; carrying
     * them is cheaper than making a label depend on a global setting.
     */
    private static function czechDate(Carbon $date): string
    {
        $months = ['ledna', 'února', 'března', 'dubna', 'května', 'června',
            'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

        return $date->day . '. ' . $months[$date->month - 1] . ' ' . $date->year;
    }

    /**
     * Czech shorthand for a day.
     *
     * "10.8" means the tenth of August in the current year, and if that has already gone
     * by more than a month, the next one — someone writing a bare day-and-month in
     * December almost always means January.
     */
    private function parseDate(string $query): ?Carbon
    {
        $query = mb_strtolower($query);

        foreach (['dnes' => 0, 'zitra' => 1, 'zítra' => 1, 'pozitri' => 2, 'pozítří' => 2] as $word => $offset) {
            if ($query === $word) return now()->startOfDay()->addDays($offset);
        }

        if (! preg_match('/^(\d{1,2})\s*\.\s*(\d{1,2})\s*\.?\s*(\d{4})?$/u', $query, $parts)) return null;

        $day = (int) $parts[1];
        $month = (int) $parts[2];
        if ($day < 1 || $day > 31 || $month < 1 || $month > 12) return null;

        $year = isset($parts[3]) ? (int) $parts[3] : now()->year;
        $date = Carbon::createFromDate($year, $month, 1)->startOfDay();
        if ($day > $date->daysInMonth) return null;

        $date = $date->setDay($day);

        if (! isset($parts[3]) && $date->lt(now()->subMonth())) $date->addYear();

        return $date;
    }

    /** @return list<array<string, mixed>> */
    private function onDate(GallerySpace $space, Carbon $date): array
    {
        if (! Schema::hasTable('calendar_events')) return [];

        return CalendarEvent::where('gallery_space_id', $space->id)
            ->whereDate('starts_at', $date->toDateString())
            ->orderBy('starts_at')->limit(self::LIMIT)->get()
            ->map(fn (CalendarEvent $event) => [
                'type' => 'event',
                'id' => $event->uuid ?? (string) $event->id,
                'title' => $event->title,
                'detail' => $event->starts_at?->format('H:i'),
                'icon' => '📅',
                'url' => self::url('event', $event->uuid ?? (string) $event->id),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function byWord(GallerySpace $space, User $user, string $query): array
    {
        $needle = '%' . $query . '%';
        $results = [];

        if (Schema::hasTable('recipes')) {
            foreach (Recipe::where('gallery_space_id', $space->id)->where('title', 'like', $needle)
                ->limit(4)->get() as $recipe) {
                $results[] = [
                    'type' => 'recipe', 'id' => $recipe->uuid ?? (string) $recipe->id,
                    'title' => $recipe->title, 'detail' => 'recept', 'icon' => '🍳',
                    'url' => self::url('recipe', $recipe->uuid ?? (string) $recipe->id),
                ];
            }
        }

        if (Schema::hasTable('calendar_events')) {
            foreach (CalendarEvent::where('gallery_space_id', $space->id)->where('title', 'like', $needle)
                ->orderByDesc('starts_at')->limit(4)->get() as $event) {
                $results[] = [
                    'type' => 'event', 'id' => $event->uuid ?? (string) $event->id,
                    'title' => $event->title,
                    'detail' => $event->starts_at?->format('j. n. H:i'), 'icon' => '📅',
                    'url' => self::url('event', $event->uuid ?? (string) $event->id),
                ];
            }
        }

        if (Schema::hasTable('journal_entries')) {
            foreach (JournalEntry::where('gallery_space_id', $space->id)->readableBy($user)
                ->where('title', 'like', $needle)->limit(3)->get() as $entry) {
                $results[] = [
                    'type' => 'journal', 'id' => $entry->uuid,
                    'title' => $entry->title ?: 'Zápisek', 'detail' => 'deník', 'icon' => '📔',
                    'url' => self::url('journal', $entry->uuid),
                ];
            }
        }

        if (Schema::hasTable('places')) {
            foreach (Place::where('gallery_space_id', $space->id)->where('name', 'like', $needle)
                ->limit(3)->get() as $place) {
                $results[] = [
                    'type' => 'place', 'id' => (string) $place->id,
                    'title' => $place->name, 'detail' => 'místo', 'icon' => '📍',
                    'url' => self::url('place', (string) $place->id),
                ];
            }
        }

        if (Schema::hasTable('trips')) {
            foreach (IlluminateSupportFacadesDB::table('trips')
                ->where('gallery_space_id', $space->id)->where('title', 'like', $needle)
                ->limit(3)->get() as $trip) {
                $results[] = [
                    'type' => 'trip', 'id' => (string) $trip->id,
                    'title' => $trip->title, 'detail' => 'cesta', 'icon' => '🧭',
                    'url' => self::url('trip', (string) $trip->id),
                ];
            }
        }

        foreach ($space->members()->where('users.name', 'like', $needle)->limit(3)->get() as $member) {
            $results[] = [
                'type' => 'person', 'id' => (string) $member->id,
                'title' => $member->name, 'detail' => 'člen prostoru', 'icon' => '👤',
                'url' => self::url('person', (string) $member->id),
            ];
        }

        return array_slice($results, 0, self::LIMIT);
    }

    /**
     * Where each kind of mention leads.
     *
     * Verified against routes/web.php rather than assumed — the previous guess sent every
     * calendar mention to /calendar/{uuid}, which is not a route, so every plan a person
     * mentioned answered 404. Keep this in step with MentionText.tsx.
     *
     * @var array<string, string>
     */
    public const ROUTES = [
        'event' => '/calendar/events/%s',
        'recipe' => '/recipes/%s',
        'place' => '/places/%s',
        'trip' => '/trips/%s/plan',
        'person' => '/people/%s',
        // The diary has no per-entry page, so a mention opens the diary itself.
        'journal' => '/denik',
    ];

    public static function url(string $type, string $id): string
    {
        $pattern = self::ROUTES[$type] ?? null;
        if (! $pattern) return '/';

        return str_contains($pattern, '%s') ? sprintf($pattern, rawurlencode($id)) : $pattern;
    }

    /**
     * The token a mention becomes inside a message body.
     *
     * Stored rather than the rendered text so the link survives: the reader resolves it
     * at display time, and a thing that was renamed reads correctly in an old message.
     */
    public static function token(string $type, string $id, string $title): string
    {
        return '[[' . $type . ':' . $id . '|' . Str::limit(str_replace(['|', ']'], '', $title), 80, '') . ']]';
    }
}
