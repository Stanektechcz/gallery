<?php

namespace Tests\Feature\Galerie;

use App\Services\Obsah\MaPrazdneKolekce;
use App\Services\Obsah\Poskytovatele;
use App\Services\Obsah\PoskytovatelObsahu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prázdné tvary, podle kterých prototyp maže ukázku.
 *
 * `galerie-data.js` nese život ukázkové dvojice a dokument z něj čte na
 * osmdesáti místech tvarem `this.state.X || UKAZKA`. Hlavička proto ty kolekce
 * u přihlášené dvojice vyprázdní — a tvary si bere odsud, z `prazdne()`
 * poskytovatelů. Ručně psaný seznam na klientu by se rozešel při prvním
 * přidaném klíči a rozdíl by nebylo vidět nikde než na obrazovce dvojice.
 */
class PrazdneKolekceProKlientaTest extends TestCase
{
    use RefreshDatabase;

    /** Seznam poskytovatelů je jeden — kontroler i prototyp čtou týž. */
    public function test_seznam_poskytovatelu_je_uplny(): void
    {
        $tridy = Poskytovatele::TRIDY;

        $this->assertSame($tridy, array_unique($tridy), 'Žádný poskytovatel dvakrát.');

        foreach (glob(app_path('Services/Obsah/*.php')) as $soubor) {
            $trida = 'App\\Services\\Obsah\\'.basename($soubor, '.php');

            if (! class_exists($trida) || ! is_subclass_of($trida, PoskytovatelObsahu::class)) {
                continue;
            }

            $this->assertContains(
                $trida,
                $tridy,
                basename($soubor).' je poskytovatel obsahu, ale v seznamu chybí — prototyp jeho kolekce nevyprázdní.',
            );
        }
    }

    /**
     * Každá kolekce, kterou poskytovatel posílá, umí být i prázdná.
     *
     * Klíč, který `kolekce()` posílá a `prazdne()` neuvádí, znamená jedinou
     * věc: tam ukázka zůstane. Takhle vypadly `AFORMS`, `POSTEPS`, `LOCKWHO`
     * a `LOCKMAIL`.
     */
    public function test_co_se_posila_umi_byt_prazdne(): void
    {
        $chybi = [];

        foreach (Poskytovatele::vsichni() as $poskytovatel) {
            if (! $poskytovatel instanceof MaPrazdneKolekce) {
                continue;
            }

            $prazdne = array_keys($poskytovatel->prazdne());
            $zdroj = file_get_contents((new \ReflectionClass($poskytovatel))->getFileName());

            preg_match_all('~[\'"]([A-Z][A-Z0-9_]{2,})[\'"]\s*=>~', $zdroj, $nalezy);

            foreach (array_unique($nalezy[1]) as $klic) {
                // Skládané kolekce dodává víc poskytovatelů; kontroluje se celek.
                if (in_array($klic, ['AL', 'ABARS', 'MOBIL'], true)) {
                    continue;
                }

                if (! in_array($klic, $prazdne, true) && $this->jeVPrototypu($klic)) {
                    $chybi[] = class_basename($poskytovatel).'::'.$klic;
                }
            }
        }

        $this->assertSame([], $chybi, 'Kolekce bez prázdného tvaru — u dvojice tam zůstane ukázka.');
    }

    /**
     * Tvary pro klienta nenesou data — ani ta ukázková.
     *
     * „Prázdné" neznamená „bez jediného písmene": `DNES.den.nadpis` je
     * popisek „dnes" a `WEEK.now.title` „Tenhle týden". Popisek je součást
     * tvaru, řádek dvojice ne — hlídá se tedy, že každý seznam a mapa jsou
     * prázdné a že se nikde neobjeví život ukázkové dvojice.
     */
    public function test_tvary_pro_klienta_nenesou_data(): void
    {
        $tvary = Poskytovatele::prazdneKolekce();
        $json = json_encode($tvary, JSON_UNESCAPED_UNICODE);

        $this->assertNotEmpty($tvary);
        $this->assertNotFalse($json, 'Tvary musí projít do @json v hlavičce.');

        foreach (['Chorvatsko', 'Zadar', 'Plitvice', 'Pustevny', 'Sintra', 'Zbrojnick', 'Adrian', 'Makinka', 'stanektech'] as $ukazka) {
            $this->assertStringNotContainsString($ukazka, $json, 'Prázdný tvar nese ukázku: '.$ukazka);
        }

        $this->assertSame([], $this->neprazdneSeznamy($tvary), 'Seznam ani mapa v prázdném tvaru nesmí mít položky.');
    }

    /**
     * Cesty ke všem neprázdným seznamům a mapám v tvaru.
     *
     * @param  array<string, mixed>  $tvar
     * @return list<string>
     */
    private function neprazdneSeznamy(array $tvar, string $cesta = ''): array
    {
        $nalezy = [];

        foreach ($tvar as $klic => $hodnota) {
            $kam = $cesta === '' ? (string) $klic : $cesta.'.'.$klic;

            if ($hodnota instanceof \stdClass) {
                $hodnota = (array) $hodnota;
            }

            if (! is_array($hodnota)) {
                continue;
            }

            /*
             * Seznam nul je taky prázdný.
             *
             * `LIBSTATS.hours` je dvacet čtyři nul (kdy se fotí) a
             * `WEEK.now.days` sedm (fotky po dnech) — histogram o pevné délce.
             * Vadí až položka, která něco tvrdí: nenulové číslo, text, řádek.
             */
            if (array_is_list($hodnota)) {
                $neco = array_filter($hodnota, fn ($p) => is_array($p) || $p instanceof \stdClass
                    ? (array) $p !== []
                    : ! in_array($p, [0, 0.0, '', null, false], true));

                if ($neco !== []) {
                    $nalezy[] = $kam;
                }

                continue;
            }

            $nalezy = array_merge($nalezy, $this->neprazdneSeznamy($hodnota, $kam));
        }

        return $nalezy;
    }

    /** Klíče, o kterých prototyp vůbec neví, nemá smysl mazat. */
    private function jeVPrototypu(string $klic): bool
    {
        static $klice = null;

        if ($klice === null) {
            $data = file_get_contents(public_path('galerie-data.js'));
            preg_match('~window\.GalerieData\s*=\s*\{(.*?)\n\s*\};~s', $data, $m);
            preg_match_all('~^\s*([A-Z][A-Z0-9_]*)\s*:~m', $m[1] ?? '', $n);
            $klice = array_flip($n[1] ?? []);
        }

        return isset($klice[$klic]);
    }
}
