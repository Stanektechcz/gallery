<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class PhotoBookController extends Controller
{
    // ─── CRUD ──────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();

        $books = DB::table('photo_books')
            ->where('gallery_space_id', $space->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($books->map(fn($b) => $this->enrichBook($b)));
    }

    public function store(Request $request): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();

        $v = $request->validate([
            'name'         => 'required|string|max:255',
            'description'  => 'nullable|string|max:2000',
            'purpose'      => 'nullable|in:photobook,print,web,gift,other',
            'target_count' => 'nullable|integer|min:1|max:500',
        ]);

        $id = DB::table('photo_books')->insertGetId([
            'uuid'             => (string) Str::uuid(),
            'gallery_space_id' => $space->id,
            'created_by'       => $request->user()->id,
            'name'             => $v['name'],
            'description'      => $v['description'] ?? null,
            'purpose'          => $v['purpose'] ?? 'photobook',
            'target_count'     => $v['target_count'] ?? null,
            'item_count'       => 0,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return response()->json($this->enrichBook(DB::table('photo_books')->find($id)), 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $book  = $this->resolve($uuid, $request);
        $items = $this->getItems($book->id);
        return response()->json([...(array) $this->enrichBook($book), 'items' => $items]);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $book = $this->resolve($uuid, $request);

        $v = $request->validate([
            'name'         => 'nullable|string|max:255',
            'description'  => 'nullable|string|max:2000',
            'purpose'      => 'nullable|in:photobook,print,web,gift,other',
            'target_count' => 'nullable|integer|min:1|max:500',
        ]);

        DB::table('photo_books')
            ->where('id', $book->id)
            ->update(array_merge(array_filter($v, fn($v) => $v !== null), ['updated_at' => now()]));

        return response()->json($this->enrichBook(DB::table('photo_books')->find($book->id)));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $book = $this->resolve($uuid, $request);
        DB::table('photo_books')->where('id', $book->id)->delete();
        return response()->json(['status' => 'deleted']);
    }

    // ─── Items ──────────────────────────────────────────────────────────────

    public function addItems(Request $request, string $uuid): JsonResponse
    {
        $book  = $this->resolve($uuid, $request);
        $space = $request->user()->gallerySpaces()->first();

        $v = $request->validate([
            'media_uuids'   => 'required|array|max:500',
            'media_uuids.*' => 'string',
        ]);

        $mediaItems = MediaItem::where('gallery_space_id', $space->id)
            ->whereIn('uuid', $v['media_uuids'])
            ->get(['id', 'uuid']);

        $maxOrder = DB::table('photo_book_items')->where('photo_book_id', $book->id)->max('sort_order') ?? -1;
        $now      = now();
        $added    = 0;

        foreach ($mediaItems as $m) {
            $inserted = DB::table('photo_book_items')->insertOrIgnore([
                'photo_book_id'  => $book->id,
                'media_item_id'  => $m->id,
                'sort_order'     => ++$maxOrder,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            if ($inserted) $added++;
        }

        DB::table('photo_books')->where('id', $book->id)->update([
            'item_count' => DB::table('photo_book_items')->where('photo_book_id', $book->id)->count(),
            'updated_at' => $now,
        ]);

        return response()->json(['added' => $added, 'items' => $this->getItems($book->id)]);
    }

    public function removeItem(Request $request, string $uuid, int $itemId): JsonResponse
    {
        $book = $this->resolve($uuid, $request);
        DB::table('photo_book_items')->where('id', $itemId)->where('photo_book_id', $book->id)->delete();
        DB::table('photo_books')->where('id', $book->id)->update([
            'item_count' => DB::table('photo_book_items')->where('photo_book_id', $book->id)->count(),
            'updated_at' => now(),
        ]);
        return response()->json(['status' => 'removed']);
    }

    public function reorder(Request $request, string $uuid): JsonResponse
    {
        $book = $this->resolve($uuid, $request);

        $v = $request->validate([
            'order'   => 'required|array',
            'order.*' => 'integer',
        ]);

        foreach ($v['order'] as $i => $itemId) {
            DB::table('photo_book_items')
                ->where('id', $itemId)
                ->where('photo_book_id', $book->id)
                ->update(['sort_order' => $i, 'updated_at' => now()]);
        }

        return response()->json(['reordered' => count($v['order'])]);
    }

    // ─── Exports ────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/books/{uuid}/export/zip
     * Download a ZIP archive of original files.
     */
    public function exportZip(Request $request, string $uuid): mixed
    {
        $book  = $this->resolve($uuid, $request);
        $space = $request->user()->gallerySpaces()->first();

        $rows = DB::table('photo_book_items')
            ->join('media_items', 'media_items.id', '=', 'photo_book_items.media_item_id')
            ->where('photo_book_items.photo_book_id', $book->id)
            ->where('media_items.gallery_space_id', $space->id)
            ->orderBy('photo_book_items.sort_order')
            ->get(['media_items.id', 'media_items.original_filename']);

        $tmpFile = tempnam(sys_get_temp_dir(), 'pb_') . '.zip';
        $zip     = new ZipArchive();
        $zip->open($tmpFile, ZipArchive::CREATE);

        $index = 1;
        foreach ($rows as $row) {
            $media   = MediaItem::with('variants')->find($row->id);
            $variant = $media?->getVariant('original')
                ?? $media?->getVariant('large')
                ?? $media?->getVariant('medium');

            if (! $variant) continue;

            $path = Storage::disk($variant->disk)->path($variant->path);
            if (file_exists($path)) {
                $ext      = pathinfo($row->original_filename, PATHINFO_EXTENSION);
                $safeName = sprintf('%03d_%s', $index, $row->original_filename);
                $zip->addFile($path, $safeName);
                $index++;
            }
        }

        $zip->close();

        $safeName = Str::slug($book->name) . '.zip';

        return response()->download($tmpFile, $safeName)->deleteFileAfterSend(true);
    }

    /**
     * GET /api/v1/books/{uuid}/export/filelist
     * Download a plain-text file list.
     */
    public function exportFileList(Request $request, string $uuid): mixed
    {
        $book  = $this->resolve($uuid, $request);
        $space = $request->user()->gallerySpaces()->first();

        $rows = DB::table('photo_book_items')
            ->join('media_items', 'media_items.id', '=', 'photo_book_items.media_item_id')
            ->where('photo_book_items.photo_book_id', $book->id)
            ->where('media_items.gallery_space_id', $space->id)
            ->orderBy('photo_book_items.sort_order')
            ->get([
                'media_items.uuid',
                'media_items.original_filename',
                'media_items.taken_at',
                'media_items.width',
                'media_items.height',
                'media_items.size_bytes',
                'media_items.camera_make',
                'media_items.camera_model'
            ]);

        $lines   = ["# Fotokniha: {$book->name}", "# Datum exportu: " . now()->toDateTimeString(), "# Počet: {$rows->count()}", ""];
        $lines[] = "Pořadí\tUUID\tSoubor\tDatum\tRozměry\tVelikost\tFotoaparát";

        foreach ($rows as $i => $row) {
            $size = $row->size_bytes ? round($row->size_bytes / 1024 / 1024, 1) . ' MB' : '';
            $dim  = ($row->width && $row->height) ? "{$row->width}×{$row->height}" : '';
            $cam  = trim("{$row->camera_make} {$row->camera_model}");
            $date = $row->taken_at ? date('Y-m-d H:i', strtotime($row->taken_at)) : '';
            $lines[] = implode("\t", [$i + 1, $row->uuid, $row->original_filename, $date, $dim, $size, $cam]);
        }

        $safeName = Str::slug($book->name) . '-filelist.txt';

        return response(implode("\n", $lines), 200, [
            'Content-Type'        => 'text/plain; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$safeName}\"",
        ]);
    }

    /**
     * GET /books/{uuid}/contact-sheet
     * Render a printable contact sheet (handled by web controller → Inertia).
     * Data prepared here for use by the web controller.
     */
    public function contactSheetData(Request $request, string $uuid): JsonResponse
    {
        $book  = $this->resolve($uuid, $request);
        $space = $request->user()->gallerySpaces()->first();

        $items = $this->getItems($book->id);

        return response()->json([
            'book'  => $this->enrichBook($book),
            'items' => $items,
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function resolve(string $uuid, Request $request): object
    {
        $space = $request->user()->gallerySpaces()->first();
        $book  = DB::table('photo_books')
            ->where('uuid', $uuid)
            ->where('gallery_space_id', $space->id)
            ->first();
        abort_if(! $book, 404);
        return $book;
    }

    private function enrichBook(object $book): array
    {
        $data = (array) $book;

        // Cover thumbnail
        $cover = null;
        if ($book->cover_media_id) {
            $m = MediaItem::with('variants')->find($book->cover_media_id);
            $cover = $m?->thumbnail_url;
        }
        if (! $cover) {
            $firstId = DB::table('photo_book_items')
                ->where('photo_book_id', $book->id)
                ->orderBy('sort_order')
                ->value('media_item_id');
            if ($firstId) {
                $m     = MediaItem::with('variants')->find($firstId);
                $cover = $m?->thumbnail_url;
            }
        }
        $data['cover_thumb'] = $cover;

        return $data;
    }

    private function getItems(int $bookId): array
    {
        $rows = DB::table('photo_book_items')
            ->join('media_items', 'media_items.id', '=', 'photo_book_items.media_item_id')
            ->where('photo_book_items.photo_book_id', $bookId)
            ->orderBy('photo_book_items.sort_order')
            ->get([
                'photo_book_items.id',
                'photo_book_items.sort_order',
                'photo_book_items.notes',
                'media_items.uuid',
                'media_items.original_filename',
                'media_items.taken_at',
                'media_items.width',
                'media_items.height',
                'media_items.size_bytes',
                'media_items.media_type',
            ]);

        return $rows->map(function ($r) {
            $m = MediaItem::with('variants')->where('uuid', $r->uuid)->first();
            $shortSide = min((int) ($r->width ?? 0), (int) ($r->height ?? 0));
            $quality = $r->media_type === 'video' ? 'unsupported' : ($shortSide >= 3000 ? 'excellent' : ($shortSide >= 1800 ? 'good' : 'low'));
            return [
                'id'         => $r->id,
                'sort_order' => $r->sort_order,
                'notes'      => $r->notes,
                'uuid'       => $r->uuid,
                'filename'   => $r->original_filename,
                'taken_at'   => $r->taken_at,
                'width'      => $r->width,
                'height'     => $r->height,
                'size_bytes' => $r->size_bytes,
                'media_type' => $r->media_type,
                'print_quality' => $quality,
                'recommended_max_cm' => $shortSide > 0 ? round(($shortSide / 300) * 2.54, 1) : null,
                'thumb_url'  => $m?->thumbnail_url,
                'full_url'   => "/media/{$r->uuid}/full",
            ];
        })->toArray();
    }
}
