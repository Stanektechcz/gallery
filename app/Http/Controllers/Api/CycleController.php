<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CycleDay;
use App\Models\CycleSetting;
use App\Models\GallerySpace;
use App\Services\Health\CycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Menstruační kalendář.
 *
 * Vlastní záznamy vidí každý svoje. Partnerovy jen v míře, jakou si nastavil — a to
 * odfiltruje služba, ne obrazovka.
 */
class CycleController extends Controller
{
    public function __construct(private readonly CycleService $cycles) {}

    public function index(Request $request): JsonResponse
    {
        $space = $this->space($request);
        $user = $request->user();

        // Co sdílejí ostatní členové prostoru. Prázdné, dokud si to někdo nezapne.
        $partners = $space->members()
            ->where('users.id', '!=', $user->id)
            ->get()
            ->map(fn ($member) => $this->cycles->partnerView($space, $member))
            ->filter()
            ->values();

        return response()->json([
            'mine' => $this->cycles->overview($space, $user),
            'partners' => $partners,
        ]);
    }

    public function statistics(Request $request): JsonResponse
    {
        $space = $this->space($request);
        $user = $request->user();

        return response()->json($this->cycles->statistics($space, $user) + [
            // Rozbor stavu vedle čísel: co z historie plyne, ne jen kolik toho je.
            "analysis" => $this->cycles->analysis($space, $user),
        ]);
    }

    public function storeDay(Request $request): JsonResponse
    {
        $this->write($request);

        $data = $request->validate([
            'day' => 'required|date',
            'flow' => 'required|in:none,spotting,light,medium,heavy',
            'symptoms' => 'nullable|array|max:20',
            'symptoms.*' => 'string|max:40',
            'moods' => 'nullable|array|max:10',
            'moods.*' => 'string|max:40',
            'pain' => 'nullable|integer|min:0|max:10',
            'temperature' => 'nullable|numeric|between:30,45',
            'note' => 'nullable|string|max:1000',
            'is_cycle_start' => 'sometimes|boolean',
        ]);

        $space = $this->space($request);
        $this->cycles->saveDay($space, $request->user(), $data);

        return response()->json($this->cycles->overview($space, $request->user()));
    }

    public function destroyDay(Request $request, string $day): JsonResponse
    {
        $this->write($request);

        CycleDay::where('user_id', $request->user()->id)
            ->whereDate('day', $day)
            ->delete();

        return response()->json($this->cycles->overview($this->space($request), $request->user()));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->write($request);

        $data = $request->validate([
            'share_level' => 'sometimes|in:none,dates,full',
            'average_cycle_days' => 'sometimes|integer|min:15|max:60',
            'average_period_days' => 'sometimes|integer|min:1|max:14',
            'remind_upcoming' => 'sometimes|boolean',
            'remind_days_before' => 'sometimes|integer|min:0|max:7',
            'track_symptoms' => 'sometimes|boolean',
        ]);

        $space = $this->space($request);
        $settings = $this->cycles->settings($space, $request->user());
        $settings->update($data);

        return response()->json($this->cycles->overview($space, $request->user()));
    }

    private function space(Request $request): GallerySpace
    {
        return $request->user()->gallerySpaces()->firstOrFail();
    }

    private function write(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze zapisovat.');
    }
}
