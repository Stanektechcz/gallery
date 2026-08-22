<?php

namespace App\Services\Moments;

use App\Models\DailyMoment;
use App\Models\DailyMomentEntry;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * "Zároveň" — the day's shared moment.
 *
 * Everything that makes this work rather than being a novelty is enforced here, not in
 * the interface:
 *
 * The time is drawn once per space per day and never moved. If it could be re-rolled,
 * somebody would re-roll it until it landed somewhere convenient, and a convenient
 * moment is a posed one.
 *
 * Nobody is handed the other person's picture until they have posted their own. The
 * browser is never sent something it is meant not to show — hiding it client-side means
 * the reveal is one devtools tab away, and this is a couple's private day.
 */
class DailyMomentService
{
    /** Never before breakfast, never after bed. */
    private const EARLIEST_HOUR = 9;
    private const LATEST_HOUR = 21;

    /** How long an answer still counts as on time. */
    private const WINDOW_MINUTES = 120;

    /**
     * Today's prompt for a space, drawn if it does not exist yet.
     *
     * Drawing on read rather than only in the scheduler means a space created this
     * afternoon still gets today's moment instead of waiting until tomorrow.
     */
    public function todayFor(GallerySpace $space, ?Carbon $now = null): DailyMoment
    {
        $now ??= Carbon::now();
        $date = $now->copy()->startOfDay();

        $existing = DailyMoment::where('gallery_space_id', $space->id)
            ->whereDate('moment_date', $date)
            ->first();

        if ($existing) return $existing;

        return DailyMoment::create([
            'gallery_space_id' => $space->id,
            'moment_date' => $date,
            'notify_at' => $this->drawTime($date, $now),
            'window_minutes' => self::WINDOW_MINUTES,
        ]);
    }

    /**
     * A time somewhere in the day that has not already passed.
     *
     * A space whose first read happens at eight in the evening cannot be given a
     * quarter past ten in the morning — the prompt would arrive already expired. In
     * that case it is drawn from what is left of the day instead.
     */
    private function drawTime(Carbon $date, Carbon $now): Carbon
    {
        $earliest = $date->copy()->setTime(self::EARLIEST_HOUR, 0);
        $latest = $date->copy()->setTime(self::LATEST_HOUR, 0);

        if ($now->greaterThan($earliest)) {
            $earliest = $now->copy()->addMinutes(5);
        }

        if ($earliest->greaterThanOrEqualTo($latest)) {
            // Nothing sensible left today: a few minutes from now, so the day is not
            // simply skipped.
            return $now->copy()->addMinutes(5);
        }

        // Floored to whole minutes on purpose: Carbon hands back a float, and the moment
        // `$earliest` carries any seconds — which it does the instant it is derived from
        // now — that float has a fraction random_int must not be given.
        $span = (int) floor($earliest->diffInMinutes($latest));

        return $earliest->copy()->addMinutes(random_int(0, max(0, $span)));
    }

    /**
     * What this person may see right now.
     *
     * The partner's entries are attached only once this person has posted. Before that
     * they are told somebody has answered, and nothing more — knowing you are waited on
     * is the nudge; seeing the picture would remove the reason to take your own.
     */
    public function state(GallerySpace $space, User $user, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $moment = $this->todayFor($space, $now);

        $entries = $moment->entries()
            ->with(['author:id,name,avatar_path', 'back.variants', 'front.variants'])
            ->get();

        $mine = $entries->firstWhere('user_id', $user->id);
        $others = $entries->where('user_id', '!=', $user->id);

        return [
            'available' => true,
            'moment' => [
                'uuid' => $moment->uuid,
                'date' => $moment->moment_date->toDateString(),
                'notify_at' => $moment->notify_at->toIso8601String(),
                'closes_at' => $moment->closesAt()->toIso8601String(),
                'prompt' => $moment->prompt,
                'is_open' => $moment->isOpen(),
                'is_late' => $moment->isOpen() && $now->greaterThan($moment->closesAt()),
                'minutes_left' => $moment->isOpen()
                    ? max(0, (int) $now->diffInMinutes($moment->closesAt(), false))
                    : null,
            ],
            'mine' => $mine ? $this->entryPayload($mine) : null,
            // Counted, not shown. This is the whole reveal rule.
            'waiting_on_you' => $mine === null && $others->isNotEmpty(),
            'others' => $mine ? $others->map(fn ($entry) => $this->entryPayload($entry))->values()->all() : [],
            'others_count' => $others->count(),
            'members_count' => $space->members()->count(),
            'streak' => $this->streak($space, $user, $now),
        ];
    }

