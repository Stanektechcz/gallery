<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Obsah\Pribeh;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Filmy, seriály a žebříček, které přišly jako změna stavu.
 *
 * `watch_titles` se jen četla. Přehled z ní kreslil pásma S až F, hvězdičky
 * se daly klikat, rozkoukaný seriál posouvat po dílech — a nic z toho nikam
 * nedošlo: po zavření záložky byl žebříček zase ukázkový a hodnocení pryč.
 *
 * Klíče jsou tu společné s ostatními obrazovkami: `xRows` drží seznamy všech
 * záložek, `rowDone` odškrtnuté řádky napříč aplikací. Zapisují se proto jen
 * ty, jejichž identifikátor začíná na `films-`, `series-` nebo `watchlist-`,
 * a ze stavu se vyhazují jen ony — zbytek aplikace si své klíče nechává.
 */
class FilmyVeStavu
{
    /** Seznamy, které tahle vrstva vlastní. */
    public const SEZNAMY = ['films', 'series', 'watchlist'];

    /** Klíče, ve kterých se hledají identifikátory titulů. */
    private const MAPY = ['tierMap', 'fmRate', 'fmEp', 'rowDone'];

    public function __construct(private readonly Pribeh $obsah) {}

    public function tykaSe(array $patch): bool
    {
        if (array_key_exists('xRows', $patch) && array_intersect(self::SEZNAMY, array_keys((array) $patch['xRows'])) !== []) {
            return true;
        }

        foreach (self::MAPY as $klic) {
            foreach (array_keys((array) ($patch[$klic] ?? [])) as $id) {
                if ($this->seznam((string) $id) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Patch bez toho, co si bere databáze — po jednotlivých titulech.
     *
     * `xRows` a `rowDone` patří i deseti dalším obrazovkám; vyhodit je celé
     * by znamenalo smazat nákupní seznam kvůli jednomu filmu.
     *
     * @return array<string, mixed>
     */
    public function bezFilmu(array $patch): array
    {
        if (is_array($patch['xRows'] ?? null)) {
            $patch['xRows'] = array_diff_key($patch['xRows'], array_flip(self::SEZNAMY));

            if ($patch['xRows'] === []) {
                unset($patch['xRows']);
            }
        }

        foreach (self::MAPY as $klic) {
            if (! is_array($patch[$klic] ?? null)) {
                continue;
            }

            $patch[$klic] = array_filter(
                $patch[$klic],
                fn (string $id) => $this->seznam($id) === null,
                ARRAY_FILTER_USE_KEY,
            );

            if ($patch[$klic] === []) {
                unset($patch[$klic]);
            }
        }

        // `tierOrder` je pořadí v pásmu a jiná obrazovka ho nepoužívá.
        unset($patch['tierOrder']);

        return $patch;
    }

    /** @return array<string, mixed> */
    public function zpracuj(array $patch, GallerySpace $prostor, ?User $uzivatel): array
    {
        if (! Schema::hasTable('watch_titles')) {
            return [];
        }

        // Řádky, ze kterých vznikly identifikátory na obrazovce. Pořadí je
        // tytéž, jaké posílá `AL` — jinak by `films-2` mířilo jinam.
        $podle = $this->podleId($prostor);

        if (is_array($patch['xRows'] ?? null)) {
            $podle = $this->zapisSeznamy((array) $patch['xRows'], $podle, $prostor, $uzivatel);
        }

        foreach (['fmRate' => 'hvezdicky', 'fmEp' => 'dily', 'rowDone' => 'videli', 'tierMap' => 'pasmo'] as $klic => $metoda) {
            foreach ((array) ($patch[$klic] ?? []) as $id => $hodnota) {
                $titul = $podle[(string) $id] ?? null;

                if ($titul !== null) {
                    $this->$metoda($titul, $hodnota, $prostor);
                }
            }
        }

        $this->poradi((array) ($patch['tierOrder'] ?? []), $podle);

        $obsah = $this->obsah->kolekce($prostor);

        return array_filter([
            'AL' => $obsah['AL'] ?? [],
            'ABARS' => $obsah['ABARS'] ?? [],
        ], fn ($v) => $v !== []);
    }

    /**
     * Který seznam identifikátor pojmenovává, nebo `null`.
     *
     * `films-3` ano, `shopping-3` ne. Prefix se musí shodovat celý — jinak by
     * `watchlist-1` spadl pod `watch`.
     */
    private function seznam(string $id): ?string
    {
        foreach (self::SEZNAMY as $seznam) {
            if (str_starts_with($id, $seznam.'-')) {
                return $seznam;
            }
        }

        return null;
    }

    /**
     * Identifikátor z obrazovky na řádek v tabulce.
     *
     * @return array<string, object>
     */
    private function podleId(GallerySpace $prostor): array
    {
        $tituly = DB::table('watch_titles')
            ->where('gallery_space_id', $prostor->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $poradi = ['films' => 0, 'series' => 0, 'watchlist' => 0];
        $podle = [];

        foreach ($tituly as $t) {
            $seznam = $t->status === 'chceme' ? 'watchlist' : ($t->kind === 'seriál' ? 'series' : 'films');
            $podle[$seznam.'-'.$poradi[$seznam]++] = $t;
        }

        return $podle;
    }

    /**
     * Nové a odebrané tituly.
     *
     * Odebrání je smazání, ne „viděli jsme": obrazovka na to má vlastní
     * tlačítko a koš na filmy nikde není.
     *
     * @param  array<string, mixed>  $seznamy
     * @param  array<string, object>  $podle
     * @return array<string, object>
     */
    private function zapisSeznamy(array $seznamy, array $podle, GallerySpace $prostor, ?User $uzivatel): array
    {
        $zmena = false;

        foreach (self::SEZNAMY as $seznam) {
            if (! is_array($seznamy[$seznam] ?? null)) {
                continue;
            }

            $radky = array_values($seznamy[$seznam]);
            $zustavaji = [];

            foreach ($radky as $r) {
                $r = (array) $r;
                $id = (string) ($r['id'] ?? '');
                $nazev = trim((string) ($r['t'] ?? ''));

                if ($nazev === '') {
                    continue;
                }

                if (isset($podle[$id])) {
                    $zustavaji[] = $id;

                    continue;
                }

                // Řádek, který na obrazovce vznikl teď — `films-n7`.
                DB::table('watch_titles')->insert([
                    'uuid' => (string) Str::uuid(),
                    'gallery_space_id' => $prostor->id,
                    'created_by' => $uzivatel?->id,
                    'title' => mb_substr($nazev, 0, 180),
                    'kind' => $seznam === 'series' ? 'seriál' : 'film',
                    'status' => $seznam === 'watchlist' ? 'chceme' : (string) ($r['g'] ?? 'hotovo'),
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $zmena = true;
            }

            foreach ($podle as $id => $titul) {
                if (str_starts_with($id, $seznam.'-') && ! in_array($id, $zustavaji, true)) {
                    DB::table('watch_titles')->where('id', $titul->id)->delete();
                    $zmena = true;
                }
            }
        }

        // Po vložení a smazání se identifikátory posunuly. Zbytek patche musí
        // mířit na to, co v tabulce je teď.
        return $zmena ? $this->podleId($prostor) : $podle;
    }

    /** Hvězdičky: `{ a, m }`, každý zvlášť. */
    private function hvezdicky(object $titul, mixed $hodnota, GallerySpace $prostor): void
    {
        if (! Schema::hasTable('watch_title_ratings')) {
            return;
        }

        [$prvni, $druhy] = $this->dvojice($prostor);
        $hodnota = (array) $hodnota;

        foreach (['a' => $prvni, 'm' => $druhy] as $strana => $kdo) {
            if ($kdo === null || ! array_key_exists($strana, $hodnota)) {
                continue;
            }

            $znamka = (int) $hodnota[$strana];

            if ($znamka < 1 || $znamka > 5) {
                continue;
            }

            DB::table('watch_title_ratings')->updateOrInsert(
                ['watch_title_id' => $titul->id, 'user_id' => $kdo],
                ['rating' => $znamka, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    /** Rozkoukaný díl. */
    private function dily(object $titul, mixed $hodnota, GallerySpace $prostor): void
    {
        $dil = max(0, min(9999, (int) $hodnota));

        DB::table('watch_titles')->where('id', $titul->id)->update([
            'episodes_done' => $dil,
            'episodes_total' => max($dil, (int) ($titul->episodes_total ?? 0)) ?: null,
            'status' => 'probíhá',
            'updated_at' => now(),
        ]);
    }

    /** „Viděli jsme" a zpátky. */
    private function videli(object $titul, mixed $hodnota, GallerySpace $prostor): void
    {
        DB::table('watch_titles')->where('id', $titul->id)->update([
            'status' => $hodnota ? 'hotovo' : 'chceme',
            'updated_at' => now(),
        ]);
    }

    /** Pásmo v žebříčku — S až F, nebo nic. */
    private function pasmo(object $titul, mixed $hodnota, GallerySpace $prostor): void
    {
        $pasmo = strtoupper(trim((string) $hodnota));

        DB::table('watch_titles')->where('id', $titul->id)->update([
            'tier' => in_array($pasmo, ['S', 'A', 'B', 'C', 'D', 'F'], true) ? $pasmo : null,
            'updated_at' => now(),
        ]);
    }

    /**
     * Pořadí v pásmu je pořadí oblíbenosti — to řekne jen člověk.
     *
     * @param  array<string, mixed>  $poradi
     * @param  array<string, object>  $podle
     */
    private function poradi(array $poradi, array $podle): void
    {
        $misto = 0;

        foreach (['S', 'A', 'B', 'C', 'D', 'F'] as $pasmo) {
            foreach ((array) ($poradi[$pasmo] ?? []) as $id) {
                $titul = $podle[(string) $id] ?? null;

                if ($titul !== null) {
                    DB::table('watch_titles')->where('id', $titul->id)
                        ->update(['sort_order' => $misto++, 'updated_at' => now()]);
                }
            }
        }
    }

    /** @return list<int|null> přihlášený první, jako `DVOJICE` */
    private function dvojice(GallerySpace $prostor): array
    {
        $lide = $prostor->members()->pluck('users.id')->all();
        $ja = auth()->id();

        if ($ja !== null && in_array($ja, $lide, false)) {
            $lide = array_merge([$ja], array_values(array_filter($lide, fn ($id) => (int) $id !== (int) $ja)));
        }

        return [$lide[0] ?? null, $lide[1] ?? null];
    }
}
