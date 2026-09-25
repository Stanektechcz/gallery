<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\StorageConnection;
use App\Models\User;
use App\Services\Storage\DriveStructureService;
use App\Services\Storage\GoogleOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Akce „Google Disk" sahají jen na připojení Google Disku.
 *
 * Dotazy hledaly `StorageConnection` jen podle vlastníka. Dropbox a OneDrive
 * ukládají řádky pro téhož vlastníka, takže „Odpojit Google Disk" odvolal
 * a označil Dropbox, obnova tokenu, test i založení struktury šly na cizího
 * poskytovatele a přesměrování ke Googlu podle jeho tokenu vynechalo souhlas.
 */
class GoogleOAuthPoskytovatelTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['role' => 'owner']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);
    }

    public function test_odpojeni_google_disku_nesahne_na_dropbox(): void
    {
        $dropbox = $this->pripojeni('dropbox');
        $google = $this->pripojeni('google_drive');

        $this->sluzba()->shouldReceive('revokeToken')->once()
            ->with(Mockery::on(fn (StorageConnection $c) => $c->id === $google->id))
            ->andReturnUsing(function (StorageConnection $c) {
                $c->update(['connection_status' => 'revoked', 'revoked_at' => now()]);

                return true;
            });

        $this->actingAs($this->adri)
            ->post('/settings/storage/google/disconnect')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('healthy', $dropbox->fresh()->connection_status);
        $this->assertNull($dropbox->fresh()->revoked_at);
    }

    public function test_bez_google_disku_se_neodpoji_nic(): void
    {
        $dropbox = $this->pripojeni('dropbox');
        $this->sluzba()->shouldNotReceive('revokeToken');

        $this->actingAs($this->adri)->post('/settings/storage/google/disconnect')->assertNotFound();

        $this->assertSame('healthy', $dropbox->fresh()->connection_status);
    }

    /**
     * Google odvolání nepotvrdil: připojení se přesto přestane používat, ale
     * hláška to řekne na rovinu — tvrdit „odpojeno" by nebyla pravda.
     */
    public function test_nepotvrzene_odvolani_rekne_pravdu(): void
    {
        $google = $this->pripojeni('google_drive');
        $this->sluzba()->shouldReceive('revokeToken')->once()->andReturnFalse();

        $this->actingAs($this->adri)
            ->post('/settings/storage/google/disconnect')
            ->assertRedirect()
            ->assertSessionMissing('success')
            ->assertSessionHas('error', fn (string $zprava) => str_contains($zprava, 'nepotvrdil') && str_contains($zprava, 'účtu Google'));

        $this->assertSame('revoked', $google->fresh()->connection_status);
        $this->assertNotNull($google->fresh()->revoked_at);
        $this->assertTrue(AuditLog::where('action', 'storage.google.disconnect')->exists());
    }

    public function test_obnova_tokenu_nesahne_na_dropbox(): void
    {
        $this->pripojeni('dropbox');
        $this->sluzba()->shouldNotReceive('refreshToken');

        $this->actingAs($this->adri)->post('/settings/storage/google/reconnect')->assertNotFound();
    }

    public function test_test_a_struktura_nesahnou_na_dropbox(): void
    {
        $this->pripojeni('dropbox');
        $this->sluzba();

        $struktura = Mockery::mock(DriveStructureService::class);
        $struktura->shouldNotReceive('runDiagnosticTest', 'initializeRootStructure');
        $this->app->bind(DriveStructureService::class, fn () => $struktura);

        $this->actingAs($this->adri)->post('/settings/storage/google/test')->assertNotFound();
        $this->actingAs($this->adri)->post('/settings/storage/google/init-structure')->assertNotFound();
    }

    /** Refresh token Dropboxu není důvod vynechat souhlas u Googlu. */
    public function test_presmerovani_se_rozhoduje_podle_google_tokenu(): void
    {
        $dropbox = $this->pripojeni('dropbox');
        $dropbox->setRefreshToken('dropbox-refresh');
        $dropbox->save();

        $souhlas = null;
        $this->sluzba()->shouldReceive('getAuthorizationUrl')->once()
            ->andReturnUsing(function (bool $vynutit) use (&$souhlas) {
                $souhlas = $vynutit;

                return 'https://accounts.google.com/o/oauth2/auth';
            });

        $this->actingAs($this->adri)->get('/oauth/google/redirect')->assertRedirect();

        $this->assertTrue($souhlas);
    }

    private function pripojeni(string $poskytovatel): StorageConnection
    {
        return StorageConnection::create([
            'provider' => $poskytovatel,
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'account_email' => $poskytovatel.'@vzpominky.test',
            'connection_status' => 'healthy',
            'root_folder_id' => 'slozka-'.$poskytovatel,
            'connected_at' => now(),
        ]);
    }

    private function sluzba(): Mockery\MockInterface
    {
        $sluzba = Mockery::mock(GoogleOAuthService::class);
        $this->app->instance(GoogleOAuthService::class, $sluzba);

        return $sluzba;
    }
}
