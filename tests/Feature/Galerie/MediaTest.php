<?php

namespace Tests\Feature\Galerie;

use App\Jobs\Media\CalculateMediaHashesJob;
use App\Jobs\Media\ExtractMediaMetadataJob;
use App\Jobs\Media\GenerateImageVariantsJob;
use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nahrávání médií z prototypu.
 *
 * Nejdůležitější věc, kterou tyhle testy hlídají, není že se soubor uloží —
 * je to, že se uloží **do stejné knihovny** jako všechno ostatní. Druhý sklad
 * fotek by se poznal až za měsíc, až by se ukázalo, že se do tarifu nezapočítávají
 * a v časové ose nejsou.
 */
class MediaTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();

        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$this->prostor->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    public function test_maly_soubor_se_ulozi_do_knihovny(): void
    {
        $odpoved = $this->post('/api/media', [
            'file' => $this->fotka('vylet.jpg'),
            'taken_at' => 1_756_000_000_000,
        ])->assertCreated()->assertJsonPath('status', 'stored');

        $media = MediaItem::sole();

        $this->assertSame($this->prostor->id, $media->gallery_space_id);
        $this->assertSame('vylet.jpg', $media->original_filename);
        $this->assertSame('photo', $media->media_type);
        $this->assertSame('ready', $media->status);
        $this->assertNotNull($media->sha256, 'Bez otisku by se duplicita nedala poznat.');
        $this->assertSame($media->uuid, $odpoved->json('id'));

        // Originál leží tam, kde ho hledá zbytek aplikace.
        $originál = $media->variants()->where('type', 'original')->sole();
        $this->assertSame("media/{$media->uuid}/original.jpg", $originál->path);
        Storage::disk('public')->assertExists($originál->path);
    }

    /** Náhledy a metadata patří do fronty — nahrávání má skončit hned. */
    public function test_nahrani_zaradi_dopocitani_do_fronty(): void
    {
        $this->post('/api/media', ['file' => $this->fotka('vylet.jpg')])->assertCreated();

        Queue::assertPushed(GenerateImageVariantsJob::class);
        Queue::assertPushed(ExtractMediaMetadataJob::class);
        Queue::assertPushed(CalculateMediaHashesJob::class);
    }

    /** Knihovna má hlídat originály, ne kopie. */
    public function test_tentyz_soubor_se_neulozi_dvakrat(): void
    {
        $obsah = $this->fotka('vylet.jpg')->get();

        $prvni = $this->post('/api/media', [
            'file' => UploadedFile::fake()->createWithContent('vylet.jpg', $obsah),
        ])->assertCreated();

        $druhy = $this->post('/api/media', [
            'file' => UploadedFile::fake()->createWithContent('kopie.jpg', $obsah),
        ])->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, MediaItem::count());
        $this->assertSame($prvni->json('id'), $druhy->json('id'));
    }

    /** Soubor v koši duplicitu neblokuje — jinak by nešel nahrát znovu. */
    public function test_soubor_z_kose_jde_nahrat_znovu(): void
    {
        $obsah = $this->fotka('vylet.jpg')->get();

        $prvni = $this->post('/api/media', [
            'file' => UploadedFile::fake()->createWithContent('vylet.jpg', $obsah),
        ])->assertCreated();

        $this->deleteJson('/api/media/'.$prvni->json('id'))->assertOk()->assertJsonPath('status', 'trashed');

        $this->post('/api/media', [
            'file' => UploadedFile::fake()->createWithContent('vylet.jpg', $obsah),
        ])->assertCreated()->assertJsonPath('status', 'stored');

        $this->assertSame(2, MediaItem::count());
    }

    // ——— po částech ———

    public function test_velky_soubor_po_castech_se_slozi(): void
    {
        $casti = ['prvni-cast--', 'druha-cast--', 'treti-cast'];

        foreach ($casti as $poradi => $cast) {
            $odpoved = $this->call('POST', '/api/media/chunk', [], [], [], $this->hlavicky([
                'X-Upload-Id' => 'up-12345',
                'X-Chunk-Index' => (string) $poradi,
                'X-Chunk-Count' => (string) count($casti),
                'X-File-Name' => 'vzpominka.mp4',
            ]), $cast);

            if ($poradi < count($casti) - 1) {
                $odpoved->assertStatus(202)->assertJsonPath('status', 'partial');
            }
        }

        $odpoved->assertCreated()->assertJsonPath('status', 'stored');

        $media = MediaItem::sole();
        $this->assertSame('vzpominka.mp4', $media->original_filename);
        $this->assertSame(strlen(implode('', $casti)), (int) $media->size_bytes, 'Části se musí složit ve správném pořadí.');

        $originál = $media->variants()->where('type', 'original')->sole();
        $this->assertSame(implode('', $casti), Storage::disk('public')->get($originál->path));
    }

    /** Nedokončené nahrávání nezaloží nic — v knihovně nesmí zůstat půlka videa. */
    public function test_nedokoncene_nahravani_nezalozi_zaznam(): void
    {
        $this->call('POST', '/api/media/chunk', [], [], [], $this->hlavicky([
            'X-Upload-Id' => 'up-12345',
            'X-Chunk-Index' => '0',
            'X-Chunk-Count' => '3',
            'X-File-Name' => 'vzpominka.mp4',
        ]), 'prvni')->assertStatus(202);

        $this->assertSame(0, MediaItem::count());
    }

    /**
     * Identifikátor jde do cesty na disku, takže se nesmí dát podstrčit.
     *
     * Bez kontroly by `../../` v hlavičce zapsalo část mimo adresář nahrávání.
     */
    public function test_podvrzeny_identifikator_neprojde(): void
    {
        $this->call('POST', '/api/media/chunk', [], [], [], $this->hlavicky([
            'X-Upload-Id' => '../../../etc',
            'X-Chunk-Index' => '0',
            'X-Chunk-Count' => '1',
            'X-File-Name' => 'vzpominka.mp4',
        ]), 'data')->assertStatus(422);
    }

    /** Cesta ve jménu souboru neurčí, kam se soubor uloží. */
    public function test_jmeno_s_cestou_se_ocisti(): void
    {
        $this->call('POST', '/api/media/chunk', [], [], [], $this->hlavicky([
            'X-Upload-Id' => 'up-12345',
            'X-Chunk-Index' => '0',
            'X-Chunk-Count' => '1',
            'X-File-Name' => rawurlencode('../../tajne/vzpominka.mp4'),
        ]), 'data')->assertCreated();

        $this->assertSame('vzpominka.mp4', MediaItem::sole()->original_filename);
    }

    // ——— výdej a mazání ———

    public function test_original_se_vydava_pres_aplikaci(): void
    {
        $nahrane = $this->post('/api/media', [
            'file' => UploadedFile::fake()->createWithContent('vylet.jpg', 'obsah fotky'),
        ])->assertCreated();

        $odpoved = $this->get('/api/media/'.$nahrane->json('id').'/raw')->assertOk();

        $this->assertSame('obsah fotky', $odpoved->streamedContent());
    }

    /** Cizí pár se k souboru nedostane, ani když zná jeho identifikátor. */
    public function test_cizi_par_nedostane_cizi_soubor(): void
    {
        $nahrane = $this->post('/api/media', [
            'file' => $this->fotka('vylet.jpg'),
        ])->assertCreated();

        $makinka = User::factory()->create();
        $jinyProstor = GallerySpace::create(['name' => 'Cizí prostor', 'owner_id' => $makinka->id]);
        $makinka->gallerySpaces()->syncWithoutDetaching([$jinyProstor->id => ['role' => 'owner']]);

        Sanctum::actingAs($makinka);

        $this->getJson('/api/media/'.$nahrane->json('id').'/raw')->assertNotFound();
        $this->deleteJson('/api/media/'.$nahrane->json('id'))->assertNotFound();
    }

    public function test_smazany_soubor_jde_do_kose_a_ne_z_disku(): void
    {
        $nahrane = $this->post('/api/media', [
            'file' => $this->fotka('vylet.jpg'),
        ])->assertCreated();

        $this->deleteJson('/api/media/'.$nahrane->json('id'))->assertOk();

        $media = MediaItem::sole();
        $this->assertNotNull($media->trashed_at);
        $this->assertNotNull($media->purge_after, 'Bez data úklidu by soubor v koši zůstal navždy.');
        Storage::disk('public')->assertExists($media->variants()->where('type', 'original')->sole()->path);
    }

    /**
     * Limit tarifu platí i pro nahrávání z prototypu.
     *
     * Bez téhle kontroly by stačilo nahrávat sem a limit úložiště by neplatil
     * vůbec — a to je zrovna ta chyba, kterou by nikdo nenahlásil.
     */
    public function test_prekroceny_tarif_soubor_nepusti(): void
    {
        BillingPlan::create([
            'code' => 'maly',
            'name' => 'Malý',
            'storage_limit_mb' => 0,
            'is_default' => true,
        ]);

        $this->post('/api/media', ['file' => $this->fotka('vylet.jpg')])->assertStatus(402);

        $this->assertSame(0, MediaItem::count());
        Storage::disk('public')->assertDirectoryEmpty('media');
    }

    public function test_bez_prihlaseni_neprojde_nic(): void
    {
        $this->app['auth']->forgetGuards();
        auth()->guard('sanctum')->forgetUser();

        $this->postJson('/api/media', [])->assertUnauthorized();
        $this->postJson('/api/media/chunk', [])->assertUnauthorized();
    }

    /**
     * Nejmenší platná fotka.
     *
     * `UploadedFile::fake()->image()` potřebuje rozšíření GD, které tu není —
     * a skutečné bajty JPEGu jsou stejně poctivější: projde se jimi i rozpoznání
     * typu podle obsahu, ne jen podle přípony. `$sul` mění otisk, aby šly vyrobit
     * dvě různé fotky.
     */
    private function fotka(string $jmeno, string $sul = ''): UploadedFile
    {
        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            ."\xFF\xDB\x00\x43\x00".str_repeat("\x08", 64)
            .$sul."\xFF\xD9";

        return UploadedFile::fake()->createWithContent($jmeno, $jpeg);
    }

    private function hlavicky(array $hlavicky): array
    {
        $server = ['CONTENT_TYPE' => 'application/octet-stream'];

        foreach ($hlavicky as $jmeno => $hodnota) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($jmeno))] = $hodnota;
        }

        return $server;
    }
}
