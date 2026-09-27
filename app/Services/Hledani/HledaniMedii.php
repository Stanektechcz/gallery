<?php

namespace App\Services\Hledani;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Support\FulltextDotaz;
use App\Support\SpaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hledání fotek podle textu — jedno místo pro staré API i prototyp.
 *
 * Dřív hledal jen starý `/api/v1/search`, a to celý dotaz naráz: „hoře Lysé"
 * nenašlo „Chata na Lysé hoře", na SQLite ani „LYSE", a jedno slovo navíc
 * (překlep, věc, která na fotce není) vrátilo prázdno. Prototyp se serveru
 * neptal vůbec a filtroval jen 240 nejnovějších fotek v prohlížeči.
 *
 * Teď se hledá po stupních a skončí u prvního, který něco najde:
 *
 *  1. všechna slova (MySQL FULLTEXT `+slovo*`, řazení podle skóre),
 *  2. aspoň jedno slovo (FULLTEXT bez `+`, víc zásahů = výš),
 *  3. a 4. totéž přes `LIKE` po slovech — na SQLite jediná cesta, na MySQL
 *     záchrana, když FULLTEXT nenajde nic: slovo uvnitř tokenu („1234"
 *     v „img_1234"), stopslovo, nebo řádek zapsaný v téže transakci
 *     (InnoDB ho do indexu vloží až při potvrzení).
 *
 * Slova jsou malá, zkrácená na kmen a bez diakritiky (`FulltextDotaz::hledanaSlova()`);
 * sloupec `search_text` nese vedle původního textu i jeho složenou kopii.
 */
class HledaniMedii
{
    /** Nejvíc položek na jednu stránku výsledků. */
    public const LIMIT_MAX = 120;

    /** Našlo se všechno, co bylo v dotazu. */
    public const UROVEN_VSE = 'and';

    /** Všechno naráz nic nenašlo; výsledky sedí aspoň na část slov. */
    public const UROVEN_CAST = 'or';

    /**
     * Hledání v tom, co ukazuje knihovna prototypu.
     *
     * Rozsah přesně jako `Knihovna::media()`: jeden prostor, bez koše,
     * **bez trezoru** (`is_hidden`) — i odemčeného; mřížka ho neukazuje nikdy.
     *
     * @param  array{media_type?: string}  $filtry
     * @return array{polozky: Collection<int, MediaItem>, celkem: int, uroven: ?string, fazety: array<string, int>}
     */
    public function hledej(GallerySpace $prostor, string $q, array $filtry = [], int $limit = 60, int $strana = 1): array
    {
        $zaklad = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->where('is_hidden', false);

        if (in_array($filtry['media_type'] ?? null, ['photo', 'video'], true)) {
            $zaklad->where('media_type', $filtry['media_type']);
        }

        return $this->vyhledej($zaklad, $q, null, $limit, $strana);
    }

    /**
     * Text nad už omezeným dotazem (prostor, filtry volajícího).
     *
     * @param  array{0: string, 1: string}|null  $razeni  `[sloupec, směr]`; bez něj se s textem
     *                                                    řadí podle shody, jinak od nejnovějších
     * @return array{polozky: Collection<int, MediaItem>, celkem: int, uroven: ?string, fazety: array<string, int>}
     */
    public function vyhledej(Builder $zaklad, string $q, ?array $razeni, int $limit, int $strana): array
    {
        $q = trim($q);
        $limit = max(1, min($limit, self::LIMIT_MAX));
        $strana = max(1, $strana);

        if ($q === '') {
            return $this->vysledek(clone $zaklad, ['uroven' => null, 'kde' => null, 'skore' => null], $razeni, $limit, $strana);
        }

        // Neplatné UTF-8 nenajde nic a do SQL nemá co nést.
        if (! mb_check_encoding($q, 'UTF-8')) {
            return ['polozky' => new Collection, 'celkem' => 0, 'uroven' => self::UROVEN_VSE, 'fazety' => $this->prazdneFazety()];
        }

        foreach ($this->stupne($q) as $stupen) {
            $vysledek = $this->vysledek(clone $zaklad, $stupen, $razeni, $limit, $strana);

            if ($vysledek['celkem'] > 0) {
                return $vysledek;
            }
        }

        return ['polozky' => new Collection, 'celkem' => 0, 'uroven' => self::UROVEN_VSE, 'fazety' => $this->prazdneFazety()];
    }

    /**
     * Stupně hledání v pořadí, jak se zkoušejí.
     *
     * Každý nese podmínku (`kde`) a zvlášť výraz skóre — podmínka jde i do
     * počtu, skóre jen do načtení stránky.
     *
     * @return list<array{uroven: string, kde: \Closure(Builder): void, skore: array{0: string, 1: list<mixed>}|null}>
     */
    private function stupne(string $q): array
    {
        $slova = FulltextDotaz::hledanaSlova($q);
        $soubor = $this->hledatVNazvuSouboru($q) ? $q : null;
        $stupne = [];

        if (DB::connection()->getDriverName() === 'mysql' && $slova !== []) {
            $stupne[] = $this->fulltext(self::UROVEN_VSE, (string) FulltextDotaz::zeSlov($slova, true), $soubor);

            if (count($slova) > 1) {
                $stupne[] = $this->fulltext(self::UROVEN_CAST, (string) FulltextDotaz::zeSlov($slova, false), null);
            }
        }

        // Jen krátká slova nebo stopslova („ok", „já a ty"): celý text jako jeden kus.
        $stupne[] = $this->likeVse($slova === [] ? [FulltextDotaz::slozit($q)] : $slova, $soubor);

        if (count($slova) > 1) {
            $stupne[] = $this->likeCast($slova);
        }

        return $stupne;
    }

    /**
     * Název souboru přes `LIKE` jen tehdy, když na tom záleží.
     *
     * Název je i v `search_text`, jenže InnoDB dělí „IMG_1234.jpg" na
     * `img_1234` a `jpg` — „1234" by se od začátku tokenu nenašlo. U dotazu
     * bez číslice, podtržítka a tečky tahle podmínka jen brání použít index.
     */
    private function hledatVNazvuSouboru(string $q): bool
    {
        return preg_match('/[\d_.]/u', $q) === 1;
    }

    /** @return array{uroven: string, kde: \Closure(Builder): void, skore: array{0: string, 1: list<mixed>}} */
    private function fulltext(string $uroven, string $booleovsky, ?string $soubor): array
    {
        return [
            'uroven' => $uroven,
            'kde' => function (Builder $dotaz) use ($booleovsky, $soubor) {
                $dotaz->where(function (Builder $w) use ($booleovsky, $soubor) {
                    $w->whereFullText($w->getModel()->qualifyColumn('search_text'), $booleovsky, ['mode' => 'boolean']);

                    if ($soubor !== null) {
                        $this->nazevSouboru($w, $soubor);
                    }
                });
            },
            'skore' => ["MATCH({$this->hledanySloupec()}) AGAINST(? IN BOOLEAN MODE)", [$booleovsky]],
        ];
    }

    /**
     * Všechna slova, každé kdekoli v textu.
     *
     * @param  list<string>  $slova
     * @return array{uroven: string, kde: \Closure(Builder): void, skore: null}
     */
    private function likeVse(array $slova, ?string $soubor): array
    {
        $podminka = $this->hledanySloupec()." LIKE ? ESCAPE '!'";

        return [
            'uroven' => self::UROVEN_VSE,
            'kde' => function (Builder $dotaz) use ($podminka, $slova, $soubor) {
                $dotaz->where(function (Builder $w) use ($podminka, $slova, $soubor) {
                    $w->where(function (Builder $vse) use ($podminka, $slova) {
                        foreach ($slova as $slovo) {
                            $vse->whereRaw($podminka, [$this->vzor($slovo)]);
                        }
                    });

                    if ($soubor !== null) {
                        $this->nazevSouboru($w, $soubor);
                    }
                });
            },
            'skore' => null,
        ];
    }

    /**
     * Aspoň jedno slovo; skóre je počet slov, která na fotce jsou.
     *
     * @param  list<string>  $slova
     * @return array{uroven: string, kde: \Closure(Builder): void, skore: array{0: string, 1: list<mixed>}}
     */
    private function likeCast(array $slova): array
    {
        $podminka = $this->hledanySloupec()." LIKE ? ESCAPE '!'";
        $vzory = array_map(fn (string $s) => $this->vzor($s), $slova);

        return [
            'uroven' => self::UROVEN_CAST,
            'kde' => function (Builder $dotaz) use ($podminka, $vzory) {
                $dotaz->where(function (Builder $w) use ($podminka, $vzory) {
                    foreach ($vzory as $vzor) {
                        $w->orWhereRaw($podminka, [$vzor]);
                    }
                });
            },
            'skore' => [
                '('.implode(' + ', array_fill(0, count($vzory), "CASE WHEN {$podminka} THEN 1 ELSE 0 END")).')',
                $vzory,
            ],
        ];
    }

    private function nazevSouboru(Builder $w, string $q): void
    {
        $sloupec = DB::connection()->getQueryGrammar()->wrap($w->getModel()->qualifyColumn('original_filename'));

        $w->orWhereRaw("{$sloupec} LIKE ? ESCAPE '!'", [$this->vzor($q)]);
    }

    /**
     * Počet, fazety a jedna stránka.
     *
     * Počty jedním dotazem s podmíněnými součty — dřív to byly čtyři
     * `COUNT(*)` nad týmž filtrem a pátý pro stránkování. Stránka se
     * nenačítá vůbec, když stupeň nenašel nic.
     *
     * @param  array{uroven: ?string, kde: (\Closure(Builder): void)|null, skore: array{0: string, 1: list<mixed>}|null}  $stupen
     * @param  array{0: string, 1: string}|null  $razeni
     * @return array{polozky: Collection<int, MediaItem>, celkem: int, uroven: ?string, fazety: array<string, int>}
     */
    private function vysledek(Builder $dotaz, array $stupen, ?array $razeni, int $limit, int $strana): array
    {
        if ($stupen['kde'] !== null) {
            ($stupen['kde'])($dotaz);
        }

        $fazety = $this->fazety($dotaz);

        if ($fazety['celkem'] === 0) {
            return ['polozky' => new Collection, 'celkem' => 0, 'uroven' => $stupen['uroven'], 'fazety' => $fazety];
        }

        $model = $dotaz->getModel();

        if ($stupen['skore'] !== null) {
            $dotaz->select($model->qualifyColumn('*'))
                ->selectRaw($stupen['skore'][0].' AS skore', $stupen['skore'][1]);
        }

        if ($razeni !== null) {
            $dotaz->orderBy($model->qualifyColumn($razeni[0]), $razeni[1]);
        } elseif ($stupen['skore'] !== null) {
            $dotaz->orderByDesc('skore');
        }

        $polozky = $dotaz
            ->orderByDesc($model->qualifyColumn('taken_at'))
            ->orderByDesc($model->qualifyColumn('uploaded_at'))
            ->orderByDesc($model->qualifyColumn('id'))
            ->forPage($strana, $limit)
            ->get();

        return ['polozky' => $polozky, 'celkem' => $fazety['celkem'], 'uroven' => $stupen['uroven'], 'fazety' => $fazety];
    }

    /** @return array<string, int> */
    private function fazety(Builder $dotaz): array
    {
        $radek = (clone $dotaz)->toBase()
            ->reorder()
            ->selectRaw('COUNT(*) AS celkem')
            ->selectRaw("SUM(CASE WHEN media_type = 'photo' THEN 1 ELSE 0 END) AS photos")
            ->selectRaw("SUM(CASE WHEN media_type = 'video' THEN 1 ELSE 0 END) AS videos")
            ->selectRaw('SUM(CASE WHEN is_favorite = ? THEN 1 ELSE 0 END) AS favorites', [true])
            ->selectRaw('SUM(CASE WHEN latitude IS NOT NULL AND longitude IS NOT NULL THEN 1 ELSE 0 END) AS with_gps')
            ->first();

        return [
            'celkem' => (int) ($radek->celkem ?? 0),
            'photos' => (int) ($radek->photos ?? 0),
            'videos' => (int) ($radek->videos ?? 0),
            'favorites' => (int) ($radek->favorites ?? 0),
            'with_gps' => (int) ($radek->with_gps ?? 0),
        ];
    }

    /** @return array<string, int> */
    private function prazdneFazety(): array
    {
        return ['celkem' => 0, 'photos' => 0, 'videos' => 0, 'favorites' => 0, 'with_gps' => 0];
    }

    /** `%slovo%` pro `LIKE … ESCAPE '!'` — `%` a `_` z textu jsou písmena, ne zástupné znaky. */
    private function vzor(string $text): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
    }

    private function hledanySloupec(): string
    {
        return DB::connection()->getQueryGrammar()->wrap((new MediaItem)->qualifyColumn('search_text'));
    }
}
