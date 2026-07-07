<?php

namespace App\Http\Controllers;

use App\Models\MediaItem;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    public function index(Request $request): Response
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $media = MediaItem::where('gallery_space_id', $space->id)
            ->whereNull('primary_album_id')
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
            ->whereIn('status', ['ready', 'received'])
            ->with(['variants' => fn($q) => $q->whereIn('type', ['thumbnail', 'placeholder'])])
            ->orderByDesc('uploaded_at')
            ->paginate(60);

        return Inertia::render('Inbox/Index', [
            'media' => $media,
        ]);
    }
}
