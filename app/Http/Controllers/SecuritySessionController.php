<?php

namespace App\Http\Controllers;

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
        return Inertia::render('Settings/Security', compact('sessions'));
    }

    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        abort_if(hash_equals($request->session()->getId(), $sessionId), 422, 'Aktuální relaci nelze odhlásit z této obrazovky.');
        $deleted = DB::table('sessions')->where('id', $sessionId)->where('user_id', $request->user()->id)->delete();
        abort_unless($deleted, 404);
        return response()->json(['status' => 'revoked']);
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();
        return response()->json(['status' => 'revoked_others']);
    }

    private function summarizeUserAgent(?string $agent): string
    {
        $agent = (string) $agent;
        if ($agent === '') return 'Neznámé zařízení';
        if (str_contains($agent, 'Android')) return 'Android zařízení';
        if (str_contains($agent, 'iPhone') || str_contains($agent, 'iPad')) return 'Apple zařízení';
        if (str_contains($agent, 'Windows')) return 'Windows';
        if (str_contains($agent, 'Macintosh')) return 'macOS';
        return 'Webový prohlížeč';
    }
}
