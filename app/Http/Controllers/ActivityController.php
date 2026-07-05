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

        $logs = AuditLog::with('user:id,name')
            ->where(function ($q) use ($space) {
                $q->where('loggable_type', 'App\\Models\\MediaItem')
                    ->whereHasMorph('loggable', [\App\Models\MediaItem::class], fn($q2) => $q2->where('gallery_space_id', $space->id));
            })
            ->orWhere(function ($q) use ($space) {
                $q->where('loggable_type', 'App\\Models\\Album')
                    ->whereHasMorph('loggable', [\App\Models\Album::class], fn($q2) => $q2->where('gallery_space_id', $space->id));
            })
            ->orderByDesc('created_at')
            ->paginate(40);

        // Format for frontend
        $formatted = $logs->through(fn($log) => [
            'id'          => $log->id,
            'event'       => $log->event,
            'user_name'   => $log->user?->name ?? 'Systém',
            'description' => $this->describe($log),
            'created_at'  => $log->created_at->toIso8601String(),
        ]);

        return Inertia::render('Activity/Index', ['logs' => $formatted]);
    }

    private function describe(\App\Models\AuditLog $log): string
    {
        $data = $log->changes ?? [];
        if (isset($data['filename'])) return $data['filename'];
        if (isset($data['title']))    return $data['title'];
        return '';
    }
}