    /**
     * How many days in a row this person has answered.
     *
     * Counted back from today when they have already posted, and from yesterday when
     * they have not — otherwise the number would read as broken every morning, before
     * the day has even been asked about, which is exactly the wrong thing to tell
     * somebody at breakfast.
     *
     * Days with no prompt at all cannot break a streak; nobody was asked.
     */
    public function streak(GallerySpace $space, User $user, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        $dny = DailyMomentEntry::where('daily_moment_entries.user_id', $user->id)
            ->join('daily_moments', 'daily_moments.id', '=', 'daily_moment_entries.daily_moment_id')
            ->where('daily_moments.gallery_space_id', $space->id)
            ->orderByDesc('daily_moments.moment_date')
            ->pluck('daily_moments.moment_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->values();

        if ($dny->isEmpty()) return 0;

        $kurzor = $dny->first() === $now->toDateString()
            ? $now->copy()
            : $now->copy()->subDay();

        $delka = 0;

        foreach ($dny as $den) {
            if ($den !== $kurzor->toDateString()) break;

            $delka++;
            $kurzor->subDay();
        }

        return $delka;
    }

    /**
     * Records one person's answer.
     *
     * The media has to already exist — it is uploaded through the normal queue like any
     * other photograph, so it lands in the archive, gets its variants, and is mirrored
     * to the space's cloud without this needing to know how any of that works.
     */
    public function post(GallerySpace $space, User $user, array $data, ?Carbon $now = null): DailyMomentEntry
    {
        $now ??= Carbon::now();
        $moment = $this->todayFor($space, $now);

        abort_unless($moment->isOpen(), 422, 'Dnešní moment ještě nezačal.');

        $back = $this->mediaFor($space, $data['back_uuid'] ?? null);
        $front = $this->mediaFor($space, $data['front_uuid'] ?? null);

        abort_if($back === null && $front === null, 422, 'Moment potřebuje aspoň jednu fotku.');

        $lateBy = $now->greaterThan($moment->closesAt())
            ? (int) $moment->closesAt()->diffInMinutes($now)
            : 0;

        // updateOrCreate, so posting twice replaces the answer instead of failing on the
        // unique key -- somebody who mis-shot their own face should get another go.
        return DailyMomentEntry::updateOrCreate(
            ['daily_moment_id' => $moment->id, 'user_id' => $user->id],
            [
                'back_media_id' => $back?->id,
                'front_media_id' => $front?->id,
                'caption' => $data['caption'] ?? null,
                'posted_at' => $now,
                'late_minutes' => $lateBy,
            ],
        );
    }

    /**
     * Past moments, newest first.
     *
     * Only days this person answered. A day they missed has nothing of theirs in it,
     * and showing the other person's picture there would hand over by the back door
     * exactly what the reveal rule withholds at the front.
     */
    public function history(GallerySpace $space, User $user, int $limit = 60): array
    {
        $answered = DailyMomentEntry::where('user_id', $user->id)
            ->whereHas('moment', fn ($query) => $query->where('gallery_space_id', $space->id))
            ->pluck('daily_moment_id');

        return DailyMoment::where('gallery_space_id', $space->id)
            ->whereIn('id', $answered)
            ->with(['entries.author:id,name,avatar_path', 'entries.back.variants', 'entries.front.variants'])
            ->orderByDesc('moment_date')
            ->limit($limit)
            ->get()
            ->map(fn (DailyMoment $moment) => [
                'uuid' => $moment->uuid,
                'date' => $moment->moment_date->toDateString(),
                'notify_at' => $moment->notify_at->toIso8601String(),
                'prompt' => $moment->prompt,
                'entries' => $moment->entries->map(fn ($entry) => $this->entryPayload($entry))->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * A media row, but only from this space.
     *
     * Looked up by uuid the caller supplied, so it is checked against the space rather
     * than trusted: otherwise anybody could staple somebody else's photograph into
     * their own day.
     */
    private function mediaFor(GallerySpace $space, ?string $uuid): ?MediaItem
    {
        if (! $uuid) return null;

        return MediaItem::where('uuid', $uuid)
            ->where('gallery_space_id', $space->id)
            ->first();
    }

    private function entryPayload(DailyMomentEntry $entry): array
    {
        return [
            'uuid' => $entry->uuid,
            'user' => [
                'id' => $entry->author?->id,
                'name' => $entry->author?->name,
            ],
            'caption' => $entry->caption,
            'posted_at' => $entry->posted_at?->toIso8601String(),
            'late_minutes' => (int) $entry->late_minutes,
            'back' => $this->mediaPayload($entry->back),
            'front' => $this->mediaPayload($entry->front),
        ];
    }

    private function mediaPayload(?MediaItem $item): ?array
    {
        if (! $item) return null;

        $variant = collect(['medium', 'small', 'thumbnail', 'video_poster'])
            ->map(fn ($type) => $item->variants->firstWhere('type', $type))
            ->filter()
            ->first();

        return [
            'uuid' => $item->uuid,
            'url' => $variant?->url,
            'media_type' => $item->media_type,
        ];
    }
}
