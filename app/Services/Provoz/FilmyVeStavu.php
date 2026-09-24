<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Obsah\Pribeh;
use App\Support\Tabulky;
use App\Support\Vejde;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        if (! Tabulky::je('watch_titles')) {
            return [];
        }

        // Řádky, ze kterých vznikly identifikátory na obrazovce. Pořadí je
        // tytéž, jaké posílá `AL` — jinak by `films-2` mířilo jinam.
        $podle = $this->podleId($prostor);

        if (is_array($patch['xRows'] ?? null)) {
            $podle = $this->zapisSeznamy((array) $patch['xRows'], $podle, $prostor, $uzivatel, $patch);
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

            // Podle titulu pod kterýmkoli seznamem — zhlédnutý titul přechází
            // z watchlistu do filmů a obrazovka ho může mít ještě pod starým.
            if (! empty($t->uuid)) {
                foreach (self::SEZNAMY as $s) {
                    $podle[$s.'-'.$t->uuid] = $t;
                }
            }
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
    private function zapisSeznamy(array $seznamy, array $podle, GallerySpace $prostor, ?User $uzivatel, array $patch = []): array
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
                    $zustavaji[] = (int) $podle[$id]->id;

                    continue;
                }

                /*
                 * Nový jen s vlastní předponou obrazovky (`films-n7`).
                 *
                 * Titul podle uuid, který v tabulce není, smazal mezitím ten
                 * druhý; pořadí (`films-4`), které nesedí, je starší kopie
                 * seznamu. Dřív se obojí založilo znovu — titul se zdvojil
                 * nebo vstal ze smazaných.
                 */
                if (! preg_match('/^(films|series|watchlist)-n\d+$/', $id)) {
                    continue;
                }

                // Znovu odeslaný nový řádek (obrazovka ho drží pod svým jménem do obnovení).
                $znamy = $this->zKlienta($prostor, $id);

                if ($znamy) {
                    $zustavaji[] = (int) $znamy->id;

                    continue;
                }

                $noveUuid = (string) Str::uuid();
                Cache::put($this->klicKlienta($prostor, $id), $noveUuid, now()->addDays(2));

                DB::table('watch_titles')->insert([
                    'uuid' => $noveUuid,
                    'gallery_space_id' => $prostor->id,
                    'created_by' => $uzivatel?->id,
                    'title' => mb_substr($nazev, 0, 180),
                    'kind' => $seznam === 'series' ? 'seriál' : 'film',
                    'status' => Vejde::do($seznam === 'watchlist' ? 'chceme' : ($r['g'] ?? 'hotovo'), 12),
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $zmena = true;
            }

            /*
             * Smazané: jen co obrazovka výslovně odebrala (OdebraneVStavu).
             *
             * Titul, který mezitím přidal ten druhý, ve starší kopii seznamu
             * chybí taky. Z odebraných se berou jen identifikátory podle
             * titulu — pořadí ze starší kopie by mířilo na jiný řádek.
             * Starší klient bez rozdílu: jako dřív, co v seznamu chybí.
             */
            $odebrane = OdebraneVStavu::pro($patch, 'xRows.'.$seznam);
            $smazat = [];

            // Nový titul vrácený tlačítkem Zpět dřív, než obrazovka dostala jeho uuid.
            foreach ($odebrane ?? [] as $odebrany) {
                $titul = preg_match('/^(films|series|watchlist)-n\d+$/', $odebrany) ? $this->zKlienta($prostor, $odebrany) : null;

                if ($titul && ! in_array((int) $titul->id, $zustavaji, true)) {
                    $smazat[] = (int) $titul->id;
                }
            }

            foreach ($podle as $id => $titul) {
                if (! preg_match('/^'.$seznam.'-\d+$/', $id) || in_array((int) $titul->id, $zustavaji, true)) {
                    continue;
                }

                if ($odebrane === null || in_array($seznam.'-'.$titul->uuid, $odebrane, true)
                    || array_intersect(array_map(fn ($s) => $s.'-'.$titul->uuid, self::SEZNAMY), $odebrane) !== []) {
                    $smazat[] = (int) $titul->id;
                }
            }

            if ($smazat !== []) {
                DB::table('watch_titles')->where('gallery_space_id', $prostor->id)->whereIn('id', $smazat)->delete();
                $zmena = true;
            }
        }

        // Po vložení a smazání se identifikátory posunuly. Zbytek patche musí
        // mířit na to, co v tabulce je teď.
        return $zmena ? $this->podleId($prostor) : $podle;
    }

    private function klicKlienta(GallerySpace $prostor, string $id): string
    {
        return 'filmy:klient:'.$prostor->id.':'.$id;
    }

    /** Titul založený z identifikátoru obrazovky (`films-n7`), pokud ještě existuje. */
    private function zKlienta(GallerySpace $prostor, string $id): ?object
    {
        $uuid = Cache::get($this->klicKlienta($prostor, $id));

        return is_string($uuid)
            ? DB::table('watch_titles')->where('gallery_space_id', $prostor->id)->where('uuid', $uuid)->first()
            : null;
    }

    /** Hvězdičky: `{ a, m }`, každý zvlášť. */
    private function hvezdicky(object $titul, mixed $hodnota, GallerySpace $prostor): void
    {
        if (! Tabulky::je('watch_title_ratings')) {
            return;
        }

        [$prvni] = $this->dvojice($prostor);
        $hodnota = (array) $hodnota;

        /*
         * Jen vlastní hvězdičky.
         *
         * Klient posílá obě strany (`a` je ten, kdo se dívá, `m` ten druhý)
         * a dřív se zapsaly obě — z Adrianova počítače tak šlo Makince přepsat
         * známku. Hodnotí se každý sám za sebe; hodnota druhého v patchi je
         * jen opis toho, co už v databázi leží.
         */
        foreach (['a' => $prvni] as $strana => $kdo) {
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
