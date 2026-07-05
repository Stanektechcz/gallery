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
}
