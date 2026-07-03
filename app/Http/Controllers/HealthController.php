<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()]);
    }

    public function ready(): JsonResponse
    {
        $checks = [];

        // DB
        try {
            DB::connection()->getPdo();
            $checks['db'] = 'ok';
        } catch (\Throwable $e) {
            $checks['db'] = 'fail';
        }

        // Storage
        $checks['storage'] = is_writable(storage_path('app')) ? 'ok' : 'fail';

        // Queue (check if jobs table is accessible)
        try {
            DB::table('jobs')->count();
            $checks['queue'] = 'ok';
        } catch (\Throwable) {
            $checks['queue'] = 'fail';
        }

        $healthy  = !in_array('fail', $checks);
        $httpCode = $healthy ? 200 : 503;

        return response()->json([
            'status' => $healthy ? 'ready' : 'degraded',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $httpCode);
    }
}
