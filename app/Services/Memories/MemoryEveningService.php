<?php

namespace App\Services\Memories;

use App\Jobs\Drive\CreateDriveFolderJob;
use App\Models\Album;
use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\MemoryEvening;
use App\Models\User;
use App\Notifications\GalleryNotification;
use App\Services\Auth\PristupDoGalerie;
use App\Services\Planning\CalendarEventCreationService;
use App\Support\Cas;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MemoryEveningService
{
    /** `event_attachments.label` je `string(255)`. */
    private const DELKA_POPISKU_PRILOHY = 255;

    public function __construct(
        private readonly CalendarEventCreationService $calendarEvents,
        private readonly PristupDoGalerie $pristup,
    ) {}

    public function schedule(GallerySpace $space, User $actor, array $data, Collection $media): MemoryEvening
    {
        $scheduled = ! empty($data['scheduled_for']) ? Carbon::parse($data['scheduled_for']) : $this->nextFreeEvening($space);
        $dedupeKey = hash('sha256', $space->id.'|'.$data['fingerprint'].'|'.$scheduled->toDateString());
        if ($existing = MemoryEvening::where('dedupe_key', $dedupeKey)->whereNotIn('status', ['cancelled'])->first()) {
            return $existing;
        }

        $eveningUuid = (string) Str::uuid();
        $evening = DB::transaction(function () use ($space, $actor, $data, $media, $scheduled, $dedupeKey, $eveningUuid) {
            $boardUuid = (string) Str::uuid();
            $board = [
                'uuid' => $boardUuid, 'gallery_space_id' => $space->id, 'created_by' => $actor->id,
                'title' => 'Výběr · '.$data['title'], 'description' => 'Společný výběr momentů pro večer se vzpomínkami.',
                'visibility' => 'shared', 'created_at' => now(), 'updated_at' => now(),
            ];
            if (Schema::hasColumn('curation_boards', 'purpose')) {
                $board['purpose'] = 'memory_evening';
            }
            $boardId = DB::table('curation_boards')->insertGetId($board);
            foreach ($media->values() as $index => $item) {
                DB::table('curation_board_items')->insert([
                    'curation_board_id' => $boardId, 'media_item_id' => $item->id, 'added_by' => $actor->id,
                    'status' => 'pending', 'sort_order' => $index, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $eventTitle = str_starts_with($data['title'], 'Večer se vzpomínkami') ? $data['title'] : 'Večer se vzpomínkami · '.$data['title'];
            $event = $this->calendarEvents->create($space, $actor, [
                'title' => $eventTitle,
                'description' => $data['description'] ?? 'Společně si projdeme vybrané fotografie a videa, zvolíme nejoblíbenější momenty a doplníme vlastní pohled.',
                'type' => 'memory_evening', 'status' => 'planned', 'starts_at' => $scheduled, 'ends_at' => $scheduled->copy()->addMinutes(90),
                'timezone' => 'Europe/Prague', 'color' => '#ec4899', 'is_private' => false,
                'recurrence_rule' => ($data['repeat_annually'] ?? false) ? ['frequency' => 'yearly', 'interval' => 1] : null,
                'metadata' => array_filter(['kind' => 'memory_evening', 'memory_evening' => true, 'memory_evening_uuid' => $eveningUuid, 'fingerprint' => $data['fingerprint'], 'board_uuid' => $boardUuid, 'memory_moment_uuids' => $data['source_moment_uuids'] ?? null, 'href' => '/memories#memory-evenings'], fn ($value) => $value !== null),
            ]);
            $members = $event->participants()->pluck('users.id');
            /*
             * Termín večera jsou pražské hodiny (z `datetime-local` bez pásma nebo
             * z pražského `nextFreeEvening()`) a tak se ukládá i `starts_at`.
             * `remind_at` ale plánovač porovnává s `now()` v UTC — bez převodu
             * chodila připomínka o hodinu či dvě později.
             */
            $okamzik = Cas::zHodin($scheduled);
            foreach ($members as $memberId) {
                foreach ([1440, 30] as $minutes) {
                    $remindAt = $okamzik->subMinutes($minutes)->utc();
                    if ($remindAt->isFuture()) {
                        DB::table('event_reminders')->insert(['event_id' => $event->id, 'user_id' => $memberId, 'channel' => 'database', 'remind_at' => $remindAt, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }

            return MemoryEvening::create([
                'uuid' => $eveningUuid, 'gallery_space_id' => $space->id, 'created_by' => $actor->id, 'calendar_event_id' => $event->id,
                'curation_board_id' => $boardId, 'fingerprint' => $data['fingerprint'], 'dedupe_key' => $dedupeKey,
                'source_type' => $data['source_type'], 'title' => $data['title'], 'description' => $data['description'] ?? null,
                'scheduled_for' => $scheduled, 'status' => 'planned', 'repeat_annually' => $data['repeat_annually'] ?? false,
                'metadata' => ['source_happened_on' => $data['source_happened_on'] ?? null, 'media_count' => $media->count()],
            ]);
        });

        // Upozornění dostane jen druhý z dvojice — host galerie ne.
        foreach ($this->pristup->dvojice($space)->reject(fn (User $clen) => (int) $clen->id === (int) $actor->id) as $member) {
            $member->notify(new GalleryNotification('memory.evening.planned', $actor->name.' naplánoval/a společný večer se vzpomínkami: '.$evening->title, '/memories#memory-evenings', '💞', ['memory_evening_uuid' => $evening->uuid]));
        }

        return $evening;
    }

    public function complete(MemoryEvening $evening, User $actor): MemoryEvening
    {
        if ($evening->status === 'completed') {
            return $evening;
        }
        $result = DB::transaction(function () use ($evening, $actor) {
            // Dvojklik nebo oba partneři naráz: stav se kontroluje znovu až pod
            // zámkem řádku, jinak by vznikla dvě alba i dvě společné vzpomínky.
            $locked = MemoryEvening::whereKey($evening->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'completed') {
                return [$locked, null];
            }

            $items = DB::table('curation_board_items as item')->where('item.curation_board_id', $evening->curation_board_id)->orderBy('item.sort_order')->get();
            $selectedIds = DB::table('curation_board_votes as vote')->join('curation_board_items as item', 'item.id', '=', 'vote.curation_board_item_id')
                ->where('item.curation_board_id', $evening->curation_board_id)->where('vote.is_selected', true)->distinct()->pluck('item.media_item_id');
            if ($selectedIds->isEmpty()) {
                $selectedIds = $items->pluck('media_item_id')->take(16);
            }
            abort_if($selectedIds->isEmpty(), 422, 'Večer neobsahuje žádné dostupné fotografie ani video.');
            // Fotka, kterou mezi naplánováním a dokončením někdo uklidil do trezoru,
            // do sdíleného alba (ani na jeho obálku) nepatří — album zůstane čitelné
            // i se zamčeným trezorem, stejně jako fotokniha.
            $media = MediaItem::whereIn('id', $selectedIds)->where('gallery_space_id', $evening->gallery_space_id)
                ->whereNull('trashed_at')->where('is_hidden', false)->orderBy('taken_at')->get();
            abort_if($media->isEmpty(), 422, 'Vybrané vzpomínky už nejsou v galerii dostupné.');

            $album = Album::create([
                'gallery_space_id' => $evening->gallery_space_id, 'title' => 'Naše vzpomínky · '.$evening->title,
                'slug' => Str::slug('nase-vzpominky-'.$evening->id.'-'.$evening->title),
                'description' => 'Společně vybrané fotografie a videa z večera se vzpomínkami.',
                'event_date_start' => $media->min('taken_at')?->toDateString(), 'event_date_end' => $media->max('taken_at')?->toDateString(),
                'cover_media_id' => $media->first()->id, 'visibility' => 'shared', 'icon' => '💞', 'color' => '#ec4899',
                'created_by' => $actor->id, 'updated_by' => $actor->id, 'sync_status' => 'pending', 'media_count' => $media->count(),
            ]);
            $album->rebuildPaths();
            foreach ($media->values() as $index => $item) {
                DB::table('album_media')->insertOrIgnore(['album_id' => $album->id, 'media_item_id' => $item->id, 'sort_order' => $index, 'is_cover' => $index === 0, 'added_at' => now(), 'added_by' => $actor->id]);
            }
            // Editorem alba je dvojice, ne každý člen prostoru: host (`viewer`)
            // by s řádkem `editor` album otevřel i upravoval (viz `AlbumPolicy`).
            $space = GallerySpace::findOrFail($evening->gallery_space_id);
            $permissions = $this->pristup->dvojice($space)->pluck('id')->map(fn ($userId) => ['album_id' => $album->id, 'user_id' => $userId, 'role' => 'editor', 'inherited' => false, 'created_at' => now(), 'updated_at' => now()])->all();
            if ($permissions) {
                DB::table('album_user_permissions')->upsert($permissions, ['album_id', 'user_id'], ['role', 'updated_at']);
            }

            $momentUuid = (string) Str::uuid();
            $momentId = DB::table('shared_memory_moments')->insertGetId([
                'uuid' => $momentUuid, 'gallery_space_id' => $evening->gallery_space_id, 'created_by' => $actor->id,
                'calendar_event_id' => $evening->calendar_event_id, 'title' => $evening->title,
                'note' => $this->jointReflection($evening), 'happened_on' => data_get($evening->metadata, 'source_happened_on') ?: $media->first()->taken_at?->toDateString(),
                'media_item_ids' => json_encode($media->pluck('id')->values()->all()), 'is_favorite' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if (Schema::hasTable('shared_memory_reflections')) {
                $reflections = DB::table('memory_evening_reflections')->where('memory_evening_id', $evening->id)->get();
                foreach ($reflections as $reflection) {
                    DB::table('shared_memory_reflections')->updateOrInsert(['shared_memory_moment_id' => $momentId, 'user_id' => $reflection->user_id], ['mood' => $reflection->mood, 'note' => $reflection->note, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            foreach ($items as $item) {
                DB::table('curation_board_items')->where('id', $item->id)->update(['status' => $selectedIds->contains($item->media_item_id) ? 'selected' : 'rejected', 'updated_at' => now()]);
            }
            if ($event = CalendarEvent::find($evening->calendar_event_id)) {
                $event->update(['status' => 'completed', 'album_id' => $album->id]);
                /*
                 * Příloha jen jednou na (akce, médium, druh).
                 *
                 * `event_attachments` nemá unikátní klíč, takže `insertOrIgnore`
                 * nededuplikoval nic — jen na MySQL potichu spolkl chyby: popisek
                 * delší než sloupec (`display_title` pojme 512 znaků, `label` 255)
                 * se tam oříznul, na SQLite ne. Teď kontrola přirozeného klíče
                 * v téže transakci a oříznutí výslovně, stejně na obou.
                 */
                foreach ($media as $item) {
                    $priloha = ['event_id' => $event->id, 'media_item_id' => $item->id, 'kind' => 'memory'];
                    if (DB::table('event_attachments')->where($priloha)->exists()) {
                        continue;
                    }
                    DB::table('event_attachments')->insert($priloha + [
                        'label' => mb_substr((string) ($item->display_title ?: $item->original_filename), 0, self::DELKA_POPISKU_PRILOHY),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            $evening->update(['album_id' => $album->id, 'shared_memory_moment_id' => $momentId, 'status' => 'completed', 'completed_at' => now()]);

            return [$evening->fresh(), $album];
        });
        if ($result[1] !== null) {
            DB::afterCommit(fn () => CreateDriveFolderJob::dispatch($result[1]));
        }

        return $result[0];
    }

    public function nextFreeEvening(GallerySpace $space): Carbon
    {
        $day = now('Europe/Prague')->addDay()->startOfDay();
        for ($offset = 0; $offset < 35; $offset++, $day->addDay()) {
            if (! in_array($day->dayOfWeekIso, [3, 5, 6, 7], true)) {
                continue;
            }
            $start = $day->copy()->setTime(19, 30);
            $end = $start->copy()->addMinutes(90);
            if (! CalendarEvent::where('gallery_space_id', $space->id)->where('status', '!=', 'cancelled')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
                return $start;
            }
        }

        return now('Europe/Prague')->addWeeks(6)->setTime(19, 30);
    }

    private function jointReflection(MemoryEvening $evening): ?string
    {
        $notes = DB::table('memory_evening_reflections as reflection')->join('users', 'users.id', '=', 'reflection.user_id')->where('reflection.memory_evening_id', $evening->id)->whereNotNull('reflection.note')->get(['users.name', 'reflection.note']);

        return $notes->isEmpty() ? $evening->description : $notes->map(fn ($item) => $item->name.': '.$item->note)->implode("\n\n");
    }
}
