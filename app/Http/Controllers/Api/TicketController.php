<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Travel\TransportSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function __construct(private readonly TransportSearchService $transportSearch) {}

    /** Search schedules, live prices and safe purchase links through one API. */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'required|string|max:160', 'to' => 'required|string|max:160', 'date' => 'required|date_format:Y-m-d',
            'time' => 'nullable|date_format:H:i', 'adults' => 'nullable|integer|min:1|max:9',
            'from_lat' => 'nullable|required_with:from_lng|numeric|between:-90,90', 'from_lng' => 'nullable|required_with:from_lat|numeric|between:-180,180',
            'to_lat' => 'nullable|required_with:to_lng|numeric|between:-90,90', 'to_lng' => 'nullable|required_with:to_lat|numeric|between:-180,180',
            'mode' => 'nullable|in:all,train,bus,tram,metro,ferry', 'max_transfers' => 'nullable|integer|min:0|max:8',
            'min_transfer_minutes' => 'nullable|integer|min:0|max:60', 'wheelchair' => 'nullable|boolean', 'bike' => 'nullable|boolean',
        ]);

        return response()->json($this->transportSearch->search($data));
    }
}
