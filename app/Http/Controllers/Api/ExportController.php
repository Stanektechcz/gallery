<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'           => 'required|in:album,selection',
            'target_id'      => 'nullable|integer',
            'include_originals' => 'boolean',
            'include_edited'    => 'boolean',
            'include_xmp'       => 'boolean',
            'preserve_structure' => 'boolean',
            'media_ids'         => 'nullable|array',
        ]);

        // Dispatch export job
        $jobId = (string) \Illuminate\Support\Str::uuid();
        \App\Jobs\GenerateExportJob::dispatch($request->user(), $data, $jobId)->onQueue('default');

        return response()->json(['job_id' => $jobId, 'status' => 'queued'], 202);
    }

    public function status(string $id): JsonResponse
    {
        $status = \Illuminate\Support\Facades\Cache::get("export_status_{$id}", 'unknown');
        return response()->json(['job_id' => $id, 'status' => $status]);
    }

    public function download(string $id): mixed
    {
        $path = storage_path("app/exports/{$id}.zip");
        if (!file_exists($path)) abort(404);
        return response()->download($path, "gallery-export-{$id}.zip");
    }
}
