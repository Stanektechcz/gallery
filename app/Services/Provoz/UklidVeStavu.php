<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Media\MazaniFotek;
use App\Services\Media\VysledekMazani;
use App\Services\Obsah\Uklid;
use App\Support\Cas;
use App\Support\SpaceContext;
use App\Support\Tabulky;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Úklid knihovny, který přišel jako změna stavu.
 *
 * Karanténa i slučování duplicit už kreslí ze skutečné knihovny — jenže
 * rozhodnutí nad nimi končila v prohlížeči. „Pustit" znamenalo, že fotka zmizí
 * ze seznamu tomu, kdo klikl; druhý ji dál viděl v karanténě a originál ležel
 * na disku dál. Tady se to rozhodnutí provede: co se má nechat, se vrátí do
 * knihovny, co se pustí, jde do koše (odkud se dá vrátit), a sloučený nález se
 * uzavře.
 *
 * Zápis je záměrně **idempotentní** — klíče zůstávají ve stavu, takže tentýž
 * seznam přijde i s dalším patchem. Druhé provedení už nemá co změnit.
 *
 * Do koše se nic neposílá napřímo, jen přes `MazaniFotek::doKose()`: ve
 * dvojici je „Pustit" i „Sloučit" jen návrh, který musí potvrdit druhý,
 * skryté fotky zamčený trezor chrání a každý přesun má záznam v protokolu
 * a lhůtu koše z nastavení. Host ani účet jen pro čtení tu nevyhodí nic —
 * zbytek jeho zápisu stavu se ale uloží.
 */
class UklidVeStavu
{
    /** `dupKeep[nález] = '*'` — „Necháváme obě": nález se uzavře a nic nejde do koše. */
    public const NECHAT_VSE = '*';

    public function __construct(
        private readonly Uklid $obsah,
        private readonly MazaniFotek $mazani,
    ) {}

    public function tykaSe(array $patch): bool
    {
        return array_key_exists('quarGone', $patch)
            || array_key_exists('dupDone', $patch)
            || array_key_exists('datDone', $patch);
    }

    /**
     * Provede rozhodnutí a vrátí patch se **skutečně** uvolněným místem.
     *
     * `clnFreed` si prototyp počítal sám z toho, co má na obrazovce. Po
     * načtení stránky ta čísla nemá z čeho složit, takže se dosadí to, co
     * z databáze opravdu zmizelo.
     *
     * @param  bool  $trezor  `Trezor::odemcen()` — zamčený trezor skryté fotky mine
     * @return array<string, mixed>
     */
    public function zpracuj(array $patch, GallerySpace $prostor, User $kdo, bool $trezor): array
    {
        // Karanténu i duplicity rozhoduje jen dvojice. Host se sem přes bránu
        // nedostane, účet jen pro čtení ano — a jeho zápis stavu má projít,
        // jen bez mazání (služba by odmítla 403 a celý zápis by spadl).
        $smi = $this->mazani->jeClenDvojice($prostor, $kdo);

        if ($smi) {
            $this->karantena($patch, $prostor, $kdo, $trezor);
        }

        $this->datovani($patch, $prostor);

        if (array_key_exists('dupDone', $patch)) {
            $patch['clnFreed'] = $this->duplicity($patch, $prostor, $smi ? $kdo : null, $trezor);
        }

        return $patch;
    }

