<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Services\Obsah\PoskytovatelObsahu;
use App\Services\Obsah\PrazdneKolekce;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Obsah, který prototyp kreslí — ze skutečné databáze.
 *
 * Prototyp čte `window.GalerieData` synchronně při vykreslení, takže na server
 * čekat neumí. Data se proto stahují po skupinách a **přimíchávají** do už
 * existujícího objektu; do té doby (a když skupina nedorazí) zůstávají ukázková.
 * Prázdná obrazovka a rozbitá aplikace vypadají z pohledu člověka stejně.
 *
 * Po skupinách, ne jednou velkou odpovědí: obrazovka financí nemá čekat na to,
 * až se spočítá kuchařka.
 */
class DataController extends Controller
{
    use UrcujePar;

    /**
     * Skupiny, jejichž obsah závisí na odemčeném trezoru nebo zámku.
     *
     * Ty se do paměti prohlížeče **neukládají vůbec** (`no-store`). `Vary`
     * by tu nepomohl: po zamčení trezoru jde tentýž požadavek se stejnými
     * hlavičkami i sezením, a prohlížeč by ještě třicet sekund vracel obsah
     * trezoru — položky, názvy, počty v koši.
     */
    private const BEZ_PAMETI = ['system', 'knihovna', 'pravidla', 'pribeh'];

    /**
     * Ostatní skupiny: uložit smí, vydat bez ověření u serveru ne (`no-cache`).
     *
     * Bylo tu `max-age=30`. Prohlížeč ale klíčuje mezipaměť jen adresou —
     * token v `Authorization` ani sezení v `Cookie` v klíči nejsou — takže po
     * přepnutí účtu na témže zařízení by druhý člověk půl minuty viděl obsah
     * prvního, i jeho soukromé rozpočty.
     *
     * `Vary: Authorization` tohle řeší a skutečně se posílá — ne odsud, ale
     * z globálního `SecurityHeaders` (`bootstrap/app.php`, `$middleware->append()`),
     * který ho k `Vary` přidá (nenahradí) u každé `private` odpovědi na
     * `api/*`. Middleware Inertie (skupina `web`, do které routy Galerie patří)
     * si sem nastaví `Vary: X-Inertia` — `SecurityHeaders` běží jako nejvíc
     * vnější middleware, takže se spustí až po něm a `Authorization` přidá
     * vedle, ne místo něj. `Cookie` se do `Vary` nepřidává, protože Laravel ji
     * šifruje při každé odpovědi jinak — nezasáhla by mezipaměť nikdy.
     * Načtení stránky (`/api/data?skupiny=…`) chodí s `no-cache` stejně, takže
     * se tím skoro nic nezdrží.
     */
    private function sPameti(JsonResponse $odpoved, array $skupiny): JsonResponse
    {
        return $odpoved->header('Cache-Control', array_intersect($skupiny, self::BEZ_PAMETI) !== []
            ? 'private, no-store'
            : 'private, no-cache');
    }

    /** @param  iterable<PoskytovatelObsahu>  $poskytovatele */
    public function __construct(private readonly iterable $poskytovatele) {}

    public function __invoke(Request $request, string $skupina): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));
        $odpoved = $this->sestav($skupina, $prostor);

        abort_if($odpoved === null, 404, 'Takovou skupinu obsahu server nezná.');

        return $this->sPameti(response()->json($odpoved), [$skupina]);
    }

    /**
     * Víc skupin jednou odpovědí: `GET /api/data?skupiny=knihovna,system`.
     *
     * Dvacet samostatných požadavků na každé načtení stránky je dvacet startů
     * Laravelu — a firewall serveru blokuje adresu po sto dvaceti požadavcích
     * za minutu. Dvojice sedí za jednou domácí adresou, takže pár obnovení
     * stránky od obou stačilo. Neznámé jméno se přeskočí, ať překlep v jedné
     * skupině nevezme ostatní.
     */
    public function davka(Request $request): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        $jmena = array_values(array_unique(array_filter(
            explode(',', (string) $request->query('skupiny', '')),
            fn (string $jmeno) => preg_match('/^[a-z]{2,20}$/', $jmeno) === 1,
        )));

        abort_if($jmena === [] || count($jmena) > 30, 422, 'Chybí seznam skupin (?skupiny=knihovna,system).');

        $skupiny = [];

        foreach ($jmena as $jmeno) {
            $odpoved = $this->sestav($jmeno, $prostor);

            if ($odpoved !== null) {
                $skupiny[$jmeno] = $odpoved;
            }
        }

        return $this->sPameti(response()->json(['skupiny' => (object) $skupiny]), $jmena);
    }

    /**
     * Jedna skupina tak, jak ji čeká klient: `{ data, uplne }`, nebo `null`,
     * když ji server nezná.
     *
     * @return array<string, mixed>|null
     */
    private function sestav(string $skupina, GallerySpace $prostor): ?array
    {
        foreach ($this->poskytovatele as $poskytovatel) {
            if ($poskytovatel->skupina() !== $skupina) {
                continue;
            }

            /*
             * Jedna rozbitá kolekce nesmí vzít celou skupinu.
             *
             * `system` staví přes dvacet kolekcí — zámek, úložiště, zdraví
             * dat, trezor, akční inbox. Když jedna z nich hodí výjimku,
             * odpovědí je 500 a klient přijde o všechny; obrazovky pak tiše
             * ukazují ukázková data a nikde není vidět, že se něco stalo.
             * Přesně to se dělo na produkci, kde `/api/data/system` vracelo
             * 500 a lokálně týž kód procházel.
             *
             * Zapíše se to do logu se jménem skupiny, řekne se to i klientovi
             * a skupina se pošle prázdná — což je stav, se kterým prototyp
             * počítá. Ve vývoji se výjimka nechá projít, ať se na ni přijde
             * dřív, než se nasadí.
             */
            try {
                $data = $poskytovatel->kolekce($prostor);
            } catch (\Throwable $e) {
                if (config('app.debug')) {
                    throw $e;
                }

                Log::error("Skupina obsahu „{$skupina}\" se nepodařila sestavit", [
                    'vyjimka' => $e::class,
                    'zprava' => $e->getMessage(),
                    'kde' => $e->getFile().':'.$e->getLine(),
                    'prostor' => $prostor->id,
                ]);

                return [
                    'data' => (object) [],
                    'uplne' => [],
                    'chyba' => 'Skupinu se nepodařilo sestavit — podrobnosti jsou v logu serveru.',
                ];
            }

            // Co poskytovatel neposlal, dostane klient prázdné — ne ukázku z prototypu.
            [$data, $uplne] = PrazdneKolekce::doplnit($poskytovatel, $data);

            // Klient přepisuje klíče a nemaže je; u kolekcí, které server dodává
            // celé, by mu tak vedle skutečných dat zůstala ukázka.
            $uplne = array_values(array_filter($uplne, fn (string $klic) => array_key_exists($klic, $data)));

            // Prázdná skupina jako objekt, ne `[]` — klient čte klíče.
            return ['data' => $data === [] ? (object) [] : $data, 'uplne' => $uplne];
        }

        return null;
    }
}
