<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Skládání velkého souboru z částí na produkčním serveru.
 *
 * Produkce (aaPanel) pouští PHP-FPM s `open_basedir` na adresář webu. Části
 * se ukládaly do `storage`, ale **skládaly** se do `sys_get_temp_dir()` —
 * mimo povolené adresáře. `tempnam()` tam skončil varováním, Laravel z něj
 * udělal výjimku a poslední část videa odpověděla 500 bez vysvětlení.
 *
 * `open_basedir` jde za běhu jen zúžit, nikdy vrátit, a PHPUnit ve vlastním
 * procesu potřebuje dočasný adresář systému sám — proto se tu hlídá zdroj
 * (žádný dočasný adresář systému) a chování na disku aplikace.
 */
class SkladaniCastiTest extends TestCase
{
    use RefreshDatabase;

    private const MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();

        $adri = User::factory()->create();
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $adri->id]);
        $adri->gallerySpaces()->syncWithoutDetaching([$prostor->id => ['role' => 'owner']]);
        Sanctum::actingAs($adri);
    }

    public function test_skladani_nepouziva_docasny_adresar_systemu(): void
    {
        // Jen kód, bez komentářů — ty tu past popisují.
        $zdroj = implode('', array_map(
            fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
            token_get_all((string) file_get_contents(app_path('Http/Controllers/Api/Galerie/MediaController.php'))),
        ));

        $this->assertStringNotContainsString('sys_get_temp_dir(', $zdroj, 'Pod open_basedir na produkci tam FPM nesmí.');
        $this->assertStringNotContainsString('tempnam(', $zdroj);
    }

    public function test_po_slozeni_nezustanou_casti_ani_slozeny_soubor(): void
    {
        $casti = [self::MP4.'prvni--', 'druha--', 'treti'];

        foreach ($casti as $poradi => $cast) {
            $odpoved = $this->cast('up-uklid', $poradi, count($casti), $cast);
        }

        $odpoved->assertCreated()->assertJsonPath('status', 'stored');
        $this->assertSame(implode('', $casti), Storage::disk('public')->get(MediaItem::sole()->variants()->where('type', 'original')->sole()->path));
        $this->assertSame([], Storage::disk('local')->allFiles('upload_chunks'));
    }

    /**
     * Adresář, do kterého FPM nesmí psát (vlastník root po úloze z cronu),
     * končil `UnableToRetrieveMetadata` a obecnou pětistovkou. Telefon teď
     * dostane větu, se kterou se dá něco dělat.
     */
    public function test_nezapsana_cast_vrati_srozumitelnou_chybu(): void
    {
        $disk = Storage::disk('local');
        Storage::set('local', new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()) extends FilesystemAdapter
        {
            public function writeStream($path, $resource, array $options = [])
            {
                return false;
            }
        });

        $this->cast('up-prava', 0, 2, self::MP4.'x')
            ->assertStatus(507)
            ->assertJsonPath('message', 'Server nemohl uložit nahrávaný soubor na disk (nemá právo zápisu do úložiště nebo je plné). Nahrávání teď nemá smysl opakovat — je potřeba opravit server.');

        $this->assertSame(0, MediaItem::count());
    }

    private function cast(string $id, int $poradi, int $celkem, string $obsah)
    {
        return $this->call('POST', '/api/media/chunk', [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_UPLOAD_ID' => $id,
            'HTTP_X_CHUNK_INDEX' => (string) $poradi,
            'HTTP_X_CHUNK_COUNT' => (string) $celkem,
            'HTTP_X_FILE_NAME' => 'video.mp4',
        ], $obsah);
    }
}