    /**
     * Datování skenů: `datDone` je rozhodnutí, `datDrafts` ručně zapsaný rok.
     *
     * „Datováno na 1988" dosud jen zmizelo ze seznamu — `taken_at` zůstalo
     * prázdné, takže se fotka příště nabídla znovu a v časové ose dál nikde
     * nebyla.
     *
     * Přijatý odhad se zapisuje jako **první leden toho roku**: přesnější
     * datum aplikace nezná a předstírat den by znamenalo tvrdit víc, než ví.
     */
    private function datovani(array $patch, GallerySpace $prostor): void
    {
        $rozhodnuti = (array) ($patch['datDone'] ?? []);

        if (! $rozhodnuti) {
            return;
        }

        $vlastni = (array) ($patch['datDrafts'] ?? []);
        $odhady = null;

        foreach ($rozhodnuti as $uuid => $jak) {
            // „Zůstává bez data" je taky odpověď — a nic se při ní nemění.
            if (! is_string($uuid) || $jak === 'skip') {
                continue;
            }

            /*
             * Přijatý odhad si server dohledá sám.
             *
             * Prototyp do stavu ukládá jen „accept", ne rok. Brát ho z toho,
             * co poslal prohlížeč, by znamenalo věřit číslu, které si mezitím
             * mohl kdokoli přepsat — a odhad je tak jako tak serverův.
             */
            if ($jak === 'manual') {
                $rok = $this->rok($vlastni[$uuid] ?? '');
            } else {
                $odhady ??= collect($this->obsah->kolekce($prostor)['DATING'] ?? [])->keyBy('id');
                $rok = $this->rok($odhady[$uuid]['guess'] ?? '');
            }

            if ($rok === null) {
                continue;
            }

            // První leden toho roku: přesnější datum aplikace nezná a
            // předstírat den by znamenalo tvrdit víc, než ví.
            MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
                ->where('gallery_space_id', $prostor->id)
                ->where('uuid', $uuid)
                ->whereNull('taken_at')
                ->update([
                    'taken_at' => $rok.'-01-01 12:00:00',
                    /*
                     * Odhad se pozná od změřeného data.
                     *
                     * Obrazovka slibuje, že přijatý návrh „není ve Zdraví dat
                     * vidět jako tvrdý údaj" — bez tohohle příznaku by rok
                     * odvozený ze sousedního souboru vypadal stejně jako
                     * datum z EXIFu. Ručně zapsaný rok příznak nedostává:
                     * ten člověk ví, ne odhaduje.
                     */
                    'taken_at_estimated' => $jak !== 'manual',
                ]);
        }
    }

    /** Rok jako čtyři číslice, nebo nic. */
    private function rok(mixed $hodnota): ?string
    {
        $rok = trim((string) $hodnota);

        return preg_match('/^\d{4}$/', $rok) && (int) $rok >= 1826 && (int) $rok <= Cas::dnes()->year
            ? $rok
            : null;
    }

    /**
     * Karanténa: `{ uuid: 'keep' | 'drop' }`.
     *
     * Ponechat vrací fotku do knihovny; pustit ji posílá do koše. Nemaže se
     * nic — koš má vlastní lhůtu a prototyp na ni má napsané „sedm dní na
     * vrácení".
     *
     * Obě rozhodnutí zároveň **končí karanténu**. Kdyby si puštěná fotka
     * `is_archived` nechala, vrácení z koše by ji vrátilo do fronty otázek,
     * kterou už dvojice zodpověděla — a schovaná by pak byla jen tím, co si
     * o ní pamatuje prohlížeč.
     *
     * „Pustit" jde přes `MazaniFotek::doKose()` — ve dvojici je to návrh.
     * **Co už je vyřízené, pozná server podle fotky, ne podle stavu:** každé
     * provedené rozhodnutí (přesun, návrh i souhlas) karanténu ukončí
     * (`is_archived = false`) a sem se berou jen fotky, které v karanténě
     * pořád jsou. Když partner návrh odmítne („Ponechat"), fotka zůstane
     * v knihovně a tentýž `drop`, který přijde s každým dalším patchem (nebo
     * ze staré kopie stavu na druhém zařízení), už nic nenavrhne. Porovnávat
     * se s uloženým stavem by nestačilo — druhé zařízení může poslat starou
     * kopii, ve které rozhodnutí vypadá jako nové.
     *
     * Skrytá fotka při zamčeném trezoru zůstane v karanténě i s rozhodnutím —
     * dokončí se, až bude trezor odemčený.
     */
    private function karantena(array $patch, GallerySpace $prostor, User $kdo, bool $trezor): void
    {
        $rozhodnuti = (array) ($patch['quarGone'] ?? []);

        $vybrat = fn (string $jak) => array_values(array_filter(
            array_keys($rozhodnuti),
            fn ($id) => is_string($id) && $id !== '' && ($rozhodnuti[$id] ?? null) === $jak,
        ));

        $dotaz = fn () => MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('is_archived', true);

        if ($nechat = $vybrat('keep')) {
            $dotaz()
                ->whereIn('uuid', $nechat)
                ->update(['is_archived' => false, 'purge_after' => null]);
        }

        $pustit = $vybrat('drop');

        if (! $pustit) {
            return;
        }

        $vKarantene = $dotaz()->whereIn('uuid', $pustit)->whereNull('trashed_at')->pluck('uuid')->all();
        $vysledek = $vKarantene === [] ? null : $this->doKose($prostor, $kdo, $vKarantene, 'uklid-karantena', $trezor);

        if ($vysledek === null) {
            return;
        }

        // V koši: lhůtu koše právě nastavila služba — tu přepsat nesmíme.
        if ($vKosi = $vysledek->vKosi()) {
            MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
                ->where('gallery_space_id', $prostor->id)
                ->whereIn('uuid', $vKosi)
                ->update(['is_archived' => false]);
        }

        // Návrh čeká na partnera v knihovně; lhůta karantény („pustíme sama
        // za…") s rozhodnutím končí, jinak by visela u fotky, která už v karanténě není.
        if ($ceka = [...$vysledek->navrzeno, ...$vysledek->uzNavrzeno]) {
            $dotaz()
                ->whereIn('uuid', $ceka)
                ->update(['is_archived' => false, 'purge_after' => null]);
        }
    }

