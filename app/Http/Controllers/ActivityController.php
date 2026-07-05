<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        // AuditLog stores user_id — filter logs by this gallery's users
        $spaceUserIds = $space
            ? $space->members()->pluck('users.id')->toArray()
            : [$user->id];

        $logs = AuditLog::with('user:id,name')
            ->whereIn('user_id', $spaceUserIds)
            ->orderByDesc('created_at')
            ->paginate(40);

        $formatted = $logs->through(fn($log) => [
            'id'          => $log->id,
            'event'       => $log->action,
            'user_name'   => $log->user?->name ?? 'Systém',
            'description' => $this->describe($log),
            'created_at'  => $log->created_at->toIso8601String(),
        ]);

        return Inertia::render('Activity/Index', ['logs' => $formatted]);
    }

    private function describe(AuditLog $log): string
    {
        $data = $log->payload ?? [];
        if (isset($data['filename'])) return $data['filename'];
        if (isset($data['title']))    return $data['title'];
        if (isset($data['via']))      return $data['via'];
        return '';
    }
}
