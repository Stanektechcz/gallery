<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ThemePalette;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserPreferenceController extends Controller
{
    /**
     * Appearance settings follow the account rather than the device, so a member who signs
     * in on their phone gets the look they chose on the desktop. Each key is optional:
     * the controls send only what they changed.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'interface_density' => 'sometimes|in:comfortable,standard,compact',
            'theme'             => 'sometimes|in:dark,light,system',
            // A member's own colours, one palette per mode. Values are sanitised below.
            'theme_palette'         => 'sometimes|array',
            'theme_palette.dark'    => 'sometimes|array',
            'theme_palette.light'   => 'sometimes|array',
        ]);

        abort_if($data === [], 422, 'Nebyla poslána žádná změna nastavení.');

        $user = $request->user();
        $preferences = is_array($user->preferences) ? $user->preferences : [];

        foreach ($data as $key => $value) {
            if ($key === 'theme_palette') {
                // Only known tokens with a valid hex value survive, so nothing a client
                // sends can reach the stylesheet.
                $preferences['theme_palette'] = [
                    'dark'  => ThemePalette::clean($value['dark'] ?? []),
                    'light' => ThemePalette::clean($value['light'] ?? []),
                ];
                continue;
            }
            $preferences[$key] = $value;
        }

        $user->forceFill(['preferences' => $preferences])->save();

        return response()->json([
            'interface_density' => $preferences['interface_density'] ?? null,
            'theme'             => $preferences['theme'] ?? null,
            'theme_palette'     => ThemePalette::forUser($user->fresh()),
        ]);
    }
}
