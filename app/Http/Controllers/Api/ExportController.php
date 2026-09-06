<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateExportJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ExportController extends Controller
{
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:album,selection',
            'target_id' => 'nullable|integer',
            'include_originals' => 'boolean',
            'include_edited' => 'boolean',
            'include_xmp' => 'boolean',
            'preserve_structure' => 'boolean',
            'media_ids' => 'nullable|array',
        ]);

        // Dispatch export job
        $jobId = (string) Str::uuid();
        Cache::put("export_owner_{$jobId}", $request->user()->id, now()->addHour());
        GenerateExportJob::dispatch($request->user()->id, $data, $jobId)->onQueue('default');

        return response()->json(['job_id' => $jobId, 'status' => 'queued'], 202);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $this->ensureOwner($request, $id);
        $status = Cache::get("export_status_{$id}", 'unknown');

        return response()->json(['job_id' => $id, 'status' => $status]);
    }

    public function download(Request $request, string $id): mixed
    {
        $this->ensureOwner($request, $id);
        $path = storage_path("app/exports/{$id}.zip");
        if (! file_exists($path)) {
            abort(404);
        }

        return response()->download($path, "gallery-export-{$id}.zip");
    }

    private function ensureOwner(Request $request, string $id): void
    {
        abort_unless(hash_equals((string) $request->user()->id, (string) Cache::get("export_owner_{$id}", '')), 404);
    }
}
