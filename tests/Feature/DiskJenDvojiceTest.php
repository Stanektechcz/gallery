<?php

namespace Tests\Feature;

use App\Jobs\Media\EnqueueDriveMediaSyncJob;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Models\User;
use App\Services\Storage\DriveConnectionResolver;
use App\Services\Storage\DriveStructureService;
use App\Services\Storage\GoogleOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Originály dvojice jdou jen na Disk dvojice — ne na Disk hosta.
 *
 * Resolver bral za člena prostoru každý řádek `gallery_space_user`, tedy i hosta
 * (`viewer`/`contributor`). Host, který si připojil vlastní Google Disk, tak mohl
 * dostávat originály dvojice a panel úložiště dvojice ukazoval e-mail jeho účtu.
 * Totéž platilo pro synchronizaci, kterou zařazuje připojení Disku: šla do všech
 * prostorů účtu, i do těch, kde je jen host.
 */
class DiskJenDvojiceTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $host;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['role' => 'owner']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        // Host má vlastní galerii (proto projde bránou `dvojice:web`) a v galerii
        // dvojice je jen divák.
        $this->host = User::factory()->create(['role' => 'owner']);
        $vlastni = GallerySpace::create(['name' => 'Hostova galerie', 'owner_id' => $this->host->id, 'is_default' => true]);
        $vlastni->members()->syncWithoutDetaching([$this->host->id => ['role' => 'owner']]);
        $this->prostor->members()->syncWithoutDetaching([$this->host->id => ['role' => 'viewer']]);
    }

    public function test_disk_hosta_neni_diskem_dvojice(): void
    {
        $this->disk($this->host, 'host@cizi.test');

        $this->assertNull(app(DriveConnectionResolver::class)->forSpace($this->prostor->id));
    }

    /** Nahrávka hosta (přispěvatele) nepřesměruje originál na jeho Disk. */
    public function test_nahravka_hosta_nejde_na_jeho_disk(): void
    {
        $this->prostor->members()->updateExistingPivot($this->host->id, ['role' => 'contributor']);
        $this->disk($this->host, 'host@cizi.test');

        $this->assertNull(app(DriveConnectionResolver::class)->forSpace($this->prostor->id, $this->host->id));
        $this->assertNull(app(DriveConnectionResolver::class)->forMedia($this->media($this->host)));
    }

    public function test_disk_partnera_z_dvojice_se_pouzije(): void
    {
        $makinka = User::factory()->create();
        $this->prostor->members()->syncWithoutDetaching([$makinka->id => ['role' => 'editor']]);
        $this->disk($this->host, 'host@cizi.test');
        $disk = $this->disk($makinka, 'makinka@vzpominky.test');

        $this->assertSame($disk->id, app(DriveConnectionResolver::class)->forSpace($this->prostor->id)?->id);
    }

    /** Vlastník prostoru je vlastník, i když mu chybí řádek členství. */
    public function test_vlastnik_bez_clenstvi_se_pocita(): void
    {
        $this->prostor->members()->detach($this->adri->id);
        $disk = $this->disk($this->adri, 'adri@vzpominky.test');

        $this->assertSame($disk->id, app(DriveConnectionResolver::class)->forSpace($this->prostor->id)?->id);
    }

    public function test_pripojeni_disku_hostem_nezaradi_synchronizaci_galerie_dvojice(): void
    {
        Queue::fake();
        $disk = $this->disk($this->host, 'host@cizi.test');

        $oauth = Mockery::mock(GoogleOAuthService::class);
        $oauth->shouldReceive('handleCallback')->once()->andReturn($disk);
        $this->app->instance(GoogleOAuthService::class, $oauth);

        $struktura = Mockery::mock(DriveStructureService::class);
        $struktura->shouldReceive('initializeRootStructure')->once()->andReturn(['root_id' => 'koren-hosta']);
        $this->app->bind(DriveStructureService::class, fn () => $struktura);

        $this->actingAs($this->host)
            ->withSession(['oauth.google.state' => 'spravny'])
            ->get('/oauth/google/callback?code=kod&state=spravny')
            ->assertRedirect(route('settings.storage.google'))
            ->assertSessionHas('success');

        $this->assertNotContains($this->prostor->id, $this->zarazeneProstory());
        $this->assertCount(1, $this->zarazeneProstory(), 'Vlastní galerie hosta se synchronizovat má.');
    }

    public function test_synchronizace_existujicich_nezaradi_galerii_kde_je_host(): void
    {
        Queue::fake();
        $this->disk($this->host, 'host@cizi.test');

        $this->actingAs($this->host)
            ->post('/settings/storage/google/sync-existing')
            ->assertRedirect();

        $this->assertNotContains($this->prostor->id, $this->zarazeneProstory());
        $this->assertCount(1, $this->zarazeneProstory());
    }

    /** @return list<int> */
    private function zarazeneProstory(): array
    {
        return Queue::pushed(EnqueueDriveMediaSyncJob::class)
            ->map(fn (EnqueueDriveMediaSyncJob $uloha) => (fn () => $this->gallerySpaceId)->call($uloha))
            ->values()
            ->all();
    }

    private function disk(User $vlastnik, string $email): StorageConnection
    {
        return StorageConnection::create([
            'provider' => 'google_drive',
            'owner_user_id' => $vlastnik->id,
            'account_email' => $email,
            'connection_status' => 'healthy',
            'root_folder_id' => 'slozka-'.$vlastnik->id,
            'connected_at' => now(),
            'last_successful_request_at' => now(),
        ]);
    }

    private function media(User $autor): MediaItem
    {
        return MediaItem::create([
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $autor->id,
            'uploaded_by' => $autor->id,
            'original_filename' => 'vylet.jpg',
            'safe_filename' => 'vylet.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1000,
            'status' => 'ready',
            'storage_status' => 'local_only',
        ]);
    }
}
