<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\EventAttachment;
use App\Models\EventReminder;
use App\Models\User;
use Carbon\Carbon;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevisitSuggestionController extends Controller
{
    public function show(Request $request, string $uuid): JsonResponse
    {
        $spaceIds = $request->user()->gallerySpaces()->pluck('gallery_spaces.id');
        $source = MediaItem::where('uuid', $uuid)->whereIn('gallery_space_id', $spaceIds)->whereNull('trashed_at')->firstOrFail();
        if ($source->latitude === null || $source->longitude === null) return response()->json(['source' => $source->uuid, 'candidates' => [], 'message' => 'Zdrojová fotografie nemá GPS souřadnice.']);
        $candidates = MediaItem::where('gallery_space_id', $source->gallery_space_id)->whereNull('trashed_at')->where('id', '!=', $source->id)
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereRaw('ABS(latitude - ?) < 0.015 AND ABS(longitude - ?) < 0.015', [$source->latitude, $source->longitude])
            ->orderByDesc('taken_at')->limit(24)->get(['uuid', 'display_title', 'original_filename', 'taken_at', 'latitude', 'longitude']);
        return response()->json(['source' => $source->uuid, 'candidates' => $candidates, 'prompt' => $source->taken_at ? 'Zopakujte snímek ve stejném místě a porovnejte jej v Porovnání.' : null]);
    }

    /**
     * A gallery memory should be able to become a real shared plan, not only
     * a passive suggestion. The source media remains attached to the new
     * calendar event, so the later reflection and album retain its origin.
     */
    public function schedule(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        $source = MediaItem::query()
            ->where('uuid', $uuid)
            ->whereIn('gallery_space_id', $user->gallerySpaces()->pluck('gallery_spaces.id'))
            ->whereNull('trashed_at')
            ->firstOrFail();
        abort_if($source->latitude === null || $source->longitude === null, 422, 'Pro návrat na místo potřebuje fotografie GPS souřadnice.');

        $data = $request->validate([
            'starts_at' => 'required|date|after:now',
            'title' => 'nullable|string|max:160',
            'place_name' => 'nullable|string|max:255',
            'reminder_minutes' => 'nullable|integer|min:0|max:525600',
        ]);
        $startsAt = Carbon::parse($data['starts_at']);
        $existing = CalendarEvent::query()
            ->where('gallery_space_id', $source->gallery_space_id)
            ->where('starts_at', $startsAt)
            ->where('metadata->source_media_uuid', $source->uuid)
            ->first();
        if ($existing) return response()->json($this->payload($existing));

        $nearestPlace = $source->places()->orderByDesc('media_place.is_primary')->first();
        $title = $data['title'] ?: 'Znovu spolu: ' . ($nearestPlace?->name ?: ($source->display_title ?: 'náš oblíbený okamžik'));
        $event = CalendarEvent::create([
            'gallery_space_id' => $source->gallery_space_id,
            'created_by' => $user->id,
            'title' => $title,
            'description' => 'Naplánováno přímo z galerie, abyste si mohli znovu vytvořit společnou vzpomínku.',
            'type' => 'outing',
            'status' => 'planned',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(2),
            'timezone' => 'Europe/Prague',
            'place_name' => $data['place_name'] ?? $nearestPlace?->name,
            'latitude' => $source->latitude,
            'longitude' => $source->longitude,
            'color' => '#ec4899',
            'is_private' => false,
            'metadata' => ['kind' => 'media_revisit', 'source_media_uuid' => $source->uuid],
        ]);

        $members = User::query()->whereHas('gallerySpaces', fn ($query) => $query->where('gallery_spaces.id', $source->gallery_space_id))->get(['users.id']);
        foreach ($members as $member) {
            $event->participants()->syncWithoutDetaching([$member->id => [
                'role' => $member->id === $user->id ? 'owner' : 'guest',
                'response' => $member->id === $user->id ? 'accepted' : 'pending',
            ]]);
            EventReminder::create([
                'event_id' => $event->id,
                'user_id' => $member->id,
                'channel' => 'database',
                'remind_at' => $startsAt->copy()->subMinutes((int) ($data['reminder_minutes'] ?? 10080)),
                'status' => 'pending',
            ]);
        }
        EventAttachment::firstOrCreate(['event_id' => $event->id, 'media_item_id' => $source->id], ['kind' => 'memory']);

        return response()->json($this->payload($event), 201);
    }

    private function payload(CalendarEvent $event): array
    {
        return [
            'id' => $event->id,
            'uuid' => $event->uuid,
            'title' => $event->title,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'place_name' => $event->place_name,
            'source_media_uuid' => $event->metadata['source_media_uuid'] ?? null,
        ];
    }
}
