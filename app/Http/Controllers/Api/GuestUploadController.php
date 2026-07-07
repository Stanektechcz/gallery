<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GuestUpload;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GuestUploadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $uploads = GuestUpload::whereHas('sharedLink', fn ($query) => $query->where('created_by', $request->user()->id))->with('sharedLink:id,name,token,target_type,target_id')->latest()->paginate(50);
        return response()->json($uploads);
    }

    public function approve(Request $request, string $uuid): JsonResponse
    {
        $upload = $this->upload($request, $uuid);
        abort_unless($upload->status === 'pending', 422, 'Upload už byl zpracován.');
        $link = $upload->sharedLink;
        $extension = strtolower(pathinfo($upload->original_filename, PATHINFO_EXTENSION));
        $media = DB::transaction(function () use ($upload, $link, $request, $extension) {
            $media = MediaItem::create(['gallery_space_id' => $link->gallery_space_id, 'owner_user_id' => $request->user()->id, 'uploaded_by' => $request->user()->id, 'primary_album_id' => $link->target_type === 'album' ? $link->target_id : null, 'original_filename' => $upload->original_filename, 'safe_filename' => preg_replace('/[^a-zA-Z0-9._-]/', '_', $upload->original_filename), 'extension' => $extension, 'mime_type' => $upload->mime_type, 'media_type' => str_starts_with($upload->mime_type, 'video/') ? 'video' : 'photo', 'size_bytes' => $upload->size_bytes, 'status' => 'ready', 'uploaded_at' => now()]);
            $destination = "media/{$media->uuid}/original.{$extension}";
            $source = Storage::disk('local')->readStream($upload->storage_path);
            abort_unless($source && Storage::disk('public')->put($destination, $source, 'public'), 500, 'Soubor se nepodařilo přesunout.');
            if (is_resource($source)) fclose($source);
            $media->variants()->create(['type' => 'original', 'disk' => 'public', 'path' => $destination, 'mime_type' => $upload->mime_type, 'size_bytes' => $upload->size_bytes]);
            $upload->update(['status' => 'approved', 'reviewed_by' => $request->user()->id, 'media_item_id' => $media->id, 'reviewed_at' => now()]);
            Storage::disk('local')->delete($upload->storage_path);
            return $media;
        });
        return response()->json(['status' => 'approved', 'media' => $media], 201);
    }

    public function reject(Request $request, string $uuid): JsonResponse
    {
        $upload = $this->upload($request, $uuid);
        abort_unless($upload->status === 'pending', 422, 'Upload už byl zpracován.');
        Storage::disk('local')->delete($upload->storage_path);
        $upload->update(['status' => 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        return response()->json(['status' => 'rejected']);
    }

    private function upload(Request $request, string $uuid): GuestUpload
    {
        return GuestUpload::where('uuid', $uuid)->whereHas('sharedLink', fn ($query) => $query->where('created_by', $request->user()->id))->with('sharedLink')->firstOrFail();
    }
}
