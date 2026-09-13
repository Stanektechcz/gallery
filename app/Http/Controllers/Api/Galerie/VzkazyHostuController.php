<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Services\Obsah\Pribeh;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Moderace vzkazů od hostů.
 *
 * „Skrýt", „Smazat" a „Přilepit jako popisek" měnily jen seznam ve stavu
 * prohlížeče. Hláška „Vzkaz skryt — host ho už nevidí" přitom nebyla pravda:
 * sdílená stránka čte `guest_comments` a skrytý vzkaz dál ukazovala každému,
 * kdo měl odkaz. Smazaný vzkaz se po načtení vrátil a popisek u fotky
 * nevznikl nikde.
 */
class VzkazyHostuController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    public function __construct(private readonly Pribeh $obsah) {}

    /** Skrýt nebo zase zveřejnit — host skrytý vzkaz na odkazu neuvidí. */
    public function update(Request $request, string $vzkaz): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $radek = $this->najdi($prostor, $vzkaz);
        $data = $request->validate(['skryty' => ['required', 'boolean']]);

        DB::table('guest_comments')->where('id', $radek->id)->update([
            'is_hidden' => $data['skryty'],
            'updated_at' => now(),
        ]);

        AuditLog::record($data['skryty'] ? 'guest_comment.hide' : 'guest_comment.show', null, ['uuid' => $radek->uuid]);

        return $this->odpoved($prostor, $data['skryty'] ? 'Vzkaz skryt — host ho na odkazu už nevidí' : 'Vzkaz je zase vidět');
    }

    /**
     * Text vzkazu jako popisek fotky, ke které host psal.
     *
     * Hlasovka přepis nemá (aplikace řeč na text nepřevádí) a vzkaz bez fotky
     * nemá kam se přilepit — obojí se odmítne, místo aby vznikl prázdný popisek.
     * Odlepení popisek smaže jen tehdy, když u fotky pořád stojí text vzkazu;
     * mezitím přepsaný popisek zůstává.
     */
    public function prilep(Request $request, string $vzkaz): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $radek = $this->najdi($prostor, $vzkaz);
        $prilepit = $request->boolean('prilepit', true);
        $text = trim((string) $radek->body);

        $fotka = $radek->media_item_id
            ? DB::table('media_items')->where('id', $radek->media_item_id)->where('gallery_space_id', $prostor->id)->first(['id', 'caption'])
            : null;

        if ($prilepit && ($text === '' || $fotka === null)) {
            return response()->json([
                'ok' => false,
                'zprava' => $text === '' ? 'Hlasovka nemá přepis — jako popisek ji přilepit nejde.' : 'Vzkaz nepatří k žádné fotce.',
            ], 422);
        }

        DB::transaction(function () use ($radek, $fotka, $prilepit, $text) {
            if ($fotka !== null && $text !== '') {
                if ($prilepit) {
                    DB::table('media_items')->where('id', $fotka->id)->update(['caption' => mb_substr($text, 0, 2000), 'updated_at' => now()]);
                } elseif (trim((string) $fotka->caption) === mb_substr($text, 0, 2000)) {
                    DB::table('media_items')->where('id', $fotka->id)->update(['caption' => null, 'updated_at' => now()]);
                }
            }

            DB::table('guest_comments')->where('id', $radek->id)->update(['is_pinned' => $prilepit, 'updated_at' => now()]);
        });

        AuditLog::record($prilepit ? 'guest_comment.pin' : 'guest_comment.unpin', null, ['uuid' => $radek->uuid]);

        return $this->odpoved($prostor, $prilepit ? 'Vzkaz přilepen jako popisek fotky' : 'Popisek odlepen');
    }

    /** Smazat natrvalo — i s nahrávkou hlasovky. */
    public function destroy(Request $request, string $vzkaz): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $radek = $this->najdi($prostor, $vzkaz);

        DB::table('guest_comments')->where('id', $radek->id)->delete();

        if ($radek->audio_path) {
            Storage::disk('public')->delete($radek->audio_path);
        }

        AuditLog::record('guest_comment.delete', null, ['uuid' => $radek->uuid, 'host' => $radek->guest_name]);

        return $this->odpoved($prostor, 'Vzkaz smazán');
    }

    /** Vzkaz jen z vlastního prostoru; cizí se tváří jako neexistující. */
    private function najdi(GallerySpace $prostor, string $uuid): object
    {
        $radek = DB::table('guest_comments')
            ->where('uuid', $uuid)
            ->where('gallery_space_id', $prostor->id)
            ->first();

        abort_if($radek === null, 404, 'Vzkaz už neexistuje.');

        return $radek;
    }

    private function odpoved(GallerySpace $prostor, string $zprava): JsonResponse
    {
        return response()->json(['ok' => true, 'zprava' => $zprava] + $this->obsahPoAkci($this->obsah, $prostor));
    }
}
