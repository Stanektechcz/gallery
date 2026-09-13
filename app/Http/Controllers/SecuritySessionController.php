<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SecuritySessionController extends Controller
{
    public function index(Request $request): Response
    {
        $sessions = DB::table('sessions')->where('user_id', $request->user()->id)->orderByDesc('last_activity')->get(['id', 'ip_address', 'user_agent', 'last_activity'])->map(fn ($session) => ['id' => $session->id, 'ip_address' => $session->ip_address, 'user_agent' => $this->summarizeUserAgent($session->user_agent), 'last_activity' => now()->setTimestamp((int) $session->last_activity)->toIso8601String(), 'is_current' => hash_equals((string) $request->session()->getId(), (string) $session->id)]);

        return Inertia::render('Settings/Profile', compact('sessions'));
    }

    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        abort_if(hash_equals($request->session()->getId(), $sessionId), 422, 'Aktuální relaci nelze odhlásit z této obrazovky.');
        $deleted = DB::table('sessions')->where('id', $sessionId)->where('user_id', $request->user()->id)->delete();
        abort_unless($deleted, 404);

        return response()->json(['status' => 'revoked']);
    }

    /**
     * Odhlásit všechna ostatní zařízení — i aplikaci.
     *
     * Rušila se jen sezení prohlížeče, a obrazovka přitom hlásila „Všechna
     * ostatní zařízení byla odhlášena". Telefon s aplikací se ale přihlašuje
     * klíčem, takže ten, kvůli kterému člověk tlačítko mačká, zůstal
     * přihlášený. Stejně jako „odhlásit ostatní" v aplikaci (`ZamekController`).
     */
    public function destroyOthers(Request $request): JsonResponse
    {
        $user = $request->user();

        $sezeni = DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        $klice = $user->tokens()->delete();

        AuditLog::record('app_lock.sign_out_others', null, ['sezeni' => $sezeni, 'klice' => $klice]);

        return response()->json(['status' => 'revoked_others']);
    }

    private function summarizeUserAgent(?string $agent): string
    {
        $agent = (string) $agent;
        if ($agent === '') {
            return 'Neznámé zařízení';
        }
        if (str_contains($agent, 'Android')) {
            return 'Android zařízení';
        }
        if (str_contains($agent, 'iPhone') || str_contains($agent, 'iPad')) {
            return 'Apple zařízení';
        }
        if (str_contains($agent, 'Windows')) {
            return 'Windows';
        }
        if (str_contains($agent, 'Macintosh')) {
            return 'macOS';
        }

        return 'Webový prohlížeč';
    }
}
