<?php

namespace App\Http\Controllers;

use App\Models\MediaItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class ExportController extends Controller
{
    public function download(Request $request): mixed
    {
        $validated = $request->validate([
            'uuids'   => 'required|array|max:200',
            'uuids.*' => 'string',
        ]);

        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $items = MediaItem::where('gallery_space_id', $space->id)
            ->whereIn('uuid', $validated['uuids'])
            ->whereNull('trashed_at')
            ->with(['variants' => fn($q) => $q->where('type', 'original')])
            ->get();

        if ($items->isEmpty()) {
            abort(404, 'Žádná média k exportu');
        }

        // Create temp zip
        $zipPath = tempnam(sys_get_temp_dir(), 'gallery_export_') . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);

        foreach ($items as $item) {
            $original = $item->variants->first();
            if (!$original) continue;

            $localPath = Storage::disk($original->disk)->path($original->path);
            if (file_exists($localPath)) {
                $filename = $item->original_filename ?: "{$item->uuid}.{$item->extension}";
                $zip->addFile($localPath, $filename);
            }
        }

        $zip->close();

        return response()->download($zipPath, 'gallery-export-' . now()->format('Y-m-d') . '.zip')
            ->deleteFileAfterSend(true);
    }
}
