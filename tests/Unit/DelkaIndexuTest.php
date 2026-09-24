<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Indexovaný sloupec se musí vejít do klíče InnoDB.
 *
 * MySQL má strop 3072 bajtů na klíč a `utf8mb4` počítá čtyři bajty na znak,
 * takže indexovat `string('x', 2048)` znamená 8192 bajtů a `ERROR 1071`.
 * SQLite délky klíčů ignoruje úplně — na vývoji i v testech tedy `migrate`
 * proběhne a problém se ukáže teprve na produkci, kde je pozdě.
 *
 * Test proto čte migrace, ne schéma testovací databáze.
 */
class DelkaIndexuTest extends TestCase
{
    /** 3072 bajtů / 4 bajty na znak `utf8mb4`. */
    private const NEJVIC_ZNAKU = 768;

    public function test_zadny_dlouhy_sloupec_se_neindexuje_cely(): void
    {
        $prehresky = [];

        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $soubor) {
            $kod = (string) file_get_contents($soubor);

            foreach ($this->sirokeSloupce($kod) as $sloupec => $sirka) {
                if (! $this->jeIndexovany($kod, $sloupec) || $this->maPrefix($kod, $sloupec)) {
                    continue;
                }

                $prehresky[] = sprintf(
                    '  %s: `%s` má %d znaků (%d bajtů) a je indexovaný — limit je 3072 bajtů',
                    basename($soubor), $sloupec, $sirka, $sirka * 4,
                );
            }
        }

        $this->assertSame([], $prehresky,
            "Na MySQL by `migrate` spadlo na ERROR 1071:\n".implode("\n", $prehresky));
    }

    /**
     * Znakové sloupce širší, než se vejde do klíče.
     *
     * @return array<string, int>
     */
    private function sirokeSloupce(string $kod): array
    {
        preg_match_all("/->(?:string|char)\(\s*'([a-z0-9_]+)'\s*,\s*(\d+)\s*\)/i", $kod, $shody, PREG_SET_ORDER);

        $siroke = [];

        foreach ($shody as $shoda) {
            if ((int) $shoda[2] > self::NEJVIC_ZNAKU) {
                $siroke[$shoda[1]] = (int) $shoda[2];
            }
        }

        return $siroke;
    }

    /**
     * Má migrace pro MySQL index s délkou prefixu?
     *
     * Laravel prefix v `index()` neumí, takže se dělá syrovým příkazem pod
     * hlídkou ovladače — `materialized_path(191)`. Vedle něj pak smí stát
     * i obyčejný `index()` pro SQLite, kde žádný strop na klíč není.
     */
    private function maPrefix(string $kod, string $sloupec): bool
    {
        return (bool) preg_match('/'.preg_quote($sloupec, '/').'\(\d+\)/', $kod);
    }

    /**
     * Je na sloupci index bez délky prefixu?
     *
     * `unique()` navázaný přímo na definici sloupce se pozná podle toho, že
     * stojí za jeho závorkou; `index('x')` a `unique('x')` se hledají zvlášť.
     * Index s prefixem (raw SQL s `(191)`) se za přehřešek nepovažuje.
     */
    private function jeIndexovany(string $kod, string $sloupec): bool
    {
        $vzory = [
            "/->(?:string|char)\(\s*'".preg_quote($sloupec, '/')."'\s*,\s*\d+\s*\)->unique\(\)/i",
            "/->(?:index|unique)\(\s*'".preg_quote($sloupec, '/')."'\s*[,)]/i",
            "/->(?:index|unique)\(\s*\[[^\]]*'".preg_quote($sloupec, '/')."'[^\]]*\]/i",
        ];

        foreach ($vzory as $vzor) {
            if (preg_match($vzor, $kod)) {
                return true;
            }
        }

        return false;
    }
}
