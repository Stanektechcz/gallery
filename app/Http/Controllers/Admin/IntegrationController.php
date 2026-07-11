<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationSetting;
use App\Services\Integrations\FreeTravelDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationController extends Controller
{
    public function index(FreeTravelDataService $service): Response
    {
        $settings = IntegrationSetting::all()->keyBy('provider');
        $providers = collect(FreeTravelDataService::PROVIDERS)->map(function (array $definition, string $provider) use ($settings) {
            $setting = $settings->get($provider);
            return $definition + ['provider' => $provider, 'is_enabled' => $definition['credentials'] === [] ? true : (bool) $setting?->is_enabled, 'is_configured' => $definition['credentials'] === [] || !empty($setting?->encrypted_config), 'last_tested_at' => $setting?->last_tested_at?->toIso8601String(), 'last_status' => $setting?->last_status, 'last_error' => $setting?->last_error];
        })->values();
        return Inertia::render('Admin/Integrations', compact('providers'));
    }

    public function update(Request $request, string $provider, FreeTravelDataService $service): JsonResponse
    {
        $definition = $service->provider($provider);
        $data = $request->validate(['is_enabled' => 'required|boolean', 'config' => 'nullable|array']);
        $setting = IntegrationSetting::firstOrNew(['provider' => $provider]);
        $existing = $setting->exists ? $setting->config() : [];
        $updates = collect($data['config'] ?? [])->only($definition['credentials'])->filter(fn ($value) => filled($value))->all();
        $config = array_replace($existing, $updates);
        if ($data['is_enabled'] && $definition['credentials'] !== [] && count(array_filter($config, fn ($value) => filled($value))) !== count($definition['credentials'])) abort(422, 'Pro aktivaci doplňte všechny požadované údaje.');
        if ($updates) $setting->replaceConfig($config);
        $setting->fill(['is_enabled' => $data['is_enabled'], 'updated_by' => $request->user()->id])->save();
        return response()->json(['provider' => $provider, 'is_enabled' => $setting->is_enabled, 'is_configured' => $definition['credentials'] === [] || !empty($setting->encrypted_config)]);
    }

    public function test(Request $request, string $provider, FreeTravelDataService $service): JsonResponse
    {
        $service->provider($provider); $setting = IntegrationSetting::firstOrCreate(['provider' => $provider]);
        try { $service->test($provider); $setting->update(['last_tested_at' => now(), 'last_status' => 'ok', 'last_error' => null]); return response()->json(['status' => 'ok']); }
        catch (\Throwable $exception) { report($exception); $setting->update(['last_tested_at' => now(), 'last_status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 500)]); return response()->json(['status' => 'failed', 'message' => 'Připojení se nepodařilo ověřit.'], 422); }
    }
}
