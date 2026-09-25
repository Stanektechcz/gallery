<?php

namespace Tests\Feature\Propojeni;

use App\Jobs\Drive\CreateDriveFolderJob;
use App\Jobs\Drive\MoveDriveFolderJob;
use App\Jobs\Drive\ProcessDriveWebhookJob;
use App\Jobs\Drive\RenameDriveFolderJob;
use App\Models\Album;
use App\Models\DriveChange;
use App\Models\DriveChangeChannel;
use App\Models\GallerySpace;
use App\Models\StorageConnection;
use App\Models\User;
use App\Services\Storage\GoogleDriveStorageProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Úlohy Google Disku mluví jen s Google Diskem dvojice.
 *
 * Složky alb (založení, přejmenování, přesun) braly první „zdravé" připojení
 * vlastníka bez ohledu na poskytovatele — při připojeném Dropboxu tak šel
 * jeho token do API Googlu a složka nevznikla. A zpracování změn z Disku
 * uvízlo na jediném dlouhém názvu: sloupec má 255 znaků, výjimka se spolkla
 * a značka stránky se neposunula, takže každé další upozornění narazilo znovu.
 */
class DiskoveUlohyTest extends TestCase
{
    use RefreshDatabase;

    private User $vlastnik;

    private GallerySpace $prostor;

    /** Připojení, se kterými se poskytovatel Disku sestavil. @var list<int> */
    private array $sestaveno = [];

