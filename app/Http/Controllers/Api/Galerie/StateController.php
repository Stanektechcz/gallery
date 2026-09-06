<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Models\CoupleState;
use App\Models\GallerySpace;
use App\Services\Provoz\AdminVeStavu;
use App\Services\Provoz\DomacnostVeStavu;
use App\Services\Provoz\PlanovaniVeStavu;
use App\Services\Provoz\TrezorVeStavu;
use App\Services\Provoz\UklidVeStavu;
use App\Services\Provoz\VztahVeStavu;
use App\Services\Provoz\ZdraviVeStavu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StateController extends Controller
{
    use UrcujePar;

    public function __construct(
        private readonly AdminVeStavu $sprava,
        private readonly DomacnostVeStavu $domacnost,
        private readonly VztahVeStavu $vztah,
        private readonly ZdraviVeStavu $zdravi,
        private readonly TrezorVeStavu $trezor,
        private readonly PlanovaniVeStavu $planovani,
        private readonly UklidVeStavu $uklid,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $state = CoupleState::forCouple($this->parId($request));

        return response()->json([
            'data' => $state->toClientObject(),
            'updated_at' => $state->updated_at?->toIso8601String(),
            'rev' => $state->rev,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'data' => ['required', 'array'],
            'rev' => ['nullable', 'integer', 'min:0'],
        ]);

        $coupleId = $this->parId($request);
        $uzivatel = $request->user();

        return DB::transaction(function () use ($validated, $coupleId, $uzivatel) {
            $state = CoupleState::where('couple_id', $coupleId)->lockForUpdate()->first()
                ?? CoupleState::forCouple($coupleId);

            // Konflikt: klient staví na starší verzi. Vrátíme aktuální stav,
            // klient ho přijme a překreslí — patch se neaplikuje.
            $clientRev = $validated['rev'] ?? null;
            if ($clientRev !== null && $clientRev < $state->rev) {
                return response()->json([
                    'data' => $state->toClientObject(),
                    'updated_at' => $state->updated_at?->toIso8601String(),
                    'rev' => $state->rev,
                    'conflict' => true,
                ], 409);
            }

            $patch = $validated['data'];

            /*
             * Administrace přichází touhle cestou, ne přes `/api/admin`.
             *
             * Prototyp ji celou drží v komponentě a tlačítka jen mění stav — ten
             * se pak uloží jako všechno ostatní. Kdyby se uložil tak, jak přišel,
             * obrazovka by od prvního kliknutí ukazovala něco, co v databázi
             * neplatí. Záměr se proto provede a odpověď nese skutečnost.
             */
            // Pozůstatek po době, kdy se administrace do stavu ukládala. Zahodí se
            // při prvním zápisu, ať tam nezastarává a starší klient z ní nekreslí.
            $state->zapomen(AdminVeStavu::SERVEROVE);

            $skutecnost = [];

            if ($this->sprava->tykaSe($patch)) {
                $skutecnost = $this->sprava->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->sprava->bezSpravy($patch);
            }

            /*
             * Domácnost totéž, jen z druhé strany.
             *
             * Dělba práce, lhůty a byt mají vlastní tabulky; kdyby se druhá
             * kopie držela i ve stavu, po prvním kliknutí by se rozešly. Zápis
             * se provede a klíče se ze stavu vyhodí — při dalším načtení si je
             * prototyp vezme z `/api/data/domacnost`.
             */
            if ($this->domacnost->tykaSe($patch)) {
                $this->domacnost->zpracuj($patch, GallerySpace::findOrFail($coupleId));
                $patch = $this->domacnost->bezDomacnosti($patch);
                $state->zapomen(DomacnostVeStavu::SERVEROVE);
            }

            /*
             * A mechanismy vztahu z téhož důvodu.
             *
             * Paměť rozhodnutí, rozvaha před nákupem, protokol nesouhlasu
             * a veto banka nemají v aplikaci jiného vlastníka — tabulka bez
             * zápisu by znamenala jen druhou pravdu.
             */
            if ($this->vztah->tykaSe($patch)) {
                $this->vztah->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->vztah->bezVztahu($patch);
                $state->zapomen(VztahVeStavu::SERVEROVE);
            }

            /*
             * Cyklus a nálada.
             *
             * Kalendář cyklu má vlastní modul i tabulku; tohle jen zajišťuje,
             * že zápis z prototypu skončí tam, kde se na něj ptá zbytek
             * aplikace. Jinak by dvojice měla dva kalendáře — jeden
             * v prohlížeči a druhý v databázi.
             */
            if ($this->zdravi->tykaSe($patch)) {
                $this->zdravi->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->zdravi->bezZdravi($patch);
                $state->zapomen(ZdraviVeStavu::SERVEROVE);
            }

            /*
             * Trezor.
             *
             * „Dát do trezoru" má fotku schovat doopravdy, ne jen v prohlížeči
             * toho, kdo klikl — druhý z dvojice by ji jinak dál viděl v mřížce.
             */
            if ($this->trezor->tykaSe($patch)) {
                $this->trezor->zpracuj($patch, GallerySpace::findOrFail($coupleId));
                $patch = $this->trezor->bezTrezoru($patch);
            }

            /*
             * Kalendář a úkoly.
             *
             * Tyhle tabulky **vlastní aplikace sama** — ptají se na ně
             * připomínky, automatizace i cesty. Bez téhle vrstvy má dvojice
             * dva kalendáře: jeden v prohlížeči a druhý v databázi, který
             * o jejích změnách neví.
             */
            if ($this->planovani->tykaSe($patch)) {
                $this->planovani->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->planovani->bezPlanovani($patch);
                $state->zapomen(PlanovaniVeStavu::SERVEROVE);
            }

            /*
             * Úklid knihovny.
             *
             * „Pustit" a „Sloučit" měnily jen prohlížeč toho, kdo klikl —
             * originál ležel na disku dál a druhý z dvojice viděl karanténu
             * nedotčenou. Klíče ve stavu zůstávají (drží tlačítko Zpět), ale
             * rozhodnutí se provede v knihovně.
             */
            if ($this->uklid->tykaSe($patch)) {
                $patch = $this->uklid->zpracuj($patch, GallerySpace::findOrFail($coupleId));
            }

            $state->applyPatch($patch);

            return response()->json([
                // Skutečnost se vrací, ale **neukládá**: administrace má jediný
                // zdroj pravdy, a to `/api/admin`. Druhá kopie ve stavu by se
                // dřív nebo později rozešla s tou první.
                'data' => (object) array_merge((array) $state->toClientObject(), $skutecnost),
                'updated_at' => $state->updated_at?->toIso8601String(),
                'rev' => $state->rev,
            ]);
        });
    }

    public function destroy(Request $request): JsonResponse
    {
        $state = CoupleState::forCouple($this->parId($request));
        $state->update(['data' => [], 'private' => [], 'rev' => 0]);

        return response()->json(['data' => (object) [], 'rev' => 0]);
    }
}
