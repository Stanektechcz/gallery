<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Planning\TripPreparationTimelineService;
use App\Services\Travel\TripReservationImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TripReservationController extends Controller
{
    public function index(Request $request, int $tripId): JsonResponse
    {
        $this->trip($request, $tripId);
        $this->available();
        $rows = DB::table('trip_reservation_imports')->where('trip_id', $tripId)->latest()->get()
            ->map(fn (object $row) => $this->payload($row));
        return response()->json($rows);
    }

    public function store(Request $request, int $tripId, TripReservationImportService $service): JsonResponse
    {
        $trip = $this->trip($request, $tripId);
        $this->available();
        $data = $request->validate([
            'file' => 'nullable|required_without:source_text|file|max:20480|mimetypes:application/pdf,image/jpeg,image/png,image/webp,text/plain,text/csv,message/rfc822,application/json',
            'source_text' => 'nullable|required_without:file|string|max:100000',
        ]);
        $file = $request->file('file');
        $sourceText = trim((string) ($data['source_text'] ?? ''));
        $hash = $file ? hash_file('sha256', (string) $file->getRealPath()) : hash('sha256', Str::squish($sourceText));
        $existing = DB::table('trip_reservation_imports')->where('trip_id', $tripId)->where('sha256', $hash)->first();
        if ($existing) return response()->json(['duplicate' => true, 'import' => $this->payload($existing)]);

        $analysis = $service->analyse($file, $sourceText, $trip);
        $uuid = (string) Str::uuid();
        $path = null;
        if ($file) {
            $extension = strtolower((string) ($file->extension() ?: $file->getClientOriginalExtension() ?: 'bin'));
            $path = $file->storeAs("trip-reservations/{$tripId}", "{$uuid}.{$extension}", 'local');
        }
        $id = DB::table('trip_reservation_imports')->insertGetId([
            'uuid' => $uuid, 'trip_id' => $tripId, 'uploaded_by' => $request->user()->id,
            'original_name' => $file?->getClientOriginalName(), 'mime_type' => $file?->getMimeType(),
            'size_bytes' => $file?->getSize(), 'storage_path' => $path, 'sha256' => $hash,
            'status' => 'needs_review', 'extraction_method' => $analysis['method'],
            'source_text' => $analysis['text'] ? Crypt::encryptString($analysis['text']) : null,
            'extracted_data' => json_encode($analysis['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'processing_error' => $analysis['error'], 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['duplicate' => false, 'import' => $this->payload(DB::table('trip_reservation_imports')->find($id))], 201);
    }

    public function confirm(Request $request, int $tripId, string $uuid, TripPreparationTimelineService $preparation): JsonResponse
    {
        $trip = $this->trip($request, $tripId);
        $this->available();
        $import = DB::table('trip_reservation_imports')->where('trip_id', $tripId)->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'type' => 'required|in:ticket,accommodation,activity,insurance,other',
            'title' => 'required|string|max:255', 'provider' => 'nullable|string|max:160',
            'reference' => 'nullable|string|max:255', 'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at', 'origin' => 'nullable|string|max:255',
            'destination' => 'nullable|string|max:255', 'place_name' => 'nullable|string|max:255',
            'amount' => 'nullable|numeric|min:0|max:999999999', 'currency' => 'nullable|string|size:3',
            'notes' => 'nullable|string|max:5000', 'trip_day_id' => 'nullable|integer',
            'sync_itinerary' => 'nullable|boolean', 'sync_calendar' => 'nullable|boolean',
            'reminder_hours' => 'nullable|array|max:4', 'reminder_hours.*' => 'integer|min:1|max:720',
        ]);
        $dayId = $data['trip_day_id'] ?? null;
        if ($dayId) abort_unless(DB::table('trip_days')->where('id', $dayId)->where('trip_id', $tripId)->exists(), 422, 'Vybraný den nepatří k této cestě.');
        if (! $dayId && ! empty($data['starts_at'])) $dayId = DB::table('trip_days')->where('trip_id', $tripId)->where('date', Carbon::parse($data['starts_at'])->toDateString())->value('id');
        $confirmed = array_replace([
            'provider' => null, 'reference' => null, 'starts_at' => null, 'ends_at' => null,
            'origin' => null, 'destination' => null, 'place_name' => null, 'amount' => null,
            'currency' => null, 'notes' => null,
        ], collect($data)->except(['sync_itinerary', 'sync_calendar', 'reminder_hours', 'trip_day_id'])->all());
        $confirmed['currency'] = strtoupper((string) ($confirmed['currency'] ?? $trip->currency ?? 'CZK'));
        $syncItinerary = (bool) ($data['sync_itinerary'] ?? true);
        $syncCalendar = (bool) ($data['sync_calendar'] ?? true);
        $reminderHours = array_values(array_unique($data['reminder_hours'] ?? [24, 2]));

        $result = DB::transaction(function () use ($request, $trip, $tripId, $import, $confirmed, $dayId, $syncItinerary, $syncCalendar, $reminderHours) {
            $documentId = $this->syncDocument($request, $tripId, $import, $confirmed);
            $activityId = $syncItinerary && $dayId ? $this->syncActivity($request, $import, (int) $dayId, $confirmed) : $import->trip_activity_id;
            $inboxId = $this->syncInbox($request, $trip, $import, $confirmed, $dayId, $activityId);
            $eventId = $syncCalendar && ! empty($confirmed['starts_at'])
                ? $this->syncCalendar($request, $trip, $import, $confirmed, $reminderHours)
                : $import->calendar_event_id;
            DB::table('trip_reservation_imports')->where('id', $import->id)->update([
                'trip_day_id' => $dayId, 'trip_activity_id' => $activityId, 'calendar_event_id' => $eventId,
                'travel_inbox_item_id' => $inboxId, 'document_check_id' => $documentId,
                'status' => 'confirmed', 'confirmed_data' => json_encode($confirmed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'confirmed_at' => now(), 'processing_error' => null, 'updated_at' => now(),
            ]);
            return DB::table('trip_reservation_imports')->find($import->id);
        });
        if ($preparation->canSync()) $preparation->sync($trip);

        return response()->json($this->payload($result));
    }

    public function destroy(Request $request, int $tripId, string $uuid): JsonResponse
    {
        $this->trip($request, $tripId);
        $this->available();
        $import = DB::table('trip_reservation_imports')->where('trip_id', $tripId)->where('uuid', $uuid)->firstOrFail();
        abort_if($import->status === 'confirmed', 422, 'Potvrzený podklad je už propojený s cestou a nelze jej zahodit bez kontroly navázaných údajů.');
        if ($import->storage_path) Storage::disk('local')->delete($import->storage_path);
        DB::table('trip_reservation_imports')->where('id', $import->id)->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function download(Request $request, int $tripId, string $uuid): BinaryFileResponse
    {
        $this->trip($request, $tripId);
        $this->available();
        $import = DB::table('trip_reservation_imports')->where('trip_id', $tripId)->where('uuid', $uuid)->firstOrFail();
        abort_unless($import->storage_path && Storage::disk('local')->exists($import->storage_path), 404);
        $name = preg_replace('/[^\pL\pN._ -]+/u', '-', (string) ($import->original_name ?: 'cestovni-podklad')) ?: 'cestovni-podklad';
        return response()->download(Storage::disk('local')->path($import->storage_path), $name, ['Content-Type' => $import->mime_type ?: 'application/octet-stream']);
    }

    private function syncDocument(Request $request, int $tripId, object $import, array $data): int
    {
        $values = ['trip_id' => $tripId, 'created_by' => $request->user()->id,
            'type' => match ($data['type']) { 'ticket' => 'ticket', 'insurance' => 'insurance', default => 'booking' },
            'title' => $data['title'], 'status' => 'ready', 'reference' => $data['reference'] ?: null, 'updated_at' => now()];
        if ($import->document_check_id && DB::table('trip_document_checks')->where('id', $import->document_check_id)->where('trip_id', $tripId)->exists()) {
            DB::table('trip_document_checks')->where('id', $import->document_check_id)->update($values);
            return (int) $import->document_check_id;
        }
        return DB::table('trip_document_checks')->insertGetId($values + ['created_at' => now()]);
    }

    private function syncActivity(Request $request, object $import, int $dayId, array $data): int
    {
        $route = collect([$data['origin'] ?? null, $data['destination'] ?? null])->filter()->implode(' → ');
        $description = collect([$data['provider'] ?? null, $route ?: null, $data['reference'] ? 'Kód: '.$data['reference'] : null, $data['notes'] ?? null])->filter()->implode("\n");
        $values = ['trip_day_id' => $dayId, 'created_by' => $request->user()->id,
            'type' => match ($data['type']) { 'ticket' => 'transport', 'accommodation' => 'stay', default => 'reservation' },
            'title' => $data['title'], 'description' => $description ?: null,
            'starts_at' => ! empty($data['starts_at']) ? Carbon::parse($data['starts_at'])->format('H:i:s') : null,
            'ends_at' => ! empty($data['ends_at']) ? Carbon::parse($data['ends_at'])->format('H:i:s') : null,
            'place_name' => $data['place_name'] ?: ($data['destination'] ?? null), 'status' => 'planned',
            'cost' => $data['amount'] ?? null, 'currency' => $data['currency'],
            'metadata' => json_encode(['source' => 'reservation_import', 'reservation_import_uuid' => $import->uuid], JSON_UNESCAPED_UNICODE),
            'updated_at' => now()];
        if ($import->trip_activity_id && DB::table('trip_activities')->where('id', $import->trip_activity_id)->exists()) {
            DB::table('trip_activities')->where('id', $import->trip_activity_id)->update($values);
            return (int) $import->trip_activity_id;
        }
        $values['sort_order'] = ((int) DB::table('trip_activities')->where('trip_day_id', $dayId)->max('sort_order')) + 1;
        return DB::table('trip_activities')->insertGetId($values + ['created_at' => now()]);
    }

    private function syncInbox(Request $request, object $trip, object $import, array $data, ?int $dayId, ?int $activityId): int
    {
        $metadata = ['source' => 'reservation_import', 'reservation_import_uuid' => $import->uuid, 'provider' => $data['provider'], 'reference' => $data['reference']];
        $values = ['gallery_space_id' => $trip->gallery_space_id, 'added_by' => $request->user()->id,
            'trip_id' => $trip->id, 'trip_day_id' => $dayId, 'trip_activity_id' => $activityId,
            'title' => $data['title'], 'notes' => $data['notes'] ?: null,
            'source_url' => $import->storage_path ? "/api/v1/trips/{$trip->id}/reservation-imports/{$import->uuid}/download" : null,
            'kind' => 'reservation', 'state' => 'assigned', 'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE), 'updated_at' => now()];
        if ($import->travel_inbox_item_id && DB::table('travel_inbox_items')->where('id', $import->travel_inbox_item_id)->exists()) {
            DB::table('travel_inbox_items')->where('id', $import->travel_inbox_item_id)->update($values);
            return (int) $import->travel_inbox_item_id;
        }
        return DB::table('travel_inbox_items')->insertGetId($values + ['uuid' => (string) Str::uuid(), 'created_at' => now()]);
    }

    private function syncCalendar(Request $request, object $trip, object $import, array $data, array $reminderHours): int
    {
        $startsAt = Carbon::parse($data['starts_at'], $trip->timezone ?? config('app.timezone'));
        $values = ['gallery_space_id' => $trip->gallery_space_id, 'created_by' => $request->user()->id, 'trip_id' => $trip->id,
            'title' => $data['title'], 'description' => $data['notes'] ?: null, 'type' => 'reservation', 'status' => 'planned',
            'starts_at' => $startsAt, 'ends_at' => ! empty($data['ends_at']) ? Carbon::parse($data['ends_at']) : null,
            'all_day' => false, 'timezone' => $trip->timezone ?? config('app.timezone'), 'place_name' => $data['place_name'] ?: ($data['destination'] ?? null),
            'metadata' => json_encode(['source' => 'reservation_import', 'reservation_import_uuid' => $import->uuid, 'provider' => $data['provider']], JSON_UNESCAPED_UNICODE),
            'updated_at' => now()];
        if ($import->calendar_event_id && DB::table('calendar_events')->where('id', $import->calendar_event_id)->exists()) {
            DB::table('calendar_events')->where('id', $import->calendar_event_id)->update($values);
            $eventId = (int) $import->calendar_event_id;
        } else {
            $eventId = DB::table('calendar_events')->insertGetId($values + ['uuid' => (string) Str::uuid(), 'created_at' => now()]);
        }
        $memberIds = DB::table('gallery_space_user')->where('gallery_space_id', $trip->gallery_space_id)->pluck('user_id');
        foreach ($memberIds as $memberId) {
            DB::table('event_participants')->insertOrIgnore(['event_id' => $eventId, 'user_id' => $memberId, 'role' => (int) $memberId === (int) $request->user()->id ? 'organizer' : 'guest', 'response' => (int) $memberId === (int) $request->user()->id ? 'accepted' : 'pending', 'created_at' => now(), 'updated_at' => now()]);
            foreach ($reminderHours as $hours) {
                $remindAt = $startsAt->copy()->subHours((int) $hours);
                if ($remindAt->isPast()) continue;
                DB::table('event_reminders')->updateOrInsert(['event_id' => $eventId, 'user_id' => $memberId, 'automation_key' => "reservation-{$import->uuid}-{$hours}h"], ['channel' => 'database', 'remind_at' => $remindAt, 'status' => 'pending', 'automation_source' => 'reservation_import', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table('event_attachments')->updateOrInsert(['event_id' => $eventId, 'label' => $data['title']], ['external_url' => $import->storage_path ? "/api/v1/trips/{$trip->id}/reservation-imports/{$import->uuid}/download" : null, 'reference_code' => $data['reference'] ?: null, 'kind' => $data['type'] === 'ticket' ? 'ticket' : 'reservation', 'created_at' => now(), 'updated_at' => now()]);
        return $eventId;
    }

    /** @return array<string,mixed> */
    private function payload(object $row): array
    {
        $payload = (array) $row;
        $payload['extracted_data'] = json_decode($row->extracted_data ?: '{}', true) ?: [];
        $payload['confirmed_data'] = json_decode($row->confirmed_data ?: '{}', true) ?: null;
        $sourceText = null;
        if ($row->source_text) {
            try { $sourceText = Crypt::decryptString($row->source_text); }
            catch (\Throwable) { $sourceText = $row->source_text; } // Compatibility with an early local development row.
        }
        $payload['source_excerpt'] = $sourceText ? Str::limit(Str::squish($sourceText), 500) : null;
        $payload['has_file'] = (bool) $row->storage_path;
        unset($payload['source_text'], $payload['storage_path'], $payload['sha256']);
        return $payload;
    }

    private function available(): void
    {
        abort_unless(Schema::hasTable('trip_reservation_imports'), 503, 'Pro import cestovních rezervací dokončete migrace aplikace.');
    }

    private function trip(Request $request, int $id): object
    {
        return DB::table('trips')->where('id', $id)->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->firstOrFail();
    }
}
