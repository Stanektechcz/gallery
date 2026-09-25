<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Models\GuestUpload;
use App\Models\MediaItem;
use App\Services\Billing\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GuestUploadController extends Controller
{
    private const UZ_ZPRACOVANO = 'Upload už byl zpracován.';

    public function index(Request $request): JsonResponse
    {
        $uploads = GuestUpload::whereHas('sharedLink', fn ($query) => $query->where('created_by', $request->user()->id))->with('sharedLink:id,name,token,target_type,target_id')->latest()->paginate(50);

        return response()->json($uploads);
    }

    /**
     * Schválí nahrávku hosta — jednou, v kvótě tarifu.
     *
     * Stav se dřív kontroloval před transakcí bez zámku, takže dvě souběžná
     * schválení (dvojklik, oba z dvojice najednou) založila dvě média. Teď si
     * nahrávku převezme podmíněný zápis uvnitř transakce; kdo přijde druhý,
     * dostane 422 a transakce se při jakékoli chybě vrátí i se stavem.
     */
    public function approve(Request $request, string $uuid): JsonResponse
    {
        $upload = $this->upload($request, $uuid);
        abort_unless($upload->status === 'pending', 422, self::UZ_ZPRACOVANO);
        $link = $upload->sharedLink;

        // Stejně jako běžné nahrání: nad limit tarifu se soubor do galerie nepřesune.
        $space = GallerySpace::find($link->gallery_space_id);
        abort_if(
            $space && ! app(EntitlementService::class)->canStore($space, (int) $upload->size_bytes),
            402,
            'Soubor by překročil limit úložiště tarifu. Uvolněte místo nebo přejděte na vyšší tarif — viz /cenik.'
        );

        $extension = UploadController::bezpecnaPripona((string) $upload->original_filename, $upload->mime_type);
        $userId = $request->user()->id;

        $media = DB::transaction(function () use ($upload, $link, $userId, $extension) {
            $claimed = GuestUpload::whereKey($upload->id)
                ->where('status', 'pending')
                ->update(['status' => 'approved', 'reviewed_by' => $userId, 'reviewed_at' => now(), 'updated_at' => now()]);
            abort_if($claimed === 0, 422, self::UZ_ZPRACOVANO);

            $media = MediaItem::create(['gallery_space_id' => $link->gallery_space_id, 'owner_user_id' => $userId, 'uploaded_by' => $userId, 'primary_album_id' => $link->target_type === 'album' ? $link->target_id : null, 'original_filename' => $upload->original_filename, 'safe_filename' => preg_replace('/[^a-zA-Z0-9._-]/', '_', $upload->original_filename), 'extension' => $extension, 'mime_type' => $upload->mime_type, 'media_type' => str_starts_with($upload->mime_type, 'video/') ? 'video' : 'photo', 'size_bytes' => $upload->size_bytes, 'status' => 'ready', 'uploaded_at' => now()]);
            $destination = "media/{$media->uuid}/original".($extension !== '' ? ".{$extension}" : '');
            $source = Storage::disk('local')->readStream($upload->storage_path);
            abort_unless($source && Storage::disk('public')->put($destination, $source, 'public'), 500, 'Soubor se nepodařilo přesunout.');
            if (is_resource($source)) {
                fclose($source);
            }
            $media->variants()->create(['type' => 'original', 'disk' => 'public', 'path' => $destination, 'mime_type' => $upload->mime_type, 'size_bytes' => $upload->size_bytes]);
            $upload->forceFill(['media_item_id' => $media->id])->save();

            return $media;
        });

        // Soubor hosta až po potvrzené transakci — při chybě musí zůstat na místě.
        Storage::disk('local')->delete($upload->storage_path);

        return response()->json(['status' => 'approved', 'media' => $media], 201);
    }

    public function reject(Request $request, string $uuid): JsonResponse
    {
        $upload = $this->upload($request, $uuid);
        abort_unless($upload->status === 'pending', 422, self::UZ_ZPRACOVANO);

        // Podmíněně, aby souběžné schválení nepřišlo o soubor, který právě přesouvá.
        $claimed = GuestUpload::whereKey($upload->id)
            ->where('status', 'pending')
            ->update(['status' => 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now()]);
        abort_if($claimed === 0, 422, self::UZ_ZPRACOVANO);

        Storage::disk('local')->delete($upload->storage_path);

        return response()->json(['status' => 'rejected']);
    }

    private function upload(Request $request, string $uuid): GuestUpload
    {
        return GuestUpload::where('uuid', $uuid)->whereHas('sharedLink', fn ($query) => $query->where('created_by', $request->user()->id))->with('sharedLink')->firstOrFail();
    }
}
