<?php

namespace Tests\Feature\Media;

use App\Jobs\Media\RemoveCloudCopy;
use App\Models\AuditLog;
use App\Models\CloudCopyDeletion;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Models\User;
use App\Services\Media\MediaPurger;
use App\Services\Storage\GoogleDriveStorageProvider;
use App\Services\Storage\OneDriveClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Trvale smazaná fotka má zmizet i z cloudu, kam se zrcadlila.
 *
 * `MediaPurger` mazal jen soubory na serveru a originál na Google Disku — a ten
 * jen tehdy, když bylo spojení zrovna zdravé. Kopie v Dropboxu, OneDrivu
 * a WebDAV zůstávaly: řádky `cloud_copy` se smazaly kaskádou spolu s položkou,
 * takže odkaz na vzdálený soubor zmizel navždy a fotka, o které aplikace tvrdí,
 * že je pryč, ležela dál v cizím cloudu. Po schválení obou partnerů.
 */
class CloudKopieMazaniTest extends TestCase
{
    use RefreshDatabase;
    use VytvariMedia;

    /** Veřejná adresa jako IP — test tak nezávisí na DNS. */
    private const WEBDAV = 'https://93.184.215.14/dav';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->zalozProstor();
    }

    public function test_trvale_smazani_odstrani_kopii_z_dropboxu(): void
    {
        Http::fake(['https://api.dropboxapi.com/2/files/delete_v2' => Http::response(['metadata' => ['.tag' => 'file']], 200)]);
        $this->pripojeni('dropbox');
        $media = $this->media();
        $cesta = '/maki gallery/prostor-'.$this->prostor->id.'/'.$media->uuid.'.jpg';
        $this->varianta($media, 'cloud_copy', $cesta, 'dropbox');

        $this->smazTrvale($media);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.dropboxapi.com/2/files/delete_v2'
            && $r->method() === 'POST'
            && $r['path'] === $cesta);
        $this->assertSame(CloudCopyDeletion::STATUS_DONE, CloudCopyDeletion::sole()->status);
        $this->assertNotNull(CloudCopyDeletion::sole()->done_at);
    }

    public function test_trvale_smazani_odstrani_kopii_z_onedrive(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/drive/root:*' => Http::response(['id' => 'ABC!12', 'name' => 'x.jpg', 'size' => 3], 201),
            'https://graph.microsoft.com/v1.0/me/drive/items/*' => Http::response(null, 204),
        ]);
        $spojeni = $this->pripojeni('onedrive');

        // Nové nahrání si pamatuje id položky v Graphu, ne jen jméno — jméno se
        // při kolizi přejmenuje a uživatel může soubor přesunout.
        $nahrano = app(OneDriveClient::class)->upload($spojeni, '/MAKI Gallery/prostor-1/x.jpg', 'abc');
        $this->assertSame('id:ABC!12', $nahrano['path']);

        $media = $this->media();
        $this->varianta($media, 'cloud_copy', $nahrano['path'], 'onedrive');

        $this->smazTrvale($media);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && $r->url() === 'https://graph.microsoft.com/v1.0/me/drive/items/ABC%2112');
        $this->assertSame(CloudCopyDeletion::STATUS_DONE, CloudCopyDeletion::sole()->status);
    }

    /** Starší kopie nesou jen jméno souboru — cesta se složí ze složky prostoru. */
    public function test_starsi_kopie_v_onedrive_se_smaze_podle_cesty(): void
    {
        Http::fake(['https://graph.microsoft.com/*' => Http::response(null, 204)]);
        $this->pripojeni('onedrive');
        $media = $this->media();
        $this->varianta($media, 'cloud_copy', $media->uuid.'.jpg', 'onedrive');

        $this->smazTrvale($media);

        $ocekavana = 'https://graph.microsoft.com/v1.0/me/drive/root:/MAKI%20Gallery/prostor-'
            .$this->prostor->id.'/'.$media->uuid.'.jpg';
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === $ocekavana);
        $this->assertSame(CloudCopyDeletion::STATUS_DONE, CloudCopyDeletion::sole()->status);
    }

    public function test_trvale_smazani_odstrani_kopii_z_webdav(): void
    {
        Http::fake([self::WEBDAV.'/*' => Http::response('', 204)]);
        $this->pripojeni('webdav');
        $media = $this->media();
        $cesta = 'MAKI Gallery/prostor-'.$this->prostor->id.'/'.$media->uuid.'.jpg';
        $this->varianta($media, 'cloud_copy', $cesta, 'webdav');

        $this->smazTrvale($media);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && $r->url() === self::WEBDAV.'/MAKI%20Gallery/prostor-'.$this->prostor->id.'/'.$media->uuid.'.jpg');
        $this->assertSame(CloudCopyDeletion::STATUS_DONE, CloudCopyDeletion::sole()->status);
    }

    /**
     * Záznam o kopii přežije smazání řádku.
     *
     * Varianty mizí kaskádou s položkou, takže odkaz na vzdálený soubor se musí
     * uložit dřív — jinak by opakovaný pokus po výpadku neměl co mazat.
     */
    public function test_odkaz_na_kopii_prezije_smazani_radku(): void
    {
        Queue::fake();
        $disk = $this->pripojeni('google_drive');
        $dropbox = $this->pripojeni('dropbox');
        $media = $this->media(['drive_file_id' => 'drive-abc', 'original_filename' => 'tajne.jpg']);
        $this->varianta($media, 'cloud_copy', '/maki gallery/x.jpg', 'dropbox');

        $this->smazTrvale($media);

        $this->assertNull(MediaItem::withTrashed()->find($media->id));
        $zaznamy = CloudCopyDeletion::orderBy('provider')->get();
        $this->assertSame(['dropbox', 'google_drive'], $zaznamy->pluck('provider')->all());
        $this->assertSame([$dropbox->id, $disk->id], $zaznamy->pluck('storage_connection_id')->all());
        $this->assertSame(['/maki gallery/x.jpg', 'drive-abc'], $zaznamy->pluck('remote_ref')->all());
        $this->assertSame([$media->uuid, $media->uuid], $zaznamy->pluck('media_uuid')->all());
        $this->assertSame([CloudCopyDeletion::STATUS_PENDING, CloudCopyDeletion::STATUS_PENDING], $zaznamy->pluck('status')->all());

        Queue::assertPushedOn('drive', RemoveCloudCopy::class);
        Queue::assertPushed(RemoveCloudCopy::class, 2);
    }

    /** Cloud, který zrovna neodpovídá, nesmí zdržet ani zastavit smazání u nás. */
    public function test_vypadek_cloudu_neblokuje_mazani(): void
    {
        Queue::fake();
        Http::fake(['https://api.dropboxapi.com/*' => Http::response(['error_summary' => 'internal_error/'], 500)]);
        $this->pripojeni('dropbox');
        $media = $this->media();
        $this->varianta($media, 'cloud_copy', '/maki gallery/x.jpg', 'dropbox');

        $this->smazTrvale($media);
        $this->assertNull(MediaItem::withTrashed()->find($media->id));

        $zaznam = CloudCopyDeletion::sole();
        $uloha = new RemoveCloudCopy($zaznam->id);

        try {
            app()->call([$uloha, 'handle']);
            $this->fail('Nepovedené smazání se má zkusit znovu — úloha musí vyhodit výjimku.');
        } catch (\RuntimeException) {
            // Očekávané: fronta úlohu zopakuje podle `backoff`.
        }

        $zaznam->refresh();
        $this->assertSame(CloudCopyDeletion::STATUS_PENDING, $zaznam->status);
        $this->assertSame(1, $zaznam->attempts);
        $this->assertNotEmpty($zaznam->last_error);

        // Po posledním pokusu to fronta vzdá — a záznam to řekne doktorovi.
        $uloha->failed(new \RuntimeException('dosly pokusy'));
        $this->assertSame(CloudCopyDeletion::STATUS_FAILED, $zaznam->refresh()->status);
    }

    /** Soubor, který v cloudu už není, je smazaný — ne chyba k opakování. */
    public function test_nenalezena_kopie_je_uspech(): void
    {
        Http::fake([
            'https://api.dropboxapi.com/*' => Http::response(['error_summary' => 'path_lookup/not_found/..', 'error' => ['.tag' => 'path_lookup']], 409),
            'https://graph.microsoft.com/*' => Http::response(['error' => ['code' => 'itemNotFound']], 404),
        ]);
        $this->pripojeni('dropbox');
        $this->pripojeni('onedrive');

        $prvni = $this->media();
        $this->varianta($prvni, 'cloud_copy', '/maki gallery/a.jpg', 'dropbox');
        $druha = $this->media();
        $this->varianta($druha, 'cloud_copy', 'id:XYZ', 'onedrive');

        $this->smazTrvale($prvni);
        $this->smazTrvale($druha);

        $this->assertSame(
            [CloudCopyDeletion::STATUS_DONE, CloudCopyDeletion::STATUS_DONE],
            CloudCopyDeletion::orderBy('id')->pluck('status')->all(),
        );
    }

    /** Odpojený cloud se nezkouší do nekonečna — záznam selže s vysvětlením. */
    public function test_bez_pripojeni_mazani_selze_s_duvodem(): void
    {
        Queue::fake();
        $media = $this->media();
        $this->varianta($media, 'cloud_copy', '/maki gallery/a.jpg', 'dropbox');

        $this->smazTrvale($media);

        $zaznam = CloudCopyDeletion::sole();
        $this->assertNull($zaznam->storage_connection_id);

        app()->call([new RemoveCloudCopy($zaznam->id), 'handle']);

        $zaznam->refresh();
        $this->assertSame(CloudCopyDeletion::STATUS_FAILED, $zaznam->status);
        $this->assertStringContainsString('připojení', mb_strtolower((string) $zaznam->last_error));
        Http::assertNothingSent();
    }

    /**
     * Disk se hledá podle prostoru položky, ne podle toho, kde všude je vlastník.
     *
     * Dřív se vzalo první zdravé spojení kohokoli, kdo je v prostoru členem —
     * i hosta s vlastní galerií a vlastním Diskem.
     */
    public function test_disk_se_maze_spojenim_vlastniho_prostoru(): void
    {
        Queue::fake();
        $this->pripojeni('google_drive'); // Disk Adriho pro jeho vlastní galerii, nižší id.

        $bara = User::factory()->create();
        $druhy = GallerySpace::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Bářina galerie',
            'slug' => 'barina-'.Str::random(6), 'owner_id' => $bara->id,
        ]);
        $druhy->members()->attach($bara->id, ['role' => 'owner', 'joined_at' => now()]);
        $druhy->members()->attach($this->adri->id, ['role' => 'viewer', 'joined_at' => now()]);
        $baryDisk = $this->pripojeni('google_drive', $druhy, $bara);

        $media = $this->media(['drive_file_id' => 'drive-bara'], $druhy);
        $this->smazTrvale($media);

        $zaznam = CloudCopyDeletion::sole();
        $this->assertSame($baryDisk->id, $zaznam->storage_connection_id);

        $sestaveno = [];
        $disk = Mockery::mock(GoogleDriveStorageProvider::class);
        $disk->shouldReceive('trash')->once()->with('drive-bara')->andReturn(true);
        $this->app->bind(GoogleDriveStorageProvider::class, function ($app, array $parametry) use (&$sestaveno, $disk) {
            $sestaveno[] = $parametry['connection']->id;

            return $disk;
        });

        app()->call([new RemoveCloudCopy($zaznam->id), 'handle']);

        $this->assertSame([$baryDisk->id], $sestaveno);
        $this->assertSame(CloudCopyDeletion::STATUS_DONE, $zaznam->refresh()->status);
    }

    /**
     * Disk připojený přes Google nemá `gallery_space_id` (spojení patří účtu) —
     * najde se přes dvojici prostoru, stejně jako při nahrávání.
     */
    public function test_disk_bez_prostoru_se_najde_pres_dvojici(): void
    {
        Queue::fake();
        $disk = $this->pripojeni('google_drive', navic: ['gallery_space_id' => null, 'connection_status' => 'error']);
        $media = $this->media(['drive_file_id' => 'drive-abc']);

        $this->smazTrvale($media);

        $this->assertSame($disk->id, CloudCopyDeletion::sole()->storage_connection_id,
            'Spojení v chybě se zaznamenat má — úloha to zkusí znovu, až se obnoví.');
    }

    /** Úklid sirotků maže přes `MediaPurger` a jméno z trezoru nepíše do protokolu. */
    public function test_uklid_sirotku_jde_pres_purger(): void
    {
        Queue::fake();
        $media = $this->media(['is_hidden' => true, 'original_filename' => 'trezor-tajne.jpg', 'storage_status' => 'local_only']);
        $this->varianta($media, 'original', 'media/chybi.jpg');

        $this->artisan('gallery:exif --clean-orphans --opravdu')
            ->doesntExpectOutputToContain('trezor-tajne.jpg')
            ->assertSuccessful();

        $this->assertNull(MediaItem::withTrashed()->find($media->id));
        $this->assertSame(0, CloudCopyDeletion::count());

        $zaznam = AuditLog::where('action', 'media.orphan.purged')->sole();
        $this->assertArrayNotHasKey('filename', (array) $zaznam->payload);
    }

    /**
     * Sirotek s kopií v cloudu se nemaže — ta kopie je jediná, co zbylo.
     *
     * „Originál na serveru chybí" umí způsobit i nepřipojený nebo špatně
     * nastavený disk. Úklid s `--opravdu` by pak přes `MediaPurger` smazal
     * i zálohu v cloudu. Taková položka se jen vypíše jako obnovitelná.
     */
    public function test_sirotek_s_kopii_v_cloudu_se_nemaze(): void
    {
        Queue::fake();
        $this->pripojeni('dropbox');
        $vDropboxu = $this->media(['is_hidden' => true, 'original_filename' => 'trezor-tajne.jpg', 'storage_status' => 'local_only']);
        $this->varianta($vDropboxu, 'original', 'media/chybi-1.jpg');
        $this->varianta($vDropboxu, 'cloud_copy', '/maki gallery/t.jpg', 'dropbox');
        $naDisku = $this->media(['drive_file_id' => 'drive-abc', 'storage_status' => 'synced']);
        $this->varianta($naDisku, 'original', 'media/chybi-2.jpg');

        $this->artisan('gallery:exif --clean-orphans --opravdu')
            ->expectsOutputToContain('Obnovitelné z cloudu (nesmazáno): 2')
            ->doesntExpectOutputToContain('trezor-tajne.jpg')
            ->assertSuccessful();

        $this->assertNotNull($vDropboxu->fresh());
        $this->assertNotNull($naDisku->fresh());
        $this->assertSame(0, CloudCopyDeletion::count());
        $this->assertSame(0, AuditLog::where('action', 'media.orphan.purged')->count());
        Queue::assertNothingPushed();
    }

    public function test_selhane_mazani_hlasi_doktor(): void
    {
        $this->zaznam(['status' => CloudCopyDeletion::STATUS_FAILED, 'last_error' => 'Připojení chybí.']);
        $this->zaznam()->forceFill(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)])->save();
        $this->zaznam(); // Čerstvý čekající je v pořádku.
        // Starý záznam, který se právě zkouší znovu, nevisí.
        $this->zaznam()->forceFill(['created_at' => now()->subDays(5)])->save();

        $this->artisan('gallery:doctor')
            // Jedno očekávání: jeden vypsaný řádek splní v Mockery jen jedno.
            ->expectsOutputToContain('Mazání kopií v cloudu: 1 selhalo, 1 čeká déle než den — přehled: '
                .'php artisan gallery:cloud-mazani, zkusit znovu: php artisan gallery:cloud-mazani --znovu')
            ->run();
    }

    /** Bez přepínače jen počty podle cloudu a stavu — nic se nemění ani nezařadí. */
    public function test_prikaz_cloud_mazani_bez_prepinace_jen_vypise(): void
    {
        Queue::fake();
        $this->zaznam(['status' => CloudCopyDeletion::STATUS_FAILED, 'last_error' => 'Dropbox odpověděl HTTP 500.']);
        $this->zaznam(['status' => CloudCopyDeletion::STATUS_FAILED]);
        $this->zaznam(['provider' => 'onedrive', 'status' => CloudCopyDeletion::STATUS_DONE]);

        $this->artisan('gallery:cloud-mazani')
            ->expectsOutputToContain('dropbox')
            ->expectsOutputToContain('onedrive')
            ->expectsOutputToContain('php artisan gallery:cloud-mazani --znovu')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(2, CloudCopyDeletion::where('status', CloudCopyDeletion::STATUS_FAILED)->count());
    }

    /**
     * `--znovu` vrátí selhané i visící do fronty; čerstvé a hotové nechá.
     *
     * `last_error` zůstává jako historie — úloha ho při úspěchu smaže a při
     * nové chybě přepíše, takže do té doby je vidět, proč se to opakuje.
     */
    public function test_prikaz_cloud_mazani_znovu_vrati_selhane_do_fronty(): void
    {
        Queue::fake();
        $selhany = $this->zaznam(['status' => CloudCopyDeletion::STATUS_FAILED, 'attempts' => 6, 'last_error' => 'Dropbox odpověděl HTTP 500.']);
        $visici = $this->zaznam(['attempts' => 2]);
        $visici->forceFill(['updated_at' => now()->subDays(2)])->save();
        $cerstvy = $this->zaznam();
        $hotovy = $this->zaznam(['status' => CloudCopyDeletion::STATUS_DONE, 'done_at' => now()]);

        $this->artisan('gallery:cloud-mazani --znovu')
            ->expectsOutputToContain('Znovu zařazeno: 2')
            ->assertSuccessful();

        $selhany->refresh();
        $this->assertSame(CloudCopyDeletion::STATUS_PENDING, $selhany->status);
        $this->assertSame(0, $selhany->attempts);
        $this->assertSame('Dropbox odpověděl HTTP 500.', $selhany->last_error);
        $this->assertTrue($visici->refresh()->updated_at->isAfter(now()->subMinute()), 'Vrácený záznam nesmí hned zase „viset".');
        $this->assertSame(CloudCopyDeletion::STATUS_DONE, $hotovy->refresh()->status);

        $zarazene = Queue::pushed(RemoveCloudCopy::class)->map(fn (RemoveCloudCopy $u) => $u->deletionId)->sort()->values()->all();
        $this->assertSame([$selhany->id, $visici->id], $zarazene);
        $this->assertNotContains($cerstvy->id, $zarazene);
        Queue::assertPushedOn('drive', RemoveCloudCopy::class);
    }

    // ——— pomocné ———

    /** Stejné pořadí jako koš a noční úklid: nejdřív soubory, pak řádek. */
    private function smazTrvale(MediaItem $media): void
    {
        app(MediaPurger::class)->purge($media);
        $media->forceDelete();
    }

    /** @param  array<string, mixed>  $navic */
    private function pripojeni(string $poskytovatel, ?GallerySpace $prostor = null, ?User $vlastnik = null, array $navic = []): StorageConnection
    {
        $prostor ??= $this->prostor;

        $token = $poskytovatel === 'webdav'
            ? json_encode(['url' => self::WEBDAV, 'user' => 'adri', 'pass' => 'heslo-aplikace'])
            : 'pristupovy-token';

        return StorageConnection::create($navic + [
            'provider' => $poskytovatel,
            'gallery_space_id' => $prostor->id,
            'owner_user_id' => $vlastnik?->id ?? $prostor->owner_id,
            'account_email' => $poskytovatel.'@vzpominky.test',
            'encrypted_access_token' => Crypt::encryptString($token),
            'token_expires_at' => now()->addHour(),
            'connection_status' => 'healthy',
            'root_folder_id' => 'koren',
            'connected_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $navic */
    private function zaznam(array $navic = []): CloudCopyDeletion
    {
        return CloudCopyDeletion::create($navic + [
            'gallery_space_id' => $this->prostor->id,
            'provider' => 'dropbox',
            'remote_ref' => '/maki gallery/'.Str::random(6).'.jpg',
            'media_uuid' => (string) Str::uuid(),
        ]);
    }
}
