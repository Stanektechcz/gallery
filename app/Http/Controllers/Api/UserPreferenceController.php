<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        ]);

        abort_if($data === [], 422, 'Nebyla poslána žádná změna nastavení.');

        $user = $request->user();
        $preferences = is_array($user->preferences) ? $user->preferences : [];

        foreach ($data as $key => $value) {
            $preferences[$key] = $value;
        }

        $user->forceFill(['preferences' => $preferences])->save();

        return response()->json([
            'interface_density' => $preferences['interface_density'] ?? null,
            'theme'             => $preferences['theme'] ?? null,
        ]);
    }
}
