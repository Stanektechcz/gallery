<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationSetting;
use App\Services\Integrations\FreeTravelDataService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class IntegrationController extends Controller
{
    public function index(Request $request, FreeTravelDataService $service): Response
    {
        $settings = IntegrationSetting::all()->keyBy('provider');
        $lastCinemaSync = Schema::hasTable('cinema_sync_runs')
            ? DB::table('cinema_sync_runs')->where('provider', 'cinema_city')->where('cinema_code', '1035')->latest('id')->first()
            : null;
        $cinemaShowings = Schema::hasTable('cinema_showings')
            ? DB::table('cinema_showings')->where('provider', 'cinema_city')->where('cinema_code', '1035')->where('starts_at', '>', now())->count()
            : 0;

        $providers = collect(FreeTravelDataService::PROVIDERS)->map(function (array $definition, string $provider) use ($settings, $lastCinemaSync, $cinemaShowings) {
            $setting = $settings->get($provider);
            $config = $setting?->config() ?? [];
            $configuredCredentials = collect($definition['credentials'])->filter(fn (string $key) => filled($config[$key] ?? null))->values()->all();
            $missingCredentials = collect($definition['credentials'])->diff($configuredCredentials)->values()->all();

            return $definition + [
                'provider' => $provider,
                'is_enabled' => $definition['credentials'] === [] ? true : (bool) $setting?->is_enabled,
                'is_configured' => $missingCredentials === [],
                'configured_credentials' => $configuredCredentials,
                'missing_credentials' => $missingCredentials,
                'last_tested_at' => $setting?->last_tested_at?->toIso8601String(),
                'last_status' => $setting?->last_status,
                'last_error' => $this->storedError($setting?->last_error),
                'runtime' => $provider === 'cinema_city' ? [
                    'showings_count' => $cinemaShowings,
                    'last_sync_status' => $lastCinemaSync?->status,
                    'last_sync_at' => $lastCinemaSync?->finished_at ?? $lastCinemaSync?->created_at,
                    'last_sync_error' => $this->storedError($lastCinemaSync?->last_error),
                ] : null,
            ];
        })->sortBy('priority')->values();
        $gallerySpaceId = $request->user()->gallerySpaces()->value('gallery_spaces.id');

        return Inertia::render('Admin/Integrations', compact('providers', 'gallerySpaceId'));
    }

    public function update(Request $request, string $provider, FreeTravelDataService $service): JsonResponse
    {
        $definition = $service->provider($provider);
        $data = $request->validate(['is_enabled' => 'required|boolean', 'config' => 'nullable|array']);
        $setting = IntegrationSetting::firstOrNew(['provider' => $provider]);
        $existing = $setting->exists ? $setting->config() : [];
        $updates = collect($data['config'] ?? [])->only($definition['credentials'])->map(function (mixed $value) {
            abort_unless(is_string($value) || is_numeric($value), 422, 'Konfigurační hodnoty musí být textové.');
            $value = trim((string) $value);
            abort_if(mb_strlen($value) > 4096, 422, 'Konfigurační hodnota je příliš dlouhá.');

            return $value;
        })->filter(fn ($value) => filled($value))->all();
        $config = array_replace($existing, $updates);
        $missing = collect($definition['credentials'])->reject(fn (string $key) => filled($config[$key] ?? null))->values();
        $enabled = $definition['credentials'] === [] ? true : (bool) $data['is_enabled'];
        if ($enabled && $missing->isNotEmpty()) {
            $labels = $missing->map(fn (string $key) => $definition['credential_meta'][$key]['label'] ?? $key)->implode(', ');
            abort(422, 'Pro aktivaci doplňte: '.$labels.'.');
        }
        if ($updates) {
            $setting->replaceConfig($config);
        }
        $setting->fill([
            'is_enabled' => $enabled,
            'last_status' => $updates ? null : $setting->last_status,
            'last_error' => $updates ? null : $setting->last_error,
            'updated_by' => $request->user()->id,
        ])->save();

        $configured = collect($definition['credentials'])->filter(fn (string $key) => filled($config[$key] ?? null))->values()->all();

        return response()->json([
            'provider' => $provider,
            'is_enabled' => (bool) $setting->is_enabled,
            'is_configured' => count($configured) === count($definition['credentials']),
            'configured_credentials' => $configured,
            'missing_credentials' => collect($definition['credentials'])->diff($configured)->values()->all(),
            'last_status' => $setting->last_status,
            'last_error' => $setting->last_error,
        ]);
    }

    public function test(Request $request, string $provider, FreeTravelDataService $service): JsonResponse
    {
        $definition = $service->provider($provider);
        $setting = IntegrationSetting::firstOrCreate(['provider' => $provider]);
        try {
            $service->test($provider);
            $setting->update(['last_tested_at' => now(), 'last_status' => 'ok', 'last_error' => null]);

            return response()->json(['status' => 'ok', 'message' => $definition['name'].': připojení funguje.']);
        } catch (Throwable $exception) {
            report($exception);
            $message = $this->friendlyError($definition['name'], $exception);
            $setting->update(['last_tested_at' => now(), 'last_status' => 'failed', 'last_error' => $message]);

            return response()->json(['status' => 'failed', 'message' => $message], 422);
        }
    }

    private function friendlyError(string $name, Throwable $exception): string
    {
        if ($exception instanceof HttpExceptionInterface && filled($exception->getMessage())) {
            return $exception->getMessage();
        }
        if ($exception instanceof ConnectionException) {
            return $name.': poskytovatel je ze serveru dočasně nedostupný. Zkontrolujte DNS, firewall a odchozí HTTPS spojení.';
        }
        if ($exception instanceof RequestException) {
            $status = $exception->response->status();
            if (in_array($status, [401, 403], true)) {
                return $name.': poskytovatel odmítl přístupové údaje (HTTP '.$status.'). Zkontrolujte klíče a jejich aktivaci.';
            }

            return $name.': poskytovatel odpověděl chybou HTTP '.$status.'. Zkuste test zopakovat později.';
        }
        if ($exception instanceof QueryException) {
            return $name.': data se nepodařilo uložit do databáze. Zkontrolujte dokončení migrací.';
        }

        return $name.': test skončil technickou chybou. Podrobnost byla bezpečně zapsána do serverového logu.';
    }

    private function storedError(?string $message): ?string
    {
        if (blank($message)) {
            return null;
        }
        if (str_contains($message, 'SQLSTATE')) {
            return 'Dřívější běh skončil databázovou chybou. Po aktualizaci spusťte test nebo synchronizaci znovu.';
        }

        $message = preg_replace('~https?://\S+~u', '[adresa skryta]', $message) ?? $message;
        $message = preg_replace('/(api[_-]?key|secret[_-]?(?:id|key)|token)\s*[=:]\s*[^\s,;]+/iu', '$1=[skryto]', $message) ?? $message;

        return mb_substr($message, 0, 500);
    }
}
