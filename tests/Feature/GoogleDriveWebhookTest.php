<?php

namespace Tests\Feature;

use App\Http\Middleware\PreventRequestForgery;
use App\Jobs\Drive\ProcessDriveWebhookJob;
use App\Models\DriveChangeChannel;
use App\Models\StorageConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Upozornění z Google Disku: projdou jen se shodným tokenem kanálu.
 *
 * Dvě chyby. Cesta byla ve skupině `web` bez výjimky z CSRF, takže skutečný
 * POST od Googlu dostal 419 — v testech to vidět nebylo, protože se ochrana
 * při testech sama vypíná. A kontrola tokenu se přeskočila, když chyběla
 * hlavička nebo uložený token: kdo znal ID kanálu, mohl spouštět zpracování
 * změn bez tokenu.
 */
class GoogleDriveWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const CESTA = '/webhooks/google-drive';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * Přes middleware přímo — skrz HTTP by test prošel i s chybou
     * (`runningUnitTests` ochranu vypíná).
     */
    public function test_cesta_je_vyjmuta_z_csrf(): void
    {
        $middleware = new PreventRequestForgery($this->app, $this->app['encrypter']);
        $metoda = new \ReflectionMethod($middleware, 'inExceptArray');
        $metoda->setAccessible(true);

        $this->assertTrue((bool) $metoda->invoke($middleware, Request::create(self::CESTA, 'POST')));
    }

    public function test_shodny_token_projde(): void
    {
        $this->kanal('tajny-token');

        $this->post(self::CESTA, [], $this->hlavicky('tajny-token'))->assertOk();

        Queue::assertPushed(ProcessDriveWebhookJob::class);
    }

    public function test_chybejici_hlavicka_s_tokenem_neprojde(): void
    {
        $this->kanal('tajny-token');

        $this->post(self::CESTA, [], $this->hlavicky(null))->assertForbidden();

        Queue::assertNotPushed(ProcessDriveWebhookJob::class);
    }

    /** Kanál bez uloženého tokenu nemá s čím porovnat — nepouští nikoho. */
    public function test_kanal_bez_tokenu_neprojde(): void
    {
        $this->kanal(null);

        $this->post(self::CESTA, [], $this->hlavicky('cokoli'))->assertForbidden();

        Queue::assertNotPushed(ProcessDriveWebhookJob::class);
    }

    public function test_jiny_token_neprojde(): void
    {
        $this->kanal('tajny-token');

        $this->post(self::CESTA, [], $this->hlavicky('jiny-token'))->assertForbidden();

        Queue::assertNotPushed(ProcessDriveWebhookJob::class);
    }

    private function kanal(?string $token): DriveChangeChannel
    {
        $vlastnik = User::factory()->create();
        $pripojeni = StorageConnection::create([
            'provider' => 'google_drive',
            'owner_user_id' => $vlastnik->id,
            'connection_status' => 'healthy',
        ]);

        return DriveChangeChannel::create([
            'storage_connection_id' => $pripojeni->id,
            'channel_id' => 'kanal-1',
            'resource_id' => 'zdroj-1',
            'channel_token' => $token,
            'is_active' => true,
        ]);
    }

    /** @return array<string, string> */
    private function hlavicky(?string $token): array
    {
        return array_filter([
            'X-Goog-Channel-Id' => 'kanal-1',
            'X-Goog-Channel-Token' => $token,
            'X-Goog-Resource-State' => 'change',
            'X-Goog-Resource-Id' => 'zdroj-1',
            'X-Goog-Message-Number' => '2',
        ], fn ($hodnota) => $hodnota !== null);
    }
}
