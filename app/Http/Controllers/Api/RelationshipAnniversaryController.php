<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Services\Auth\PristupDoGalerie;
use App\Support\Cas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Keeps one shared relationship start date connected to calendar and reminders. */
class RelationshipAnniversaryController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $space = $this->space($request, $request->integer('gallery_space_id') ?: null);

        return response()->json($this->payload($space));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gallery_space_id' => 'required|integer',
            // „Dnes" dvojice (Praha), ne serveru v UTC — po půlnoci by jinak
            // dnešní datum neprošlo.
            'started_on' => 'required|date|before_or_equal:'.Cas::dnes()->toDateString(),
            'reminder_days' => 'nullable|array|min:1|max:8',
            'reminder_days.*' => 'integer|between:0,365',
        ]);
        $space = $this->space($request, (int) $data['gallery_space_id']);
        $reminderDays = collect($data['reminder_days'] ?? [30, 7, 1])
            ->map(fn ($day) => (int) $day)->unique()->sortDesc()->values()->all();

        DB::transaction(function () use ($space, $request, $data, $reminderDays): void {
            $settings = $space->settings ?? [];
            $previous = (array) ($settings['relationship_anniversary'] ?? []);
            $startedOn = Carbon::parse($data['started_on'])->startOfDay();

            $milestoneId = $this->upsertMilestone($space, $request->user()->id, $startedOn, $previous['milestone_id'] ?? null);
            $eventIds = $this->upsertCalendarEvents($space, $request->user()->id, $startedOn, $reminderDays, (array) ($previous['event_ids'] ?? []));

            $settings['relationship_anniversary'] = [
                'started_on' => $startedOn->toDateString(),
                'reminder_days' => $reminderDays,
                'milestone_id' => $milestoneId,
                'event_ids' => $eventIds,
                'updated_at' => now()->toIso8601String(),
            ];
            $space->update(['settings' => $settings]);
        });

        return response()->json($this->payload($space->fresh()));
    }

    private function payload(GallerySpace $space): array
    {
        $config = (array) (($space->settings ?? [])['relationship_anniversary'] ?? []);
        $events = collect((array) ($config['event_ids'] ?? []))
            ->filter()
            ->pipe(fn ($ids) => $ids->isEmpty() ? collect() : CalendarEvent::where('gallery_space_id', $space->id)->whereIn('id', $ids)->get())
            ->map(fn (CalendarEvent $event) => [
                'uuid' => $event->uuid,
                'title' => $event->title,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'recurrence_rule' => $event->recurrence_rule,
            ])->values();

        return [
            'gallery_space_id' => $space->id,
            'started_on' => $config['started_on'] ?? null,
            'reminder_days' => $config['reminder_days'] ?? [30, 7, 1],
            'events' => $events,
        ];
    }

    private function upsertMilestone(GallerySpace $space, int $userId, Carbon $startedOn, ?int $id): ?int
    {
        if (! Schema::hasTable('relationship_milestones')) {
            return null;
        }

        $values = [
            'gallery_space_id' => $space->id,
            'title' => 'Začátek našeho vztahu',
            'description' => 'Společně nastavené datum pro naše výročí.',
            'occurred_on' => $startedOn->toDateString(),
            'icon' => '❤️',
            'visibility' => 'shared',
            'remind_annually' => true,
            'updated_at' => now(),
        ];
        $existing = $id ? DB::table('relationship_milestones')->where('id', $id)->where('gallery_space_id', $space->id)->first() : null;
        if ($existing) {
            DB::table('relationship_milestones')->where('id', $existing->id)->update($values);

            return $existing->id;
        }

        return DB::table('relationship_milestones')->insertGetId($values + [
            'uuid' => (string) Str::uuid(), 'created_by' => $userId, 'created_at' => now(),
        ]);
    }

    /** @return array<string, int> */
    private function upsertCalendarEvents(GallerySpace $space, int $userId, Carbon $startedOn, array $reminderDays, array $knownIds): array
    {
        // Výročí patří dvojici — host prostoru (`viewer`) nebyl jen pozvaný, ale
        // dostal roli `editor` a připomínky na výročí cizího vztahu.
        $members = app(PristupDoGalerie::class)->dvojice($space)->pluck('id')->map(fn ($id) => (int) $id)
            ->push($userId)->unique()->values()->all();
        $definitions = [
            'one_month' => ['title' => '1 měsíc spolu', 'date' => $startedOn->copy()->addMonthNoOverflow(), 'recurrence' => null],
            'half_year' => ['title' => 'Půl roku spolu', 'date' => $startedOn->copy()->addMonthsNoOverflow(6), 'recurrence' => null],
            'annual' => ['title' => 'Naše výročí', 'date' => $startedOn->copy()->addYearNoOverflow(), 'recurrence' => ['frequency' => 'yearly', 'interval' => 1]],
        ];
        $ids = [];

        foreach ($definitions as $key => $definition) {
            $event = ! empty($knownIds[$key])
                ? CalendarEvent::where('id', $knownIds[$key])->where('gallery_space_id', $space->id)->first()
                : null;
            $event ??= new CalendarEvent(['gallery_space_id' => $space->id, 'created_by' => $userId]);
            $metadata = $event->metadata ?? [];
            $metadata['source'] = 'relationship_anniversary';
            $metadata['anniversary_key'] = $key;

            $startsAt = $definition['date']->copy()->setTime(18, 0);
            $event->fill([
                'title' => $definition['title'],
                'description' => 'Automaticky navázáno na začátek vašeho vztahu.',
                'type' => 'anniversary',
                'status' => $definition['recurrence'] || $startsAt->isFuture() ? 'planned' : 'completed',
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHours(2),
                'timezone' => 'Europe/Prague',
                'recurrence_rule' => $definition['recurrence'],
                'color' => '#ec4899',
                'is_private' => false,
                'metadata' => $metadata,
            ]);
            $event->save();
            $event->participants()->syncWithoutDetaching(collect($members)->mapWithKeys(fn ($memberId) => [$memberId => [
                // Relationship anniversaries are maintained by both partners.
                // An editor role lets either partner add a task, a reservation or
                // a memory without having to duplicate the calendar event.
                'role' => $memberId === $userId ? 'owner' : 'editor',
                'response' => 'accepted',
            ]])->all());
            // Hosta zapsaného dřívější verzí z akce výročí zase odebere.
            $cizi = $event->participants()->pluck('users.id')->map(fn ($id) => (int) $id)->diff($members)->values()->all();
            if ($cizi !== []) {
                $event->participants()->detach($cizi);
            }

            // The recurring annual reminder is calculated from the source date
            // by gallery:relationship-milestones. One-off first-month and
            // half-year events use ordinary mobile/browser event reminders.
            if ($key !== 'annual') {
                $event->reminders()->delete();
                if ($startsAt->isFuture()) {
                    foreach ($members as $memberId) {
                        foreach ($reminderDays as $days) {
                            $remindAt = $startsAt->copy()->subDays($days);
                            if ($remindAt->gte(now())) {
                                $event->reminders()->create(['user_id' => $memberId, 'channel' => 'database', 'remind_at' => $remindAt, 'status' => 'pending']);
                            }
                        }
                    }
                }
            }
            $ids[$key] = $event->id;
        }

        return $ids;
    }

    /** Jen prostor, kde účet patří do dvojice — ne galerie, kam je pozvaný jako host. */
    private function space(Request $request, ?int $id): GallerySpace
    {
        $query = $request->user()->gallerySpaces()->whereKey(app(PristupDoGalerie::class)->idProstoruDvojice($request->user()));

        return $id ? $query->whereKey($id)->firstOrFail() : $query->firstOrFail();
    }
}
