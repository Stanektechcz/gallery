<?php

namespace App\Services\Provoz;

use App\Support\Cas;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Záloha databáze a obnova z ní.
 *
 * Deník, finance, alba, lidé i poznámky jsou jen v databázi. `BACKUP_AND_RESTORE.md`
 * popisoval noční zálohu, která nikdy neexistovala.
 *
 * **Záloha nese data, ne schéma.** Schéma postaví `php artisan migrate` z kódu
 * a obnova do něj data vloží. Proto to funguje stejně na MySQL i na SQLite —
 * a dá se to ověřit celým kruhem v testech. Výpis přes `mysqldump` by se tu
 * vyzkoušet nedal (a `shell_exec` je na serveru vypnutý).
 *
 * Formát je gzipovaný NDJSON: první řádek hlavička (verze, čas, použité migrace,
 * počty řádků), pak řádek za řádkem `{"t": tabulka, "r": {...}}`.
 */
class ZalohaDatabaze
{
    public const SLOZKA = 'zalohy';

    /** Předpona automatických záloh — jen ty se po čase mažou. */
    public const PREDPONA = 'zaloha-';

    private const VERZE = 1;

    /**
     * Co se nezálohuje, protože po obnově nechybí nic, co by někdo postrádal.
     *
     * @var array<string, string>
     */
    public const VYNECHAT = [
        'migrations' => 'schéma postaví migrate',
        'sessions' => 'přihlášená sezení — po obnově se přihlásí znovu',
        'cache' => 'mezipaměť',
        'cache_locks' => 'zámky mezipaměti',
        'jobs' => 'fronta',
        'job_batches' => 'fronta',
        'failed_jobs' => 'fronta',
        'password_reset_tokens' => 'odkazy na obnovu hesla platí hodinu',
        'scheduled_task_runs' => 'protokol běhů úloh',
        'upload_sessions' => 'rozpracovaná nahrávání ukazují na dočasné soubory',
        'cinema_sync_runs' => 'protokol stahování programu kina',
    ];

    /** Kolik řádků vložit najednou. */
    private const DAVKA = 200;

    /**
     * Vytvoří zálohu a vrátí, co v ní je.
     *
     * Po zápisu se soubor přečte znovu a počty se porovnají s hlavičkou —
     * záloha, kterou nejde přečíst, je horší než žádná, protože uklidňuje.
     *
     * @return array{cesta: string, tabulky: array<string, int>, bajtu: int}
     */
    public function zalohuj(string $predpona = self::PREDPONA): array
    {
        $cesta = self::SLOZKA.'/'.$predpona.Cas::ted()->format('Y-m-d_His').'.ndjson.gz';
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::SLOZKA);

        $tabulky = [];
        foreach ($this->tabulky() as $tabulka) {
            $tabulky[$tabulka] = DB::table($tabulka)->count();
        }

        $gz = gzopen($disk->path($cesta), 'wb6');
        if ($gz === false) {
            throw new RuntimeException("Zálohu nejde zapsat do {$cesta}.");
        }

        try {
            gzwrite($gz, $this->json([
                'zaloha' => self::VERZE,
                'vytvoreno' => Cas::ted()->toIso8601String(),
                'ovladac' => DB::getDriverName(),
                'migrace' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
                'tabulky' => $tabulky,
            ])."\n");

            foreach (array_keys($tabulky) as $tabulka) {
                foreach ($this->radky($tabulka) as $radek) {
                    gzwrite($gz, $this->json(['t' => $tabulka, 'r' => $this->doJson((array) $radek)])."\n");
                }
            }
        } finally {
            gzclose($gz);
        }

        $this->over($cesta, $tabulky);

