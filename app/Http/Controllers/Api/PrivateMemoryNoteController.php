<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class PrivateMemoryNoteController extends Controller
{
    public function show(Request $request, string $uuid): JsonResponse
    {
        $media = $this->media($request, $uuid);
        $note = DB::table('media_private_notes')->where('media_item_id', $media->id)->where('user_id', $request->user()->id)->first();
        return response()->json(['content' => $note ? Crypt::decryptString($note->encrypted_content) : '']);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $media = $this->media($request, $uuid); $data = $request->validate(['content' => 'nullable|string|max:10000']);
        if (blank($data['content'] ?? null)) { DB::table('media_private_notes')->where('media_item_id', $media->id)->where('user_id', $request->user()->id)->delete(); return response()->json(['content' => '']); }
        DB::table('media_private_notes')->updateOrInsert(['media_item_id' => $media->id, 'user_id' => $request->user()->id], ['encrypted_content' => Crypt::encryptString($data['content']), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['content' => $data['content']]);
    }

    private function media(Request $request, string $uuid): MediaItem
    {
        return MediaItem::where('uuid', $uuid)->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->whereNull('trashed_at')->firstOrFail();
    }
}
