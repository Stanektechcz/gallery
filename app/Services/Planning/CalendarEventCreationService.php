<?php

namespace App\Services\Planning;

use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Auth\PristupDoGalerie;
use Illuminate\Support\Collection;

/** Creates calendar events with one consistent participant baseline. */
class CalendarEventCreationService
{
    /**
     * Šířka `calendar_events.title` (2026_07_11_120000_create_shared_planning_tables).
     *
     * Volající skládají název s předponou („Vaření · ", „Filmový večer · "…)
     * z polí, která sama mají 180–255 znaků. SQLite v testech délku nehlídá,
     * MySQL by vrátil „Data too long" a uživatel 500 — proto se zkracuje tady,
     * na jediném místě, kudy všechny akce vznikají.
     */
    public const TITLE_MAX = 160;

    public function __construct(private readonly PristupDoGalerie $pristup) {}

    /**
     * Passing no participant list creates a shared event for the whole space.
     * Passing a list is useful for a private or selectively invited calendar event.
     */
    public function create(GallerySpace $space, User $actor, array $attributes, ?array $participantIds = null): CalendarEvent
    {
        if (isset($attributes['title']) && is_string($attributes['title'])) {
            $attributes['title'] = mb_substr($attributes['title'], 0, self::TITLE_MAX);
        }

        $event = CalendarEvent::create(array_replace($attributes, [
            'gallery_space_id' => $space->id,
            'created_by' => $actor->id,
        ]));

        $members = collect($participantIds ?? $this->coupleMemberIds($space, $actor))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->push((int) $actor->id)
            ->unique()
            ->values();

        $event->participants()->syncWithoutDetaching($members->mapWithKeys(fn (int $memberId) => [$memberId => [
            'role' => $memberId === (int) $actor->id ? 'owner' : 'guest',
            'response' => $memberId === (int) $actor->id ? 'accepted' : 'pending',
        ]])->all());

        return $event;
    }

    /**
     * Kdo tvoří „celý prostor" u sdílené akce — dvojice, ne hosté galerie.
     *
     * Hosté (viewer/contributor) vidí jen sdílené odkazy; akce dvojice jim do
     * kalendáře ani do připomínek nepatří. Účet s odebraným přístupem taky ne.
     * Vlastník je vlastník, i kdyby mu v členství zůstala výchozí role
     * (`PristupDoGalerie::dvojice`). Veřejné, aby si připomínky a účastníky
     * řadiče, které je zakládají samy, nebraly z celého `gallery_space_user`.
     *
     * @return Collection<int, int>
     */
    public function coupleMemberIds(GallerySpace $space, User $actor): Collection
    {
        return $this->pristup->dvojice($space)
            ->map(fn (User $member) => (int) $member->id)
            ->push((int) $actor->id)
            ->unique()
            ->values();
    }
}
