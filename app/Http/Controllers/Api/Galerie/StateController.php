<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Models\CoupleState;
use App\Models\GallerySpace;
use App\Services\Provoz\AdminVeStavu;
use App\Services\Provoz\DarkyVeStavu;
use App\Services\Provoz\DomacnostVeStavu;
use App\Services\Provoz\KapsleVeStavu;
use App\Services\Provoz\KlidVeStavu;
use App\Services\Provoz\NastaveniVeStavu;
use App\Services\Provoz\PlanovaniVeStavu;
use App\Services\Provoz\PravidlaVeStavu;
use App\Services\Provoz\PribehVeStavu;
use App\Services\Provoz\RozboryVeStavu;
use App\Services\Provoz\TrezorVeStavu;
use App\Services\Provoz\UklidVeStavu;
use App\Services\Provoz\VztahVeStavu;
use App\Services\Provoz\ZdraviVeStavu;
use App\Services\Provoz\ZpravyVeStavu;
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
        private readonly PravidlaVeStavu $pravidla,
        private readonly RozboryVeStavu $rozbory,
        private readonly ZpravyVeStavu $zpravy,
        private readonly DarkyVeStavu $darky,
        private readonly KapsleVeStavu $kapsle,
        private readonly NastaveniVeStavu $nastaveni,
        private readonly KlidVeStavu $klid,
        private readonly PribehVeStavu $pribeh,
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

            /*
             * Automatizace.
             *
             * `rulesAll()` v prototypu vrací `state.rules || RULEDEF`, takže
             * jedno přepnutí vypínače navždy zastínilo skutečná pravidla:
             * obrazovka od té chvíle kreslila kopii z prohlížeče, nová
             * pravidla nikde nedoběhla a ta skutečná z ní zmizela.
             */
            if ($this->pravidla->tykaSe($patch)) {
                $skutecnost += $this->pravidla->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->pravidla->bezPravidel($patch);
                $state->zapomen(PravidlaVeStavu::SERVEROVE);
            }

            /*
             * Sezónní fondy.
             *
             * `seasonVals()` čte `state.season || SEASON`, takže po prvním
             * kliknutí přestal platit `budget_goals` a začala platit kopie —
             * a v Rozpočtech pak stál jiný stav fondu než v jeho vlastní
             * obrazovce.
             */
            if ($this->rozbory->tykaSe($patch)) {
                $skutecnost += $this->rozbory->zpracuj($patch, GallerySpace::findOrFail($coupleId));
                $patch = $this->rozbory->bezRozboru($patch);
                $state->zapomen(RozboryVeStavu::SERVEROVE);
            }

            /*
             * Zprávy.
             *
             * „Zpráva odeslána," řekl prototyp — a nikam ji neodeslal. Bublina
             * se objevila v prohlížeči odesílatele, druhý z dvojice o ní
             * nevěděl a po zavření záložky zmizela.
             */
            if ($this->zpravy->tykaSe($patch)) {
                $skutecnost += $this->zpravy->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->zpravy->bezZprav($patch);
                $state->zapomen(ZpravyVeStavu::SERVEROVE);
            }

            /*
             * Dárky a přání.
             *
             * Táž věc: `state.wishes || GIFT_WISHES`. První napsané přání
             * zastínilo celou sekci — druhý o něm nevěděl a po zavření
             * záložky zmizelo.
             */
            if ($this->darky->tykaSe($patch)) {
                $skutecnost += $this->darky->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->darky->bezDarku($patch);
                $state->zapomen(DarkyVeStavu::SERVEROVE);
            }

            /*
             * Časové kapsle.
             *
             * Dopis, který má přijít za rok, je přesně ta věc, u které na
             * uložení záleží nejvíc: mezitím se vymění telefon, a s ním celá
             * lokální kopie.
             */
            if ($this->kapsle->tykaSe($patch)) {
                $skutecnost += $this->kapsle->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->kapsle->bezKapsli($patch);
                $state->zapomen(KapsleVeStavu::SERVEROVE);
            }

            /*
             * Přepínače nastavení.
             *
             * „Sync každé čtyři hodiny" i „upozornit na duplicitní transakci"
             * měnily jen barvu v prohlížeči toho, kdo klikl — napojení se
             * dál synchronizovalo podle toho, co bylo v databázi.
             */
            if ($this->nastaveni->tykaSe($patch)) {
                $skutecnost += $this->nastaveni->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->nastaveni->bezNastaveni($patch);
                $state->zapomen(NastaveniVeStavu::SERVEROVE);
            }

            /*
             * Klid a pohoda.
             *
             * Mapa energie je celá o tom najít okno, kdy mají sílu **oba** —
             * a klepnutí do ní končilo v prohlížeči toho, kdo klikl. Druhý se
             * k ní nedostal, takže se to okno nedalo najít nikdy.
             */
            if ($this->klid->tykaSe($patch)) {
                $skutecnost += $this->klid->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->klid->bezKlidu($patch);
                $state->zapomen(KlidVeStavu::SERVEROVE);
            }

            /*
             * Příběh, nouzový přístup a papírová záloha.
             *
             * „Soukromé zápisy v deníku se nouzově neodemknou" je rozhodnutí,
             * které musí platit i na druhém zařízení — ne jen v prohlížeči
             * toho, kdo přepínač vypnul.
             */
            if ($this->pribeh->tykaSe($patch)) {
                $skutecnost += $this->pribeh->zpracuj($patch, GallerySpace::findOrFail($coupleId), $uzivatel);
                $patch = $this->pribeh->bezPribehu($patch);
                $state->zapomen(PribehVeStavu::SERVEROVE);
            }

            $state->applyPatch($patch);

            return response()->json([
                // Skutečnost se vrací, ale **neukládá**: administrace má jediný
                // zdroj pravdy, a to `/api/admin`. Druhá kopie ve stavu by se
                // dřív nebo později rozešla s tou první.
                'data' => (object) array_merge((array) $state->toClientObject(), $skutecnost),
                /*
                 * A klient si ji nemá ukládat ani k sobě.
                 *
                 * Do lokální kopie patří jen to, co server uložil. Uložená
                 * skutečnost by se při dalším spuštění postavila před data
                 * ze serveru — tedy přesně to, čemu se tahle vrstva vyhýbá.
                 */
                'docasne' => array_keys($skutecnost),
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