    private MockInterface $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vlastnik = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->vlastnik->id, 'is_default' => true]);
        $this->prostor->members()->syncWithoutDetaching([$this->vlastnik->id => ['role' => 'owner']]);

        $this->disk = Mockery::mock(GoogleDriveStorageProvider::class);
        $this->app->bind(GoogleDriveStorageProvider::class, function ($app, array $parametry) {
            $this->sestaveno[] = $parametry['connection']->id;

            return $this->disk;
        });
    }

    public function test_slozka_alba_vznikne_na_google_disku_i_vedle_dropboxu(): void
    {
        // Dropbox je starší (nižší id) — dřív vyhrál prosté `first()`.
        $this->pripojeni('dropbox');
        $google = $this->pripojeni('google_drive', 'koren-google');
        $album = $this->album();

        $this->disk->shouldReceive('find')->once()->with('koren-google', 'Léto')->andReturn(null);
        $this->disk->shouldReceive('createFolder')->once()->with('Léto', 'koren-google')->andReturn(['id' => 'slozka-leto']);

        (new CreateDriveFolderJob($album->id))->handle();

        $this->assertSame([$google->id], $this->sestaveno);
        $this->assertSame('slozka-leto', $album->fresh()->drive_folder_id);
    }

    public function test_bez_google_disku_se_slozka_nezaklada(): void
    {
        $this->pripojeni('dropbox', 'koren-dropbox');
        $album = $this->album();

        (new CreateDriveFolderJob($album->id))->handle();

        $this->assertSame([], $this->sestaveno, 'Token Dropboxu nesmí odejít do API Googlu.');
        $this->assertNull($album->fresh()->drive_folder_id);
    }

    /** Disk hosta (divák s vlastním Diskem) do složek dvojice nepatří. */
    public function test_disk_hosta_se_nepouzije(): void
    {
        $host = User::factory()->create(['role' => 'owner']);
        $this->prostor->members()->syncWithoutDetaching([$host->id => ['role' => 'viewer']]);
        $this->pripojeni('google_drive', 'koren-hosta', $host);
        $album = $this->album();

        (new CreateDriveFolderJob($album->id))->handle();

        $this->assertSame([], $this->sestaveno);
    }

    public function test_prejmenovani_jde_na_google_disk(): void
    {
        $this->pripojeni('dropbox');
        $google = $this->pripojeni('google_drive', 'koren-google');
        $album = $this->album(['drive_folder_id' => 'slozka-leto']);

        $this->disk->shouldReceive('renameFolder')->once()->with('slozka-leto', 'Podzim');

        (new RenameDriveFolderJob($album->id, 'Podzim'))->handle();

        $this->assertSame([$google->id], $this->sestaveno);
    }

    public function test_presun_jde_na_google_disk(): void
    {
        $this->pripojeni('dropbox');
        $google = $this->pripojeni('google_drive', 'koren-google');
        $album = $this->album(['drive_folder_id' => 'slozka-leto']);

        $this->disk->shouldReceive('moveFolder')->once()->with('slozka-leto', 'nadrazena');

        (new MoveDriveFolderJob($album->id, 'nadrazena'))->handle();

        $this->assertSame([$google->id], $this->sestaveno);
    }

    public function test_prejmenovani_ani_presun_bez_google_disku_nic_neposle(): void
    {
        $this->pripojeni('dropbox', 'koren-dropbox');
        $album = $this->album(['drive_folder_id' => 'slozka-leto']);

        (new RenameDriveFolderJob($album->id, 'Podzim'))->handle();
        (new MoveDriveFolderJob($album->id, 'nadrazena'))->handle();

        $this->assertSame([], $this->sestaveno);
    }

    public function test_dlouhy_nazev_z_disku_nezablokuje_dalsi_zmeny(): void
    {
        $google = $this->pripojeni('google_drive', 'koren-google');
        $kanal = DriveChangeChannel::create([
            'storage_connection_id' => $google->id,
            'channel_id' => 'kanal-1',
            'resource_id' => 'zdroj-1',
            'channel_token' => 'tajny',
            'page_token' => 'stranka-1',
            'is_active' => true,
        ]);

        $this->disk->shouldReceive('listChanges')->once()->with('stranka-1')->andReturn([
            'changes' => [
                ['file_id' => 'soubor-dlouhy', 'removed' => false, 'time' => now()->toIso8601String(),
                    'file' => ['name' => str_repeat('Ř', 300), 'trashed' => false]],
                ['file_id' => 'soubor-kratky', 'removed' => false, 'time' => now()->toIso8601String(),
                    'file' => ['name' => 'fotka.jpg', 'trashed' => false]],
            ],
            'next_page_token' => null,
            'new_start_token' => 'stranka-2',
        ]);

        (new ProcessDriveWebhookJob($google->id, 'kanal-1', 'change', 'zdroj-1', 2))->handle();

        $this->assertSame('stranka-2', $kanal->fresh()->page_token);
        $dlouhy = DriveChange::where('file_id', 'soubor-dlouhy')->first();
        $this->assertNotNull($dlouhy);
        $this->assertLessThanOrEqual(255, mb_strlen((string) $dlouhy->file_name));
        $this->assertTrue(DriveChange::where('file_id', 'soubor-kratky')->exists());
    }

    /** Jedna vadná změna neshodí ostatní ani posun značky stránky. */
    public function test_vadna_zmena_se_preskoci(): void
    {
        $google = $this->pripojeni('google_drive', 'koren-google');
        $kanal = DriveChangeChannel::create([
            'storage_connection_id' => $google->id,
            'channel_id' => 'kanal-1',
            'resource_id' => 'zdroj-1',
            'channel_token' => 'tajny',
            'page_token' => 'stranka-1',
            'is_active' => true,
        ]);

        $this->disk->shouldReceive('listChanges')->once()->andReturn([
            'changes' => [
                // Neplatný čas změny — databáze by ho nepřijala.
                ['file_id' => 'soubor-vadny', 'removed' => false, 'time' => 'není-čas', 'file' => null],
                ['file_id' => 'soubor-kratky', 'removed' => false, 'time' => now()->toIso8601String(),
                    'file' => ['name' => 'fotka.jpg', 'trashed' => false]],
            ],
            'next_page_token' => 'stranka-2',
            'new_start_token' => null,
        ]);

        (new ProcessDriveWebhookJob($google->id, 'kanal-1', 'change', 'zdroj-1', 3))->handle();

        $this->assertSame('stranka-2', $kanal->fresh()->page_token);
        $this->assertTrue(DriveChange::where('file_id', 'soubor-kratky')->exists());
    }

    private function pripojeni(string $poskytovatel, ?string $koren = null, ?User $vlastnik = null): StorageConnection
    {
        return StorageConnection::create([
            'provider' => $poskytovatel,
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => ($vlastnik ?? $this->vlastnik)->id,
            'encrypted_access_token' => Crypt::encryptString('token-'.$poskytovatel),
            'connection_status' => 'healthy',
            'root_folder_id' => $koren,
            'connected_at' => now(),
            'last_successful_request_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $atributy */
    private function album(array $atributy = []): Album
    {
        return Album::create(array_merge([
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Léto',
            'slug' => 'leto-'.Str::random(6),
            'visibility' => 'shared',
            'created_by' => $this->vlastnik->id,
            'updated_by' => $this->vlastnik->id,
        ], $atributy));
    }
}
