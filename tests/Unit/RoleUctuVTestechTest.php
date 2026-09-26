<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Fixtury zakládají účty jen s rolí, kterou sloupec `users.role` unese.
 *
 * `users.role` je výčet. SQLite ho v testech nehlídá (kontrola se při
 * pozdějších úpravách tabulky ztratila), MySQL ve striktním režimu řádek
 * s cizí hodnotou odmítne — v CI tak kvůli `'role' => 'member'` spadlo
 * šestnáct testů koše a seznamů ještě před první kontrolou. Tady se to
 * pozná už na SQLite, čtením zdrojů.
 */
class RoleUctuVTestechTest extends TestCase
{
    public function test_ucty_ve_fixturach_maji_roli_z_vyctu(): void
    {
        $povolene = $this->roleZMigrace();
        $this->assertContains('owner', $povolene, 'Výčet rolí se z migrace nepřečetl — kontrola by neměla co hlídat.');

        $spatne = [];
        $pocet = 0;

        foreach ($this->soubory() as $soubor) {
            $kod = (string) file_get_contents($soubor);

            // Jeden příkaz od `User::factory()` po středník; role pivotu
            // (`members()->attach(…, ['role' => 'editor'])`) je v jiném příkazu.
            preg_match_all("/User::factory\(\)[^;]*?'role'\s*=>\s*'([a-z_]+)'/s", $kod, $shody, PREG_OFFSET_CAPTURE);

            foreach ($shody[1] as [$role, $pozice]) {
                $pocet++;
                if (! in_array($role, $povolene, true)) {
                    $radek = substr_count(substr($kod, 0, $pozice), "\n") + 1;
                    $spatne[] = '  '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $soubor).":{$radek} — '{$role}'";
                }
            }
        }

        $this->assertGreaterThan(100, $pocet, 'Sken nenašel skoro žádné účty s rolí — nejspíš je rozbitý.');
        $this->assertSame([], $spatne, 'Role mimo výčet users.role ('.implode(', ', $povolene)."):\n".implode("\n", $spatne));
    }

    /** @return list<string> */
    private function roleZMigrace(): array
    {
        $kod = (string) file_get_contents(database_path('migrations/0001_01_01_000000_create_users_table.php'));
        preg_match("/enum\('role',\s*\[([^\]]+)\]/", $kod, $shoda);
        preg_match_all("/'([a-z_]+)'/", $shoda[1] ?? '', $role);

        return $role[1];
    }

    /** @return list<string> */
    private function soubory(): array
    {
        $soubory = [];

        foreach ([base_path('tests'), database_path('seeders'), database_path('factories')] as $slozka) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($slozka, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $soubor) {
                if ($soubor->isFile() && $soubor->getExtension() === 'php' && $soubor->getPathname() !== __FILE__) {
                    $soubory[] = $soubor->getPathname();
                }
            }
        }

        return $soubory;
    }
}
