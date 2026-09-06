<?php

namespace App\Services\Obsah;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\Person;
use App\Support\SpaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

/**
 * Knihovna ve tvaru, ve kterém ji kreslí prototyp.
 *
 * Mřížka fotek se v prototypu **nebrala z dat** — dokument si ji vyráběl sám
 * ze šesti napsaných dnů (`days()`, `photos()`), takže dvojice viděla 55 barevných
 * obdélníků místo svých fotek. Tenhle poskytovatel dodá `DAYS`, `PHOTOS`, `ALBUMS`,
 * `ATREE`, `PERSONS` a `DUP_GROUPS` ze skutečné knihovny, a to v přesně tom tvaru,
 * na který jsou obrazovky napsané.
 *
 * Náhled se posílá jako `background` v CSS: prototyp každou dlaždici kreslí
 * jako barevný přechod, takže `url(…) center/cover` sedne beze změny značek.
 */
class Knihovna implements PoskytovatelObsahu
{
    /**
     * Kolik fotek se posílá do mřížky.
     *
     * Prototyp umí donačítat; poslat celou knihovnu (u dvojice desítky tisíc)
     * by znamenalo megabajty JSONu a zamrzlý prohlížeč.
     */
    private const FOTEK = 240;

    /** Které snímky mají zmenšeninu; `media_item_id => ano/ne`. */
    private array $nahledy = [];

    public function skupina(): string
    {
        return 'knihovna';
    }

    /**
     * Lidé a štítky přicházejí celí.
     *
     * Nechat vedle skutečných tváří ukázkové znamená lhát: dvojice by
     * v „Lidech" našla Kláru, kterou nikdy neoznačila, a na jejím profilu
     * osm tisíc fotek, které nemá. U štítků totéž — a ještě navíc by na ně
     * šlo kliknout a hledání by nenašlo nic.
     */
    public function uplne(): array
    {
        return ['PERSONS', 'ATAGS', 'APEOPLE'];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        $media = $this->media($prostor);

        // Prázdná knihovna nechává ukázku: prázdná mřížka a rozbitá aplikace
        // vypadají z pohledu člověka stejně.
        if ($media->isEmpty()) {
            return [];
        }

        $dny = $this->dny($media);
        $fotky = $this->fotky($media, $dny);
        $alba = $this->alba($prostor);
        $lide = $this->osoby($prostor, $fotky);

        return array_filter([
            'DAYS' => $dny->values()->all(),
            'PHOTOS' => $fotky,
            'ALBUMS' => $alba,
            'ATREE' => $this->strom($alba),
            'PERSONS' => $lide,
            // Táž jména, jen ve tvaru, na který je napsané úzké rozvržení.
            'APEOPLE' => $this->osobyDoZalozek($lide),
            'ATAGS' => $this->stitkyKnihovny($prostor),
            'YBCH' => $this->roky($prostor),
            // Čísla u položek postranního panelu a součet na úvodní obrazovce.
            'NAVCNT' => $this->navPocty($prostor),
            'TOTAL' => $this->celkem($prostor),
            // Úzké rozvržení kreslí tytéž fotky z vlastních kolekcí; drží si je
            // ve `window.GalerieMobil`, ne v `GalerieData`.
            'MOBIL' => $this->mobil($dny, $fotky, $alba),
        ], fn ($v) => $v !== null && $v !== [])
            /*
             * Duplicity se posílají i prázdné.
             *
             * U ostatních kolekcí je prázdno k nerozeznání od rozbité obrazovky,
             * a proto zůstává ukázka. Tady je prázdno odpověď: prototyp na ni má
             * napsané „Knihovna je uklizená". Nechat místo toho čtyři vymyšlené
             * nálezy by znamenalo posílat dvojici uklízet fotky, které nemá.
             */
            + ['DUP_GROUPS' => $this->duplicity($prostor)];
    }

    /** @return Collection<int, MediaItem> */
    private function media(GallerySpace $prostor): Collection
    {
        return MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
            ->with(['uploader:id,name', 'primaryAlbum:id,title'])
            ->orderByDesc('taken_at')
            ->orderByDesc('uploaded_at')
            ->limit(self::FOTEK)
            ->get();
    }

    /**
     * Dny, do kterých se mřížka dělí.
     *
     * `{ key, label, short, place, album, n, y }` — klíčem je datum, aby se na něj
     * dala fotka napojit.
     *
     * @param  Collection<int, MediaItem>  $media
     * @return Collection<string, array<string, mixed>>
     */
    private function dny(Collection $media): Collection
    {
        return $media
            ->groupBy(fn (MediaItem $m) => $this->den($m)->format('Y-m-d'))
            ->map(function (Collection $fotky, string $klic) {
                $den = CarbonImmutable::parse($klic);
                $prvni = $fotky->first();

                return [
                    'key' => $klic,
                    'label' => $this->denCesky($den),
                    'short' => $den->format('j. n. Y'),
                    'place' => $prvni->location_name ?: 'Bez místa',
                    'album' => $prvni->primaryAlbum?->title ?: 'Bez alba',
                    'n' => $fotky->count(),
                    'y' => $den->year,
                ];
            });
    }

