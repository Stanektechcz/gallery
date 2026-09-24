<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Každý příkaz buď někdo spouští, nebo je tady napsané proč ne.
 *
 * Audit našel čtrnáct příkazů, které nespouští nic — ani plánovač, ani
 * `deploy.sh`, ani `Artisan::call` kdekoli v kódu. Většina z nich jsou opravná
 * nářadí, která se pouštějí ručně, a to je v pořádku. Jenže mezi nimi ležela
 * i upozornění na konec zkušebního období, která prostě nikdy nechodila,
 * a brána před nasazením, ze které zbyla věta v dokumentu.
 *
 * Rozdíl mezi „ručně schválně" a „zapomněli jsme to zapojit" se z kódu
 * nepozná. Proto je tu seznam: nový příkaz, který nikdo nespouští, shodí
 * test, dokud u něj někdo nenapíše, do které skupiny patří.
 */
class PrikazySeSpoustiTest extends TestCase
{
    /**
     * Příkazy, které se pouštějí ručně — a proč.
     *
     * @var array<string, string>
     */
    private const RUCNE = [
        'gallery:exif' => 'jednorázová oprava metadat; s --clean-orphans maže, takže ho plánovač pouštět nemá',
        'gallery:exif-diagnostics' => 'diagnostika při hledání příčiny',
        'gallery:import' => 'hromadný import ze složky, spouští člověk',
        'gallery:publish-android-app' => 'vydání aplikace je rozhodnutí, ne úloha',
        'gallery:push-keys' => 'vygeneruje klíče pro upozornění, jednou při nasazení',
        'gallery:push-selftest' => 'zkouška doručení, když upozornění nechodí',
        'gallery:reconcile-dates' => 'oprava dat pořízení po importu',
        'gallery:sync-drive' => 'ruční srovnání s Diskem, když se něco rozešlo',
        'gallery:thumbnails' => 'dogenerování náhledů po opravě',
        'gallery:ucet' => 'správa účtu z příkazové řádky',
        'gallery:videos' => 'dogenerování převodů videa',
        'rozpocet:regensburg' => 'jednorázový import konkrétního výletu',
    ];

    public function test_kazdy_prikaz_nekdo_spousti(): void
    {
        $spoustene = array_merge($this->zPlanovace(), $this->zNasazeni(), $this->zKodu());
        $viseji = [];

        foreach ($this->vsechnyPrikazy() as $prikaz) {
            if (in_array($prikaz, $spoustene, true) || isset(self::RUCNE[$prikaz])) {
                continue;
            }

            $viseji[] = '  '.$prikaz;
        }

        $this->assertSame([], $viseji,
            "Tyhle příkazy nespouští nic. Zapojte je, nebo je dopište do `RUCNE` i s důvodem:\n"
            .implode("\n", $viseji));
    }

    /** A seznam ručních příkazů nesmí zastarat. */
    public function test_seznam_rucnich_prikazu_neobsahuje_neexistujici(): void
    {
        $vsechny = $this->vsechnyPrikazy();

        foreach (array_keys(self::RUCNE) as $prikaz) {
            $this->assertContains($prikaz, $vsechny,
                "`{$prikaz}` už neexistuje — vyřaďte ho ze seznamu, ať neuklidňuje zbytečně.");
        }
    }

    /** @return list<string> */
    private function vsechnyPrikazy(): array
    {
        $nalezene = [];

        foreach (File::allFiles(app_path('Console/Commands')) as $soubor) {
            if (preg_match("/signature\s*=\s*'([a-z0-9:_-]+)/i", (string) $soubor->getContents(), $shoda)) {
                $nalezene[] = $shoda[1];
            }
        }

        sort($nalezene);

        return $nalezene;
    }

    /** @return list<string> */
    private function zPlanovace(): array
    {
        return array_values(array_filter(array_map(
            fn ($uloha) => preg_match('/artisan[\'"]?\s+([a-z0-9:_-]+)/i', (string) $uloha->command, $shoda)
                ? $shoda[1]
                : null,
            app(Schedule::class)->events(),
        )));
    }

    /** @return list<string> */
    private function zNasazeni(): array
    {
        preg_match_all('/artisan\s+([a-z0-9:_-]+)/i', (string) file_get_contents(base_path('deploy.sh')), $shody);

        return $shody[1];
    }

    /**
     * Příkazy volané z kódu — `Artisan::call('…')`.
     *
     * @return list<string>
     */
    private function zKodu(): array
    {
        $nalezene = [];

        foreach (File::allFiles(app_path()) as $soubor) {
            preg_match_all("/Artisan::call\(\s*'([a-z0-9:_-]+)/i", (string) $soubor->getContents(), $shody);
            $nalezene = array_merge($nalezene, $shody[1]);
        }

        return $nalezene;
    }
}
