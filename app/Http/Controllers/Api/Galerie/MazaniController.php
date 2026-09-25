<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Notifications\GalleryNotification;
use App\Services\Media\MazaniFotek;
use App\Services\Media\VysledekMazani;
use App\Services\Obsah\System;
use App\Support\SpaceContext;
use App\Support\Trezor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Mazání fotek z prototypu — po společném schválení.
 *
 * Dvojice se dohodla: „Mazat fotky mohou jen po společném schválení pokud si
 * po vzájemném schválení nenastaví jinak." Pravidlo drží `MazaniFotek`;
 * tady se jen převádí na odpovědi pro obrazovku.
 *
 * „Do koše" (`DELETE media/{uuid}`, `POST media/do-kose`) ve společném režimu
 * fotku jen navrhne. V `ids` jsou proto **jen fotky, které opravdu odešly do
 * koše** — podle nich je klient odebere z knihovny; navržené zůstávají
 * v knihovně s označením a chodí zvlášť v `navrzeno`.
 *
 * Fotky z trezoru se zamčeným trezorem služba přeskočí a odpověď o nich
 * **mlčí úplně** — ani uuid, ani počet. Jinak by se dalo ptát „je tohle
 * v trezoru?" a z odpovědi to poznat.
 *
 * Odmítnutí (host 403, vlastní návrh 403, nic k dohodě 409, špatný kód zámku
 * 422/429) vrací služba výjimkou a tady se nechávají propadnout.
 */
