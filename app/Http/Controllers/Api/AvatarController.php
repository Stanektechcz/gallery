<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Setting and serving a person's face.
 *
 * Three ways to have one and they are mutually exclusive by construction: choosing a
 * preset clears an upload, uploading clears a preset, and clearing both falls back to the
 * generated initial. Keeping two at once would mean deciding which wins on every render.
 */
class AvatarController extends Controller
{
    private const DISK = 'local';
    private const MAX_KB = 4096;

    /** Reported when the upload is refused, so the screen can say which rule bit. */
    private const MESSAGES = [
        'image.max' => 'Obrázek je příliš velký. Nahrajte soubor do 4 MB.',
        'image.mimetypes' => 'Nepodporovaný formát. Použijte JPEG, PNG, WebP nebo GIF.',
    ];

    /** @var list<string> */
    private const MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Built-in faces. Names only — the drawing is done by the client as inline SVG, so
     * they cost no storage, no request and render instantly at any size.
     *
     * @var list<string>
     */
    public const PRESETS = [
        'kocka', 'pes', 'liska', 'sova', 'medved', 'panda',
        'srdce', 'hvezda', 'mesic', 'kytka', 'kava', 'duha',
    ];

    public function options(): JsonResponse
    {
        return response()->json(['presets' => self::PRESETS]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->read_only_mode, 403, 'V režimu pouze pro čtení nelze měnit avatar.');

        $data = $request->validate([
            'preset' => 'nullable|string|in:' . implode(',', self::PRESETS),
            'colour' => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'image' => 'nullable|file|max:' . self::MAX_KB . '|mimetypes:' . implode(',', self::MIME),
            'clear' => 'nullable|boolean',
        ], self::MESSAGES);

        if ($request->boolean('clear')) {
            $this->removeUpload($user);
            $user->update(['avatar_path' => null, 'avatar_preset' => null]);

            return response()->json($this->payload($user->fresh()));
        }

        if ($upload = $request->file('image')) {
            $this->removeUpload($user);

            /*
             | Storing can fail for reasons that are not the caller's fault — an
             | unwritable disk root being the usual one after a deployment, since the
             | directory is created by whoever ran the last artisan command rather than
             | by the web user. A 500 tells the person nothing they can act on, so the
             | reason is caught and named.
             */
            try {
                $stored = $upload->store('avatars', self::DISK);
            } catch (Throwable $reason) {
                report($reason);
                abort(500, 'Obrázek se nepodařilo uložit na disk. Zkontrolujte práva ke složce storage/app.');
            }

            abort_unless($stored, 500, 'Obrázek se nepodařilo uložit na disk.');

            $user->avatar_path = $stored;
            // An uploaded face replaces a chosen one; only one can be shown.
            $user->avatar_preset = null;
        } elseif (! empty($data['preset'])) {
            $this->removeUpload($user);
            $user->avatar_preset = $data['preset'];
            $user->avatar_path = null;
        }

        if (! empty($data['colour'])) $user->avatar_colour = strtolower($data['colour']);

        $user->save();

        return response()->json($this->payload($user->fresh()));
    }

    /** Streams an uploaded face to anyone signed in who shares a space with its owner. */
    public function show(Request $request, string $uuid)
    {
        $user = User::where('uuid', $uuid)->firstOrFail();
        abort_unless($user->avatar_path && Storage::disk(self::DISK)->exists($user->avatar_path), 404);

        // A face is only visible to people the person actually shares a gallery with.
        $viewer = $request->user();
        abort_unless(
            $viewer->id === $user->id || $this->shareASpace($viewer, $user),
            403,
        );

        return Storage::disk(self::DISK)->response($user->avatar_path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function shareASpace(User $viewer, User $owner): bool
    {
        return $viewer->gallerySpaces()
            ->whereHas('members', fn ($members) => $members->whereKey($owner->id))
            ->exists();
    }

    private function removeUpload(User $user): void
    {
        if ($user->avatar_path) Storage::disk(self::DISK)->delete($user->avatar_path);
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        return [
            'avatar_url' => $user->avatar_url,
            'avatar_fallback' => $user->avatar_fallback,
        ];
    }
}
