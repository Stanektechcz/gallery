<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Support\SpaceContext;
use App\Support\Tabulky;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * `LIBSTATS` — statistiky přes celou knihovnu, spočítané v databázi.
 *
 * `Knihovna::statistiky()` dřív stahovala každý viditelný řádek prostoru (bez
 * `LIMIT`, dvanáct sloupců) a počítala v PHP — u dvojice s deseti tisíci
 * fotkami to znamenalo jeden dotaz vracející tisíce řádků a `CarbonImmutable::parse()`
 * až dvakrát na řádek, při každém otevření aplikace (`knihovna` je `no-store`,
 * viz `DataController::BEZ_PAMETI`). `roky()` a `fotekPoDnech()` v tomtéž
 * souboru přitom agregaci v SQL už dělaly — tahle třída dělá totéž pro zbytek
 * `LIBSTATS` a žije mimo `Knihovna.php`, ať ten soubor dál neroste za tisíc
 * řádků jedinou další odpovědností.
 *
 * `CarbonImmutable::parse($kdy)` v původním kódu nikdy neměnila pásmo (žádné
 * `setTimezone()`), jen přečetla uloženou hodnotu — `taken_at`/`uploaded_at`/
 * `created_at` jsou v UTC (`config('app.timezone')`). `SUBSTR` na syrovém
 * řetězci proto dá týž rok, měsíc i hodinu jako dřívější `CarbonImmutable`,
 * beze změny výsledku (viz i komentář u `Knihovna::roky()`).
 *
 * Skupinování podle textového sloupce (místo, výrobce, model, objektiv) je
 * citlivé na porovnávání řetězců databáze: MySQL `*_ci` kolace (výchozí pro
 * `utf8mb4_unicode_ci`/`utf8mb4_general_ci`) nerozlišuje velikost písmen a
 * u některých znaků ani diakritiku, takže by `GROUP BY location_name` mohlo
 * sloučit „Plzeň" a „PLZEŇ" (případně i „Plzen") do jedné skupiny, zatímco
 * PHP `trim()`/`explode()` je vidí jako dvě různé hodnoty. SQLite (testy)
 * porovnává byte-přesně, takže se to v testech neprojeví — rozdíl by se
 * ukázal až na produkční MySQL databázi. Proto se seskupuje podle syrového
 * sloupce a teprve v PHP (nad už agregovanými, mnohem menšími řádky) se
 * dělá `trim()`/`explode()`/skládání jména přístroje — tvar výstupu tak
 * zůstává identický s původním PHP průchodem.
 */
class StatistikyKnihovny
{
    /**
     * @return array<string, mixed>
     */
    public function spocitej(GallerySpace $prostor): array
    {
        $souhrn = $this->zaklad($prostor)
            ->selectRaw(
                'COUNT(*) AS celkem, '.
                "SUM(CASE WHEN media_type = 'video' THEN 1 ELSE 0 END) AS videos, ".
                "SUM(CASE WHEN COALESCE(status, '') NOT IN ('ready', 'failed') THEN 1 ELSE 0 END) AS pending, ".
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS errors, ".
                "SUM(CASE WHEN media_type = 'video' AND duration_ms > 0 THEN duration_ms ELSE 0 END) AS trvani_soucet, ".
                "SUM(CASE WHEN media_type = 'video' AND duration_ms > 0 THEN 1 ELSE 0 END) AS trvani_pocet"
            )
            ->first();

        if ($souhrn === null || (int) $souhrn->celkem === 0) {
            return Knihovna::PRAZDNE_STATISTIKY;
        }

        $s = Knihovna::PRAZDNE_STATISTIKY;
        $s['total'] = (int) $souhrn->celkem;
        $s['videos'] = (int) $souhrn->videos;
        $s['pending'] = (int) $souhrn->pending;
        $s['errors'] = (int) $souhrn->errors;
        $s['videoAvg'] = $souhrn->trvani_pocet > 0
            ? (int) round($souhrn->trvani_soucet / $souhrn->trvani_pocet / 1000)
            : null;

        $ja = auth()->id();
        $maOblibene = $ja !== null && Tabulky::je('user_favorites');

        $s['favs'] = $maOblibene ? $this->pocetOblibenych($prostor, $ja) : 0;

        $autoriById = $this->autoriZaklad($prostor);

        if ($maOblibene) {
            $this->doplnOblibeneAutoru($prostor, $ja, $autoriById);
        }

        $this->scitejMesice($prostor, $s);
        $this->scitejHodiny($prostor, $s, $autoriById);
        $this->scitejMista($prostor, $s, $autoriById);
        $this->scitejPristroje($prostor, $s);
        $this->scitejObjektivy($prostor, $s);

        arsort($s['places']);
        $s['placeN'] = count($s['places']);
        // Míst bývají stovky; obrazovka ukazuje prvních pár a počet.
        $s['places'] = array_slice($s['places'], 0, 40, true);

        // Pole z klíčů `0–11` a let by JSON poslal jako seznam; obrazovka čte mapu.
        $s['years'] = (object) $s['years'];
        $s['months'] = (object) $s['months'];
        $s['places'] = (object) $s['places'];
        $s['dev'] = (object) $s['dev'];
        $s['lens'] = (object) $s['lens'];
        $s['autori'] = (object) $this->uzavriAutory($autoriById);

        return $s;
    }

    /** Základní dotaz — stejný filtr jako `roky()`/`fotekPoDnech()`: prostor, bez koše, bez skrytých. */
    private function zaklad(GallerySpace $prostor): Builder
    {
        return MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
            ->toBase();
    }

    private function pocetOblibenych(GallerySpace $prostor, int $ja): int
    {
        return (int) $this->zaklad($prostor)
            ->whereIn('id', DB::table('user_favorites')->where('user_id', $ja)->select('media_item_id'))
            ->count();
    }

    /**
     * Autoři podle `uploaded_by`, ještě bez jména (to se dohledává, jakmile jsou
     * hotové všechny agregace — jedním dotazem na `users`, ne po jednom).
     *
     * @return array<int, array{count: int, videos: int, favs: int, mista: array<string, int>, hodiny: array<int, int>}>
     */
    private function autoriZaklad(GallerySpace $prostor): array
    {
        $radky = $this->zaklad($prostor)
            ->selectRaw("uploaded_by, COUNT(*) AS pocet, SUM(CASE WHEN media_type = 'video' THEN 1 ELSE 0 END) AS videos")
            ->groupBy('uploaded_by')
            ->orderBy('uploaded_by')
            ->get();

        $autori = [];

        foreach ($radky as $r) {
            if ($r->uploaded_by === null) {
                continue;
            }

            $autori[(int) $r->uploaded_by] = [
                'count' => (int) $r->pocet,
                'videos' => (int) $r->videos,
                'favs' => 0,
                'mista' => [],
                'hodiny' => array_fill(0, 24, 0),
            ];
        }

        return $autori;
    }

    /**
     * @param  array<int, array{count: int, videos: int, favs: int, mista: array<string, int>, hodiny: array<int, int>}>  $autoriById
     */
    private function doplnOblibeneAutoru(GallerySpace $prostor, int $ja, array &$autoriById): void
    {
        $radky = $this->zaklad($prostor)
            ->whereIn('id', DB::table('user_favorites')->where('user_id', $ja)->select('media_item_id'))
            ->selectRaw('uploaded_by, COUNT(*) AS pocet')
            ->groupBy('uploaded_by')
            ->get();

        foreach ($radky as $r) {
            if ($r->uploaded_by !== null && isset($autoriById[$r->uploaded_by])) {
                $autoriById[$r->uploaded_by]['favs'] = (int) $r->pocet;
            }
        }
    }

    /**
     * Roky a měsíce — stejný zdroj data jako `fotekPoDnech()` (`taken_at` nebo
     * náhrada), ale `kdy` je díky `created_at` vždy vyplněné, takže se počítá
     * úplně každý řádek (na rozdíl od hodin, které bez `taken_at` nic nepočítají).
     *
     * @param  array<string, mixed>  $s
     */
    private function scitejMesice(GallerySpace $prostor, array &$s): void
    {
        $vyraz = 'COALESCE(taken_at, uploaded_at, created_at)';

        $radky = $this->zaklad($prostor)
            ->selectRaw("SUBSTR($vyraz, 1, 4) AS rok, SUBSTR($vyraz, 6, 2) AS mesic, COUNT(*) AS pocet")
            ->groupByRaw("SUBSTR($vyraz, 1, 4), SUBSTR($vyraz, 6, 2)")
            ->get();

        foreach ($radky as $r) {
            $rok = (int) $r->rok;
            $mesic = ((int) $r->mesic) - 1;
            $pocet = (int) $r->pocet;

            $s['years'][$rok] = ($s['years'][$rok] ?? 0) + $pocet;
            $s['months'][$mesic] = ($s['months'][$mesic] ?? 0) + $pocet;
        }
    }

    /**
     * Hodiny — jen ze snímků, které vůbec mají `taken_at` (stejně jako dřív).
     *
     * @param  array<string, mixed>  $s
     * @param  array<int, array{count: int, videos: int, favs: int, mista: array<string, int>, hodiny: array<int, int>}>  $autoriById
     */
    private function scitejHodiny(GallerySpace $prostor, array &$s, array &$autoriById): void
    {
        $radky = $this->zaklad($prostor)
            ->whereNotNull('taken_at')
            ->selectRaw('uploaded_by, SUBSTR(taken_at, 12, 2) AS hodina, COUNT(*) AS pocet')
            ->groupBy('uploaded_by')
            ->groupByRaw('SUBSTR(taken_at, 12, 2)')
            ->get();

        foreach ($radky as $r) {
            $h = (int) $r->hodina;
            $pocet = (int) $r->pocet;

            $s['hours'][$h] += $pocet;

            if ($r->uploaded_by !== null && isset($autoriById[$r->uploaded_by])) {
                $autoriById[$r->uploaded_by]['hodiny'][$h] += $pocet;
            }
        }
    }

    /**
     * Místa — jen první část adresy (viz komentář o kolaci nahoře), skládaná
     * z agregovaných řádků (řádově stovky, ne desetitisíce fotek).
     *
     * @param  array<string, mixed>  $s
     * @param  array<int, array{count: int, videos: int, favs: int, mista: array<string, int>, hodiny: array<int, int>}>  $autoriById
     */
    private function scitejMista(GallerySpace $prostor, array &$s, array &$autoriById): void
    {
        $radky = $this->zaklad($prostor)
            ->whereNotNull('location_name')
            ->where('location_name', '!=', '')
            ->selectRaw('uploaded_by, location_name, COUNT(*) AS pocet')
            ->groupBy('uploaded_by', 'location_name')
            ->get();

        foreach ($radky as $r) {
            $misto = trim(explode(',', (string) $r->location_name)[0]);

            if ($misto === '') {
                continue;
            }

            $pocet = (int) $r->pocet;
            $s['places'][$misto] = ($s['places'][$misto] ?? 0) + $pocet;

            if ($r->uploaded_by !== null && isset($autoriById[$r->uploaded_by])) {
                $autoriById[$r->uploaded_by]['mista'][$misto] = ($autoriById[$r->uploaded_by]['mista'][$misto] ?? 0) + $pocet;
            }
        }
    }

    /**
     * Přístroje — bez filtru (i prázdný výrobce/model se počítá, jako dřív).
     *
     * @param  array<string, mixed>  $s
     */
    private function scitejPristroje(GallerySpace $prostor, array &$s): void
    {
        $radky = $this->zaklad($prostor)
            ->selectRaw('camera_make, camera_model, COUNT(*) AS pocet')
            ->groupBy('camera_make', 'camera_model')
            ->get();

        foreach ($radky as $r) {
            $pristroj = trim((string) ($r->camera_model ?? '')) !== ''
                ? (trim((string) $r->camera_make) === '' || stripos((string) $r->camera_model, (string) $r->camera_make) !== false
                    ? trim((string) $r->camera_model)
                    : trim($r->camera_make.' '.$r->camera_model))
                : trim((string) ($r->camera_make ?? ''));

            $s['dev'][$pristroj] = ($s['dev'][$pristroj] ?? 0) + (int) $r->pocet;
        }
    }

    /**
     * Objektivy — bez filtru, stejně jako přístroje.
     *
     * @param  array<string, mixed>  $s
     */
    private function scitejObjektivy(GallerySpace $prostor, array &$s): void
    {
        $radky = $this->zaklad($prostor)
            ->selectRaw('lens_model, COUNT(*) AS pocet')
            ->groupBy('lens_model')
            ->get();

        foreach ($radky as $r) {
            $objektiv = trim((string) ($r->lens_model ?? ''));
            $s['lens'][$objektiv] = ($s['lens'][$objektiv] ?? 0) + (int) $r->pocet;
        }
    }

    /**
     * Jméno se dohledává až na konci, jedním dotazem na `users` pro všechny
     * autory najednou — smazaný uživatel bez záznamu v `users` se stejně jako
     * dřív do `autori` vůbec nedostane.
     *
     * Autoři stejného jména (dvě různá `id`) by přepsali jeden druhého — to je
     * chování, které mělo i původní PHP (klíčem byla jména, ne `id`).
     *
     * @param  array<int, array{count: int, videos: int, favs: int, mista: array<string, int>, hodiny: array<int, int>}>  $autoriById
     * @return array<string, array{count: int, favs: int, videos: int, place: ?string, hour: ?int}>
     */
    private function uzavriAutory(array $autoriById): array
    {
        $jmena = DB::table('users')->whereIn('id', array_keys($autoriById))->pluck('name', 'id');

        $vysledek = [];

        foreach ($autoriById as $id => $a) {
            $kdo = $jmena[$id] ?? null;

            if ($kdo === null) {
                continue;
            }

            arsort($a['mista']);
            $nejHodina = max($a['hodiny']) > 0 ? array_search(max($a['hodiny']), $a['hodiny'], true) : null;

            $vysledek[$kdo] = [
                'count' => $a['count'],
                'favs' => $a['favs'],
                'videos' => $a['videos'],
                'place' => array_key_first($a['mista']),
                'hour' => $nejHodina,
            ];
        }

        return $vysledek;
    }
}