    /**
     * Jedna dlaždice mřížky. Pole je dané prototypem a nemění se.
     *
     * @param  Collection<int, MediaItem>  $media
     * @param  Collection<string, array<string, mixed>>  $dny
     * @return list<array<string, mixed>>
     */
    private function fotky(Collection $media, Collection $dny): array
    {
        $stitky = $this->stitky($media);
        $vAlbu = $this->vazbyAlb($media);
        $this->zjistiNahledy($media->pluck('id')->map(fn ($i) => (int) $i)->all());
        $poradi = 0;

        return $media->map(function (MediaItem $m) use ($dny, $stitky, $vAlbu, &$poradi) {
            $poradi++;
            $klic = $this->den($m)->format('Y-m-d');
            $den = $dny[$klic];
            $video = $m->media_type === 'video';

            return [
                'id' => $m->uuid,
                'day' => $klic,
                'dayLabel' => $den['label'],
                'dateShort' => $den['short'],
                'place' => $m->location_name ?: $den['place'],
                // Album, do kterého fotka patří. Kromě `primary_album_id` se
                // počítá i členství přes spojovací tabulku — jinak by fotka
                // vložená do alba ručně hlásila „Bez alba".
                'album' => $m->primaryAlbum?->title ?: ($vAlbu[$m->id] ?? $den['album']),
                'y' => $den['y'],
                'isVideo' => $video,
                'orient' => $this->orientace($m),
                'dur' => $video && $m->duration_ms ? $this->trvani((int) $m->duration_ms) : null,
                'fav' => (bool) $m->is_favorite,
                // Stav zpracování, ne výmysl: co ještě nemá náhled, se pozná.
                'pending' => $m->status !== 'ready',
                'error' => $m->status === 'failed',
                'shared' => (bool) $m->is_archived === false && $m->storage_status === 'mirrored',
                'author' => $m->uploader?->name ?? '—',
                'name' => $m->original_filename,
                'size' => $this->velikost((int) $m->size_bytes),
                'caption' => (string) ($m->caption ?? ''),
                // Skutečný náhled místo barevného přechodu.
                'bg' => $this->nahled($m),
                'tags' => $stitky[$m->id] ?? [],
                'sync' => match ($m->status) {
                    'failed' => 'error',
                    'ready' => 'ok',
                    default => 'pending',
                },
                /*
                 * Co obrazovka úklidu nabídne doplnit.
                 *
                 * Prototyp to měl napsané v `AMISS` jako seznam ukázkových
                 * identifikátorů; tady se to pozná z fotky samotné.
                 */
                'miss' => array_values(array_filter([
                    $m->taken_at ? null : 'date',
                    $m->location_name || $m->latitude ? null : 'place',
                ])),
                'n' => $poradi,
            ];
        })->values()->all();
    }

    /**
     * Do jakého alba fotka patří přes spojovací tabulku.
     *
     * `media_item_id => název alba`. Jedna fotka může být ve víc albech; do
     * dlaždice se vejde jedno, tak se bere první.
     *
     * @param  Collection<int, MediaItem>  $media
     * @return array<int, string>
     */
    private function vazbyAlb(Collection $media): array
    {
        return DB::table('album_media as am')
            ->join('albums as a', 'a.id', '=', 'am.album_id')
            ->whereIn('am.media_item_id', $media->pluck('id'))
            ->whereNull('a.deleted_at')
            ->orderBy('am.sort_order')
            ->get(['am.media_item_id', 'a.title'])
            ->groupBy('media_item_id')
            ->map(fn (Collection $r) => (string) $r->first()->title)
            ->all();
    }

