<?php

namespace Tests\Feature\Galerie;

use App\Jobs\Media\CalculateMediaHashesJob;
use App\Jobs\Media\ExtractMediaMetadataJob;
use App\Jobs\Media\GenerateImageVariantsJob;
use App\Jobs\Media\InitiateDriveResumableUploadJob;
use App\Jobs\Media\UploadDriveChunkJob;
use App\Jobs\MirrorMediaToCloud;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Co se s fotkou děje po nahrání přes prototyp (`/api/media`).
 *
 * Tahle cesta nezakládá `UploadSession`, a úlohy hledaly soubor jen přes ni.
 * Otisky proto u každé nahrávky hlásily chybu, EXIF se nepřečetl nikdy
 * (datum pořízení zůstalo časem poslední změny souboru z prohlížeče), náhledy
 * se počítaly dvakrát a na Google Disk odcházely dvě až tři kopie téže fotky.
 */
class ZpracovaniNahravkyTest extends TestCase
{
    use RefreshDatabase;

    /** Datum pořízení zapsané do EXIFu zkušební fotky. */
    private const EXIF_DATUM = '2019-07-14 15:30:00';

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();
        // Deterministicky přes vestavěné `exif_read_data`, ať je na stroji
        // exiftool, nebo ne — oba čtou stejné `DateTimeOriginal`.
        config(['gallery.exiftool_path' => '/neexistuje/exiftool']);

        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$this->prostor->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    // ——— F3: zdroj, EXIF a jedno zpracování ———

    /**
     * Datum z EXIFu přepíše čas změny souboru z prohlížeče.
     *
     * Komentář v kontroleru to sliboval, ale metadata soubor bez nahrávací
     * relace nenašla, takže fotka z roku 2019 zůstala v časové ose u dne,
     * kdy ji někdo zkopíroval do telefonu.
     */
    public function test_exif_z_fotky_nahrane_pres_prototyp_urci_datum_porizeni(): void
    {
        $this->post('/api/media', [
            'file' => $this->fotkaSExifem('dovolena.jpg'),
            'taken_at' => 1_756_000_000_000,
        ])->assertCreated();

        $this->probehniFrontu();

        $this->assertSame(self::EXIF_DATUM, MediaItem::sole()->taken_at?->format('Y-m-d H:i:s'));
    }

    /** Otisky se spočítají z uloženého originálu, ne z relace, která neexistuje. */
    public function test_otisky_najdou_ulozeny_original(): void
    {
        $this->post('/api/media', ['file' => $this->fotkaSExifem('dovolena.jpg')])->assertCreated();

        $media = MediaItem::sole();
        app()->call([new CalculateMediaHashesJob($media->id), 'handle']);

        $media->refresh();
        $this->assertNull($media->processing_error, 'Uložený originál je platný zdroj, žádná chyba se hlásit nemá.');
        $this->assertNotNull($media->md5);
    }

    /**
     * Každý krok proběhne jednou a na Disk odejde jedna kopie.
     *
     * Kontroler zařadil náhledy, metadata i otisky vedle sebe, a metadata pak
     * náhledy zařadila znovu. Nahrání na Disk startovalo z náhledů (dvakrát)
     * i z kopie do cloudu — ve chvíli, kdy `drive_file_id` ještě nikde nebylo,
     * takže každé z nich založilo na Disku vlastní soubor.
     */
    public function test_nahrani_zpracuje_kazdy_krok_jednou_a_na_disk_posle_jednu_kopii(): void
    {
        $this->pripojDisk();

        $this->post('/api/media', ['file' => $this->fotkaSExifem('dovolena.jpg')])->assertCreated();

        $this->probehniFrontu();

        // Náhledy doběhly až k zařazení Disku — jinak by „jednou" nic nedokazovalo.
        $this->assertNull(MediaItem::sole()->processing_error);
        $this->assertSame('uploading_to_drive', MediaItem::sole()->processing_stage);

        Queue::assertPushed(CalculateMediaHashesJob::class, 1);
        Queue::assertPushed(ExtractMediaMetadataJob::class, 1);
        Queue::assertPushed(GenerateImageVariantsJob::class, 1);
        Queue::assertPushed(MirrorMediaToCloud::class, 1);
        Queue::assertPushed(InitiateDriveResumableUploadJob::class, 1);
    }

