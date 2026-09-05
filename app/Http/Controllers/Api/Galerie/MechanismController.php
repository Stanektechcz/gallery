<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Definice mechanismů pro dva.
 *
 * Klient je má staticky v `galerie-mechanismy.js`; tenhle endpoint vrací tatáž
 * data pod týmiž klíči, aby se po nasazení nemusel měnit žádný kód na klientovi —
 * jen zdroj. Soubor se generuje z prototypu (`node tools/export-seeds.js`).
 */
class MechanismController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cesta = config('galerie.mechanisms_path');

        if (! $cesta || ! File::exists($cesta)) {
            /*
             * Radši 503 než prázdné pole.
             *
             * Klient si při chybě nechá vlastní data ze souboru; kdyby dostal
             * `{"data": {}}`, přepsal by je prázdnem a v aplikaci by zmizely
             * všechny mechanismy najednou.
             */
            return response()->json([
                'message' => 'Data mechanismů nejsou vygenerovaná — spusťte `node tools/export-seeds.js` (viz docs/galerie-implementace.md).',
            ], 503);
        }

        /*
         * Soubor má 26 kB a mění se jen s novou verzí aplikace. Číst a parsovat
         * ho při každém načtení stránky je zbytečná práce — proto cache podle
         * času změny souboru: po nasazení nového se klíč sám změní.
         */
        $klic = 'galerie:mechanismy:'.File::lastModified($cesta);

        $ulozene = Cache::remember($klic, now()->addDay(), function () use ($cesta) {
            $obsah = File::get($cesta);
            $data = json_decode($obsah, true);

            return is_array($data)
                ? ['data' => $data, 'rev' => substr(hash('sha256', $obsah), 0, 12)]
                : null;
        });

        if ($ulozene === null) {
            Cache::forget($klic);

            return response()->json(['message' => 'Data mechanismů jsou poškozená.'], 500);
        }

        // Podruhé už se nepřenáší nic — prohlížeč pošle If-None-Match a dostane 304.
        $etag = '"'.$ulozene['rev'].'"';

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response()->json(null, 304);
        }

        return response()->json($ulozene)
            // `private`, ne `public`: odpověď chodí přes přihlášený kanál a do
            // sdílené proxy nepatří, i když jsou data pro všechny stejná.
            ->header('Cache-Control', 'private, max-age=3600')
            ->header('ETag', $etag);
    }
}
