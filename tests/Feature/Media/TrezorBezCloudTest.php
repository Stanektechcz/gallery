<?php

namespace Tests\Feature\Media;

use App\Jobs\Media\EnqueueDriveMediaSyncJob;
use App\Jobs\Media\InitiateDriveResumableUploadJob;
use App\Jobs\Media\OdeberKopieVTrezoru;
use App\Jobs\Media\RemoveCloudCopy;
use App\Jobs\Media\UploadDriveChunkJob;
use App\Jobs\MirrorMediaToCloud;
use App\Models\BillingPlan;
use App\Models\CloudCopyDeletion;
use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Services\Storage\GoogleDriveStorageProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * „Fotky z trezoru nejdou na cloud" — rozhodnutí 27. 9. 2026.
 *
 * Položka v trezoru se nesmí zkopírovat na Google Disk, do Dropboxu, OneDrivu
 * ani WebDAV. Kopie, které už existují, zmizí, když položka do trezoru vejde
 * — ale jen po ověření originálu na serveru, protože kopie v cloudu může být
 * jediná, co zbylo. Vyjmutí z trezoru zrcadlení rozběhne znovu.
 */
class TrezorBezCloudTest extends TestCase
{
    use RefreshDatabase;
    use VytvariMedia;

    /** Obsah originálu — deset bajtů, jako `size_bytes` ve variantě. */
    private const OBSAH = '0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->zalozProstor();
    }

    // ——— strážci v úlohách ———

    public function test_zrcadleni_preskoci_polozku_v_trezoru(): void
    {
        Http::fake();
        $this->pripojeni('dropbox');
        $media = $this->sOriginalem(['is_hidden' => true]);

        app()->call([new MirrorMediaToCloud($media->id), 'handle']);

        Http::assertNothingSent();
        $this->assertFalse($media->variants()->where('type', 'cloud_copy')->exists());
    }

    /** Do trezoru odešla během nahrávání — kopie se nezapíše, ale zaznamená ke smazání. */
    public function test_polozka_schovana_behem_zrcadleni_se_zaznamena_ke_smazani(): void
    {
        Queue::fake();
        $spojeni = $this->pripojeni('dropbox');
        $media = $this->sOriginalem();

        Http::fake(['https://content.dropboxapi.com/2/files/upload' => function () use ($media) {
            DB::table('media_items')->where('id', $media->id)->update(['is_hidden' => true]);

            return Http::response(['path_lower' => '/maki gallery/x.jpg', 'size' => 10], 200);
        }]);

        app()->call([new MirrorMediaToCloud($media->id), 'handle']);

        $this->assertFalse($media->variants()->where('type', 'cloud_copy')->exists());
        $zaznam = CloudCopyDeletion::sole();
        $this->assertSame(CloudCopyDeletion::DUVOD_TREZOR, $zaznam->reason);
        $this->assertSame('dropbox', $zaznam->provider);
        $this->assertSame('/maki gallery/x.jpg', $zaznam->remote_ref);
        $this->assertSame($spojeni->id, $zaznam->storage_connection_id);
        Queue::assertPushed(RemoveCloudCopy::class, fn (RemoveCloudCopy $u) => $u->deletionId === $zaznam->id);
    }

    public function test_zahajeni_nahravani_na_disk_preskoci_trezor(): void
    {
        Queue::fake();
        $this->pripojeni('google_drive');
        $media = $this->sOriginalem(['is_hidden' => true, 'storage_status' => 'local_only']);
        $this->disk(fn (MockInterface $d) => $d->shouldReceive('createResumableSession')->andReturn('https://upload.test/s'));

        app()->call([new InitiateDriveResumableUploadJob($media->id), 'handle']);

        Queue::assertNotPushed(UploadDriveChunkJob::class);
        $this->assertSame('local_only', $media->fresh()->storage_status);
    }

    /** Další části se u položky v trezoru neposílají a stav nahrávání se uvolní. */
    public function test_cast_nahravani_se_u_trezoru_neposle(): void
    {
        Queue::fake();
        $this->pripojeni('google_drive');
        $media = $this->sOriginalem(['is_hidden' => true, 'storage_status' => 'uploading']);
        $this->disk(function (MockInterface $d) {
            $d->shouldReceive('queryResumableStatus')->once()->andReturn(['status' => 'incomplete', 'uploaded_bytes' => 0]);
            $d->shouldNotReceive('uploadChunk');
        });

        app()->call([new UploadDriveChunkJob($media->id, null, 'https://upload.test/s', 0, 10), 'handle']);

        $this->assertSame('local_only', $media->fresh()->storage_status);
        $this->assertSame(0, CloudCopyDeletion::count());
        Queue::assertNotPushed(UploadDriveChunkJob::class);
    }

    /**
     * Poslední část na Googlu doběhla, ale úloha spadla před dokončením.
     * Opakování u položky v trezoru se musí zeptat, jestli soubor na Disku
     * už je — jinak by tam zůstal a nikdo o něm nevěděl.
     */
    public function test_opakovani_u_trezoru_zaznamena_hotovy_soubor_na_disku(): void
    {
        Queue::fake();
        $this->pripojeni('google_drive');
        $media = $this->sOriginalem(['is_hidden' => true, 'storage_status' => 'uploading']);
        $this->disk(function (MockInterface $d) {
            $d->shouldReceive('queryResumableStatus')->once()->andReturn(['status' => 'complete', 'file' => ['id' => 'drive-hotovo']]);
            $d->shouldNotReceive('uploadChunk');
        });

        app()->call([new UploadDriveChunkJob($media->id, null, 'https://upload.test/s', 0, 10), 'handle']);

        $this->assertNull($media->fresh()->drive_file_id);
        $zaznam = CloudCopyDeletion::sole();
        $this->assertSame(['drive-hotovo', CloudCopyDeletion::DUVOD_TREZOR], [$zaznam->remote_ref, $zaznam->reason]);
        Queue::assertPushed(RemoveCloudCopy::class, 1);
    }

    /**
     * Soubor na Disku doběhl až v trezoru, ale originál na serveru nejde
     * ověřit — kopie se nemaže, jen se zapíše, aby o ní galerie věděla
     * (a doktor ji ohlásil).
     */
    public function test_dokonceni_v_trezoru_bez_overeneho_originalu_kopii_ponecha(): void
    {
        Queue::fake();
        $this->pripojeni('google_drive');
        $media = $this->sOriginalem(['storage_status' => 'uploading']);
        DB::table('media_variants')->where('media_item_id', $media->id)->update(['size_bytes' => 11]);
        $this->disk(function (MockInterface $d) use ($media) {
            $d->shouldReceive('queryResumableStatus')->andReturn(['status' => 'incomplete', 'uploaded_bytes' => 0]);
            $d->shouldReceive('uploadChunk')->once()->andReturnUsing(function () use ($media) {
                DB::table('media_items')->where('id', $media->id)->update(['is_hidden' => true]);

                return ['status' => 'complete', 'file' => ['id' => 'drive-nove']];
            });
        });

        app()->call([new UploadDriveChunkJob($media->id, null, 'https://upload.test/s', 0, 10), 'handle']);

        $this->assertSame('drive-nove', $media->fresh()->drive_file_id);
        $this->assertSame(0, CloudCopyDeletion::count());
        Queue::assertNotPushed(RemoveCloudCopy::class);
    }

    /**
     * Mazání z trezoru se před smazáním zeptá znovu na originál.
     *
     * Mezi zapsáním a smazáním mohou uběhnout hodiny, a Disk maže trvale.
     * Bez ověřeného originálu se nesmaže nic — záznam selže s důvodem.
     */
    public function test_mazani_z_trezoru_bez_overeneho_originalu_selze(): void
    {
        Http::fake();
        $dropbox = $this->pripojeni('dropbox');
        $media = $this->media(['is_hidden' => true, 'size_bytes' => 10]);
        $this->varianta($media, 'original', 'media/'.$media->uuid.'/original.jpg'); // soubor chybí
        $zaznam = $this->zaznam($media, $dropbox, '/maki gallery/x.jpg');

        app()->call([new RemoveCloudCopy($zaznam->id), 'handle']);

        $zaznam->refresh();
        $this->assertSame(CloudCopyDeletion::STATUS_FAILED, $zaznam->status);
        $this->assertStringContainsString('originál', (string) $zaznam->last_error);
        Http::assertNothingSent();
    }

    /** Položka mezitím vyšla z trezoru — stará kopie se nechá, nový zápis zrcadla by mohl prohrát závod. */
    public function test_mazani_z_trezoru_preskoci_polozku_mimo_trezor(): void
    {
        Http::fake();
        $dropbox = $this->pripojeni('dropbox');
        $media = $this->sOriginalem();
        $zaznam = $this->zaznam($media, $dropbox, '/maki gallery/stara.jpg');

        app()->call([new RemoveCloudCopy($zaznam->id), 'handle']);

        $this->assertSame(CloudCopyDeletion::STATUS_DONE, $zaznam->refresh()->status);
        Http::assertNothingSent();
    }

    /** Poslední část doběhla až po přesunu do trezoru — soubor na Disku jde ke smazání. */
    public function test_dokonceni_nahravani_polozky_v_trezoru_zaznamena_smazani(): void
    {
        Queue::fake();
        $disk = $this->pripojeni('google_drive');
        $media = $this->sOriginalem(['storage_status' => 'uploading']);
        $this->disk(function (MockInterface $d) use ($media) {
            $d->shouldReceive('queryResumableStatus')->andReturn(['status' => 'incomplete', 'uploaded_bytes' => 0]);
            $d->shouldReceive('uploadChunk')->once()->andReturnUsing(function () use ($media) {
                DB::table('media_items')->where('id', $media->id)->update(['is_hidden' => true]);

                return ['status' => 'complete', 'file' => ['id' => 'drive-nove']];
            });
        });

        app()->call([new UploadDriveChunkJob($media->id, null, 'https://upload.test/s', 0, 10), 'handle']);

        $media->refresh();
        $this->assertNull($media->drive_file_id);
        $this->assertSame('local_only', $media->storage_status);
        $zaznam = CloudCopyDeletion::sole();
        $this->assertSame(['google_drive', 'drive-nove', CloudCopyDeletion::DUVOD_TREZOR, $disk->id],
            [$zaznam->provider, $zaznam->remote_ref, $zaznam->reason, $zaznam->storage_connection_id]);
        Queue::assertPushed(RemoveCloudCopy::class, 1);
    }

    public function test_dorovnani_a_zkusit_znovu_vynechaji_trezor(): void
    {
        Queue::fake();
        $this->pripojeni('dropbox');
        $vTrezoru = $this->sOriginalem(['is_hidden' => true, 'storage_status' => 'local_only']);
        $bezna = $this->sOriginalem(['storage_status' => 'local_only']);

        $this->artisan('gallery:mirror-backlog')->assertSuccessful();
        app()->call([new EnqueueDriveMediaSyncJob($this->prostor->id), 'handle']);

        Queue::assertPushed(MirrorMediaToCloud::class, fn (MirrorMediaToCloud $u) => $u->mediaId === $bezna->id);
        Queue::assertNotPushed(MirrorMediaToCloud::class, fn (MirrorMediaToCloud $u) => $u->mediaId === $vTrezoru->id);
        $naDisk = Queue::pushed(InitiateDriveResumableUploadJob::class)->map(fn ($u) => (int) $u->uniqueId())->all();
        $this->assertSame([$bezna->id], $naDisk);
    }

    // ——— vložení do trezoru ———

    public function test_vlozeni_do_trezoru_odstrani_overene_kopie(): void
    {
        Queue::fake();
        $disk = $this->pripojeni('google_drive');
        $this->pripojeni('dropbox');
        $media = $this->sOriginalem(['drive_file_id' => 'drive-abc', 'drive_parent_folder_id' => 'slozka', 'storage_status' => 'synced']);
        $this->varianta($media, 'cloud_copy', '/maki gallery/x.jpg', 'dropbox');

        $this->actingAs($this->adri)
            ->postJson('/vault/media/'.$media->uuid.'/toggle')
            ->assertOk()
            ->assertJsonPath('is_hidden', true);

        Queue::assertPushedOn('drive', OdeberKopieVTrezoru::class, fn (OdeberKopieVTrezoru $u) => $u->mediaId === $media->id);

        app()->call([new OdeberKopieVTrezoru($media->id), 'handle']);

        $media->refresh();
        $this->assertNull($media->drive_file_id);
        $this->assertNull($media->drive_parent_folder_id);
        $this->assertSame('local_only', $media->storage_status);
        $this->assertFalse($media->variants()->where('type', 'cloud_copy')->exists());
        $this->assertTrue($media->variants()->where('type', 'original')->exists());

        $zaznamy = CloudCopyDeletion::orderBy('provider')->get();
        $this->assertSame(['dropbox', 'google_drive'], $zaznamy->pluck('provider')->all());
        $this->assertSame([CloudCopyDeletion::DUVOD_TREZOR, CloudCopyDeletion::DUVOD_TREZOR], $zaznamy->pluck('reason')->all());
        Queue::assertPushed(RemoveCloudCopy::class, 2);

        // Disk maže trvale, ne do koše; Dropbox jen do svých smazaných souborů.
        Http::fake(['https://api.dropboxapi.com/2/files/delete_v2' => Http::response(['metadata' => []], 200)]);
        $this->disk(function (MockInterface $d) {
            $d->shouldReceive('deletePermanently')->once()->with('drive-abc')->andReturn(true);
            $d->shouldNotReceive('trash');
        }, $disk);

        foreach ($zaznamy as $zaznam) {
            app()->call([new RemoveCloudCopy($zaznam->id), 'handle']);
        }

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.dropboxapi.com/2/files/delete_v2'
            && $r['path'] === '/maki gallery/x.jpg');
        $this->assertSame(['done', 'done'], CloudCopyDeletion::orderBy('provider')->pluck('status')->all());
    }

    /** Hromadné vložení ze stavu: jen položky, které se opravdu mění. */
    public function test_trezor_ze_stavu_odstrani_kopie(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->adri);
        $this->pripojeni('google_drive');
        $nova = $this->sOriginalem(['drive_file_id' => 'drive-nova', 'storage_status' => 'synced']);
        $uzBylaVTrezoru = $this->sOriginalem(['is_hidden' => true, 'storage_status' => 'local_only']);

        $this->patchJson('/api/state', ['data' => ['vaultAdded' => [$nova->uuid, $uzBylaVTrezoru->uuid]]])->assertOk();

        $this->assertTrue($nova->fresh()->is_hidden);
        Queue::assertPushed(OdeberKopieVTrezoru::class, 1);
        Queue::assertPushed(OdeberKopieVTrezoru::class, fn (OdeberKopieVTrezoru $u) => $u->mediaId === $nova->id);

        app()->call([new OdeberKopieVTrezoru($nova->id), 'handle']);

        $this->assertNull($nova->fresh()->drive_file_id);
        $this->assertSame('drive-nova', CloudCopyDeletion::sole()->remote_ref);
    }

    /**
     * Bez ověřeného originálu kopie zůstávají.
     *
     * Chybějící soubor, jiná velikost nebo jiný otisk: kopie v cloudu může být
     * jediná, co zbylo. Nic se nezaznamená ani nesmaže.
     */
    public function test_bez_overeneho_originalu_kopie_zustavaji(): void
    {
        Queue::fake();
        $this->pripojeni('google_drive');

        $chybi = $this->media(['is_hidden' => true, 'drive_file_id' => 'drive-1', 'storage_status' => 'synced', 'size_bytes' => 10]);
        $this->varianta($chybi, 'original', 'media/'.$chybi->uuid.'/original.jpg');

        $jinyOtisk = $this->sOriginalem(['is_hidden' => true, 'drive_file_id' => 'drive-2', 'storage_status' => 'synced']);
        $jinyOtisk->update(['sha256' => hash('sha256', 'jiny obsah')]);

        $jinaVelikost = $this->sOriginalem(['is_hidden' => true, 'drive_file_id' => 'drive-3', 'storage_status' => 'synced']);
        DB::table('media_variants')->where('media_item_id', $jinaVelikost->id)->update(['size_bytes' => 11]);

        $mimoServer = $this->media(['is_hidden' => true, 'drive_file_id' => 'drive-4', 'storage_status' => 'synced']);
        $this->varianta($mimoServer, 'original', 'id-na-disku', 'google_drive');

        foreach ([$chybi, $jinyOtisk, $jinaVelikost, $mimoServer] as $media) {
            app()->call([new OdeberKopieVTrezoru($media->id), 'handle']);
        }

        $this->assertSame(0, CloudCopyDeletion::count());
        $this->assertSame(['drive-1', 'drive-2', 'drive-3', 'drive-4'],
            DB::table('media_items')->orderBy('id')->pluck('drive_file_id')->all());
        Queue::assertNotPushed(RemoveCloudCopy::class);
    }

    // ——— vyjmutí z trezoru ———

    public function test_vyjmuti_z_trezoru_znovu_zrcadli(): void
    {
        Queue::fake();
        $this->pripojeni('google_drive');
        $media = $this->sOriginalem(['is_hidden' => true, 'storage_status' => 'local_only']);

        $this->actingAs($this->adri)
            ->withSession($this->odemcenyTrezor($this->adri))
            ->postJson('/vault/media/'.$media->uuid.'/toggle')
            ->assertOk()
            ->assertJsonPath('is_hidden', false);

        Queue::assertPushed(MirrorMediaToCloud::class, fn (MirrorMediaToCloud $u) => $u->mediaId === $media->id);
        Queue::assertPushed(InitiateDriveResumableUploadJob::class, fn ($u) => (int) $u->uniqueId() === $media->id);
        Queue::assertNotPushed(OdeberKopieVTrezoru::class);
    }

    public function test_vyjmuti_ze_stavu_znovu_zrcadli(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->adri);
        $this->pripojeni('dropbox');
        $media = $this->sOriginalem(['is_hidden' => true, 'storage_status' => 'local_only']);

        $this->withSession($this->odemcenyTrezor($this->adri))
            ->patchJson('/api/state', ['data' => ['vaultAdded' => [], 'vaultVyjmout' => [$media->uuid]]])
            ->assertOk();

        $this->assertFalse($media->fresh()->is_hidden);
        Queue::assertPushed(MirrorMediaToCloud::class, fn (MirrorMediaToCloud $u) => $u->mediaId === $media->id);
    }

    /**
     * Položka mezitím vyšla z trezoru a zrcadlení kopii založilo na stejném
     * místě — čekající smazání ji nesmí vzít s sebou.
     */
    public function test_mazani_z_trezoru_preskoci_kopii_ktera_se_znovu_pouziva(): void
    {
        Http::fake();
        $dropbox = $this->pripojeni('dropbox');
        $disk = $this->pripojeni('google_drive');
        $media = $this->sOriginalem(['drive_file_id' => 'drive-znovu']);
        $this->varianta($media, 'cloud_copy', '/maki gallery/x.jpg', 'dropbox');
        $this->disk(fn (MockInterface $d) => $d->shouldNotReceive('deletePermanently', 'trash'), $disk);

        $zaznamy = [
            $this->zaznam($media, $dropbox, '/maki gallery/x.jpg'),
            $this->zaznam($media, $disk, 'drive-znovu'),
        ];

        foreach ($zaznamy as $zaznam) {
            app()->call([new RemoveCloudCopy($zaznam->id), 'handle']);
            $this->assertSame(CloudCopyDeletion::STATUS_DONE, $zaznam->refresh()->status);
        }

        Http::assertNothingSent();
        $this->assertTrue($media->variants()->where('type', 'cloud_copy')->exists());
    }

    // ——— příkaz a počítadla ———

    public function test_prikaz_bez_prepinace_nic_nezmeni_a_nevypise_jmena(): void
    {
        Queue::fake();
        [$overena, $bezOriginalu, $nahrava] = $this->trezorSKopiemi();

        $this->artisan('gallery:trezor-z-cloudu')
            ->expectsOutputToContain('Lze odebrat (originál ověřen): 1')
            ->expectsOutputToContain('Neověřeno, kopie zůstává: 1')
            ->expectsOutputToContain('Nahrává se na Disk (zkuste později): 1')
            ->doesntExpectOutputToContain('tajne')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame('drive-ok', $overena->fresh()->drive_file_id);
        $this->assertSame(0, CloudCopyDeletion::count());
    }

    public function test_prikaz_s_provest_zaradi_jen_overene(): void
    {
        Queue::fake();
        [$overena] = $this->trezorSKopiemi();

        $this->artisan('gallery:trezor-z-cloudu --provest')
            ->expectsOutputToContain('Zařazeno k odebrání z cloudu: 1')
            ->doesntExpectOutputToContain('tajne')
            ->assertSuccessful();

        Queue::assertPushed(OdeberKopieVTrezoru::class, 1);
        Queue::assertPushed(OdeberKopieVTrezoru::class, fn (OdeberKopieVTrezoru $u) => $u->mediaId === $overena->id);
    }

    public function test_doktor_nepocita_trezor_do_zalohy_a_hlasi_trezor_s_kopii(): void
    {
        $this->pripojeni('google_drive');
        $this->sOriginalem(['drive_file_id' => 'drive-1', 'storage_status' => 'synced']);
        $this->sOriginalem(['is_hidden' => true, 'storage_status' => 'local_only']);
        $this->sOriginalem(['is_hidden' => true, 'drive_file_id' => 'drive-trezor', 'storage_status' => 'synced']);

        $this->artisan('gallery:doctor')
            ->expectsOutputToContain('Trezor: položek s kopií v cloudu 1 — přehled: php artisan gallery:trezor-z-cloudu')
            ->expectsOutputToContain('Drive backup: 1/1 originals (100 %)')
            ->run();
    }

    /**
     * Odkaz z položky už je pryč, ale smazání v cloudu selhalo nebo visí —
     * kopie tam dál leží. Doktor to musí vidět.
     */
    public function test_doktor_hlasi_nedokoncene_mazani_z_trezoru(): void
    {
        $dropbox = $this->pripojeni('dropbox');
        $media = $this->sOriginalem(['is_hidden' => true, 'storage_status' => 'local_only']);
        $this->zaznam($media, $dropbox, '/a.jpg')->forceFill(['status' => CloudCopyDeletion::STATUS_FAILED])->save();
        $this->zaznam($media, $dropbox, '/b.jpg')->forceFill(['updated_at' => now()->subDays(2)])->save();
        $this->zaznam($media, $dropbox, '/c.jpg'); // čerstvý čekající je v pořádku

        $this->artisan('gallery:doctor')
            ->expectsOutputToContain('Trezor: nedokončené mazání kopií v cloudu 2 — zkusit znovu: php artisan gallery:cloud-mazani --znovu')
            ->run();
    }

    public function test_doktor_bez_trezoru_v_cloudu_projde(): void
    {
        $this->sOriginalem(['is_hidden' => true, 'storage_status' => 'local_only']);

        $this->artisan('gallery:doctor')
            ->expectsOutputToContain('Trezor: žádná položka nemá kopii v cloudu')
            ->run();
    }

    /** Panel úložiště: trezor nečeká na přenos, ale věta o dvou kopiích ho přizná. */
    public function test_panel_uloziste_trezor_nepocita_jako_cekajici(): void
    {
        Queue::fake();
        BillingPlan::create(['code' => 'duo', 'name' => 'Duo', 'price_monthly' => 0, 'storage_limit_mb' => 25_000, 'is_default' => true]);
        $this->pripojeni('google_drive', ['quota_used' => 1_000, 'quota_total' => 200_000_000_000, 'quota_refreshed_at' => now()]);
        $this->media(['drive_file_id' => 'drive-1', 'storage_status' => 'synced']);
        $this->media(['is_hidden' => true, 'storage_status' => 'local_only']);
        Sanctum::actingAs($this->adri);

        $data = $this->getJson('/api/storage')->assertOk()->json('data');

        $this->assertSame('Vše kromě trezoru je ve dvou kopiích', $data['sync']);
        $this->assertSame('ok', $data['syncTon']);

        $dlazdice = $this->getJson('/api/data/system')->assertOk()->json('data.DISK');
        // Jen položka mimo trezor: uložená na Disku. Trezor nevisí jako „přenese se později".
        $this->assertSame(['1', '0', '0', '0'], array_column($dlazdice['states'], 'value'));
        $this->assertStringContainsString('leží tady na serveru', $dlazdice['intro']);
        $this->assertStringContainsString('trezoru se do cloudu nekopírují', $dlazdice['intro']);
    }

    // ——— pomocné ———

    /** Položka s ověřitelným originálem na disku `public` (deset bajtů, známý otisk). */
    private function sOriginalem(array $atributy = []): MediaItem
    {
        $media = $this->media($atributy + ['size_bytes' => strlen(self::OBSAH), 'sha256' => hash('sha256', self::OBSAH)]);
        $cesta = 'media/'.$media->uuid.'/original.jpg';
        Storage::disk('public')->put($cesta, self::OBSAH);
        $this->varianta($media, 'original', $cesta);

        return $media;
    }

    /**
     * Tři položky v trezoru: ověřená s kopií, bez originálu s kopií a právě
     * se nahrávající. Jména souborů se ve výpisu objevit nesmí.
     *
     * @return array{0: MediaItem, 1: MediaItem, 2: MediaItem}
     */
    private function trezorSKopiemi(): array
    {
        $this->pripojeni('google_drive');
        $this->pripojeni('dropbox');

        $overena = $this->sOriginalem(['is_hidden' => true, 'drive_file_id' => 'drive-ok', 'original_filename' => 'tajne-1.jpg']);
        $bezOriginalu = $this->media(['is_hidden' => true, 'original_filename' => 'tajne-2.jpg', 'trashed_at' => now()]);
        $this->varianta($bezOriginalu, 'cloud_copy', '/maki gallery/t.jpg', 'dropbox');
        $nahrava = $this->sOriginalem(['is_hidden' => true, 'storage_status' => 'uploading', 'original_filename' => 'tajne-3.jpg']);
        // Mimo trezor — příkaz se jí netýká.
        $this->sOriginalem(['drive_file_id' => 'drive-bezna', 'original_filename' => 'tajne-4.jpg']);

        return [$overena, $bezOriginalu, $nahrava];
    }

    /** @param  array<string, mixed>  $navic */
    private function pripojeni(string $poskytovatel, array $navic = []): StorageConnection
    {
        return StorageConnection::create($navic + [
            'provider' => $poskytovatel,
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->prostor->owner_id,
            'account_email' => $poskytovatel.'@vzpominky.test',
            'encrypted_access_token' => Crypt::encryptString('pristupovy-token'),
            'token_expires_at' => now()->addHour(),
            'connection_status' => 'healthy',
            'root_folder_id' => 'koren',
            'connected_at' => now(),
        ]);
    }

    /** Google Disk nahrazený v kontejneru — úlohy si ho berou přes `app()`. */
    private function disk(callable $nastav, ?StorageConnection $ocekavane = null): void
    {
        $disk = Mockery::mock(GoogleDriveStorageProvider::class);
        $nastav($disk);

        $this->app->bind(GoogleDriveStorageProvider::class, function ($app, array $parametry) use ($disk, $ocekavane) {
            if ($ocekavane) {
                $this->assertSame($ocekavane->id, $parametry['connection']->id);
            }

            return $disk;
        });
    }

    private function zaznam(MediaItem $media, StorageConnection $spojeni, string $odkaz): CloudCopyDeletion
    {
        return CloudCopyDeletion::create([
            'gallery_space_id' => $this->prostor->id,
            'storage_connection_id' => $spojeni->id,
            'provider' => $spojeni->provider,
            'remote_ref' => $odkaz,
            'media_uuid' => $media->uuid,
            'reason' => CloudCopyDeletion::DUVOD_TREZOR,
        ]);
    }
}
