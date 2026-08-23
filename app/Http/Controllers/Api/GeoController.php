<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Geo\PlaceSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeoController extends Controller
{
    public function __construct(private readonly PlaceSearchService $places) {}

    public function suggest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => 'required|string|min:2|max:200',
            'lat' => 'nullable|numeric|between:-90,90',
            'lon' => 'nullable|numeric|between:-180,180',
        ]);

        $space = $request->user()->gallerySpaces()->first();

        return response()->json([
            'results' => $this->places->suggest($data['q'], $space, [
                'lat' => isset($data['lat']) ? (float) $data['lat'] : null,
                'lon' => isset($data['lon']) ? (float) $data['lon'] : null,
            ]),
        ]);
    }

    /**
     * Uloží místo pod jménem, které mu dali oni.
     *
     * OpenStreetMap zná jen to, co do něj někdo vložil. „Mauzoleum" tam je, „Mausoleum
     * David Černý" ne — a žádný vyhledávač to nezmění. Tohle je odpověď: místo se najde
     * pod tím jménem, jaké má v mapě, uloží se pod tím, jak mu říkají oni, a od té chvíle
     * se nabízí první.
     */
    public function store(Request $request): JsonResponse
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze místa ukládat.');

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'country_code' => 'nullable|string|max:3',
            'address' => 'nullable|string|max:255',
        ]);

        $space = $request->user()->gallerySpaces()->firstOrFail();

        // Stejné jméno na stejném místě se nezakládá podruhé — jinak by se seznam
        // vlastních míst za pár měsíců zaplnil kopiemi téhož rohu ulice.
        $place = \App\Models\Place::firstOrCreate(
            [
                'gallery_space_id' => $space->id,
                'name' => $data['name'],
            ],
            $data + [
                'gallery_space_id' => $space->id,
                'source' => 'manual',
                'created_by' => $request->user()->id,
            ],
        );

        return response()->json([
            'place' => [
                'id' => $place->id,
                'name' => $place->name,
                'latitude' => (float) $place->latitude,
                'longitude' => (float) $place->longitude,
                'country' => $place->country,
                'country_code' => $place->country_code,
                'city' => $place->city,
                'address' => $place->address,
                'detail' => collect([$place->address, $place->city, $place->country])->filter()->implode(', '),
                'category' => 'saved',
                'source' => 'own',
            ],
        ], 201);
    }

    public function reverse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
        ]);

        return response()->json([
            'result' => $this->places->reverse((float) $data['lat'], (float) $data['lon']),
        ]);
    }
}
