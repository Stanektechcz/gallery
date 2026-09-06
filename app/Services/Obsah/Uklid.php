<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Support\SpaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Karanténa, výběry fotek a rozpracovaný tisk.
 *
 * Tři seznamy, které prototyp kreslil z napsaných řádků, a přitom všechny tři
 * mají oporu v knihovně: karanténa je to, co dvojice odložila místo smazání
 * (`is_archived`), výběry jsou pořadová čísla do mřížky a tisk jsou skutečné
 * zakázky z `photo_books`.
 */
class Uklid implements PoskytovatelObsahu
{
    /** Kolik fotek posílá knihovna do mřížky — výběry musí ukazovat dovnitř. */
    private const FOTEK = 240;

    public function skupina(): string
    {
        return 'uklid';
    }

    /**
     * Datování přichází celé.
     *
     * Ukázka hlásí osm skenů k dataci a u každého odhad s odůvodněním („auto
     * na snímku je Škoda 100"). Nechat je vedle skutečných fotek bez data
     * znamená poslat dvojici datovat snímky, které nemá.
     */
    public function uplne(): array
    {
        return ['DATING'];
    }

    public function kolekce(GallerySpace $prostor): array
    {
        return array_filter([
            'QUAR' => $this->karantena($prostor),
            'AGRID' => $this->vybery($prostor),
            'PJOBS' => $this->zakazky($prostor),
            'DATING' => $this->kDatovani($prostor),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Karanténa: `{ id, name, meta, why, left, n, expired }`.
     *
     * Odložená fotka není smazaná — dvojice si nechala rozhodnutí na později
     * a obrazovka jí připomíná, kolik času zbývá. Bez `purge_after` se nic
     * nevymýšlí: takový snímek čeká, dokud si na něj někdo nevzpomene.
     *
     * @return list<array<string, mixed>>
     */
    private function karantena(GallerySpace $prostor): array
    {
        $dnes = CarbonImmutable::now();

        return MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('is_archived', true)
            ->whereNull('trashed_at')
            ->orderByDesc('updated_at')
            ->limit(40)
            ->get()
            ->map(function (MediaItem $m) use ($dnes) {
                $do = $m->purge_after ? CarbonImmutable::parse($m->purge_after) : null;

                return [
                    'id' => $m->uuid,
                    'name' => $m->original_filename,
                    'meta' => trim(implode(' · ', array_filter([
                        $m->location_name,
                        $m->taken_at ? CarbonImmutable::parse($m->taken_at)->format('j. n. Y') : null,
                        $this->velikost((int) $m->size_bytes),
                    ]))),
                    // Proč to leží stranou, ví jen člověk; server si nic nedomýšlí.
                    'why' => (string) ($m->notes ?: $m->caption ?: ''),
                    'left' => $do ? $this->zbyva($dnes, $do) : 'bez lhůty',
                    'n' => (int) $m->id,
                    'expired' => $do !== null && $do->lt($dnes),
                    // Kolik se pustením opravdu uvolní. Prototyp měl napsaný
                    // odhad 3,4 MB na položku pro všechno od screenshotu po video.
                    'mb' => round((int) $m->size_bytes / 1_048_576, 1),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Snímky bez data a odhad roku: `{ id, name, meta, guess, span, conf, n, reasons, bg }`.
     *
     * Odhad stojí **jen na tom, co aplikace opravdu vidí** — na fotkách kolem
     * téhle. Ukázka odůvodňovala rok tím, že „auto na snímku je Škoda 100,
     * vyráběná 1969–1977"; tohle aplikace nepozná a předstírat to znamená dát
     * dvojici jistotu, kterou nemá.
     *
     * Každý důvod jde ověřit: sousední soubor, tentýž import, totéž album,
     * tentýž fotoaparát. Když neplatí ani jeden, snímek se **pořád nabídne** —
     * jen bez odhadu. Datovat ho může jen člověk, a to je taky odpověď.
     *
     * @return list<array<string, mixed>>
     */
    private function kDatovani(GallerySpace $prostor): array
    {
        $bezData = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->where('is_archived', false)
            ->whereNull('taken_at')
            ->orderBy('original_filename')
            ->limit(40)
            ->get();

        if ($bezData->isEmpty()) {
            return [];
        }

        $sDatem = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->whereNotNull('taken_at')
            ->get(['id', 'original_filename', 'taken_at', 'primary_album_id', 'camera_make', 'camera_model', 'uploaded_at']);

        return $bezData
            ->map(function (MediaItem $m) use ($sDatem) {
                [$roky, $duvody] = $this->odhadRoku($m, $sDatem);

                return [
                    'id' => $m->uuid,
                    'name' => $m->original_filename,
                    'meta' => $this->popisSnimku($m),
                    'guess' => $roky ? (string) $this->stred($roky) : '',
                    'span' => $roky ? (int) ceil((max($roky) - min($roky)) / 2) : 0,
                    'conf' => $this->jistota($roky, count($duvody)),
                    'n' => (int) $m->id,
                    'reasons' => $duvody ?: ['Aplikace nemá z čeho vyjít — kolem téhle fotky není nic s datem.'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Roky, ke kterým ukazují stopy kolem snímku, a proč.
     *
     * @param  Collection<int, MediaItem>  $sDatem
     * @return array{0: list<int>, 1: list<string>}
     */
    private function odhadRoku(MediaItem $m, Collection $sDatem): array
    {
        $roky = [];
        $duvody = [];

        $rok = fn (object $f) => (int) CarbonImmutable::parse($f->taken_at)->year;

        // Sousední soubor: `sken_0142` a `sken_0143` jsou z jedné role filmu.
        $sousedi = $sDatem->filter(fn (object $f) => $this->sousedni($m->original_filename, $f->original_filename));

        if ($sousedi->isNotEmpty()) {
            $roky = array_merge($roky, $sousedi->map($rok)->all());
            $duvody[] = 'Sousední soubor '.$sousedi->first()->original_filename.' má datum '
                .CarbonImmutable::parse($sousedi->first()->taken_at)->format('j. n. Y').'.';
        }

        // Tentýž import: co přišlo v jedné dávce, bývá z jedné doby.
        if ($m->uploaded_at) {
            $davka = $sDatem->filter(fn (object $f) => $f->uploaded_at
                && abs(CarbonImmutable::parse($f->uploaded_at)->diffInMinutes(CarbonImmutable::parse($m->uploaded_at))) < 5);

            if ($davka->isNotEmpty()) {
                $roky = array_merge($roky, $davka->map($rok)->all());
                $duvody[] = 'Přišel v jednom importu s '
                    .($davka->count() === 1 ? 'fotkou' : 'fotkami').' z '
                    .$this->rozsah($davka->map($rok)->all()).'.';
            }
        }

        // Totéž album.
        if ($m->primary_album_id) {
            $album = $sDatem->where('primary_album_id', $m->primary_album_id);

            if ($album->isNotEmpty()) {
                $roky = array_merge($roky, $album->map($rok)->all());
                $duvody[] = 'Ve stejném albu jsou fotky z '.$this->rozsah($album->map($rok)->all()).'.';
            }
        }

        // Tentýž fotoaparát: přístroj se používá pár let, ne pořád.
        if ($m->camera_model) {
            $pristroj = $sDatem->where('camera_model', $m->camera_model);

            if ($pristroj->isNotEmpty()) {
                $roky = array_merge($roky, $pristroj->map($rok)->all());
                $duvody[] = 'Týmž přístrojem ('.trim($m->camera_make.' '.$m->camera_model)
                    .') jste fotili v '.$this->rozsah($pristroj->map($rok)->all()).'.';
            }
        }

        return [array_values(array_unique($roky)), $duvody];
    }

    /** `sken_0142` a `sken_0143` — stejný název, číslo o jedničku vedle. */
    private function sousedni(string $a, string $b): bool
    {
        if (! preg_match('/^(.*?)(\d+)(\.[^.]+)$/', $a, $prvni)
            || ! preg_match('/^(.*?)(\d+)(\.[^.]+)$/', $b, $druhy)) {
            return false;
        }

        return $prvni[1] === $druhy[1]
            && $prvni[3] === $druhy[3]
            && abs((int) $prvni[2] - (int) $druhy[2]) === 1;
    }

    /**
     * Jistota odhadu.
     *
     * Roste s počtem stop a klesá s tím, jak daleko od sebe ty stopy leží.
     * Jedna stopa napříč dvaceti lety není odhad, je to tušení — a tak se to
     * i napíše.
     *
     * @param  list<int>  $roky
     */
    private function jistota(array $roky, int $duvodu): int
    {
        if (! $roky) {
            return 0;
        }

        $rozpeti = max($roky) - min($roky);

        return max(20, min(93, 60 + $duvodu * 12 - $rozpeti * 6));
    }

    /** @param  list<int>  $roky */
    private function stred(array $roky): int
    {
        sort($roky);

        return $roky[(int) floor((count($roky) - 1) / 2)];
    }

    /** @param  list<int>  $roky */
    private function rozsah(array $roky): string
    {
        $od = min($roky);
        $do = max($roky);

        return $od === $do ? (string) $od : $od.'–'.$do;
    }

    private function popisSnimku(MediaItem $m): string
    {
        return implode(' · ', array_filter([
            $m->media_type === 'video' ? 'video bez data' : 'bez data',
            $m->width && $m->height ? $m->width.' × '.$m->height : null,
            $m->location_name,
            $this->velikost((int) $m->size_bytes),
        ]));
    }

    /**
     * Výběry fotek: `{ anniv, show, contact }` s pořadovými čísly do mřížky.
     *
     * Prototyp z nich skládá dlaždice (`photos()[i]`), takže to musí být
     * **pořadí v téže mřížce**, kterou posílá knihovna — ne identifikátory.
     *
     * @return array<string, array<string, mixed>>
     */
    private function vybery(GallerySpace $prostor): array
    {
        $mrizka = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
            ->orderByDesc('taken_at')
            ->orderByDesc('uploaded_at')
            ->limit(self::FOTEK)
            ->get(['id', 'is_favorite', 'taken_at']);

        if ($mrizka->isEmpty()) {
            return [];
        }

        $oblibene = $mrizka->keys()->filter(fn (int $i) => (bool) $mrizka[$i]->is_favorite)->values();

        return array_filter([
            // Jedna fotka za rok — z toho se skládá výroční album.
            'anniv' => $this->vyber($this->poRocich($mrizka), 'Vybráno automaticky z každého roku — pořadí i výběr jde změnit.'),
            'show' => $this->vyber($oblibene->take(12)->all(), 'Aktuální výběr pro promítání i televizi.'),
            'contact' => $this->vyber(range(0, min(23, $mrizka->count() - 1)), 'Kontaktní arch — 24 náhledů na stránku A4.'),
        ], fn ($v) => $v !== null);
    }

    /**
     * Po jedné fotce z každého roku, od nejnovějšího.
     *
     * @param  Collection<int, MediaItem>  $mrizka
     * @return list<int>
     */
    private function poRocich(Collection $mrizka): array
    {
        $videno = [];
        $poradi = [];

        foreach ($mrizka as $i => $m) {
            $rok = $m->taken_at ? CarbonImmutable::parse($m->taken_at)->year : 0;

            if ($rok === 0 || isset($videno[$rok])) {
                continue;
            }

            $videno[$rok] = true;
            $poradi[] = $i;
        }

        return $poradi;
    }

    /**
     * @param  list<int>  $poradi
     * @return array<string, mixed>|null
     */
    private function vyber(array $poradi, string $poznamka): ?array
    {
        return $poradi ? ['idx' => array_values($poradi), 'note' => $poznamka] : null;
    }

    /**
     * Zakázky do tisku: `[id, druh, název, stav, kusů, barva, popis]`.
     *
     * @return list<array<int, mixed>>
     */
    private function zakazky(GallerySpace $prostor): array
    {
        if (! Schema::hasTable('photo_books')) {
            return [];
        }

        return DB::table('photo_books')
            ->where('gallery_space_id', $prostor->id)
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn (object $z) => [
                $z->uuid,
                $this->druhTisku((string) $z->purpose),
                $z->name,
                $this->stavTisku($z),
                (int) $z->item_count,
                (int) $z->id,
                trim((string) ($z->description ?: $this->popisVyberu($z))),
            ])
            ->values()
            ->all();
    }

    private function popisVyberu(object $z): string
    {
        if (! $z->target_count) {
            return $this->pocet((int) $z->item_count, 'fotka', 'fotky', 'fotek');
        }

        return 'vybráno '.$z->item_count.' z '.$z->target_count;
    }

    private function stavTisku(object $z): string
    {
        if ($z->target_count && $z->item_count >= $z->target_count) {
            return 'ke korektuře';
        }

        return 'rozpracováno';
    }

    private function druhTisku(string $ucel): string
    {
        return match ($ucel) {
            'photobook' => 'kniha',
            'print' => 'ramecek',
            'gift' => 'prani',
            default => 'kniha',
        };
    }

    // ——— formát ———

    private function zbyva(CarbonImmutable $dnes, CarbonImmutable $do): string
    {
        if ($do->lt($dnes)) {
            return 'lhůta vypršela';
        }

        $dni = (int) ceil($dnes->diffInDays($do));

        // Do měsíce se počítá po dnech, dál se zaokrouhluje: člověk čte „čtyři
        // měsíce", ne „3,97 měsíce" — a useknutí dolů by ze čtyř udělalo tři.
        return $dni <= 30
            ? $this->pocet(max(1, $dni), 'den', 'dny', 'dní')
            : $this->pocet((int) round($dnes->diffInMonths($do)), 'měsíc', 'měsíce', 'měsíců');
    }

    /** Neznámá velikost se mlčí — „0 kB" je horší než nic. */
    private function velikost(int $bajtu): ?string
    {
        if ($bajtu <= 0) {
            return null;
        }

        $mb = $bajtu / 1_048_576;

        return $mb >= 1
            ? str_replace('.', ',', (string) round($mb, 1)).' MB'
            : str_replace('.', ',', (string) max(1, round($bajtu / 1024))).' kB';
    }

    private function pocet(int $kolik, string $jeden, string $dva, string $pet): string
    {
        return $kolik.' '.match (true) {
            $kolik === 1 => $jeden,
            $kolik >= 2 && $kolik <= 4 => $dva,
            default => $pet,
        };
    }
}
