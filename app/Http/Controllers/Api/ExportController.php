<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateExportJob;
use App\Models\Album;
use App\Models\MediaItem;
use App\Support\SpaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ExportController extends Controller
{
    use UrcujePar;

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
            'media_ids.*' => 'integer',
        ]);

        $prostor = $this->parId($request);
        $this->jenZProstoru($data, $prostor);

        // Dispatch export job
        $jobId = (string) Str::uuid();
        Cache::put("export_owner_{$jobId}", $request->user()->id, now()->addHour());
        // `heavy`, ne `default`: vývoz může běžet hodinu (`GenerateExportJob::$timeout`)
        // a na frontě, kterou vyprazdňuje hlavní `queue-drain` s krátkým stropem,
        // by táhl zámek proti souběhu s sebou — viz `heavy-drain` v `routes/console.php`.
        GenerateExportJob::dispatch($request->user()->id, $data, $jobId, $prostor)->onQueue('heavy');

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

    /**
     * Cizí identifikátor se odmítne hned, ne až ve frontě.
     *
     * Úloha filtruje podle prostoru sama (`GenerateExportJob::vybraneFotky`),
     * takže cizí fotka by se do ZIPu nedostala. Ale kdo pošle cizí čísla,
     * dostal by prázdný vývoz a žádné vysvětlení — a hlavně by se z rozdílu
     * mezi „prázdný" a „něco v tom je" dalo číst, co v cizí galerii existuje.
     *
     * @param  array<string, mixed>  $data
     */
    private function jenZProstoru(array $data, int $prostor): void
    {
        if (($data['type'] ?? null) === 'album' && ($data['target_id'] ?? null) !== null) {
            abort_unless(Album::withoutGlobalScope(SpaceContext::SCOPE)
                ->whereKey($data['target_id'])
                ->where('gallery_space_id', $prostor)
                ->exists(), 403, 'Tohle album do vaší galerie nepatří.');
        }

        $fotky = array_values(array_filter((array) ($data['media_ids'] ?? [])));

        if ($fotky === []) {
            return;
        }

        $vlastnich = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->whereIn('id', $fotky)
            ->where('gallery_space_id', $prostor)
            ->count();

        abort_unless($vlastnich === count(array_unique($fotky)), 403,
            'Ve výběru jsou fotky, které do vaší galerie nepatří.');
    }
}
