<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\EventAttachment;
use App\Models\EventReminder;
use App\Models\MediaItem;
use App\Services\Auth\PristupDoGalerie;
use App\Services\Planning\CalendarEventCreationService;
use App\Services\Planning\RelationshipMilestoneService;
use App\Support\Cas;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RelationshipMilestoneController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->withLinkedMedia($this->visible($request)->orderBy('occurred_on')->get()));
    }

    public function store(Request $request, RelationshipMilestoneService $milestones): JsonResponse
    {
        $data = $this->validated($request);
        $data = $this->normalizePersonalDay($data);
        abort_unless(in_array((int) $data['gallery_space_id'], $this->spaceIds($request), true), 404);
        if (! empty($data['media_item_id'])) {
            $this->sdilitelneMedium((int) $data['gallery_space_id'], (int) $data['media_item_id']);
        }
        $milestone = $milestones->create((int) $data['gallery_space_id'], $request->user()->id, $data, 'manual');

        return response()->json($this->withLinkedMedia(collect([$milestone]))->first(), 201);
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
        if (! empty($data['media_item_id'])) {
            $this->sdilitelneMedium((int) $milestone->gallery_space_id, (int) $data['media_item_id']);
        }
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
        // Dnešek dvojice (Praha) — po půlnoci v UTC by výročí vycházelo až „zítra".
        $today = Carbon::parse(Cas::dnes()->toDateString());
        $items = $this->visible($request)->where('remind_annually', true)->get()->map(function ($item) use ($today) {
            $next = Carbon::parse($item->occurred_on)->year($today->year);
            if ($next->lt($today)) {
                $next->addYear();
            } $item->next_anniversary = $next->toDateString();
            $item->days_until = (int) $today->diffInDays($next->copy()->startOfDay());

            return $item;
        })->sortBy('days_until')->values();

        return response()->json($this->withLinkedMedia($items));
    }

    /** Plan a real shared celebration from a milestone without losing its memory link. */
    public function scheduleCelebration(Request $request, string $uuid, CalendarEventCreationService $calendarEvents): JsonResponse
    {
        $user = $request->user();
        $milestone = $this->visible($request)->where('uuid', $uuid)->firstOrFail();
        if ($milestone->visibility === 'private') {
            abort_unless($milestone->created_by === $user->id, 403);
        }

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
        if ($existing) {
            return response()->json($this->celebrationPayload($existing));
        }

        $shared = $milestone->visibility === 'shared';
        $space = $user->gallerySpaces()->whereKey($milestone->gallery_space_id)->firstOrFail();
        $event = $calendarEvents->create($space, $user, [
            'title' => $data['title'] ?? (($milestone->kind ?? 'milestone') === 'birthday' ? "Oslava narozenin: {$milestone->person_name}" : "Oslava: {$milestone->title}"),
            'description' => $milestone->description ?: (($milestone->kind ?? 'milestone') === 'birthday' ? "Společná oslava narozenin pro {$milestone->person_name}." : "Společná oslava milníku „{$milestone->title}“."),
            'type' => ($milestone->kind ?? 'milestone') === 'birthday' ? 'birthday' : 'anniversary', 'status' => 'planned',
            'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addHours(3), 'timezone' => 'Europe/Prague',
            'color' => ($milestone->kind ?? 'milestone') === 'birthday' ? '#f59e0b' : '#ec4899', 'is_private' => ! $shared,
            'metadata' => ['kind' => ($milestone->kind ?? 'milestone') === 'birthday' ? 'birthday_celebration' : 'milestone_celebration', 'source_milestone_uuid' => $milestone->uuid, 'relationship' => $milestone->relationship ?? null],
        ], $shared ? null : [$user->id]);

        $members = $event->participants()->get(['users.id']);
        // Termín z `datetime-local` jsou pražské hodiny (tak se ukládá i `starts_at`);
        // plánovač porovnává `remind_at` s `now()` v UTC, proto okamžik v UTC.
        $remindAt = Cas::zHodin($startsAt)->subMinutes((int) ($data['reminder_minutes'] ?? 10080))->utc();
        foreach ($members as $member) {
            EventReminder::create([
                'event_id' => $event->id,
                'user_id' => $member->id,
                'channel' => 'database',
                'remind_at' => $remindAt,
                'status' => 'pending',
            ]);
        }
        // Fotka milníku, která je teď v koši nebo v trezoru, ke sdílené akci nejde.
        if ($milestone->media_item_id && $this->jeSdilitelne((int) $milestone->gallery_space_id, (int) $milestone->media_item_id)) {
            EventAttachment::firstOrCreate(['event_id' => $event->id, 'media_item_id' => $milestone->media_item_id], ['kind' => 'memory']);
        }

        return response()->json($this->celebrationPayload($event), 201);
    }

    private function visible(Request $request)
    {
        return DB::table('relationship_milestones')->whereIn('gallery_space_id', $this->spaceIds($request))->where(fn ($query) => $query->where('visibility', 'shared')->orWhere('created_by', $request->user()->id));
    }

    /**
     * Prostory dvojice — ne galerie, kam je účet pozvaný jen jako host (brána
     * posuzuje jen první prostor účtu).
     *
     * @return list<int>
     */
    private function spaceIds(Request $request): array
    {
        return app(PristupDoGalerie::class)->idProstoruDvojice($request->user());
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

        // Milník je sdílený (přehled, kalendář) — fotka z trezoru se u něj
        // neukáže, dokud se z trezoru nevrátí.
        $mediaById = MediaItem::query()
            ->whereIn('id', $mediaIds)
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
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

    /**
     * Fotka k milníku musí být z téhož prostoru a mimo koš i trezor — jinak 404,
     * stejně jako fotka z cizího prostoru.
     */
    private function sdilitelneMedium(int $spaceId, int $mediaId): void
    {
        abort_unless($this->jeSdilitelne($spaceId, $mediaId), 404);
    }

    private function jeSdilitelne(int $spaceId, int $mediaId): bool
    {
        return DB::table('media_items')->where('id', $mediaId)->where('gallery_space_id', $spaceId)
            ->whereNull('trashed_at')->where('is_hidden', false)->exists();
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
            'kind' => ($partial ? 'sometimes|' : 'nullable|').'in:milestone,birthday',
            'person_name' => 'nullable|string|max:120',
            'relationship' => 'nullable|in:partner,parent,grandparent,sibling,child,friend,relative,aunt_uncle,cousin,colleague,other',
            'is_highlighted' => 'nullable|boolean',
            'description' => 'nullable|string|max:5000',
            'occurred_on' => $prefix.'date',
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
