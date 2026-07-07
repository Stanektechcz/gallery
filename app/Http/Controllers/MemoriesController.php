<?php

namespace App\Http\Controllers;

use App\Services\Media\MemoryDiscoveryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MemoriesController extends Controller
{
    public function index(Request $request, MemoryDiscoveryService $memories): Response
    {
        $cards = $memories->discover($request->user());

        return Inertia::render('Memories/Index', [
            'memories'     => $cards,
            'today_label'  => now()->translatedFormat('j. F'),
            'has_memories' => $cards->isNotEmpty(),
        ]);
    }
}
