<?php

namespace App\Services\Tisk;

use App\Models\MediaItem;
use App\Support\Cas;
use App\Support\SpaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fotky „k tisku" a sady, které se z nich stáhly.
 *
 * Označení patří prostoru (oba z dvojice vidí totéž), ne sdílenému stavu:
 * ten se skládá z opisů obou zařízení a starší opis by druhému označení
 * vrátil nebo smazal. „Stáhnout vše" označené fotky přesune do sady
 * (`print_batches`) — seznam se vyprázdní a sada zůstane v historii, odkud
 * jde stáhnout znovu.
 *
 * Koš a trezor se nikdy neukazují ani nestahují. Označení ani členství
 * v sadě se jimi nemaže: po vrácení fotky z koše je zase, kde byla.
 */
class KTisku
{
    /** Víc fotek jedna sada nenese — archiv by server skládal minuty (jako `AlbumArchivController`). */
    public const STROP = 500;

    /** Označí fotku; druhé označení nic nezdvojí. Vrací počet označených. */
    public function oznac(int $prostorId, MediaItem $media, ?int $kdo): int
    {
        DB::table('print_marks')->insertOrIgnore([
            'gallery_space_id' => $prostorId,
            'media_item_id' => $media->id,
            'marked_by' => $kdo,
            'marked_at' => now(),
        ]);

        return $this->pocet($prostorId);
    }

    /** Zruší označení (kdokoli z dvojice). Vrací počet označených. */
    public function zrus(int $prostorId, MediaItem $media): int
    {
        DB::table('print_marks')
            ->where('gallery_space_id', $prostorId)
            ->where('media_item_id', $media->id)
            ->delete();

        return $this->pocet($prostorId);
    }

    /** Kolik označených fotek je vidět (bez koše a trezoru). */
    public function pocet(int $prostorId): int
    {
        return $this->viditelne($prostorId)
            ->whereIn('media_items.id', DB::table('print_marks')->where('gallery_space_id', $prostorId)->select('media_item_id'))
            ->count();
    }

    /**
     * Označené fotky v pořadí označení — nejdřív ta, která se označila první.
     *
     * @return Collection<int, MediaItem>
     */
    public function oznacene(int $prostorId, int $strop = self::STROP): Collection
    {
        return $this->viditelne($prostorId)
            ->join('print_marks', function ($spoj) use ($prostorId) {
                $spoj->on('print_marks.media_item_id', '=', 'media_items.id')
                    ->where('print_marks.gallery_space_id', '=', $prostorId);
            })
            ->orderBy('print_marks.marked_at')
            ->orderBy('print_marks.id')
            ->limit($strop)
            ->select('media_items.*')
            ->get();
    }

