<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Pravidlo `exists:` nad tabulkou galerie se ptá jen vlastní galerie.
 *
 * `exists:people,id` ověří, že osoba existuje — kdekoli. Ve starém API si tak
 * kdokoli s číslem cizí osoby, štítku nebo místa připojil tyhle záznamy
 * k vlastní fotce, a odpověď mu je vrátila celé. Globální rozsah prostoru
 * nepomůže: `exists:` je holý dotaz a většina tabulek s galerií rozsah nemá.
 *
 * Pravidlo má buď omezení na galerii přímo v sobě
 * (`Rule::exists(...)->where('gallery_space_id', ...)`), nebo je tady v seznamu
 * s důvodem, proč si kontroler id po validaci ověřuje sám.
 */
class ExistsVGaleriiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pravidla, u kterých galerii hlídá kód po validaci.
     *
     * @var array<string, string> `soubor:pole` => proč je to v pořádku
     */
    private const OVERENE_JINDE = [
        'Http/Controllers/Api/UploadController.php:target_album_id' => 'album se hledá s `where(gallery_space_id)` a cizí vrátí 422',
        'Http/Controllers/ShareController.php:media_ids.*' => 'výběr se znovu filtruje `MediaItem::where(gallery_space_id)`',
        'Http/Controllers/MediaController.php:album_id' => 'album se bere přes `Album::find` s globálním rozsahem',
        'Http/Controllers/MediaController.php:album_uuid' => 'album se bere přes `Album::where(uuid)` s globálním rozsahem',
        'Http/Requests/Album/CreateAlbumRequest.php:parent_id' => 'rodič se bere přes `Album::find` s globálním rozsahem',
        'Http/Requests/Album/MoveAlbumRequest.php:parent_id' => 'rodič přes `Album::findOrFail` s rozsahem a `Gate::authorize`',
    ];

    public function test_exists_nad_tabulkou_galerie_hlida_galerii(): void
    {
        $nalezy = $this->pravidla();

        $this->assertNotEmpty($nalezy, 'Sken nenašel ani jedno pravidlo `exists:` — hlídal by naprázdno.');

        $dery = [];

        foreach ($nalezy as ['klic' => $klic, 'tabulka' => $tabulka, 'hlida' => $hlida, 'radek' => $radek]) {
            if ($hlida || ! Schema::hasColumn($tabulka, 'gallery_space_id') || isset(self::OVERENE_JINDE[$klic])) {
                continue;
            }

            $dery[] = "  {$klic} (řádek {$radek}): `exists:{$tabulka}` ověří jen, že záznam existuje — v kterékoli galerii";
        }

        $this->assertSame([], $dery,
            "Omezte pravidlo na galerii (`Rule::exists(...)->where('gallery_space_id', …)`),\n"
            ."nebo ho dopište do OVERENE_JINDE i s důvodem:\n".implode("\n", $dery));
    }

    /** A seznam výjimek nesmí zastarat. */
    public function test_vyjimky_odpovidaji_skutecnym_pravidlum(): void
    {
        $klice = array_column($this->pravidla(), 'klic');

        foreach (array_keys(self::OVERENE_JINDE) as $vyjimka) {
            $this->assertContains($vyjimka, $klice, "`{$vyjimka}` už v kódu není — vyřaďte ho z výjimek.");
        }
    }

    /**
     * Každé pravidlo `exists:` s tabulkou a tím, zda hlídá galerii.
     *
     * @return list<array{klic: string, tabulka: string, hlida: bool, radek: int}>
     */
    private function pravidla(): array
    {
        $nalezy = [];

        foreach (File::allFiles(app_path()) as $soubor) {
            $cesta = str_replace(DIRECTORY_SEPARATOR, '/', $soubor->getRelativePathname());

            foreach (preg_split('/\R/', (string) $soubor->getContents()) as $i => $radek) {
                if (! preg_match("/'([\\w.*]+)'\\s*=>.*?(?:exists:([a-z_]+),|Rule::exists\\('([a-z_]+)')/", $radek, $shoda)) {
                    continue;
                }

                $nalezy[] = [
                    'klic' => $cesta.':'.$shoda[1],
                    'tabulka' => ($shoda[2] ?? '') !== '' ? $shoda[2] : $shoda[3],
                    'hlida' => str_contains($radek, 'gallery_space_id'),
                    'radek' => $i + 1,
                ];
            }
        }

        return $nalezy;
    }
}