    /** Druhé zařazení téhož média, dokud první čeká ve frontě, se zahodí. */
    public function test_nahravani_na_disk_je_ve_fronte_pro_medium_jen_jednou(): void
    {
        $media = $this->ulozenaFotka();
        $jina = $this->ulozenaFotka();

        InitiateDriveResumableUploadJob::dispatch($media->id)->onQueue('drive');
        InitiateDriveResumableUploadJob::dispatch($media->id)->onQueue('drive');
        InitiateDriveResumableUploadJob::dispatch($jina->id)->onQueue('drive');

        Queue::assertPushed(InitiateDriveResumableUploadJob::class, 2);
    }

    /**
     * Nahrávání na Disk, které už běží, se nezakládá podruhé.
     *
     * Zámek fronty drží jen do konce úlohy; pozdější opakování (noční
     * dorovnání, „Zkusit znovu") musí poznat rozběhnuté nahrávání samo.
     */
    public function test_rozbehnute_nahravani_na_disk_se_nezalozi_znovu(): void
    {
        $media = $this->ulozenaFotka(['storage_status' => 'uploading']);

        app()->call([new InitiateDriveResumableUploadJob($media->id), 'handle']);

        // Bez připojeného Disku by úloha, která by pokračovala, přepnula stav
        // na `local_only` — zůstat musí `uploading`.
        $this->assertSame('uploading', $media->fresh()->storage_status);
    }

    /** Nahrávání, které se hodiny nepohnulo, se naopak zkusit znovu smí. */
    public function test_zaseknute_nahravani_na_disk_se_zkusi_znovu(): void
    {
        $media = $this->ulozenaFotka(['storage_status' => 'uploading']);
        DB::table('media_items')->where('id', $media->id)->update([
            'updated_at' => now()->subHours(MediaItem::NAHRAVANI_NA_DISK_ZASEKNUTE_PO_HODINACH + 1),
        ]);

        app()->call([new InitiateDriveResumableUploadJob($media->id), 'handle']);

        $this->assertSame('local_only', $media->fresh()->storage_status);
    }

    // ——— F6: koš mezi zařazením a během ———

    /** Fotka vyhozená do koše, zatímco čekala ve frontě, na Disk nesmí. */
    public function test_fotka_z_kose_se_na_disk_nezacne_nahravat(): void
    {
        $media = $this->ulozenaFotka(['storage_status' => 'pending', 'trashed_at' => now()]);

        app()->call([new InitiateDriveResumableUploadJob($media->id), 'handle']);

        $this->assertSame('pending', $media->fresh()->storage_status, 'Úloha se koše nesmí ani dotknout.');
    }

    /** Ani kopie do cloudu pro fotku z koše nezařadí nahrávání na Disk. */
    public function test_kopie_do_cloudu_fotku_z_kose_preskoci(): void
    {
        $this->pripojDisk();
        $media = $this->ulozenaFotka(['trashed_at' => now()]);

        app()->call([new MirrorMediaToCloud($media->id), 'handle']);

        Queue::assertNotPushed(InitiateDriveResumableUploadJob::class);
    }

    /**
     * Otisky jsou první článek řetězu — když soubor nenajdou, řetěz nekončí.
     *
     * Náhledy se dřív zařazovaly vedle otisků; od té doby, co na nich visí,
     * by fotka bez místního souboru (originál jen na vzdáleném disku) zůstala
     * bez náhledu i bez metadat. Stejně tak po posledním nepovedeném pokusu.
     */
    public function test_otisky_bez_souboru_retez_neukonci(): void
    {
        $media = $this->ulozenaFotka();
        Storage::disk('public')->delete("media/{$media->uuid}/original.jpg");

        app()->call([new CalculateMediaHashesJob($media->id), 'handle']);

        $this->assertNotNull($media->fresh()->processing_error);
        Queue::assertPushed(ExtractMediaMetadataJob::class, 1);
    }

