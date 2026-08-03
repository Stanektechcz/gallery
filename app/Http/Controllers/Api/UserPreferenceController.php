<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserPreferenceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['interface_density' => 'required|in:comfortable,standard,compact']);
        $user = $request->user();
        $preferences = is_array($user->preferences) ? $user->preferences : [];
        $preferences['interface_density'] = $data['interface_density'];
        $user->forceFill(['preferences' => $preferences])->save();

        return response()->json(['interface_density' => $preferences['interface_density']]);
    }
}