    /**
     * Sloučení duplicit: `dupDone` jsou uzavřené nálezy, `dupKeep` vítěz.
     *
     * Vítěz je uuid kopie (starší klient posílal **pořadí** v seznamu, který
     * poslal server — proto se tu čte přesně tímtéž řazením jako
     * v `Knihovna::duplicity()`), nebo `*` = nechat všechny. Ostatní kopie jdou
     * přes `doKose()` (ve dvojici k návrhu) a nález se uzavře, aby se příště
     * nenabízel znovu.
     *
     * @param  User|null  $kdo  `null` = nikdo z dvojice; nález se jen spočítá, neslučuje
     * @return float uvolněné místo v MB, spočítané z toho, co šlo do koše
     */
    private function duplicity(array $patch, GallerySpace $prostor, ?User $kdo, bool $trezor): float
    {
        if (! Tabulky::je('duplicate_groups')) {
            return 0.0;
        }

        $hotove = array_values(array_filter(
            (array) ($patch['dupDone'] ?? []),
            fn ($id) => is_string($id) && $id !== '',
        ));

        $vitezove = (array) ($patch['dupKeep'] ?? []);

        // Dřív uzavřené nálezy se počítají taky — jinak by číslo po načtení
        // stránky spadlo na to, co dvojice stihla v téhle relaci.
        $uvolneno = $this->uvolneno($prostor);

        if (! $hotove || $kdo === null) {
            return $uvolneno;
        }

        $skupiny = DB::table('duplicate_groups')
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('resolved_at')
            ->whereIn('uuid', $hotove)
            ->get(['id', 'uuid']);

        foreach ($skupiny as $skupina) {
            $vitez = $vitezove[$skupina->uuid] ?? null;

            /*
             * Bez výslovného vítěze se nevyhazuje nic a nález zůstane otevřený.
             *
             * „Necháváme obě" na počítači posílalo jen `dupDone` — a záložní
             * výběr (největší kopie) pak ostatní poslal do koše. Chybějící
             * vítěz ale nemusí znamenat ani „nechat obě": telefon při
             * sloučení posílal prázdné `dupKeep`. Neví-li server, co člověk
             * chtěl, nesmaže nic a nález nezavře — dá se rozhodnout znovu.
             */
            if ($vitez === null || $vitez === '') {
                continue;
            }

            $uvolneno += $vitez === self::NECHAT_VSE
                ? $this->nechatVse($skupina, $prostor)
                : $this->sluc($skupina, $vitez, $prostor, $kdo, $trezor);
        }

        return round($uvolneno, 1);
    }

    /** „Necháváme obě": nález se uzavře, všechny kopie zůstanou. */
    private function nechatVse(object $skupina, GallerySpace $prostor): float
    {
        DB::table('duplicate_group_items')
            ->where('duplicate_group_id', $skupina->id)
            ->update(['is_kept' => true, 'updated_at' => now()]);

        DB::table('duplicate_groups')
            ->where('id', $skupina->id)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('resolved_at')
            ->update(['resolution' => 'kept_all', 'resolved_at' => now(), 'updated_at' => now()]);

        return 0.0;
    }

