<?php

namespace App\Services\Planning;

use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Auth\PristupDoGalerie;
use Illuminate\Support\Facades\DB;

/** Creates calendar events with one consistent participant baseline. */
class CalendarEventCreationService
{
    /**
     * Passing no participant list creates a shared event for the whole space.
     * Passing a list is useful for a private or selectively invited calendar event.
     */
    public function create(GallerySpace $space, User $actor, array $attributes, ?array $participantIds = null): CalendarEvent
    {
        $event = CalendarEvent::create(array_replace($attributes, [
            'gallery_space_id' => $space->id,
            'created_by' => $actor->id,
        ]));

        // „Celý prostor" je dvojice, ne hosté galerie (viewer/contributor) — ti
        // vidí jen sdílené odkazy, akce dvojice jim do kalendáře nepatří.
        // Vlastník je vlastník, i kdyby mu v členství zůstala výchozí role.
        $members = $participantIds === null
            ? DB::table('gallery_space_user')->where('gallery_space_id', $space->id)
                ->where(fn ($q) => $q->whereIn('role', PristupDoGalerie::ROLE_DVOJICE)
                    ->orWhere('user_id', (int) $space->owner_id))
                ->pluck('user_id')->all()
            : $participantIds;
        $members = collect($members)
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
}