        return ['cesta' => $cesta, 'tabulky' => $tabulky, 'bajtu' => (int) $disk->size($cesta)];
    }

    /**
     * Smaže nejstarší automatické zálohy nad počet, který se má držet.
     *
     * @return list<string> smazané soubory
     */
    public function uklid(int $drzet): array
    {
        $zalohy = collect(Storage::disk('local')->files(self::SLOZKA))
            ->filter(fn (string $f) => str_starts_with(basename($f), self::PREDPONA))
            ->sort()
            ->values();

        $pryc = $zalohy->slice(0, max(0, $zalohy->count() - max(1, $drzet)))->values()->all();
        Storage::disk('local')->delete($pryc);

        return $pryc;
    }

    /**
     * Hlavička zálohy.
     *
     * @return array<string, mixed>
     */
    public function hlavicka(string $cesta): array
    {
        $gz = $this->otevri($cesta);

        try {
            $hlavicka = json_decode((string) gzgets($gz), true);
        } finally {
            gzclose($gz);
        }

        if (! is_array($hlavicka) || ($hlavicka['zaloha'] ?? null) !== self::VERZE) {
            throw new DomainException("{$cesta} není záloha, kterou by tahle verze přečetla.");
        }

        return $hlavicka;
    }

    /**
     * Migrace, které záloha zná a tahle databáze ne.
     *
     * Neprázdný seznam znamená zálohu z novějšího kódu: nesla by tabulky
     * a sloupce, které tu nejsou. Opačně to nevadí — novější kód má schéma
     * s tím, co záloha nese, a navíc.
     *
     * @param  array<string, mixed>  $hlavicka
     * @return list<string>
     */
    public function neznameMigrace(array $hlavicka): array
    {
        $tady = DB::table('migrations')->pluck('migration')->all();

        return array_values(array_diff((array) ($hlavicka['migrace'] ?? []), $tady));
    }

    /**
     * Obnoví tabulky ze zálohy — všechny najednou, nebo žádnou.
     *
     * Tabulky, které záloha nese, se vyprázdní a naplní z ní; ostatních se to
     * nedotkne. Sloupec, který mezitím ze schématu zmizel, se přeskočí
     * a ohlásí. Cizí klíče jsou po dobu obnovy vypnuté (pořadí tabulek v záloze
     * nemusí odpovídat závislostem) a vypínají se **před** transakcí: SQLite
     * uvnitř transakce vypnutí ignoruje.
     *
     * @return array{tabulky: array<string, int>, vynechane_sloupce: array<string, list<string>>, chybejici_tabulky: list<string>}
     */
    public function obnov(string $cesta): array
    {
        $hlavicka = $this->hlavicka($cesta);

        if (($nezname = $this->neznameMigrace($hlavicka)) !== []) {
            throw new DomainException('Záloha je z novějšího kódu (neznámé migrace: '.implode(', ', array_slice($nezname, 0, 3)).'). Nasaďte nejdřív tu verzi, ze které je.');
        }

        $zdroj = array_keys((array) ($hlavicka['tabulky'] ?? []));
        $existuji = array_values(array_filter($zdroj, fn (string $t) => Schema::hasTable($t)));
        $sloupce = [];
        foreach ($existuji as $tabulka) {
            $sloupce[$tabulka] = array_flip(Schema::getColumnListing($tabulka));
        }

        $vlozeno = array_fill_keys($existuji, 0);
        $vynechane = [];

        Schema::disableForeignKeyConstraints();

        try {
            DB::transaction(function () use ($cesta, $existuji, $sloupce, &$vlozeno, &$vynechane) {
                foreach ($existuji as $tabulka) {
                    DB::table($tabulka)->delete();
                }

                $davky = [];
                $gz = $this->otevri($cesta);

                try {
                    gzgets($gz); // hlavička

                    while (($radek = gzgets($gz)) !== false) {
                        $zaznam = json_decode($radek, true);
                        $tabulka = $zaznam['t'] ?? null;

                        if (! is_string($tabulka) || ! isset($sloupce[$tabulka])) {
                            continue;
                        }

                        $data = $this->zJson((array) ($zaznam['r'] ?? []));

                        foreach (array_keys(array_diff_key($data, $sloupce[$tabulka])) as $sloupec) {
                            $vynechane[$tabulka][$sloupec] = true;
                        }

                        $davky[$tabulka][] = array_intersect_key($data, $sloupce[$tabulka]);

                        if (count($davky[$tabulka]) >= self::DAVKA) {
                            DB::table($tabulka)->insert($davky[$tabulka]);
                            $vlozeno[$tabulka] += count($davky[$tabulka]);
                            $davky[$tabulka] = [];
                        }
                    }
                } finally {
                    gzclose($gz);
                }

                foreach ($davky as $tabulka => $zbytek) {
                    if ($zbytek !== []) {
                        DB::table($tabulka)->insert($zbytek);
                        $vlozeno[$tabulka] += count($zbytek);
                    }
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return [
            'tabulky' => $vlozeno,
            'vynechane_sloupce' => array_map('array_keys', $vynechane),
            'chybejici_tabulky' => array_values(array_diff($zdroj, $existuji)),
        ];
    }

    /** @return list<string> tabulky, které se zálohují */
    public function tabulky(): array
    {
        $vsechny = array_map(fn (array $t) => (string) $t['name'], Schema::getTables());
        sort($vsechny);

        return array_values(array_filter($vsechny, fn (string $t) => ! isset(self::VYNECHAT[$t])));
    }

    // ——— pomocné ———

    /** Řádky tabulky po dávkách, ať se velká tabulka nenačte celá do paměti. */
    private function radky(string $tabulka): iterable
    {
        return Schema::hasColumn($tabulka, 'id')
            ? DB::table($tabulka)->lazyById(1000, 'id')
            : DB::table($tabulka)->cursor();
    }

    /** @param  array<string, int>  $ocekavane */
    private function over(string $cesta, array $ocekavane): void
    {
        $nalezene = array_fill_keys(array_keys($ocekavane), 0);
        $gz = $this->otevri($cesta);

        try {
            gzgets($gz);
            while (($radek = gzgets($gz)) !== false) {
                $t = json_decode($radek, true)['t'] ?? null;
                if (is_string($t) && isset($nalezene[$t])) {
                    $nalezene[$t]++;
                }
            }
        } finally {
            gzclose($gz);
        }

        /*
         * Víc řádků je v pořádku — mezi spočítáním a výpisem mohl někdo něco
         * přidat. Méně znamená, že se část ztratila.
         */
        $chybi = array_filter($ocekavane, fn (int $pocet, string $t) => $nalezene[$t] < $pocet, ARRAY_FILTER_USE_BOTH);

        if ($chybi !== []) {
            Storage::disk('local')->delete($cesta);

            throw new RuntimeException('Záloha se nezapsala celá (chybí řádky v: '.implode(', ', array_keys($chybi)).'). Soubor je smazaný.');
        }
    }

    /** @return resource */
    private function otevri(string $cesta)
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($cesta)) {
            throw new DomainException("Záloha {$cesta} neexistuje.");
        }

        $gz = gzopen($disk->path($cesta), 'rb');
        if ($gz === false) {
            throw new RuntimeException("Zálohu {$cesta} nejde otevřít.");
        }

        return $gz;
    }

    /** @param  array<string, mixed>  $data */
    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * Hodnoty, které JSON nepojme (binární data), jako base64 se značkou.
     *
     * @param  array<string, mixed>  $radek
     * @return array<string, mixed>
     */
    private function doJson(array $radek): array
    {
        foreach ($radek as $sloupec => $hodnota) {
            if (is_string($hodnota) && ! mb_check_encoding($hodnota, 'UTF-8')) {
                $radek[$sloupec] = ['__b64' => base64_encode($hodnota)];
            }
        }

        return $radek;
    }

    /**
     * Opak `doJson()`. Z databáze chodí jen skaláry, takže pole je vždycky značka.
     *
     * @param  array<string, mixed>  $radek
     * @return array<string, mixed>
     */
    private function zJson(array $radek): array
    {
        foreach ($radek as $sloupec => $hodnota) {
            if (is_array($hodnota) && array_keys($hodnota) === ['__b64']) {
                $puvodni = base64_decode((string) $hodnota['__b64'], true);
                $radek[$sloupec] = $puvodni === false ? null : $puvodni;
            }
        }

        return $radek;
    }
}
