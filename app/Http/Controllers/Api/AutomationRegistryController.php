<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Planning\AutomationRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutomationRegistryController extends Controller
{
    public function __construct(private readonly AutomationRegistryService $registry) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'nullable|integer']);
        $space = $this->space($request, $data['gallery_space_id'] ?? null);
        return response()->json(['space' => ['id' => $space->id, 'name' => $space->name], 'automations' => $this->registry->items($space)]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'enabled' => 'required|boolean']);
        $space = $this->space($request, (int) $data['gallery_space_id']);
        $this->registry->setEnabled($space, $key, (bool) $data['enabled']);
        return response()->json(['automation' => $this->registry->item($space->fresh(), $key)]);
    }

    private function space(Request $request, ?int $id)
    {
        $spaces = $request->user()->gallerySpaces()->orderByDesc('is_default');
        return $id ? $spaces->findOrFail($id) : $spaces->firstOrFail();
    }
}