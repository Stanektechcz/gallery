<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Notifications\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Připomínka druhému — upozornění do jeho telefonu.
 *
 * „Připomenout" u žádosti hlásilo, že připomínka odešla, a neodešlo nic;
 * potom poctivé „zatím neumíme". Aplikace přitom upozornění posílat umí
 * (WebPushService, odběr z Nastavení). Připomínka jde jen druhému členovi
 * prostoru, nikomu jinému — a když upozornění zapnutá nemá, řekne se to.
 */
class PripomenutiController extends Controller
{
    use UrcujePar;

    public function __invoke(Request $request, WebPushService $push): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        $data = $request->validate([
            'text' => ['required', 'string', 'max:300'],
            'obrazovka' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
        ]);

        $ja = $request->user();

        /** @var User|null $druhy */
        $druhy = $prostor->members()->where('users.id', '!=', $ja->id)->first();

        if ($druhy === null) {
            return response()->json(['ok' => false, 'zprava' => 'V galerii zatím není nikdo, komu připomínku poslat.'], 422);
        }

        $jmeno = Str::before(trim((string) $ja->name), ' ') ?: 'Partner';

        $odeslano = $push->sendToUser($druhy, array_filter([
            'title' => 'Připomínka od '.$jmeno,
            'body' => Str::limit(trim($data['text']), 180),
            'url' => '/',
            // Obrazovku, kterou upozornění otevře, určuje klient — zná svoje trasy.
            'route' => $data['obrazovka'] ?? null,
            'tag' => 'pripominka-'.$ja->id,
        ]));

        AuditLog::record('reminder.send', null, ['komu' => $druhy->id, 'doruceno' => $odeslano]);

        $druhyJmeno = Str::before(trim((string) $druhy->name), ' ');

        return response()->json([
            'ok' => true,
            'doruceno' => $odeslano,
            'zprava' => $odeslano > 0
                ? 'Připomínka odešla do telefonu — '.$druhyJmeno
                : $druhyJmeno.' nemá zapnutá upozornění — připomínku uvidí až v aplikaci',
        ]);
    }
}
