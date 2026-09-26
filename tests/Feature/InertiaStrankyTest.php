<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Složky se stránkami musí sedět i velikostí písmen.
 *
 * Výchozí nastavení balíčku ukazuje na `resources/js/pages`, repozitář má
 * `Pages`. Windows ten rozdíl přejde (`is_dir` tam uspěje), Linux ne — a CI
 * pak hlásilo u každého `assertInertia`, že stránka neexistuje. Proto se tu
 * každý díl cesty porovnává s tím, co skutečně vrátí výpis složky, a test
 * spadne už na Windows, kdyby se velikost zase rozešla.
 */
class InertiaStrankyTest extends TestCase
{
    public function test_slozky_stranek_existuji_i_s_velikosti_pismen(): void
    {
        $cesty = (array) config('inertia.pages.paths');

        $this->assertNotEmpty($cesty, 'Bez nastavených složek by assertInertia nenašel žádnou stránku.');

        foreach ($cesty as $cesta) {
            $this->assertTrue(
                $this->existujePresne((string) $cesta),
                "Složka stránek {$cesta} neexistuje přesně v tomhle tvaru (pozor na velká a malá písmena)."
            );
        }
    }

    public function test_pripony_zustaly_i_s_nasi_konfiguraci(): void
    {
        // Laravel slučuje jen klíče nejvyšší úrovně — kdyby config/inertia.php
        // nesl jen `paths`, přípony by zmizely a kontrola by nenašla nic.
        $this->assertContains('tsx', (array) config('inertia.pages.extensions'));
        $this->assertTrue((bool) config('inertia.testing.ensure_pages_exist'), 'Kontrola stránek v testech má zůstat zapnutá.');
    }

    /**
     * Jde odspodu po dílech cesty uvnitř projektu a každý hledá ve výpisu
     * nadřazené složky — `scandir` vrací skutečná jména, i na Windows.
     */
    private function existujePresne(string $cesta): bool
    {
        $koren = rtrim(str_replace('\\', '/', base_path()), '/');
        $cesta = rtrim(str_replace('\\', '/', $cesta), '/');

        if (! is_dir($cesta) || stripos($cesta, $koren.'/') !== 0) {
            return false;
        }

        $aktualni = $koren;

        foreach (explode('/', substr($cesta, strlen($koren) + 1)) as $dil) {
            if (! in_array($dil, scandir($aktualni) ?: [], true)) {
                return false;
            }
            $aktualni .= '/'.$dil;
        }

        return true;
    }
}
