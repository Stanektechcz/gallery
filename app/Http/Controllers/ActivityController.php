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
        $user = $request->user();
        // Výchozí galerie, jinak nejstarší — `first()` bez řazení bral kteroukoli.
        $space = $user->gallerySpaces()->orderByDesc('is_default')->orderBy('gallery_spaces.id')->first();

        /*
         * Jen tahle galerie, ne jen její lidé.
         *
         * Filtr na členy nestačil: kdo je ve dvou galeriích, tomu partner v té
         * první viděl, co nahrál do druhé — i se jménem souboru. Stejně jako
         * přehled „Dnes" (Obsah\Dnes). Bez galerie jen vlastní záznamy.
         */
        $logs = AuditLog::with('user:id,name')
            ->when(
                $space,
                fn ($q) => $q->where('gallery_space_id', $space->id)
                    ->whereIn('user_id', $space->members()->pluck('users.id')),
                fn ($q) => $q->where('user_id', $user->id),
            )
            ->orderByDesc('created_at')
            ->paginate(40);

        $formatted = $logs->through(fn ($log) => [
            'id' => $log->id,
            'event' => $log->action,
            'user_name' => $log->user?->name ?? 'Systém',
            'description' => $this->describe($log),
            'created_at' => $log->created_at->toIso8601String(),
        ]);

        return Inertia::render('Activity/Index', ['logs' => $formatted]);
    }

    private function describe(AuditLog $log): string
    {
        $data = $log->payload ?? [];
        if ($log->action === 'assistant.apply' && ! empty($data['created']) && is_array($data['created'])) {
            return implode(' · ', $data['created']);
        }
        if (isset($data['filename'])) {
            return $data['filename'];
        }
        if (isset($data['title'])) {
            return $data['title'];
        }
        if (isset($data['via'])) {
            return $data['via'];
        }

        return '';
    }
}