class MazaniController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    public function __construct(
        private readonly MazaniFotek $mazani,
        private readonly System $obsah,
    ) {}

    /**
     * Jedna položka z prohlížeče fotky.
     *
     * Cizí nebo neexistující je 404 (globální rozsah prostoru), položka
     * z jiného mého prostoru 403 — stejně jako ostatní akce nad fotkou.
     * Skrytá se zamčeným trezorem a položka už v koši dostanou totéž 404
     * jako neexistující: druhé kliknutí na hotovou věc nic neprozradí.
     */
    public function smazat(Request $request, string $uuid): JsonResponse
    {
        $prostor = $this->prostor($request);
        $trezor = Trezor::odemcen($request);
        $media = MediaItem::where('uuid', $uuid)->first();

        abort_if($media === null, 404, 'Takový soubor tu není.');
        abort_unless((int) $media->gallery_space_id === (int) $prostor->id, 403);
        abort_if($media->is_hidden && ! $trezor, 404, 'Takový soubor tu není.');

        $vysledek = $this->mazani->doKose($prostor, $request->user(), [$media->uuid], 'detail', $trezor);
        $stav = $this->stavDoKose($vysledek);

        abort_if($stav === 'skipped', 404, 'Takový soubor tu není.');

        $this->oznamNavrh($prostor, $request->user(), $vysledek);

        return response()->json([
            'id' => $media->uuid,
            'status' => $stav,
            'zprava' => $this->zpravaDoKose($vysledek, $prostor, $request->user()),
            'rezim' => $this->mazani->rezim($prostor),
        ]);
    }

    /**
     * Víc položek jedním požadavkem (hromadné „Do koše", série, duplicity).
     *
     * Po jednom `DELETE` na položku by stovka vybraných fotek vyčerpala limit
     * API a dávka požadavků najednou už jednou spustila WAF. Cizí,
     * neexistující a už vyhozené identifikátory se tiše přeskočí.
     */
    public function doKose(Request $request): JsonResponse
    {
        $ids = $this->ids($request);
        $prostor = $this->prostor($request);

        $vysledek = $this->mazani->doKose($prostor, $request->user(), $ids, 'knihovna', Trezor::odemcen($request));
        $this->oznamNavrh($prostor, $request->user(), $vysledek);

        return response()->json([
            'ids' => $vysledek->vKosi(),
            'navrzeno' => $vysledek->navrzeno,
            'uzNavrzeno' => $vysledek->uzNavrzeno,
            'status' => $this->stavDoKose($vysledek),
            'zprava' => $this->zpravaDoKose($vysledek, $prostor, $request->user()),
            'rezim' => $this->mazani->rezim($prostor),
        ]);
    }

    /** Souhlas s návrhem druhého — fotky jdou do koše na třicet dní. */
    public function schvalit(Request $request): JsonResponse
    {
        $ids = $this->ids($request);
        $prostor = $this->prostor($request);

        $vysledek = $this->mazani->schval($prostor, $request->user(), $ids, Trezor::odemcen($request));
        $smazano = $vysledek->schvaleno;

        $zprava = match (true) {
            $smazano !== [] => $this->sPoctem('Smazáno po společném schválení', count($smazano)).' · '.$this->lhuta(),
            $vysledek->uzNavrzeno !== [] => 'Vlastní návrh musí potvrdit druhý z vás.',
            default => 'Nic ke schválení — návrh mezitím někdo vyřídil.',
        };

        return response()->json([
            'ok' => $smazano !== [],
            'ids' => $smazano,
            'zprava' => $zprava,
            'rezim' => $this->mazani->rezim($prostor),
        ] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    /**
     * „Ponechat": navrhující návrh stahuje, druhý ho odmítá. Fotka zůstává.
     *
     * Zpráva rozlišuje obojí — „Návrh stažen" u vlastního, „Ponecháno
     * v knihovně" u cizího. Kdo co navrhl, se zjistí předem; výsledek služby
     * to neříká a zpráva se týká jen toho, co se opravdu ponechalo.
     */
    public function ponechat(Request $request): JsonResponse
    {
        $ids = $this->ids($request);
        $prostor = $this->prostor($request);
        $ja = (int) $request->user()->id;

        $moje = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereIn('uuid', $ids)
            ->where('trash_requested_by', $ja)
            ->pluck('uuid')
            ->all();

        $vysledek = $this->mazani->ponechat($prostor, $request->user(), $ids, Trezor::odemcen($request));
        $stazeno = array_values(array_intersect($vysledek->ponechano, $moje));
        $odmitnuto = array_values(array_diff($vysledek->ponechano, $moje));

        $casti = array_filter([
            $odmitnuto === [] ? null : $this->sPoctem('Ponecháno v knihovně', count($odmitnuto)),
            $stazeno === [] ? null : $this->sPoctem('Návrh stažen', count($stazeno)),
        ]);

        return response()->json([
            'ok' => $vysledek->ponechano !== [],
            'ids' => $vysledek->ponechano,
            'zprava' => $casti === [] ? 'Nic se nezměnilo — návrh už nečeká.' : implode(' · ', $casti),
            'rezim' => $this->mazani->rezim($prostor),
        ] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    /**
     * Návrh režimu: `kazdy` čeká na potvrzení druhého, `spolecne` platí hned.
     *
     * Když druhý „každý sám" už navrhl, je tohle potvrzení — a služba chce
     * kód zámku nebo heslo (`kod`/`heslo`).
     */
    public function rezim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rezim' => ['required', 'string', 'in:'.implode(',', MazaniFotek::REZIMY)],
            'kod' => ['nullable', 'string', 'max:255'],
            'heslo' => ['nullable', 'string', 'max:255'],
        ]);
        $prostor = $this->prostor($request);

        $vysledek = $this->mazani->navrhniRezim($prostor, $request->user(), $data['rezim'], $data['kod'] ?? null, $data['heslo'] ?? null);

        return $this->poZmeneRezimu($prostor, $request->user(), $vysledek, $vysledek !== 'beze_zmeny');
    }

    /** Potvrzení návrhu druhého: „každý maže sám". Chce kód zámku nebo heslo. */
    public function potvrditRezim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kod' => ['nullable', 'string', 'max:255'],
            'heslo' => ['nullable', 'string', 'max:255'],
        ]);
        $prostor = $this->prostor($request);

        $vysledek = $this->mazani->potvrdRezim($prostor, $request->user(), $data['kod'] ?? null, $data['heslo'] ?? null);

        return $this->poZmeneRezimu($prostor, $request->user(), $vysledek, true);
    }

    /** Stáhnout (nebo odmítnout) čekající návrh režimu — smí kdokoli z dvojice. */
    public function zrusitRezim(Request $request): JsonResponse
    {
        $prostor = $this->prostor($request);
        $zruseno = $this->mazani->zrusNavrhRezimu($prostor, $request->user());

        return response()->json([
            'ok' => $zruseno,
            'vysledek' => $zruseno ? 'zruseno' : 'beze_zmeny',
            'zprava' => $zruseno ? 'Návrh stažen' : 'Žádný návrh nečekal.',
            'rezim' => $this->mazani->rezim($prostor),
        ] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    // ——— pomocné ———

    private function poZmeneRezimu(GallerySpace $prostor, User $kdo, string $vysledek, bool $ok): JsonResponse
    {
        $stav = $this->mazani->stavRezimu($prostor);
        $partner = $this->partner($prostor, $kdo);

        $zprava = match ($vysledek) {
            'navrzeno' => $partner === null
                ? 'Návrh odeslán — platí, až ho potvrdí druhý z vás'
                : 'Návrh odeslán — platí, až ho '.$partner.' potvrdí',
            'potvrzeno' => 'Každý teď maže sám — potvrdili jste oba',
            'zprisneno' => 'Mazání zase jen po společném schválení',
            'zruseno' => 'Návrh stažen — mazání dál jen po společném schválení',
            default => match (true) {
                $stav['rezim'] === MazaniFotek::KAZDY => 'Každý už maže sám.',
                $stav['navrh'] !== null => 'Návrh už čeká na potvrzení.',
                default => 'Mazání už je jen po společném schválení.',
            },
        };

        return response()->json([
            'ok' => $ok,
            'vysledek' => $vysledek,
            'zprava' => $zprava,
            'rezim' => $stav['rezim'],
        ] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    /**
     * Stav „Do koše" pro klienta.
     *
     * `trashed` — všechno v koši, `proposed` — navrženo (nové návrhy),
     * `uz_navrzeno` — můj návrh už čekal, `mixed` — něco v koši a něco čeká,
     * `skipped` — nic se nestalo (cizí, už v koši, zamčený trezor).
     */
    private function stavDoKose(VysledekMazani $v): string
    {
        $vKosi = $v->vKosi() !== [];
        $ceka = $v->navrzeno !== [] || $v->uzNavrzeno !== [];

        return match (true) {
            $vKosi && $ceka => 'mixed',
            $vKosi => 'trashed',
            $v->navrzeno !== [] => 'proposed',
            $v->uzNavrzeno !== [] => 'uz_navrzeno',
            default => 'skipped',
        };
    }

    /**
     * Hláška pro člověka. `VysledekMazani::zprava()` se nepoužívá schválně:
     * počítá i přeskočené fotky z trezoru, a tím by prozradila, že tam jsou.
     */
    private function zpravaDoKose(VysledekMazani $v, GallerySpace $prostor, User $kdo): string
    {
        $casti = [];
        $vKosi = count($v->vKosi());

        if ($vKosi > 0) {
            $co = $v->schvaleno !== [] ? 'Smazáno po společném schválení' : 'Přesunuto do koše';
            $casti[] = $this->sPoctem($co, $vKosi).' · '.$this->lhuta();
        }

        if ($v->navrzeno !== [] || $v->uzNavrzeno !== []) {
            $partner = $this->partner($prostor, $kdo);
            $ceka = $partner === null ? 'čeká na druhého z vás' : 'čeká, až to potvrdí '.$partner;

            if ($v->navrzeno !== []) {
                $casti[] = $this->sPoctem('Navrženo ke smazání', count($v->navrzeno)).' · '.$ceka;
            }

            if ($v->uzNavrzeno !== []) {
                $casti[] = $this->sPoctem('Návrh už čeká', count($v->uzNavrzeno)).' · '.$ceka;
            }
        }

        return $casti === [] ? 'Nic se nezměnilo.' : implode(' · ', $casti);
    }

    /**
     * Upozornit druhého z dvojice, že na něj čeká návrh — jednou za požadavek.
     *
     * Jen aktivní členové dvojice (host ne — `notifySpace` by psal i jemu),
     * bez jmen souborů a bez fotek z trezoru: když se navrhlo jen z trezoru,
     * neodejde nic, jinak by oznámení prozradilo, že se v něm něco děje.
     * Selhání upozornění nesmí shodit smazání, které už proběhlo.
     */
    private function oznamNavrh(GallerySpace $prostor, User $kdo, VysledekMazani $v): void
    {
        if ($v->navrzeno === []) {
            return;
        }

        try {
            $pocet = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
                ->where('gallery_space_id', $prostor->id)
                ->whereIn('uuid', $v->navrzeno)
                ->where('is_hidden', false)
                ->count();

            $komu = collect(System::ostatniZDvojice($prostor, (int) $kdo->id))
                ->filter(fn (array $clovek) => $clovek['aktivni'])
                ->keys();

            if ($pocet === 0 || $komu->isEmpty()) {
                return;
            }

            $kolik = $pocet.' '.($pocet === 1 ? 'položku' : ($pocet <= 4 ? 'položky' : 'položek'));

            GalleryNotification::notifyUsers(
                $prostor,
                (int) $kdo->id,
                $komu,
                'media.trash_proposed',
                $kdo->name.' navrhuje smazat '.$kolik.' — '.($pocet === 1 ? 'čeká' : 'čekají').' na váš souhlas v koši',
                null,
                ['pocet' => $pocet],
            );
        } catch (\Throwable $e) {
            Log::warning('Upozornění na návrh ke smazání se nepodařilo odeslat', [
                'gallery_space_id' => $prostor->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Jméno toho, na koho se čeká — jen když je jediný. U víc lidí
     * (nebo žádného) zpráva zůstane obecná, ať nejmenuje špatného.
     */
    private function partner(GallerySpace $prostor, User $kdo): ?string
    {
        $ostatni = System::ostatniZDvojice($prostor, (int) $kdo->id);

        return count($ostatni) === 1 ? reset($ostatni)['jmeno'] : null;
    }

    /** „30 dní na vrácení" podle skutečné lhůty koše. */
    private function lhuta(): string
    {
        $dni = (int) config('gallery.trash_retention_days', 30);

        return $dni.' '.($dni === 1 ? 'den' : ($dni >= 2 && $dni <= 4 ? 'dny' : 'dní')).' na vrácení';
    }

    /** „Navrženo ke smazání" u jedné položky, „… (3 položky)" u víc. */
    private function sPoctem(string $co, int $pocet): string
    {
        if ($pocet === 1) {
            return $co;
        }

        return $co.' ('.$pocet.' '.($pocet <= 4 ? 'položky' : 'položek').')';
    }

    /** @return list<string> */
    private function ids(Request $request): array
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['string', 'max:64'],
        ]);

        return array_values(array_unique($data['ids']));
    }

    private function prostor(Request $request): GallerySpace
    {
        return GallerySpace::findOrFail($this->parId($request));
    }
}
