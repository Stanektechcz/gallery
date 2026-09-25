<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\StorageConnection;
use App\Models\User;
use App\Services\Storage\GoogleOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Připojení Google Disku nejde podstrčit cizím kódem.
 *
 * Přesměrování ke Googlu neneslo `state` a zpětné volání žádný nekontrolovalo.
 * Útočník si tedy mohl u Googlu projít souhlasem se svým vlastním Diskem,
 * nechat si nevyužitý `code` a podstrčit přihlášené oběti odkaz
 * `/oauth/google/callback?code=…`. Server by ten kód vyměnil, uložil **jeho**
 * Disk k účtu oběti a hned zařadil synchronizaci všech jejích galerií —
 * celá knihovna fotek by odtekla na cizí Disk. Discord a Dropbox v téže
 * aplikaci `state` mají; Google jako jediný ne, a právě ten přenáší fotky.
 */
class GoogleOAuthStateTest extends TestCase
{
    use RefreshDatabase;

    private const KLIC = 'oauth.google.state';

    private User $adri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['role' => 'owner']);
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);
    }

    public function test_zpetne_volani_bez_state_nic_nepripoji(): void
    {
        $this->sluzba()->shouldNotReceive('handleCallback');

        $this->actingAs($this->adri)
            ->get('/oauth/google/callback?code=kod-utocnika')
            ->assertRedirect(route('settings.storage.google'))
            ->assertSessionHas('error');

        $this->assertSame(0, StorageConnection::count());
    }

    public function test_zpetne_volani_s_cizim_state_nic_nepripoji(): void
    {
        $this->sluzba()->shouldNotReceive('handleCallback');

        $this->actingAs($this->adri)
            ->withSession([self::KLIC => 'moje-cekajici-prihlaseni'])
            ->get('/oauth/google/callback?code=kod-utocnika&state=jiny')
            ->assertRedirect(route('settings.storage.google'))
            ->assertSessionHas('error');

        $this->assertSame(0, StorageConnection::count());
    }

    /** Stav platí jednou — druhé použití téže odpovědi neprojde. */
    public function test_state_se_po_pouziti_zahodi(): void
    {
        $this->sluzba()->shouldReceive('handleCallback')->once()->andThrow(new \RuntimeException('Google nedostupný'));

        $this->actingAs($this->adri)->withSession([self::KLIC => 'spravny']);
        $this->get('/oauth/google/callback?code=kod&state=spravny')->assertRedirect(route('settings.storage.google'));
        $this->get('/oauth/google/callback?code=kod&state=spravny')->assertRedirect(route('settings.storage.google'));
    }

    /**
     * `?error=` se čte až po ověření stavu a text od Googlu se nezobrazuje.
     *
     * `error_description` jde z adresy, kterou může poslat kdokoli — jako
     * hláška na naší doméně by to byl hotový podvodný text („zavolejte na…").
     */
    public function test_chyba_od_googlu_neukaze_text_z_adresy(): void
    {
        $this->sluzba()->shouldNotReceive('handleCallback');

        $this->actingAs($this->adri)
            ->withSession([self::KLIC => 'spravny'])
            ->get('/oauth/google/callback?error=access_denied&state=spravny&error_description='.rawurlencode('Účet zablokován, volejte 777 123 456'))
            ->assertRedirect(route('settings.storage.google'))
            ->assertSessionHas('error', fn (string $zprava) => ! str_contains($zprava, '777') && str_contains($zprava, 'zrušena'));
    }

    public function test_chyba_bez_platneho_stavu_se_hlasi_jako_cizi_navrat(): void
    {
        $this->sluzba()->shouldNotReceive('handleCallback');

        $this->actingAs($this->adri)
            ->withSession([self::KLIC => 'moje-cekajici-prihlaseni'])
            ->get('/oauth/google/callback?error=access_denied&error_description=cokoli')
            ->assertRedirect(route('settings.storage.google'))
            ->assertSessionHas('error', fn (string $zprava) => str_contains($zprava, 'nepatří k tomuhle přihlášení'));

        $this->assertNull(session(self::KLIC), 'Stav se spotřebuje i u chybového návratu.');
    }

    public function test_presmerovani_posle_googlu_stav_ze_sezeni(): void
    {
        $predany = null;
        $this->sluzba()->shouldReceive('getAuthorizationUrl')->once()
            ->andReturnUsing(function (bool $souhlas, ?string $state) use (&$predany) {
                $predany = $state;

                return 'https://accounts.google.com/o/oauth2/auth?state='.$state;
            });

        $this->actingAs($this->adri)->get('/oauth/google/redirect')->assertRedirect();

        $this->assertIsString($predany);
        $this->assertGreaterThanOrEqual(32, strlen($predany), 'Stav musí být náhodný a dost dlouhý.');
        $this->assertSame($predany, session(self::KLIC));
    }

    private function sluzba(): Mockery\MockInterface
    {
        $sluzba = Mockery::mock(GoogleOAuthService::class);
        $this->app->instance(GoogleOAuthService::class, $sluzba);

        return $sluzba;
    }
}
