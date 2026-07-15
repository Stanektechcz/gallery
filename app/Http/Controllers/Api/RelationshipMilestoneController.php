<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\EventAttachment;
use App\Models\EventReminder;
use App\Models\MediaItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RelationshipMilestoneController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->withLinkedMedia($this->visible($request)->orderBy('occurred_on')->get()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data = $this->normalizePersonalDay($data);
        abort_unless($request->user()->gallerySpaces()->whereKey($data['gallery_space_id'])->exists(), 404);
        if (!empty($data['media_item_id'])) DB::table('media_items')->where('id', $data['media_item_id'])->where('gallery_space_id', $data['gallery_space_id'])->whereNull('trashed_at')->firstOrFail();
        $id = DB::table('relationship_milestones')->insertGetId($data + ['uuid' => (string) Str::uuid(), 'created_by' => $request->user()->id, 'icon' => $data['icon'] ?? '❤️', 'visibility' => $data['visibility'] ?? 'shared', 'remind_annually' => $data['remind_annually'] ?? true, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json($this->withLinkedMedia(collect([DB::table('relationship_milestones')->find($id)]))->first(), 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $milestone = $this->visible($request)->where('uuid', $uuid)->firstOrFail();
        abort_unless($milestone->created_by === $request->user()->id || $milestone->visibility === 'shared', 403);
        $data = $this->validated($request, true);
        // A milestone must stay in the space in which it was created.  Moving it
        // through a PATCH request would bypass the membership check from store().
        unset($data['gallery_space_id']);
        $data = $this->normalizePersonalDay($data, $milestone);
        if (!empty($data['media_item_id'])) DB::table('media_items')->where('id', $data['media_item_id'])->where('gallery_space_id', $milestone->gallery_space_id)->whereNull('trashed_at')->firstOrFail();
        DB::table('relationship_milestones')->where('id', $milestone->id)->update($data + ['updated_at' => now()]);
        return response()->json($this->withLinkedMedia(collect([DB::table('relationship_milestones')->find($milestone->id)]))->first());
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $milestone = $this->visible($request)->where('uuid', $uuid)->firstOrFail();
        abort_unless($milestone->created_by === $request->user()->id, 403);
        DB::table('relationship_milestones')->where('id', $milestone->id)->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function upcoming(Request $request): JsonResponse
    {
        $today = now();
        $items = $this->visible($request)->where('remind_annually', true)->get()->map(function ($item) use ($today) { $next = \Carbon\Carbon::parse($item->occurred_on)->year($today->year); if ($next->lt($today->copy()->startOfDay())) $next->addYear(); $item->next_anniversary = $next->toDateString(); $item->days_until = $today->startOfDay()->diffInDays($next->startOfDay()); return $item; })->sortBy('days_until')->values();
        return response()->json($this->withLinkedMedia($items));
    }

    /** Plan a real shared celebration from a milestone without losing its memory link. */
    public function scheduleCelebration(Request $request, string $uuid): JsonResponse
    {
        $user = $request->user();
        $milestone = $this->visible($request)->where('uuid', $uuid)->firstOrFail();
        if ($milestone->visibility === 'private') abort_unless($milestone->created_by === $user->id, 403);

        $data = $request->validate([
            'starts_at' => 'required|date|after:now',
            'title' => 'nullable|string|max:160',
            'reminder_minutes' => 'nullable|integer|min:0|max:525600',
        ]);
        $startsAt = Carbon::parse($data['starts_at']);
        $existing = CalendarEvent::query()
            ->where('gallery_space_id', $milestone->gallery_space_id)
            ->where('starts_at', $startsAt)
            ->where('metadata->source_milestone_uuid', $milestone->uuid)
            ->first();
        if ($existing) return response()->json($this->celebrationPayload($existing));

        $shared = $milestone->visibility === 'shared';
        $event = CalendarEvent::create([
            'gallery_space_id' => $milestone->gallery_space_id,
            'created_by' => $user->id,
            'title' => $data['title'] ?? (($milestone->kind ?? 'milestone') === 'birthday' ? "Oslava narozenin: {$milestone->person_name}" : "Oslava: {$milestone->title}"),
            'description' => $milestone->description ?: (($milestone->kind ?? 'milestone') === 'birthday' ? "Společná oslava narozenin pro {$milestone->person_name}." : "Společná oslava milníku „{$milestone->title}“."),
            'type' => ($milestone->kind ?? 'milestone') === 'birthday' ? 'birthday' : 'anniversary',
            'status' => 'planned',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(3),
            'timezone' => 'Europe/Prague',
            'color' => ($milestone->kind ?? 'milestone') === 'birthday' ? '#f59e0b' : '#ec4899',
            'is_private' => ! $shared,
            'metadata' => [
                'kind' => ($milestone->kind ?? 'milestone') === 'birthday' ? 'birthday_celebration' : 'milestone_celebration',
                'source_milestone_uuid' => $milestone->uuid,
                'relationship' => $milestone->relationship ?? null,
            ],
        ]);

        $members = $shared
            ? User::query()->whereHas('gallerySpaces', fn ($query) => $query->where('gallery_spaces.id', $milestone->gallery_space_id))->get(['users.id'])
            : collect([$user]);
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
        if ($milestone->media_item_id) {
            EventAttachment::firstOrCreate(['event_id' => $event->id, 'media_item_id' => $milestone->media_item_id], ['kind' => 'memory']);
        }

        return response()->json($this->celebrationPayload($event), 201);
    }

    private function visible(Request $request)
    {
        return DB::table('relationship_milestones')->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->where(fn ($query) => $query->where('visibility', 'shared')->orWhere('created_by', $request->user()->id));
    }

    /**
     * A milestone is much more useful when its primary photo or video can be
     * rendered everywhere it is surfaced. Keep the relationship deliberately
     * small so this endpoint stays suitable for dashboard and calendar loads.
     */
    private function withLinkedMedia($milestones)
    {
        $mediaIds = $milestones->pluck('media_item_id')->filter()->unique()->values();
        if ($mediaIds->isEmpty()) {
            return $milestones->map(fn ($milestone) => array_merge((array) $milestone, ['media' => null]))->values();
        }

        $mediaById = MediaItem::query()
            ->whereIn('id', $mediaIds)
            ->whereNull('trashed_at')
            ->with('variants')
            ->get()
            ->keyBy('id');

        return $milestones->map(function ($milestone) use ($mediaById) {
            $row = (array) $milestone;
            $media = $mediaById->get($row['media_item_id'] ?? null);
            $row['media'] = $media ? [
                'uuid' => $media->uuid,
                'thumbnail_url' => $media->thumbnail_url,
                'display_title' => $media->display_title,
                'original_filename' => $media->original_filename,
                'media_type' => $media->media_type,
            ] : null;

            return $row;
        })->values();
    }

    private function celebrationPayload(CalendarEvent $event): array
    {
        return [
            'id' => $event->id,
            'uuid' => $event->uuid,
            'title' => $event->title,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'source_milestone_uuid' => $event->metadata['source_milestone_uuid'] ?? null,
        ];
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes|' : 'required|';

        return $request->validate([
            'gallery_space_id' => $partial ? 'sometimes|integer' : 'required|integer',
            'title' => 'nullable|string|max:160',
            'kind' => ($partial ? 'sometimes|' : 'nullable|') . 'in:milestone,birthday',
            'person_name' => 'nullable|string|max:120',
            'relationship' => 'nullable|in:partner,parent,grandparent,sibling,child,friend,relative,aunt_uncle,cousin,colleague,other',
            'is_highlighted' => 'nullable|boolean',
            'description' => 'nullable|string|max:5000',
            'occurred_on' => $prefix . 'date',
            'icon' => 'nullable|string|max:16',
            'visibility' => 'nullable|in:shared,private',
            'remind_annually' => 'nullable|boolean',
            'media_item_id' => 'nullable|integer',
        ]);
    }

    private function normalizePersonalDay(array $data, ?object $existing = null): array
    {
        $kind = $data['kind'] ?? $existing?->kind ?? 'milestone';
        $personName = trim((string) ($data['person_name'] ?? $existing?->person_name ?? ''));
        $title = trim((string) ($data['title'] ?? $existing?->title ?? ''));

        if ($kind === 'birthday') {
            if ($personName === '') {
                throw ValidationException::withMessages(['person_name' => 'U narozenin vyplňte jméno oslavence.']);
            }
            $data['person_name'] = $personName;
            $data['title'] = $title !== '' && ! str_starts_with($title, 'Narozeniny:') ? $title : "Narozeniny: {$personName}";
            $data['icon'] ??= '🎂';
            $data['is_highlighted'] ??= true;
        } elseif ($title === '') {
            throw ValidationException::withMessages(['title' => 'Vyplňte název milníku.']);
        }

        $data['kind'] = $kind;

        return $data;
    }
}