    public function test_otisky_po_poslednim_pokusu_zaradi_dalsi_krok(): void
    {
        $media = $this->ulozenaFotka();

        (new CalculateMediaHashesJob($media->id))->failed(new \RuntimeException('výpadek'));

        Queue::assertPushed(ExtractMediaMetadataJob::class, 1);
    }

    /** Úlohy zůstávají bezpečné, i když řádek mezitím zmizel. */
    public function test_ulohy_bez_radku_media_skonci_potichu(): void
    {
        foreach ([CalculateMediaHashesJob::class, ExtractMediaMetadataJob::class, InitiateDriveResumableUploadJob::class] as $uloha) {
            app()->call([new $uloha(999_999), 'handle']);
        }
        app()->call([new MirrorMediaToCloud(999_999), 'handle']);

        Queue::assertNothingPushed();
    }

    // ——— pomocné ———

    /**
     * Projde zařazené úlohy tak, jak by je vzal pracovník, dokud nějaké přibývají.
     *
     * Nahrávání na Disk se nespouští — mluví s Googlem; počítá se jen, kolikrát
     * se zařadilo.
     */
    private function probehniFrontu(): void
    {
        $preskocit = [InitiateDriveResumableUploadJob::class, UploadDriveChunkJob::class];
        $hotove = [];

        for ($kolo = 0; $kolo < 20; $kolo++) {
            $nove = false;

            foreach (Queue::pushedJobs() as $trida => $zaznamy) {
                foreach ($zaznamy as $zaznam) {
                    $uloha = $zaznam['job'];
                    $klic = spl_object_id($uloha);

                    if (isset($hotove[$klic]) || in_array($trida, $preskocit, true)) {
                        continue;
                    }

                    $hotove[$klic] = true;
                    $nove = true;
                    app()->call([$uloha, 'handle']);
                }
            }

            if (! $nove) {
                return;
            }
        }

        $this->fail('Fronta se nezastavila — úlohy se řetězí donekonečna.');
    }

    private function pripojDisk(): StorageConnection
    {
        return StorageConnection::create([
            'provider' => 'google_drive',
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'account_email' => 'disk@vzpominky.test',
            'connection_status' => 'healthy',
            'root_folder_id' => 'slozka-123',
            'connected_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $navic */
    private function ulozenaFotka(array $navic = []): MediaItem
    {
        $media = MediaItem::create($navic + [
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'vylet.jpg',
            'safe_filename' => 'vylet.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 100,
            'status' => 'ready',
            'uploaded_at' => now(),
        ]);

        $cesta = "media/{$media->uuid}/original.jpg";
        Storage::disk('public')->put($cesta, $this->fotkaSExifem('vylet.jpg')->get());
        $media->variants()->create(['type' => 'original', 'disk' => 'public', 'path' => $cesta, 'mime_type' => 'image/jpeg', 'size_bytes' => 100]);

        return $media;
    }

    /**
     * Skutečný JPEG s EXIFem, v němž je jen `DateTimeOriginal`.
     *
     * Segment APP1 se skládá ručně (TIFF little-endian: IFD0 s ukazatelem na
     * Exif IFD, v něm jediná položka 0x9003), protože GD EXIF nezapisuje.
     */
    private function fotkaSExifem(string $jmeno): UploadedFile
    {
        $obraz = imagecreatetruecolor(16, 16);
        imagefill($obraz, 0, 0, imagecolorallocate($obraz, 200, 120, 40));
        ob_start();
        imagejpeg($obraz);
        $jpeg = (string) ob_get_clean();
        imagedestroy($obraz);

        $datum = str_replace('-', ':', self::EXIF_DATUM)."\0";
        $tiff = 'II'.pack('v', 0x2A).pack('V', 8)
            // IFD0: jedna položka — ukazatel na Exif IFD na offsetu 26.
            .pack('v', 1).pack('vvVV', 0x8769, 4, 1, 26).pack('V', 0)
            // Exif IFD: DateTimeOriginal (ASCII, 20 bajtů) na offsetu 44.
            .pack('v', 1).pack('vvVV', 0x9003, 2, strlen($datum), 44).pack('V', 0)
            .$datum;
        $app1 = "\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\0\0".$tiff;

        return UploadedFile::fake()->createWithContent($jmeno, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));
    }
}
