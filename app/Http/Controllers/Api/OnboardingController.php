<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * First-run checklist for a new customer.
 *
 * Every step is derived from real state rather than from a "clicked" flag, so it stays
 * honest if someone does the work out of order or from another device.
 */
class OnboardingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $space = $user->gallerySpaces()->orderByDesc('is_default')->first();
        if (! $space) return response()->json(['visible' => false, 'steps' => []]);

        $steps = [
            [
                'key' => 'space',
                'title' => 'Pojmenovat galerii',
                'description' => 'Prostor je založený a nese vaše jméno.',
                'done' => filled($space->name),
                'href' => null,
            ],
            [
                'key' => 'partner',
                'title' => 'Pozvat druhého člena',
                'description' => 'Galerie dává smysl ve dvou. Pozvánku pošlete e-mailem.',
                'done' => $space->members()->count() > 1 || $this->hasPendingInvitation(),
                'href' => '/admin/users',
            ],
            [
                'key' => 'media',
                'title' => 'Nahrát první fotky',
                'description' => 'Přetáhněte je kamkoliv do galerie, nebo použijte tlačítko nahrávání.',
                'done' => MediaItem::where('gallery_space_id', $space->id)->exists(),
                'href' => '/timeline',
            ],
            [
                'key' => 'album',
                'title' => 'Založit první album',
                'description' => 'Alba drží vzpomínky pohromadě podle událostí.',
                'done' => Album::where('gallery_space_id', $space->id)->exists(),
                'href' => '/albums',
            ],
        ];

        $remaining = collect($steps)->reject(fn (array $step) => $step['done'])->count();

        return response()->json([
            // Hidden once everything is done, or once the user has waved it away.
            'visible' => $remaining > 0 && ! ($user->preferences['onboarding_dismissed'] ?? false),
            'remaining' => $remaining,
            'steps' => $steps,
        ]);
    }

    public function dismiss(Request $request): JsonResponse
    {
        $user = $request->user();
        $preferences = is_array($user->preferences) ? $user->preferences : [];
        $preferences['onboarding_dismissed'] = true;
        $user->update(['preferences' => $preferences]);

        return response()->json(['dismissed' => true]);
    }

    /** An invited account exists but has not yet set its password. */
    private function hasPendingInvitation(): bool
    {
        return User::whereNotNull('invitation_token')->whereNull('invitation_accepted_at')->exists();
    }
}
