<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Travel\TravelJournalStoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class TripJournalRecordingController extends Controller
{
    public function store(Request $request, int $id, TravelJournalStoryService $stories): JsonResponse
    {
        $trip = $this->trip($request, $id);
        $this->available();
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze ukládat hlasové vzpomínky.');
        $data = $request->validate([
            'recording' => 'required|file|max:25600',
            'duration_ms' => 'required|integer|min:250|max:600000',
            'content' => 'nullable|string|max:5000', 'visibility' => 'nullable|in:shared,private',
            'mood' => 'nullable|in:joyful,calm,adventurous,cozy,tired,grateful,funny',
            'is_story_worthy' => 'nullable|boolean', 'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);
        $file = $request->file('recording');
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension()));
        abort_unless(in_array($extension, ['webm', 'ogg', 'mp3', 'm4a', 'mp4', 'wav'], true), 422, 'Nepodporovaný formát hlasové nahrávky.');
        $detectedMime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType()));
        $clientMime = strtolower((string) $file->getClientMimeType());
        $allowedMime = fn (string $mime) => Str::startsWith($mime, 'audio/') || in_array($mime, ['video/webm', 'video/mp4', 'application/octet-stream'], true);
        abort_unless($allowedMime($detectedMime) && $allowedMime($clientMime), 422, 'Soubor není platná hlasová nahrávka.');

        $uuid = (string) Str::uuid();
        $path = $file->storeAs("travel-journal/{$id}", "{$uuid}.{$extension}", 'local');
        try {
            $entryId = DB::transaction(function () use ($request, $trip, $id, $data, $file, $uuid, $path, $detectedMime) {
                $visibility = $data['visibility'] ?? 'shared';
                $entryId = DB::table('travel_journal_entries')->insertGetId([
                    'trip_id' => $id,
                    'trip_day_id' => DB::table('trip_days')->where('trip_id', $id)->where('date', now()->toDateString())->value('id'),
                    'user_id' => $request->user()->id, 'type' => 'voice', 'content' => trim((string) ($data['content'] ?? '')) ?: 'Hlasová vzpomínka',
                    'visibility' => $visibility, 'mood' => $data['mood'] ?? null,
                    'is_story_worthy' => $visibility === 'shared' && ($data['is_story_worthy'] ?? true),
                    'latitude' => $data['latitude'] ?? null, 'longitude' => $data['longitude'] ?? null,
                    'metadata' => json_encode(['has_recording' => true, 'capture' => 'browser_media_recorder'], JSON_UNESCAPED_UNICODE),
                    'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('travel_journal_recordings')->insert([
                    'uuid' => $uuid, 'journal_entry_id' => $entryId, 'uploaded_by' => $request->user()->id,
                    'disk' => 'local', 'path' => $path, 'mime_type' => $detectedMime,
                    'size_bytes' => $file->getSize(), 'duration_ms' => $data['duration_ms'],
                    'sha256' => hash_file('sha256', Storage::disk('local')->path($path)), 'created_at' => now(), 'updated_at' => now(),
                ]);
                return $entryId;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        $stories->syncEntry($id, $entryId);
        $entry = DB::table('travel_journal_entries')->find($entryId);
        return response()->json((array) $entry + ['is_mine' => true, 'user_name' => $request->user()->name,
            'recording_url' => "/api/v1/trips/{$id}/journal/{$entryId}/recording", 'recording_duration_ms' => $data['duration_ms']], 201);
    }

    public function show(Request $request, int $id, int $entryId): BinaryFileResponse
    {
        $this->trip($request, $id);
        $this->available();
        $recording = DB::table('travel_journal_entries as entry')
            ->join('travel_journal_recordings as recording', 'recording.journal_entry_id', '=', 'entry.id')
            ->where('entry.id', $entryId)->where('entry.trip_id', $id)
            ->where(fn ($visible) => $visible->where('entry.visibility', 'shared')->orWhere('entry.user_id', $request->user()->id))
            ->first(['recording.*']);
        abort_unless($recording && Storage::disk($recording->disk)->exists($recording->path), 404);
        return response()->file(Storage::disk($recording->disk)->path($recording->path), [
            'Content-Type' => $recording->mime_type,
            'Content-Disposition' => 'inline; filename="hlasova-vzpominka.'.pathinfo($recording->path, PATHINFO_EXTENSION).'"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function available(): void
    {
        abort_unless(Schema::hasTable('travel_journal_recordings'), 503, 'Pro skutečné hlasové vzpomínky dokončete migrace aplikace.');
    }

    private function trip(Request $request, int $id): object
    {
        return DB::table('trips')->where('id', $id)->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->firstOrFail();
    }
}
