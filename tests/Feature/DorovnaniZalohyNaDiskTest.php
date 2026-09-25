<?php

namespace Tests\Feature;

use App\Jobs\Media\EnqueueDriveMediaSyncJob;
use App\Jobs\Media\InitiateDriveResumableUploadJob;
use App\Jobs\MirrorMediaToCloud;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Noční `gallery:mirror-backlog` a „Zkusit znovu" pro Google Disk.
 *
 * Dorovnání vynechávalo fotky s variantou na disku poskytovatele — jenže Disk
 * si kopii pamatuje `drive_file_id`, ne variantou. Hotové fotky proto do
 * výběru padaly pořád, `limit(500)` bez řazení bral každou noc tytéž a na
 * fotky, které na Disku opravdu chyběly, nikdy nedošlo. Rozběhnutá nahrávání
 * navíc dostávala další zahájení — tedy další kopii na Disku.
 */
class DorovnaniZalohyNaDiskTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);
    }

    public function test_dorovnani_zaradi_nezkopirovanou_fotku_i_za_hotovymi(): void
    {
        $this->pripoj('google_drive');
        foreach (range(1, 3) as $i) {
            $this->fotka(['drive_file_id' => "disk-{$i}", 'storage_status' => 'synced']);
        }
        $chybi = $this->fotka();

        $this->artisan('gallery:mirror-backlog', ['--limit' => 3])->assertExitCode(0);

        Queue::assertPushed(MirrorMediaToCloud::class, 1);
        Queue::assertPushed(MirrorMediaToCloud::class, fn (MirrorMediaToCloud $uloha) => $uloha->mediaId === $chybi->id);
    }

    public function test_dorovnani_preskoci_rozbehnute_nahravani_ale_ne_zaseknute(): void
    {
        $this->pripoj('google_drive');
        $bezi = $this->fotka(['storage_status' => 'uploading']);
        $zasekle = $this->fotka(['storage_status' => 'uploading']);
        $this->zestarni($zasekle);

        $this->artisan('gallery:mirror-backlog')->assertExitCode(0);

        Queue::assertPushed(MirrorMediaToCloud::class, 1);
        Queue::assertPushed(MirrorMediaToCloud::class, fn (MirrorMediaToCloud $uloha) => $uloha->mediaId === $zasekle->id);
        Queue::assertNotPushed(MirrorMediaToCloud::class, fn (MirrorMediaToCloud $uloha) => $uloha->mediaId === $bezi->id);
    }

    /** Ostatní cloudy si kopii pamatují variantou — tam se nic nemění. */
    public function test_dropbox_dal_pozna_kopii_podle_varianty(): void
    {
        $this->pripoj('dropbox');
        $hotova = $this->fotka();
        $hotova->variants()->create(['type' => 'cloud_copy', 'disk' => 'dropbox', 'path' => '/galerie/x.jpg']);
        $chybi = $this->fotka();

        $this->artisan('gallery:mirror-backlog')->assertExitCode(0);

        Queue::assertPushed(MirrorMediaToCloud::class, 1);
        Queue::assertPushed(MirrorMediaToCloud::class, fn (MirrorMediaToCloud $uloha) => $uloha->mediaId === $chybi->id);
    }

    /** „Zkusit znovu" posílá jen to, co na Disku není a ani se tam nenahrává. */
    public function test_zkusit_znovu_vynecha_hotove_a_rozbehnute(): void
    {
        $this->fotka(['drive_file_id' => 'disk-1', 'storage_status' => 'synced']);
        $this->fotka(['storage_status' => 'uploading']);
        $this->fotka(['trashed_at' => now()]);
        $zasekle = $this->fotka(['storage_status' => 'uploading']);
        $this->zestarni($zasekle);
        $chybi = $this->fotka();

        app()->call([new EnqueueDriveMediaSyncJob($this->prostor->id), 'handle']);

        $zarazene = Queue::pushed(InitiateDriveResumableUploadJob::class)
            ->map(fn (InitiateDriveResumableUploadJob $uloha) => (int) $uloha->uniqueId())
            ->sort()->values()->all();
        $this->assertSame([$zasekle->id, $chybi->id], $zarazene);
    }

    // ——— pomocné ———

    private function pripoj(string $poskytovatel): StorageConnection
    {
        return StorageConnection::create([
            'provider' => $poskytovatel,
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'account_email' => 'disk@vzpominky.test',
            'connection_status' => 'healthy',
            'root_folder_id' => 'slozka-123',
            'connected_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $navic */
    private function fotka(array $navic = []): MediaItem
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
            'storage_status' => 'local_only',
            'uploaded_at' => now(),
        ]);

        $media->variants()->create(['type' => 'original', 'disk' => 'public', 'path' => "media/{$media->uuid}/original.jpg"]);

        return $media;
    }

    /** Nahrávání, které se déle, než je mez, nepohnulo. */
    private function zestarni(MediaItem $media): void
    {
        DB::table('media_items')->where('id', $media->id)->update([
            'updated_at' => now()->subHours(MediaItem::NAHRAVANI_NA_DISK_ZASEKNUTE_PO_HODINACH + 1),
        ]);
    }
}
