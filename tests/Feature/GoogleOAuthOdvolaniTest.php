<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\StorageConnection;
use App\Models\User;
use App\Services\Storage\DriveStructureService;
use App\Services\Storage\GoogleOAuthService;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Odvolání u Googlu a chybové hlášky připojení Disku.
 *
 * `revokeToken()` vracel `true`, ať Google odpověděl cokoli: `Google\Client`
 * při odmítnutí nevyhodí výjimku, jen vrátí `false` — a to se zahazovalo.
 * Navíc se Googlu posílal celý uložený JSON tokenu místo tokenu samotného,
 * takže Google odvolání odmítal vždycky a aplikace hlásila „odpojeno".
 *
 * Hlášky po selhání připojení nebo založení struktury vkládaly text výjimky
 * (adresy, tokeny, SQL) rovnou do stránky.
 */
class GoogleOAuthOdvolaniTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{request: RequestInterface}> */
    private array $odeslane = [];

    private User $adri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['role' => 'owner']);
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);
    }

    public function test_odmitnuti_googlem_vrati_false_a_pripojeni_neoznaci(): void
    {
        $pripojeni = $this->pripojeni();

        $this->assertFalse($this->sluzba(new Response(400, [], '{"error":"invalid_token"}'))->revokeToken($pripojeni));
        $this->assertNull($pripojeni->fresh()->revoked_at);
    }

    /** Googlu jde refresh token — odvolá celý souhlas, ne JSON z databáze. */
    public function test_potvrzene_odvolani_posle_token_a_oznaci_pripojeni(): void
    {
        $pripojeni = $this->pripojeni();

        $this->assertTrue($this->sluzba(new Response(200))->revokeToken($pripojeni));

        $this->assertCount(1, $this->odeslane);
        parse_str((string) $this->odeslane[0]['request']->getBody(), $telo);
        $this->assertSame('refresh-tajny', $telo['token'] ?? null);
        $this->assertSame('revoked', $pripojeni->fresh()->connection_status);
        $this->assertNotNull($pripojeni->fresh()->revoked_at);
    }

    /** Bez refresh tokenu se odvolá přístupový token vytažený z JSONu. */
    public function test_bez_refresh_tokenu_se_odvola_pristupovy(): void
    {
        $pripojeni = $this->pripojeni(refresh: false);

        $this->assertTrue($this->sluzba(new Response(200))->revokeToken($pripojeni));

        parse_str((string) $this->odeslane[0]['request']->getBody(), $telo);
        $this->assertSame('pristup-tajny', $telo['token'] ?? null);
    }

    public function test_odpojeni_s_odmitnutim_rekne_pravdu(): void
    {
        $pripojeni = $this->pripojeni();
        $this->app->instance(GoogleOAuthService::class, $this->sluzba(new Response(400)));

        $this->actingAs($this->adri)
            ->post('/settings/storage/google/disconnect')
            ->assertRedirect()
            ->assertSessionMissing('success')
            ->assertSessionHas('error', fn (string $zprava) => str_contains($zprava, 'nepotvrdil'));

        $this->assertSame('revoked', $pripojeni->fresh()->connection_status);
    }

    public function test_selhani_pripojeni_neukaze_text_vyjimky(): void
    {
        $sluzba = Mockery::mock(GoogleOAuthService::class);
        $sluzba->shouldReceive('handleCallback')->once()->andThrow(new \RuntimeException('SQLSTATE secret token=ya29.x'));
        $this->app->instance(GoogleOAuthService::class, $sluzba);

        $this->actingAs($this->adri)
            ->withSession(['oauth.google.state' => 'spravny'])
            ->get('/oauth/google/callback?code=kod&state=spravny')
            ->assertRedirect(route('settings.storage.google'))
            ->assertSessionHas('error', fn (string $zprava) => $this->bezDetailu($zprava));
    }

    public function test_selhani_struktury_neukaze_text_vyjimky(): void
    {
        $this->pripojeni();
        $this->app->instance(GoogleOAuthService::class, Mockery::mock(GoogleOAuthService::class));

        $struktura = Mockery::mock(DriveStructureService::class);
        $struktura->shouldReceive('initializeRootStructure')->once()->andThrow(new \RuntimeException('SQLSTATE secret token=ya29.x'));
        $this->app->bind(DriveStructureService::class, fn () => $struktura);

        $this->actingAs($this->adri)
            ->post('/settings/storage/google/init-structure')
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $zprava) => $this->bezDetailu($zprava));
    }

    private function bezDetailu(string $zprava): bool
    {
        return $zprava !== '' && ! str_contains($zprava, 'SQLSTATE') && ! str_contains($zprava, 'secret') && ! str_contains($zprava, 'ya29');
    }

    /** Skutečná služba, jen HTTP klient Googlu odpovídá z připravené fronty. */
    private function sluzba(Response $odpoved): GoogleOAuthService
    {
        $zasobnik = HandlerStack::create(new MockHandler([$odpoved]));
        $zasobnik->push(Middleware::history($this->odeslane));

        $sluzba = new GoogleOAuthService;
        // Stejně jako výchozí klient Googlu: chybový stav nevyhazuje výjimku.
        (fn () => $this->client)->call($sluzba)->setHttpClient(new Guzzle(['handler' => $zasobnik, 'http_errors' => false]));

        return $sluzba;
    }

    private function pripojeni(bool $refresh = true): StorageConnection
    {
        $pripojeni = new StorageConnection([
            'provider' => 'google_drive',
            'owner_user_id' => $this->adri->id,
            'account_email' => 'adri@vzpominky.test',
            'connection_status' => 'healthy',
            'root_folder_id' => 'slozka-1',
            'connected_at' => now(),
        ]);
        $pripojeni->setAccessToken(json_encode(['access_token' => 'pristup-tajny', 'expires_in' => 3600]));

        if ($refresh) {
            $pripojeni->setRefreshToken('refresh-tajny');
        }

        $pripojeni->save();

        return $pripojeni;
    }
}