    /**
     * Štítky k fotkám — jedním dotazem, ne po jedné.
     *
     * @param  Collection<int, MediaItem>  $media
     * @return array<int, list<string>>
     */
    private function stitky(Collection $media): array
    {
        if (! Schema::hasTable('media_tag')) {
            return [];
        }

        return DB::table('media_tag as mt')
            ->join('tags as t', 't.id', '=', 'mt.tag_id')
            ->whereIn('mt.media_item_id', $media->pluck('id'))
            ->get(['mt.media_item_id', 't.name'])
            ->groupBy('media_item_id')
            ->map(fn (Collection $r) => $r->pluck('name')->all())
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function alba(GallerySpace $prostor): array
    {
        $modely = Album::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('deleted_at')
            ->with('cover:id,uuid')
            ->orderByDesc('updated_at')
            ->limit(40)
            ->get();

        $radky = $modely->map(fn (Album $a) => [
            'id' => $a->uuid,
            'name' => $a->title,
            // Obálka alba; bez ní si prototyp dokreslí barevný přechod z `n`.
            'bg' => $a->cover ? $this->nahled($a->cover) : null,
            // Celá cesta, ne jen jméno: prototyp ji kreslí jako „Chorvatsko → Zadar".
            'path' => $a->full_display_path ?: $a->title,
            'date' => $this->rozsah($a),
            'place' => $a->location_name ?: ($a->event_place_name ?: ''),
            'count' => $this->pocet((int) $a->media_count, 'položka', 'položky', 'položek'),
            'subs' => (int) ($a->descendant_count ?? 0),
            // Album se v aplikaci neoznačuje jako oblíbené; sdílené je to,
            // co není soukromé.
            'fav' => false,
            'shared' => $a->visibility !== 'private',
            'n' => $a->id,
            'desc' => (string) ($a->description ?? ''),
            'children' => [],
        ])->keyBy('n');

        /*
         * Podalba, ne pět vymyšlených.
         *
         * Detail alba si je v prototypu dokresloval sám ze jmen „Zadar, Krka,
         * Plitvice…" podle počtu potomků. Skutečná hierarchie je v `parent_id`.
         */
        foreach ($modely as $a) {
            if (! $a->parent_id || ! $radky->has($a->parent_id) || ! $radky->has($a->id)) {
                continue;
            }

            $rodic = $radky[$a->parent_id];
            $rodic['children'][] = [
                'id' => $a->uuid,
                'name' => $a->title,
                'count' => $this->pocet((int) $a->media_count, 'položka', 'položky', 'položek'),
                'bg' => $a->cover ? $this->nahled($a->cover) : null,
                'n' => $a->id,
            ];
            $radky[$a->parent_id] = $rodic;
        }

        return $radky->values()->all();
    }

    /**
     * Strom alb v levém sloupci.
     *
     * `[jméno, úroveň, počet, oblíbené, sdílené, barva, id]` — sedmé pole je
     * navíc proti prototypu, aby kliknutí otevřelo skutečné album; dokument ho
     * bere jako nepovinné a bez něj se chová jako dřív.
     *
     * @param  list<array<string, mixed>>  $alba
     * @return list<array<int, mixed>>
     */
    private function strom(array $alba): array
    {
        // Řadí se podle cesty, aby podalbum stálo pod svým rodičem — ne podle
        // poslední změny, jak alba přicházejí ze seznamu.
        $serazena = $alba;
        usort($serazena, fn (array $a, array $b) => strcmp((string) $a['path'], (string) $b['path']));

        return array_map(function (array $a) {
            $cesta = array_values(array_filter(array_map('trim', explode('→', (string) $a['path']))));

            return [
                $cesta ? end($cesta) : $a['name'],
                max(0, count($cesta) - 1),
                // Prototyp si k číslu dopisuje „položek" sám.
                trim(preg_replace('/\s*(položka|položky|položek)\s*/u', '', (string) $a['count'])),
                $a['fav'] ? 1 : 0,
                $a['shared'] ? 1 : 0,
                $a['n'],
                $a['id'],
            ];
        }, $serazena);
    }

    /**
     * Lidé na fotkách. Klíčem je **jméno** — prototyp klíč rovnou vypisuje
     * (`pName(id)` vrací klíč, dokud ho někdo nepřejmenuje).
     *
     * @param  list<array<string, mixed>>  $fotky
     * @return array<string, array<string, mixed>>
     */
    private function osoby(GallerySpace $prostor, array $fotky): array
    {
        $lide = Person::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->limit(30)
            ->get();

        if ($lide->isEmpty()) {
            return [];
        }

        $id = $lide->pluck('id')->all();
        $souhrn = $this->souhrnOsob($prostor, $id);
        $roky = $this->rokyOsob($prostor, $id);
        $spolu = $this->spoluOsob($prostor, $id);
        $albaOsob = $this->albaOsob($prostor, $id);
        $poradi = $this->poradiFotek($prostor, $id, $fotky);

        // Nejvíc fotek nahoře — prototyp seznam nijak neřadí a bere ho tak, jak přijde.
        $lide = $lide->sortByDesc(fn (Person $o) => (int) ($souhrn[$o->id]->pocet ?? 0));

        $vysledek = [];

        foreach ($lide as $o) {
            $s = $souhrn[$o->id] ?? null;
            $pocet = (int) ($s->pocet ?? 0);
            $alb = count($albaOsob[$o->id] ?? []);
            $od = ($s->prvni ?? null) ? CarbonImmutable::parse($s->prvni) : null;
            $do = ($s->posledni ?? null) ? CarbonImmutable::parse($s->posledni) : null;

            // Dvě Kláry by se v mapě přepsaly; druhá dostane číslo, ať je poznat,
            // která je která.
            $klic = $o->name;
            $poradove = 2;
            while (isset($vysledek[$klic])) {
                $klic = $o->name.' ('.$poradove++.')';
            }

            $vysledek[$klic] = [
                'n' => (int) $o->id,
                'tag' => $o->is_hidden ? 'skryto' : 'potvrzeno',
                'meta' => implode(' · ', array_filter([
                    $this->pocet($pocet, 'fotka', 'fotky', 'fotek'),
                    $od && $do ? ($od->year === $do->year ? (string) $od->year : $od->year.' – '.$do->year) : null,
                    $alb ? $this->vAlbech($alb) : null,
                ])),
                'photosMeta' => $this->pocet($pocet, 'fotka', 'fotky', 'fotek')
                    .((int) ($s->videi ?? 0) ? ', '.$this->pocet((int) $s->videi, 'video', 'videa', 'videí') : ''),
                'stats' => array_values(array_filter([
                    ['Fotek', $this->cislo($pocet)],
                    $od ? ['První výskyt', $od->format('j. n. Y')] : null,
                    $do ? ['Naposledy', $do->isToday() ? 'dnes' : $do->format('j. n. Y')] : null,
                    ['Alb', $this->cislo($alb)],
                ])),
                'years' => $roky[$o->id] ?? [],
                'co' => $spolu[$o->id] ?? [],
                'albums' => $albaOsob[$o->id] ?? [],
                'idx' => $poradi[$o->id] ?? [],
                'sug' => [],
            ];
        }

        return $vysledek;
    }

    /**
     * Počet, první a poslední výskyt a počet videí — jedním dotazem na všechny.
     *
     * @param  list<int>  $id
     * @return array<int, object>
     */
    private function souhrnOsob(GallerySpace $prostor, array $id): array
    {
        return $this->tagy($prostor, $id)
            ->groupBy('mp.person_id')
            ->get([
                'mp.person_id',
                DB::raw('COUNT(*) AS pocet'),
                DB::raw("SUM(CASE WHEN m.media_type = 'video' THEN 1 ELSE 0 END) AS videi"),
                DB::raw('MIN(COALESCE(m.taken_at, m.uploaded_at)) AS prvni'),
                DB::raw('MAX(COALESCE(m.taken_at, m.uploaded_at)) AS posledni'),
            ])
            ->keyBy('person_id')
            ->all();
    }

    /**
     * Roky osoby: `[popisek, počet, procenta, příznak]`.
     *
     * Tři poslední roky zvlášť, zbytek do jednoho pruhu — přesně jak to má
     * napsané prototyp, jen ze skutečných dat.
     *
     * @param  list<int>  $id
     * @return array<int, list<array<int, mixed>>>
     */
    private function rokyOsob(GallerySpace $prostor, array $id): array
    {
        $vysledek = [];

        $this->tagy($prostor, $id)
            ->get(['mp.person_id', DB::raw('COALESCE(m.taken_at, m.uploaded_at) AS kdy')])
            ->groupBy('person_id')
            ->each(function (Collection $radky, $osoba) use (&$vysledek) {
                // Rok se počítá v PHP: `YEAR()` a `strftime()` se mezi MySQL
                // a SQLite liší a jedna z těch dvou by spadla.
                $poRoce = $radky
                    ->filter(fn ($r) => (bool) $r->kdy)
                    ->countBy(fn ($r) => CarbonImmutable::parse($r->kdy)->year)
                    ->sortKeysDesc();

                if ($poRoce->isEmpty()) {
                    return;
                }

                $nejvic = max($poRoce->values()->all());
                $roky = $poRoce->keys()->all();
                $pruhy = [];

                foreach (array_slice($roky, 0, 3) as $rok) {
                    $pruhy[] = [
                        (string) $rok,
                        $this->cislo((int) $poRoce[$rok]),
                        (int) round($poRoce[$rok] / $nejvic * 100),
                        0,
                    ];
                }

                $zbytek = array_slice($roky, 3);

                if ($zbytek) {
                    $soucet = array_sum(array_map(fn ($r) => (int) $poRoce[$r], $zbytek));
                    $popis = count($zbytek) === 1 ? (string) $zbytek[0] : end($zbytek).' – '.$zbytek[0];
                    // Příznak 2 kreslí pruh tlumeně; starší roky tak nepřetahují pozornost.
                    $pruhy[] = [$popis, $this->cislo($soucet), (int) round($soucet / max($nejvic, $soucet) * 100), 2];
                }

                $vysledek[(int) $osoba] = $pruhy;
            });

        return $vysledek;
    }

    /**
     * S kým se člověk na fotkách potkává: `[jméno, 'N spolu', barva]`.
     *
     * @param  list<int>  $id
     * @return array<int, list<array<int, mixed>>>
     */
    private function spoluOsob(GallerySpace $prostor, array $id): array
    {
        return DB::table('media_person as a')
            ->join('media_person as b', function ($spoj) {
                $spoj->on('b.media_item_id', '=', 'a.media_item_id')
                    ->whereColumn('b.person_id', '!=', 'a.person_id');
            })
            ->join('media_items as m', 'm.id', '=', 'a.media_item_id')
            ->join('people as o', 'o.id', '=', 'b.person_id')
            ->where('m.gallery_space_id', $prostor->id)
            ->whereNull('m.trashed_at')
            ->whereIn('a.person_id', $id)
            ->whereIn('b.person_id', $id)
            ->groupBy('a.person_id', 'b.person_id', 'o.name')
            ->orderByDesc(DB::raw('COUNT(*)'))
            // Obě strany dvojice se jmenují `person_id`; bez přejmenování si
            // druhá přepíše první a seznam „s kým" ukazuje člověka sám se sebou.
            ->get(['a.person_id AS kdo', 'b.person_id AS s_kym', 'o.name', DB::raw('COUNT(*) AS pocet')])
            ->groupBy('kdo')
            ->map(fn (Collection $r) => $r->take(6)->map(fn ($x) => [
                $x->name,
                $this->cislo((int) $x->pocet).' spolu',
                (int) $x->s_kym,
            ])->values()->all())
            ->all();
    }

    /**
     * Alba, ve kterých člověk je: `[název, 'N fotek', barva]`.
     *
     * Bere se z fotek, ne z `album_person` — ta spojovací tabulka se v aplikaci
     * nikdy neplní, takže by seznam byl vždycky prázdný.
     *
     * @param  list<int>  $id
     * @return array<int, list<array<int, mixed>>>
     */
    private function albaOsob(GallerySpace $prostor, array $id): array
    {
        // Album drží fotky dvěma cestami — spojovací tabulkou a `primary_album_id`.
        // `union` je spojí a odstraní dvojí započtení téže fotky.
        $vazby = DB::table('album_media')
            ->select('media_item_id', 'album_id')
            ->union(
                DB::table('media_items')
                    ->where('gallery_space_id', $prostor->id)
                    ->whereNotNull('primary_album_id')
                    ->select('id as media_item_id', 'primary_album_id as album_id')
            );

        return DB::query()
            ->fromSub($vazby, 'vazba')
            ->join('media_person as mp', 'mp.media_item_id', '=', 'vazba.media_item_id')
            ->join('albums as a', 'a.id', '=', 'vazba.album_id')
            ->join('media_items as m', 'm.id', '=', 'vazba.media_item_id')
            ->where('m.gallery_space_id', $prostor->id)
            ->whereNull('m.trashed_at')
            ->whereNull('a.deleted_at')
            ->whereIn('mp.person_id', $id)
            ->groupBy('mp.person_id', 'a.id', 'a.title')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get(['mp.person_id', 'a.id AS album_id', 'a.title', DB::raw('COUNT(*) AS pocet')])
            ->groupBy('person_id')
            ->map(fn (Collection $r) => $r->take(6)->map(fn ($x) => [
                $x->title,
                $this->pocet((int) $x->pocet, 'fotka', 'fotky', 'fotek'),
                (int) $x->album_id,
            ])->values()->all())
            ->all();
    }

    /**
     * Kde v mřížce fotky té osoby leží.
     *
     * Profil z nich skládá dlaždice (`pTile`), a ten sahá do `PHOTOS` pořadovým
     * číslem, ne uuid.
     *
     * @param  list<int>  $id
     * @param  list<array<string, mixed>>  $fotky
     * @return array<int, list<int>>
     */
    private function poradiFotek(GallerySpace $prostor, array $id, array $fotky): array
    {
        $misto = [];

        foreach ($fotky as $i => $f) {
            $misto[$f['id']] = $i;
        }

        return DB::table('media_person as mp')
            ->join('media_items as m', 'm.id', '=', 'mp.media_item_id')
            ->where('m.gallery_space_id', $prostor->id)
            ->whereIn('mp.person_id', $id)
            ->whereIn('m.uuid', array_keys($misto))
            ->get(['mp.person_id', 'm.uuid'])
            ->groupBy('person_id')
            ->map(fn (Collection $r) => $r->take(24)->map(fn ($x) => $misto[$x->uuid])->values()->all())
            ->all();
    }

    /**
     * Společný základ dotazů na označené fotky.
     *
     * @param  list<int>  $id
     */
    private function tagy(GallerySpace $prostor, array $id): Builder
    {
        return DB::table('media_person as mp')
            ->join('media_items as m', 'm.id', '=', 'mp.media_item_id')
            ->where('m.gallery_space_id', $prostor->id)
            ->whereNull('m.trashed_at')
            ->whereIn('mp.person_id', $id);
    }

    /**
     * Nálezy úklidu ve tvaru, jaký kreslí obrazovka „Duplicity".
     *
     * @return list<array<string, mixed>>
     */
    private function duplicity(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('duplicate_groups')) {
            return [];
        }

        $skupiny = DB::table('duplicate_groups')
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('resolved_at')
            ->orderByDesc('detected_at')
            ->limit(20)
            ->get(['id', 'uuid', 'match_type']);

        if ($skupiny->isEmpty()) {
            return [];
        }

        $polozky = DB::table('duplicate_group_items as p')
            ->join('media_items as m', 'm.id', '=', 'p.media_item_id')
            ->whereIn('p.duplicate_group_id', $skupiny->pluck('id'))
            ->whereNull('m.trashed_at')
            ->orderByDesc('m.size_bytes')
            ->get([
                'p.duplicate_group_id', 'p.is_kept', 'm.id', 'm.uuid', 'm.original_filename',
                'm.width', 'm.height', 'm.size_bytes', 'm.location_name',
            ])
            ->groupBy('duplicate_group_id');

        return $skupiny
            ->map(function (object $s) use ($polozky) {
                $radky = $polozky[$s->id] ?? collect();

                // Nález o jedné položce už nález není — druhá kopie mezitím zmizela.
                if ($radky->count() < 2) {
                    return null;
                }

                $shodne = $s->match_type === 'exact';
                // Vítěz: co si člověk vybral, jinak největší soubor. Prototyp
                // ho popisuje jako „vítěze podle rozlišení a metadat".
                $vitez = $radky->firstWhere('is_kept', true) ?? $radky->first();

                return [
                    'id' => $s->uuid,
                    'match' => $shodne ? 'shoda 100 %' : 'podobný snímek',
                    /*
                     * U podobných se nic neuvolní: prototyp na to má vlastní větu
                     * („snímky nejsou totožné") a nabídne nechat obě. U shodných
                     * jde do koše všechno kromě vítěze.
                     */
                    'freed' => $shodne
                        ? round(((int) $radky->sum('size_bytes') - (int) $vitez->size_bytes) / 1_048_576, 1)
                        : 0,
                    'reason' => $shodne
                        ? 'Shodný obsah, jiná velikost souboru. Zůstane největší kopie, ostatní jdou do koše.'
                        : 'Podobná kompozice, ale jiný okamžik. Tady může být správná odpověď nechat obě.',
                    'items' => $radky->map(fn (object $m) => [
                        'id' => $m->uuid,
                        'n' => (int) $m->id,
                        'name' => $m->original_filename,
                        'meta' => implode(' · ', array_filter([
                            $m->width && $m->height ? $m->width.' × '.$m->height : null,
                            $this->velikost((int) $m->size_bytes),
                            $m->location_name ?: 'bez místa',
                        ])),
                        'best' => (int) $m->id === (int) $vitez->id,
                        'bg' => $this->nahled($m),
                    ])->values()->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Čísla u položek postranního panelu; klíčem je cesta z `NAV_GROUPS`.
     *
     * Prototyp je má napsaná v katalogu nabídky, takže knihovna hlásila 24 316
     * fotek i tomu, kdo právě nahrál první. Prázdný řetězec odznak schová.
     *
     * @return array<string, string>
     */
    private function navPocty(GallerySpace $prostor): array
    {
        $vse = $this->knihovna($prostor);

        $chybi = (clone $vse)
            ->where(fn ($q) => $q->whereNull('taken_at')
                ->orWhere(fn ($v) => $v->whereNull('location_name')->whereNull('latitude')))
            ->count();

        $nalezy = Schema::hasTable('duplicate_groups')
            ? DB::table('duplicate_groups')->where('gallery_space_id', $prostor->id)->whereNull('resolved_at')->count()
            : 0;

        return array_map(
            fn (int $kolik) => $kolik ? $this->cislo($kolik) : '',
            [
                'all' => (clone $vse)->count(),
                'favorites' => (clone $vse)->where('is_favorite', true)->count(),
                'x-lide' => Person::withoutGlobalScope(SpaceContext::SCOPE)
                    ->where('gallery_space_id', $prostor->id)->count(),
                'x-uklid' => $chybi + $nalezy,
            ],
        );
    }

    /** Součet na úvodní obrazovce: „24 316 vzpomínek · 7 let". */
    private function celkem(GallerySpace $prostor): string
    {
        $vse = $this->knihovna($prostor);
        $kolik = (clone $vse)->count();
        $od = (clone $vse)->min('taken_at');

        $let = $od
            ? max(1, CarbonImmutable::parse($od)->diffInYears(CarbonImmutable::now()) + 1)
            : 1;

        return $this->pocet($kolik, 'vzpomínka', 'vzpomínky', 'vzpomínek')
            .' · '.$this->pocet((int) $let, 'rok', 'roky', 'let');
    }

    /** Základ dotazů na to, co je v knihovně vidět. */
    private function knihovna(GallerySpace $prostor): \Illuminate\Database\Eloquent\Builder
    {
        return MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->where('is_hidden', false);
    }

    /**
     * Kapitoly roků: `[rok, počet]`.
     *
     * @return list<array<int, mixed>>
     */
    private function roky(GallerySpace $prostor): array
    {
        // Rok se vytahuje v PHP, ne v SQL: `YEAR()` a `strftime()` se mezi MySQL
        // a SQLite liší a jedna z těch dvou by v testech nebo v produkci spadla.
        return MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->whereNotNull('taken_at')
            ->pluck('taken_at')
            ->countBy(fn ($kdy) => CarbonImmutable::parse($kdy)->year)
            ->sortKeysDesc()
            ->map(fn (int $pocet, $rok) => [(int) $rok, $pocet])
            ->values()
            ->all();
    }

    /**
     * Tytéž fotky pro úzké rozvržení.
     *
     * Telefon má vlastní, mnohem menší tvar (`[popisek, místo, počet]` a fotka
     * s indexem dne), takže se neposílá knihovna dvakrát celá — jen se přeloží,
     * co je už spočítané.
     *
     * @param  Collection<string, array<string, mixed>>  $dny
     * @param  list<array<string, mixed>>  $fotky
     * @param  list<array<string, mixed>>  $alba
     * @return array<string, mixed>
     */
    private function mobil(Collection $dny, array $fotky, array $alba): array
    {
        $poradiDnu = array_flip($dny->keys()->all());

        return [
            'DAYS' => $dny->values()->map(fn (array $d) => [$d['label'], $d['place'], $d['n']])->all(),
            // Telefon si album drží jako seznam identifikátorů fotek, ne jako počet.
            'ALBUMS' => array_map(fn (array $a) => [
                'id' => $a['id'],
                'name' => $a['name'],
                'ids' => array_values(array_map(
                    fn (array $f) => $f['id'],
                    array_filter($fotky, fn (array $f) => $f['album'] === $a['name']),
                )),
                'seed' => $a['n'],
                'bg' => $a['bg'],
            ], $alba),
            'PHOTOS' => array_map(fn (array $f) => [
                'id' => $f['id'],
                'di' => $poradiDnu[$f['day']] ?? 0,
                'day' => $f['dayLabel'],
                'place' => $f['place'],
                'video' => $f['isVideo'],
                'dur' => $f['dur'] ?? '0:20',
                'fav' => $f['fav'],
                'seed' => $f['n'],
                'bg' => $f['bg'],
            ], $fotky),
        ];
    }

    // ——— formát ———

    /**
     * Náhled jako hodnota pro `background`.
     *
     * Prototyp dlaždici kreslí barevným přechodem, takže `url(…) center/cover`
     * sedne beze změny značek; barva pod ním drží dlaždici, než se náhled stáhne.
     *
     * Adresa je podepsaná — obrázek v CSS si prohlížeč stahuje sám a hlavičku
     * s tokenem k němu nepřidá. Platnost končí na konci zítřejšího dne, tedy
     * ve stejný okamžik pro všechny dlaždice: kdyby se počítala od teď, měla by
     * každá adresa jinou vteřinu vypršení a prohlížeč by si nemohl nechat
     * ani jednu v paměti.
     */
    private function nahled(object $m): string
    {
        if (! $this->maNahled($m)) {
            // Bez zmenšeniny by dlaždice zůstala prázdná; tenhle přechod je týž
            // výpočet, jaký si prototyp dělá sám z `n`.
            return $this->prechod((int) $m->id);
        }

        $adresa = URL::temporarySignedRoute(
            'galerie.media.thumb',
            CarbonImmutable::tomorrow()->endOfDay(),
            ['uuid' => $m->uuid],
        );

        return "url('".$adresa."') center/cover no-repeat #2b2842";
    }

    /**
     * Má snímek co ukázat?
     *
     * Bez tohohle by mřížka poslala tolik požadavků, kolik má dlaždic, a všechny
     * by se vrátily jako 404.
     */
    private function maNahled(object $m): bool
    {
        if (! array_key_exists($m->id, $this->nahledy)) {
            $this->zjistiNahledy([(int) $m->id]);
        }

        return $this->nahledy[$m->id] ?? false;
    }

    /**
     * Které snímky mají zmenšeninu — jedním dotazem na celou mřížku.
     *
     * @param  list<int>  $id
     */
    private function zjistiNahledy(array $id): void
    {
        $id = array_values(array_diff($id, array_keys($this->nahledy)));

        if (! $id) {
            return;
        }

        $maji = DB::table('media_variants')
            ->whereIn('media_item_id', $id)
            ->whereIn('type', ['thumbnail', 'small', 'video_poster', 'original'])
            ->distinct()
            ->pluck('media_item_id')
            ->all();

        foreach ($id as $jeden) {
            $this->nahledy[$jeden] = in_array($jeden, $maji, true);
        }
    }

    /** Barevný přechod prototypu (`bg(n)`), aby prázdná dlaždice nebyla díra. */
    private function prechod(int $n): string
    {
        $uhel = 130 + ($n % 5) * 12;
        $odstin = ($n * 47 + 12) % 360;
        $sytost = 16 + ($n % 5) * 5;

        return 'linear-gradient('.$uhel.'deg, '
            .'hsl('.$odstin.' '.$sytost.'% 58%), '
            .'hsl('.(($odstin + 34) % 360).' '.($sytost + 6).'% 30%))';
    }

    private function den(MediaItem $m): CarbonImmutable
    {
        return CarbonImmutable::parse($m->taken_at ?? $m->uploaded_at ?? $m->created_at);
    }

    private function denCesky(CarbonImmutable $den): string
    {
        $dny = ['Neděle', 'Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota'];
        $mesice = [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června',
            'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

        return $dny[$den->dayOfWeek].' '.$den->day.'. '.$mesice[$den->month].' '.$den->year;
    }

    private function orientace(MediaItem $m): string
    {
        if (! $m->width || ! $m->height) {
            return 'landscape';
        }

        return match (true) {
            $m->height > $m->width * 1.1 => 'portrait',
            abs($m->width - $m->height) <= $m->width * 0.1 => 'square',
            default => 'landscape',
        };
    }

    private function trvani(int $ms): string
    {
        $vteriny = (int) round($ms / 1000);

        return intdiv($vteriny, 60).':'.str_pad((string) ($vteriny % 60), 2, '0', STR_PAD_LEFT);
    }

    private function velikost(int $bajtu): string
    {
        $mb = $bajtu / 1_048_576;

        return $mb >= 1
            ? str_replace('.', ',', (string) round($mb, 1)).' MB'
            : str_replace('.', ',', (string) round($bajtu / 1024)).' kB';
    }

    private function rozsah(Album $a): string
    {
        $od = $a->event_date_start ?? $a->created_at;
        $do = $a->event_date_end ?? $od;

        if (! $od) {
            return '';
        }

        $od = CarbonImmutable::parse($od);
        $do = $do ? CarbonImmutable::parse($do) : $od;

        return $od->isSameDay($do)
            ? $od->format('j. n. Y')
            : $od->format('j. n. Y').' – '.$do->format('j. n. Y');
    }

    /** Číslo s mezerou po tisících — prototyp je tak píše všude. */
    /**
     * Štítky knihovny: `{ all: [[název, počet]], sug: [] }`.
     *
     * Prototyp měl napsané, že knihovna má 4 812 fotek s tagem „léto" — a dalo
     * se na něj kliknout. Hledání pak nenašlo nic. Tady jsou skutečné štítky
     * i s tím, kolika fotek se opravdu týkají.
     *
     * Návrhy zůstávají prázdné **schválně**: štítky nikdo nenavrhuje, aplikace
     * nemá rozpoznávání obsahu. Prázdná záložka je odpověď, vymyšlené návrhy
     * jsou práce navíc pro dvojici.
     *
     * @return array<string, list<array{0: string, 1: string}>>
     */
    private function stitkyKnihovny(GallerySpace $prostor): array
    {
        $stitky = DB::table('tags as t')
            ->leftJoin('media_tag as mt', 'mt.tag_id', '=', 't.id')
            ->leftJoin('media_items as m', function ($j) {
                $j->on('m.id', '=', 'mt.media_item_id')
                    ->whereNull('m.trashed_at')
                    ->where('m.is_hidden', false);
            })
            ->where('t.gallery_space_id', $prostor->id)
            ->groupBy('t.id', 't.name')
            ->orderByDesc(DB::raw('COUNT(m.id)'))
            ->orderBy('t.name')
            ->limit(60)
            ->get(['t.name', DB::raw('COUNT(m.id) AS pocet')]);

        if ($stitky->isEmpty()) {
            return [];
        }

        return [
            'all' => $stitky
                ->map(fn (object $s) => [(string) $s->name, $this->cislo((int) $s->pocet)])
                ->values()
                ->all(),
            'sug' => [],
        ];
    }

    /**
     * Lidé ve tvaru záložek: `{ ok, sug, hidden }`, řádek `[jméno, popis, pořadí]`.
     *
     * Široké rozvržení si tytéž lidi bere z `PERSONS`; úzké má vlastní seznam.
     * Skládá se proto z už spočítaného, ne druhým dotazem — jinak by na telefonu
     * stálo jiné číslo než na počítači.
     *
     * Návrhy jsou prázdné: rozpoznávání tváří, které by je vyrábělo, aplikace
     * nemá, a `PERSONS` proto nikdy neoznačí osobu jako návrh.
     *
     * @param  array<string, array<string, mixed>>  $lide
     * @return array<string, list<array{0: string, 1: string, 2: int}>>
     */
    private function osobyDoZalozek(array $lide): array
    {
        if (! $lide) {
            return [];
        }

        $zalozky = ['ok' => [], 'sug' => [], 'hidden' => []];

        foreach ($lide as $jmeno => $o) {
            $kam = ($o['tag'] ?? '') === 'skryto' ? 'hidden' : 'ok';

            $zalozky[$kam][] = [
                (string) $jmeno,
                (string) ($o['meta'] ?? ''),
                (int) (($o['idx'] ?? [])[0] ?? 0),
            ];
        }

        return $zalozky;
    }

    private function cislo(int $kolik): string
    {
        return number_format($kolik, 0, ',', ' ');
    }

    private function pocet(int $kolik, string $jeden, string $dva, string $pet): string
    {
        return $this->cislo($kolik).' '.match (true) {
            $kolik === 1 => $jeden,
            $kolik >= 2 && $kolik <= 4 => $dva,
            default => $pet,
        };
    }

    /** „v 9 albech" i „ve 126 albech" — předložku volí prototyp podle velikosti čísla. */
    private function vAlbech(int $kolik): string
    {
        return ($kolik >= 10 ? 've ' : 'v ').$kolik.' '.($kolik === 1 ? 'albu' : 'albech');
    }
}
