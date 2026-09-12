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
    /**
     * Sbírky, které v souboru popisují život ukázkové dvojice.
     *
     * `mechanismy.json` je export prototypu: vedle katalogů (skupiny záložek,
     * druhy arbitrů, části odchodového balíčku) nese i „data" — kdo spal pět
     * a půl hodiny, jaký postoj mají Adrian a Makinka k dětem, zdravotní stav
     * jejich rodičů, jmenovitě kdo z jejich okolí se o ně bojí. Endpoint to
     * posílal každé přihlášené dvojici jako její vlastní.
     *
     * Aplikace pro tyhle mechanismy tabulky nemá; co v nich dvojice zapíše,
     * drží společný stav. Ze serveru proto chodí prázdné — ve stejném tvaru,
     * aby obrazovky měly co číst. Katalogy zůstávají, jak jsou.
     */
    private const PRAZDNE = [
        'DAY_LOAD' => [],
        'DAY_HIST' => [],
        'BLIZ' => ['weeks' => [], 'init' => [], 'no' => [], 'block' => []],
        'SOLO_MONEY' => [],
        'SOLO_COST' => ['rent' => 0, 'life' => 0, 'alone' => 0],
        'KIDS' => ['pos' => [], 'blockers' => [], 'talks' => []],
        'PARENTS' => [],
        'ARB_ROWS' => [],
        'SURP' => ['pct' => 3, 'spendYear' => 0, 'draws' => []],
        'VERS' => [],
        'SVED' => [],
        'FIGHT_START' => [],
        'QUART' => [],
        'INDEP' => [],
        'TRUST' => [],
        'EXPIRE' => [],
    ];

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
        // `v2`: od verze se sbírkami bez ukázky. Klíč jen podle souboru by po
        // nasazení dál vydával v cache uloženou ukázku, dokud se soubor nezmění.
        $klic = 'galerie:mechanismy:v2:'.File::lastModified($cesta);

        $ulozene = Cache::remember($klic, now()->addDay(), function () use ($cesta) {
            $obsah = File::get($cesta);
            $data = json_decode($obsah, true);

            if (! is_array($data)) {
                return null;
            }

            $data = self::bezUkazky($data);

            // Otisk z toho, co opravdu odchází — po vyprázdnění sbírek jiný
            // než ze souboru, takže prohlížeč se starou kopií dostane novou.
            return ['data' => $data, 'rev' => substr(hash('sha256', (string) json_encode($data)), 0, 12)];
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

    /**
     * Katalogy ze souboru, sbírky prázdné.
     *
     * U odchodového balíčku se nechávají popisy částí, ale ne velikosti
     * „2 400 MB plateb" a „41 200 MB fotek" — ty patřily ukázce; velikost
     * spočítá obrazovka z toho, co dvojice má.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function bezUkazky(array $data): array
    {
        foreach (self::PRAZDNE as $klic => $prazdne) {
            if (array_key_exists($klic, $data)) {
                $data[$klic] = self::tvar($prazdne);
            }
        }

        if (is_array($data['EXIT_PACK'] ?? null)) {
            $data['EXIT_PACK'] = array_map(
                fn (array $cast) => in_array($cast['key'] ?? null, ['money', 'photo'], true) ? ['mb' => 0] + $cast : $cast,
                $data['EXIT_PACK'],
            );
        }

        return $data;
    }

    /**
     * Prázdné mapy (`init`, `no` — kdo kolikrát navrhl) musí odejít jako `{}`.
     *
     * @param  array<string, mixed>  $prazdne
     * @return array<string, mixed>
     */
    private static function tvar(array $prazdne): array
    {
        foreach (['init', 'no'] as $mapa) {
            if (array_key_exists($mapa, $prazdne)) {
                $prazdne[$mapa] = new \stdClass;
            }
        }

        return $prazdne;
    }
}
