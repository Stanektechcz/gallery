<?php

namespace App\Http\Controllers\Galerie;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\SharedLink;
use App\Support\SpaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vzkaz od hosta u sdíleného odkazu.
 *
 * Tabulka `guest_comments` v aplikaci existovala a obrazovka z ní četla — jenže
 * **nikde se do ní nezapisovalo**. Babička, které dvojice pošle odkaz na fotky,
 * neměla jak nechat vzkaz; co se na obrazovce ukazovalo, mohlo vzniknout jedině
 * ručně v databázi.
 *
 * Je to jediná cesta v celé galerii, která přijímá zápis **bez přihlášení**.
 * Proto platí tři věci:
 *
 *  - píše se jen tam, kde to dvojice povolila (`allow_comments`),
 *  - odkaz musí platit — vypršelý ani chráněný heslem bez ověření nepustí,
 *  - text se ukládá tak, jak přišel, a zobrazuje se jako text. Nikde se
 *    nevkládá do stránky jako HTML.
 *
 * Přepisovat hlasovku aplikace neumí, a tak to ani nepředstírá: uloží se
 * nahrávka a délka, `body` zůstane prázdné.
 */
class HostKomentarController extends Controller
{
    /** Nahrávka delší než tohle už není vzkaz u fotky. */
    private const NEJVIC_VTERIN = 300;

    private const NEJVIC_BAJTU = 10 * 1024 * 1024;

    public function __invoke(Request $request, string $token): JsonResponse
    {
        $odkaz = SharedLink::where('token', $token)->first();

        if ($odkaz === null || ! $odkaz->isAccessible()) {
            return response()->json(['ok' => false, 'zprava' => 'Tenhle odkaz už neplatí.'], 404);
        }

        if (! $odkaz->allow_comments) {
            return response()->json(['ok' => false, 'zprava' => 'U tohohle odkazu nejsou vzkazy zapnuté.'], 403);
        }

        // Odkaz chráněný heslem pustí dál až po ověření — jinak by se dalo
        // komentovat to, co si člověk nesmí ani prohlédnout.
        if ($odkaz->password_hash && ! $request->session()->get("share_verified_{$token}")) {
            return response()->json(['ok' => false, 'zprava' => 'Nejdřív heslo k odkazu.'], 403);
        }

        $data = $request->validate([
            'jmeno' => ['required', 'string', 'max:80'],
            'text' => ['nullable', 'string', 'max:2000'],
            'fotka' => ['nullable', 'string', 'max:64'],
            'vterin' => ['nullable', 'integer', 'min:1', 'max:'.self::NEJVIC_VTERIN],
            'nahravka' => ['nullable', 'file', 'mimetypes:audio/webm,audio/ogg,audio/mpeg,audio/mp4,audio/wav', 'max:'.(self::NEJVIC_BAJTU / 1024)],
        ]);

        $nahravka = $request->file('nahravka');
        $text = trim((string) ($data['text'] ?? ''));

        if ($nahravka === null && $text === '') {
            return response()->json(['ok' => false, 'zprava' => 'Napište vzkaz, nebo nahrajte hlas.'], 422);
        }

        $cesta = null;
        $bajtu = null;

        if ($nahravka !== null) {
            $cesta = $nahravka->store('hlasovky/'.$odkaz->gallery_space_id, 'public');
            $bajtu = $nahravka->getSize();
        }

        DB::table('guest_comments')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $odkaz->gallery_space_id,
            'shared_link_id' => $odkaz->id,
            'media_item_id' => $this->fotka($odkaz, $data['fotka'] ?? null),
            'guest_name' => trim($data['jmeno']),
            // Hlasovka nemá přepis — aplikace řeč na text nepřevádí a tvrdit
            // opak by znamenalo vymyslet, co babička řekla.
            'body' => $nahravka !== null ? null : $text,
            'kind' => $nahravka !== null ? 'voice' : 'text',
            'duration' => $nahravka !== null ? $this->delka((int) ($data['vterin'] ?? 0)) : null,
            'audio_path' => $cesta,
            'audio_bytes' => $bajtu,
            'is_hidden' => false,
            'is_pinned' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'zprava' => $nahravka !== null
                ? 'Hlas nahrán — dvojice si ho pustí u fotek.'
                : 'Vzkaz odeslán.',
        ], 201);
    }

    /**
     * Fotka, ke které vzkaz patří.
     *
     * Musí být z téhož prostoru; cizí uuid se tiše ignoruje, aby se vzkazem
     * nešlo ukázat na fotku, kterou host nikdy neviděl.
     */
    private function fotka(SharedLink $odkaz, ?string $uuid): ?int
    {
        if (! $uuid) {
            return null;
        }

        return MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $odkaz->gallery_space_id)
            ->where('uuid', $uuid)
            ->value('id');
    }

    /** „1:24" — tak to čte obrazovka. */
    private function delka(int $vterin): string
    {
        $vterin = max(1, min(self::NEJVIC_VTERIN, $vterin));

        return floor($vterin / 60).':'.str_pad((string) ($vterin % 60), 2, '0', STR_PAD_LEFT);
    }
}
