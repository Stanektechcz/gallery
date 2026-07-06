<?php

namespace App\Http\Controllers;

use App\Models\MediaItem;
use App\Models\StorageConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RecoveryController extends Controller
{
    public function index(Request $request): Response
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        // Database
        $dbOk = true;
        try {
            DB::select('SELECT 1');
        } catch (\Throwable) {
            $dbOk = false;
        }

        // Drive connection
        $driveConn = $space
            ? StorageConnection::whereHas('owner', fn($q) => $q->whereHas('gallerySpaces', fn($q2) => $q2->where('gallery_spaces.id', $space->id)))
            ->where('provider', 'google_drive')->first()
            : null;

        // Media stats
        $totalMedia  = $space ? MediaItem::where('gallery_space_id', $space->id)->whereNull('trashed_at')->count() : 0;
        $missingLocal = $space ? MediaItem::where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->whereDoesntHave('variants', fn($q) => $q->where('type', 'original'))
            ->count() : 0;

        $withDrive = $space ? MediaItem::where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->whereNotNull('drive_file_id')
            ->count() : 0;

        $noThumb = $space ? MediaItem::where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->whereDoesntHave('variants', fn($q) => $q->where('type', 'thumbnail'))
            ->count() : 0;

        // Binary tools
        $exiftoolPath = config('gallery.exiftool_path', '/usr/bin/exiftool');
        $ffmpegPath   = config('gallery.ffmpeg_path', '/usr/bin/ffmpeg');

        $exiftoolOk = function_exists('proc_open') && @file_exists($exiftoolPath);
        $ffmpegOk   = function_exists('proc_open') && @file_exists($ffmpegPath);
        $imagickOk  = extension_loaded('imagick');
        $gdOk       = extension_loaded('gd');

        return Inertia::render('Recovery/Index', [
            'checks' => [
                ['label' => 'MySQL / Database',    'ok' => $dbOk,              'detail' => $dbOk ? 'Připojeno' : 'Chyba připojení'],
                ['label' => 'Google Drive API',    'ok' => $driveConn && $driveConn->connection_status === 'healthy', 'detail' => $driveConn ? ($driveConn->connection_status === 'healthy' ? "Připojeno ({$driveConn->account_email})" : "Stav: {$driveConn->connection_status}") : 'Nepřipojeno'],
                ['label' => 'ExifTool',            'ok' => $exiftoolOk,        'detail' => $exiftoolPath],
                ['label' => 'FFmpeg',              'ok' => $ffmpegOk,          'detail' => $ffmpegPath],
                ['label' => 'Imagick extension',   'ok' => $imagickOk,         'detail' => $imagickOk ? 'Načteno' : 'Chybí'],
                ['label' => 'GD extension',        'ok' => $gdOk,              'detail' => $gdOk ? 'Načteno' : 'Chybí'],
            ],
            'media_stats' => [
                'total'         => $totalMedia,
                'with_drive'    => $withDrive,
                'missing_local' => $missingLocal,
                'no_thumb'      => $noThumb,
            ],
            'drive_info' => $driveConn ? [
                'email'       => $driveConn->account_email,
                'status'      => $driveConn->connection_status,
                'quota_total' => $driveConn->quota_total,
                'quota_used'  => $driveConn->quota_used,
                'last_ok'     => $driveConn->last_successful_request_at?->toIso8601String(),
            ] : null,
        ]);
    }

    /**
     * GET /api/v1/recovery/duplicates
     * Find exact duplicates (same sha256) in the gallery space.
     */
    public function findDuplicates(Request $request): \Illuminate\Http\JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();

        // Find sha256 hashes that appear more than once
        $dupHashes = DB::table('media_items')
            ->where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->whereNotNull('sha256')
            ->select('sha256', DB::raw('COUNT(*) as cnt'))
            ->groupBy('sha256')
            ->having('cnt', '>', 1)
            ->orderByDesc('cnt')
            ->limit(50)
            ->pluck('cnt', 'sha256');

        $groups = [];
        foreach ($dupHashes as $hash => $count) {
            $items = MediaItem::with('variants')
                ->where('gallery_space_id', $space->id)
                ->whereNull('trashed_at')
                ->where('sha256', $hash)
                ->orderBy('taken_at')
                ->get();

            $groups[] = [
                'sha256' => $hash,
                'count'  => $count,
                'items'  => $items->map(fn($m) => [
                    'id'            => $m->id,
                    'uuid'          => $m->uuid,
                    'filename'      => $m->original_filename,
                    'taken_at'      => $m->taken_at,
                    'size_bytes'    => $m->size_bytes,
                    'thumbnail_url' => $m->thumbnail_url,
                    'in_albums'     => $m->albums()->count() + ($m->primary_album_id ? 1 : 0),
                    'is_favorite'   => $m->is_favorite,
                ])->toArray(),
            ];
        }

        return response()->json([
            'group_count'  => count($groups),
            'total_extras' => array_sum(array_map(fn($g) => $g['count'] - 1, $groups)),
            'groups'       => $groups,
        ]);
    }

    /**
     * DELETE /api/v1/recovery/duplicates/trash
     * Move duplicate items (all but oldest per group) to trash.
     */
    public function trashDuplicates(Request $request): \Illuminate\Http\JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();
        $v = $request->validate([
            'media_ids'   => 'required|array|max:500',
            'media_ids.*' => 'integer',
        ]);

        $trashed = MediaItem::where('gallery_space_id', $space->id)
            ->whereIn('id', $v['media_ids'])
            ->whereNull('trashed_at')
            ->get();

        $count = 0;
        foreach ($trashed as $m) {
            $m->update(['trashed_at' => now(), 'purge_after' => now()->addDays(30)]);
            $count++;
        }

        return response()->json(['trashed' => $count]);
    }
}
