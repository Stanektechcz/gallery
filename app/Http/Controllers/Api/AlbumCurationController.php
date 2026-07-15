<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Media\EnqueueAlbumDriveSyncJob;
use App\Jobs\Media\RepairAlbumMediaPreviewsJob;
use App\Models\Album;
use App\Models\AuditLog;
use App\Services\Media\AlbumCurationAssistantService;
use App\Services\Storage\DriveConnectionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AlbumCurationController extends Controller
{
    public function __construct(
        private readonly AlbumCurationAssistantService $assistant,
        private readonly DriveConnectionResolver $driveConnections,
    ) {}

    public function show(Request $request, string $uuid): JsonResponse
    {
        $album = $this->album($uuid);
        Gate::authorize('view', $album);

        return response()->json($this->assistant->snapshot($album, $request->user()->id));
    }

    public function setCover(Request $request, string $uuid): JsonResponse
    {
        $album = $this->album($uuid);
        Gate::authorize('update', $album);
        $data = $request->validate(['media_uuid' => 'required|uuid']);
        $media = $this->assistant->mediaQuery($album)->where('uuid', $data['media_uuid'])->firstOrFail();

        DB::transaction(function () use ($album, $media, $request): void {
            $album->update(['cover_media_id' => $media->id, 'updated_by' => $request->user()->id]);
            DB::table('album_media')->where('album_id', $album->id)->update(['is_cover' => false]);

            if (($album->album_type ?? 'physical') !== 'smart') {
                $existing = DB::table('album_media')->where('album_id', $album->id)->where('media_item_id', $media->id)->exists();
                if (! $existing) {
                    DB::table('album_media')->insert([
                        'album_id' => $album->id,
                        'media_item_id' => $media->id,
                        'sort_order' => ((int) DB::table('album_media')->where('album_id', $album->id)->max('sort_order')) + 1,
                        'is_cover' => true,
                        'added_at' => now(),
                        'added_by' => $request->user()->id,
                    ]);
                } else {
                    DB::table('album_media')->where('album_id', $album->id)->where('media_item_id', $media->id)->update(['is_cover' => true]);
                }
            }
        });

        AuditLog::record('album.cover.select', $album, ['media_item_id' => $media->id, 'source' => 'curation_assistant']);

        return response()->json([
            'cover' => [
                'media_id' => $media->id,
                'media_uuid' => $media->uuid,
                'thumbnail_url' => $media->load('variants')->thumbnail_url,
            ],
        ]);
    }

    public function createShortlist(Request $request, string $uuid): JsonResponse
    {
        $album = $this->album($uuid);
        Gate::authorize('update', $album);
        abort_unless(Schema::hasTable('curation_boards') && Schema::hasColumn('curation_boards', 'album_id'), 503, 'Pro partnerský výběr dokončete migrace aplikace.');

        $shortlist = collect($this->assistant->snapshot($album, $request->user()->id)['shortlist']);
        abort_if($shortlist->isEmpty(), 422, 'Album zatím nemá dostupné záběry pro společný výběr.');

        DB::transaction(function () use ($album, $request, $shortlist): void {
            DB::table('albums')->where('id', $album->id)->lockForUpdate()->first();
            $board = DB::table('curation_boards')->where('album_id', $album->id)->where('purpose', 'album_selection')->first();
            if (! $board) {
                $boardId = DB::table('curation_boards')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'gallery_space_id' => $album->gallery_space_id,
                    'album_id' => $album->id,
                    'purpose' => 'album_selection',
                    'created_by' => $request->user()->id,
                    'title' => 'Společný výběr · ' . $album->title,
                    'description' => 'Doporučené záběry z alba. Oba partneři mohou hlasovat; ruční poznámky a rozhodnutí zůstávají zachované.',
                    'visibility' => 'shared',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $board = DB::table('curation_boards')->find($boardId);
            }

            foreach ($shortlist as $sortOrder => $candidate) {
                $existing = DB::table('curation_board_items')->where('curation_board_id', $board->id)->where('media_item_id', $candidate['id'])->first();
                if ($existing) {
                    DB::table('curation_board_items')->where('id', $existing->id)->update(['sort_order' => $sortOrder, 'updated_at' => now()]);
                    continue;
                }
                DB::table('curation_board_items')->insert([
                    'curation_board_id' => $board->id,
                    'media_item_id' => $candidate['id'],
                    'added_by' => $request->user()->id,
                    'status' => 'shortlisted',
                    'note' => $candidate['reasons'] ? 'Doporučeno: ' . implode(', ', array_slice($candidate['reasons'], 0, 3)) : null,
                    'sort_order' => $sortOrder,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('curation_boards')->where('id', $board->id)->update(['updated_at' => now()]);
        });

        AuditLog::record('album.curation_shortlist.prepare', $album, ['items_count' => $shortlist->count()]);
        return response()->json(['board' => $this->assistant->boardPayload($album, $request->user()->id)], 201);
    }

    public function syncBackup(Request $request, string $uuid): JsonResponse
    {
        $album = $this->album($uuid);
        Gate::authorize('update', $album);
        abort_unless($this->driveConnections->forSpace($album->gallery_space_id) !== null, 422, 'Nejprve připojte funkční Google Drive v nastavení úložiště.');
        $count = $this->assistant->mediaQuery($album)->whereNull('drive_file_id')->count();
        if ($count > 0) {
            EnqueueAlbumDriveSyncJob::dispatch($album->id)->onQueue('drive');
        }
        AuditLog::record('album.backup.enqueue', $album, ['items_count' => $count]);

        return response()->json(['queued' => $count, 'message' => $count ? 'Zálohování alba bylo zařazeno do fronty.' : 'Všechny originály už mají cloudovou kopii.'], $count ? 202 : 200);
    }

    public function repairPreviews(Request $request, string $uuid): JsonResponse
    {
        $album = $this->album($uuid);
        Gate::authorize('update', $album);
        $count = $this->assistant->mediaQuery($album)
            ->whereDoesntHave('variants', fn ($variants) => $variants->whereIn('type', ['thumbnail', 'small', 'video_poster']))
            ->count();
        if ($count > 0) {
            RepairAlbumMediaPreviewsJob::dispatch($album->id)->onQueue('media');
        }
        AuditLog::record('album.previews.repair', $album, ['items_count' => $count]);

        return response()->json(['queued' => $count, 'message' => $count ? 'Oprava náhledů byla zařazena do fronty.' : 'Všechna média už mají náhled.'], $count ? 202 : 200);
    }

    private function album(string $uuid): Album
    {
        return Album::where('uuid', $uuid)->whereNull('deleted_at')->firstOrFail();
    }
}