    /**
     * Sada ze všech označených fotek; označení se do ní přesunou.
     *
     * `null`, když není co stáhnout. Dvě současná klepnutí (každý na svém
     * telefonu) nesmí založit dvě sady z týchž fotek: označení se mažou
     * v transakci a když jich druhý požadavek smaže méně, než přečetl,
     * první už je vzal — transakce se vrátí a volající dostane `false`.
     *
     * @return object|false|null řádek `print_batches`; false = sadu právě založil druhý
     */
    public function zalozSadu(int $prostorId, ?int $kdo): object|false|null
    {
        DB::beginTransaction();

        try {
            $vysledek = $this->zalozVTransakci($prostorId, $kdo);
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if ($vysledek === false || $vysledek === null) {
            DB::rollBack();
        } else {
            DB::commit();
        }

        return $vysledek;
    }

    private function zalozVTransakci(int $prostorId, ?int $kdo): object|false|null
    {
        $media = $this->oznacene($prostorId);

        if ($media->isEmpty()) {
            return null;
        }

        $smazano = DB::table('print_marks')
            ->where('gallery_space_id', $prostorId)
            ->whereIn('media_item_id', $media->pluck('id'))
            ->delete();

        if ($smazano !== $media->count()) {
            return false;
        }

        // Den podle Prahy — v UTC by sada po půlnoci nesla včerejší datum.
        $dnes = Cas::dnes();
        $poradi = DB::table('print_batches')
            ->where('gallery_space_id', $prostorId)
            ->where('file_stem', 'like', 'K-tisku-'.$dnes->toDateString().'%')
            ->count() + 1;

        $id = DB::table('print_batches')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostorId,
            'created_by' => $kdo,
            'name' => 'K tisku '.$dnes->format('j. n. Y').($poradi > 1 ? ' ('.$poradi.')' : ''),
            'file_stem' => 'K-tisku-'.$dnes->toDateString().($poradi > 1 ? '-'.$poradi : ''),
            'items_count' => $media->count(),
            'download_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('print_batch_items')->insert($media->values()->map(fn (MediaItem $m, int $i) => [
            'print_batch_id' => $id,
            'media_item_id' => $m->id,
            'position' => $i + 1,
        ])->all());

        return DB::table('print_batches')->where('id', $id)->first();
    }

    /** Sada dvojice podle uuid, jinak 404 — cizí sada se tváří jako neexistující. */
    public function sada(int $prostorId, string $uuid): object
    {
        $sada = DB::table('print_batches')
            ->where('gallery_space_id', $prostorId)
            ->where('uuid', $uuid)
            ->first();

        abort_if($sada === null, 404, 'Takovou sadu k tisku aplikace nezná.');

        return $sada;
    }

    /**
     * Fotky sady v jejím pořadí, bez koše a trezoru, s variantou `original`.
     *
     * @return Collection<int, MediaItem>
     */
    public function mediaSady(object $sada): Collection
    {
        return $this->viditelne((int) $sada->gallery_space_id)
            ->join('print_batch_items', 'print_batch_items.media_item_id', '=', 'media_items.id')
            ->where('print_batch_items.print_batch_id', $sada->id)
            ->orderBy('print_batch_items.position')
            ->select('media_items.*')
            ->with(['variants' => fn ($q) => $q->where('type', 'original')])
            ->get();
    }

    /** Zapíše stažení sady (archiv nebo uložení do telefonu). */
    public function zapisStazeni(object $sada): object
    {
        DB::table('print_batches')->where('id', $sada->id)->update([
            'download_count' => DB::raw('download_count + 1'),
            'downloaded_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('print_batches')->where('id', $sada->id)->first();
    }

    /**
     * Historie sad, nejnovější první.
     *
     * @return list<array<string, mixed>>
     */
    public function sady(int $prostorId, int $kolik = 30): array
    {
        $sady = DB::table('print_batches')
            ->where('gallery_space_id', $prostorId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($kolik)
            ->get();

        if ($sady->isEmpty()) {
            return [];
        }

        $jmena = DB::table('users')->whereIn('id', $sady->pluck('created_by')->filter())->pluck('name', 'id');
        $dostupne = $this->viditelne($prostorId)
            ->join('print_batch_items', 'print_batch_items.media_item_id', '=', 'media_items.id')
            ->whereIn('print_batch_items.print_batch_id', $sady->pluck('id'))
            ->groupBy('print_batch_items.print_batch_id')
            ->selectRaw('print_batch_items.print_batch_id as sada, count(*) as pocet')
            ->toBase()
            ->pluck('pocet', 'sada');

        return $sady->map(fn (object $s) => $this->naKlienta($s, (int) ($dostupne[$s->id] ?? 0), $jmena[$s->created_by] ?? null))
            ->values()->all();
    }

    /** @return array<string, mixed> */
    public function naKlienta(object $sada, ?int $dostupne = null, ?string $kdo = null): array
    {
        return [
            'id' => $sada->uuid,
            'nazev' => $sada->name,
            'pocet' => (int) $sada->items_count,
            // Kolik z nich jde stáhnout teď — bez koše a trezoru.
            'dostupne' => $dostupne ?? (int) $sada->items_count,
            'vytvoreno' => Cas::mistni($sada->created_at)?->format('j. n. Y H:i'),
            'kdo' => $kdo,
            'stazeno' => Cas::mistni($sada->downloaded_at)?->format('j. n. Y H:i'),
            'stazeniPocet' => (int) $sada->download_count,
            'archiv' => 'k-tisku/sady/'.$sada->uuid.'/archiv',
            'zip' => $sada->file_stem.'.zip',
        ];
    }

    /**
     * Soubory k uložení do telefonu — cesta k originálu přes přihlášené API.
     *
     * Veřejná adresa by platila i pro toho, kdo se do galerie nikdy nedostal;
     * `media/{uuid}/raw` hlídá dvojici, trezor i koš.
     *
     * @param  Collection<int, MediaItem>  $media
     * @return list<array<string, mixed>>
     */
    public function soubory(Collection $media): array
    {
        return $media->map(fn (MediaItem $m) => [
            'id' => $m->uuid,
            'name' => basename(str_replace('\\', '/', (string) $m->original_filename)) ?: 'fotka.jpg',
            'mime' => $m->mime_type ?: 'image/jpeg',
            'bytes' => (int) $m->size_bytes,
            'cesta' => 'media/'.$m->uuid.'/raw',
        ])->values()->all();
    }

    /** @return Builder<MediaItem> */
    private function viditelne(int $prostorId): Builder
    {
        return MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('media_items.gallery_space_id', $prostorId)
            ->whereNull('media_items.trashed_at')
            ->where('media_items.is_hidden', false);
    }
}