    /**
     * @param  string|int  $vitez  identifikátor vybrané kopie (starší klient posílal pořadí)
     * @return float uvolněné MB — jen za kopie, které opravdu skončily v koši
     */
    private function sluc(object $skupina, $vitez, GallerySpace $prostor, User $kdo, bool $trezor): float
    {
        $radky = DB::table('duplicate_group_items as p')
            ->join('media_items as m', 'm.id', '=', 'p.media_item_id')
            ->where('p.duplicate_group_id', $skupina->id)
            // Jen fotky prostoru, který slučuje. Starší hledání duplicit dávalo
            // do jednoho nálezu i fotky jiných dvojic, a sloučení je posílalo
            // do koše — po třiceti dnech je pak úklid koše smazal nadobro.
            // Stejné podmínky jako seznam v `Knihovna::duplicity()`, ať se
            // slučuje přesně to, co obrazovka ukázala.
            ->where('m.gallery_space_id', $prostor->id)
            ->where('m.is_hidden', false)
            ->whereNull('m.trashed_at')
            ->orderByDesc('m.size_bytes')
            // Pevný doplněk řazení: dvě stejně velké kopie by se jinak mohly
            // při čtení a při zápisu seřadit opačně a do koše by šla ta, kterou
            // obrazovka označila za vítěze.
            ->orderBy('m.id')
            ->get(['p.id as vazba', 'm.id as media', 'm.uuid', 'm.size_bytes']);

        // Nález o jedné položce už nález není — druhá kopie mezitím zmizela.
        if ($radky->count() < 2) {
            return 0.0;
        }

        /*
         * Vybraná kopie podle identifikátoru, ne podle pořadí.
         *
         * Prohlížeč posílal pořadové číslo v seznamu. Stačilo, aby mezi
         * vykreslením a kliknutím kterákoli kopie zmizela — partner ji vyhodil,
         * doběhl noční úklid — a pořadí se posunulo: do koše šla jiná fotka,
         * než která na obrazovce svítila. Pořadí se pro starší klienty pořád
         * přijímá, ale je to záloha, ne první volba.
         */
        $nechat = match (true) {
            is_string($vitez) && $vitez !== '' => $radky->firstWhere('uuid', $vitez),
            is_int($vitez) => $radky[$vitez] ?? null,
            default => null,
        };

        // Vybraná kopie mezitím zmizela (nebo přišel nesmysl): záložní výběr
        // by poslal do koše kopii, kterou nikdo nevybral. Nález zůstane otevřený.
        if ($nechat === null) {
            return 0.0;
        }

        $doKose = $radky->reject(fn (object $r) => $r->media === $nechat->media);

        // Nejdřív služba: když odmítne (souběh se změnou role), nález se nezavře.
        $vysledek = $this->doKose($prostor, $kdo, $doKose->pluck('uuid')->all(), 'uklid-duplicity', $trezor);

        if ($vysledek === null) {
            return 0.0;
        }

        DB::table('duplicate_group_items')
            ->where('duplicate_group_id', $skupina->id)
            ->update(['is_kept' => false, 'updated_at' => now()]);

        DB::table('duplicate_group_items')
            ->where('id', $nechat->vazba)
            ->update(['is_kept' => true, 'updated_at' => now()]);

        /*
         * Nález se uzavře i tehdy, když kopie jen čekají na souhlas partnera.
         *
         * Rozhodnutí „tuhle nechat" padlo; o zbytku teď rozhoduje návrh
         * v knihovně. Odmítne-li ho partner, kopie zůstane — a nález se
         * nevrátí, protože ho znovu poslaný `dupDone` díky `resolved_at` mine.
         */
        DB::table('duplicate_groups')
            ->where('id', $skupina->id)
            ->update(['resolution' => 'merged', 'resolved_at' => now(), 'updated_at' => now()]);

        // Uvolnilo se jen to, co je opravdu v koši — návrh zatím nic.
        $vKosi = $vysledek->vKosi();

        return round((int) $doKose->whereIn('uuid', $vKosi)->sum('size_bytes') / 1_048_576, 1);
    }

    /**
     * „Do koše" přes společné schválení; `null`, když služba odmítla (host,
     * jen pro čtení, cizí prostor) — zápis stavu pak projde bez mazání.
     *
     * @param  list<string>  $uuids
     */
    private function doKose(GallerySpace $prostor, User $kdo, array $uuids, string $odkud, bool $trezor): ?VysledekMazani
    {
        try {
            return $this->mazani->doKose($prostor, $kdo, $uuids, $odkud, $trezor);
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() !== 403) {
                throw $e;
            }

            return null;
        }
    }

    /** Kolik už dvojice uklizením duplicit uvolnila — z uzavřených nálezů. */
    private function uvolneno(GallerySpace $prostor): float
    {
        $bajtu = DB::table('duplicate_group_items as p')
            ->join('duplicate_groups as g', 'g.id', '=', 'p.duplicate_group_id')
            ->join('media_items as m', 'm.id', '=', 'p.media_item_id')
            ->where('g.gallery_space_id', $prostor->id)
            ->where('g.resolution', 'merged')
            ->where('p.is_kept', false)
            ->whereNotNull('m.trashed_at')
            ->sum('m.size_bytes');

        return round((int) $bajtu / 1_048_576, 1);
    }
}
