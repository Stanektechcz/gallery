<?php

namespace Tests\Feature\Galerie;

use App\Models\CoupleState;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Notifications\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Upozornění a plánované úlohy prototypu.
 *
 * Posílá se jediná věc — rozhodnutí čeká na revizi. Vypršení domluvy je
 * schválně tiché, a právě to tu má test, protože je to snadné omylem „opravit".
 */
class UpozorneniTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $makinka;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create();
        $this->makinka = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->makinka->id => ['role' => 'editor'],
        ]);
    }

    // ——— odběr ———

    public function test_odber_se_ulozi_a_zase_zrusi(): void
    {
        Sanctum::actingAs($this->adri);

        $odber = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => 'verejny-klic', 'auth' => 'tajemstvi'],
        ];

        $this->postJson('/api/push/subscribe', $odber)->assertCreated();

        $this->assertSame(1, DB::table('push_subscriptions')->where('user_id', $this->adri->id)->count());

        $this->deleteJson('/api/push/subscribe', ['endpoint' => $odber['endpoint']])->assertOk();

        $this->assertSame(0, DB::table('push_subscriptions')->count());
    }

    /** Tentýž prohlížeč nesmí založit dva odběry — dostal by všechno dvakrát. */
    public function test_opakovany_odber_tehoz_zarizeni_nezaloz_druhy(): void
    {
        Sanctum::actingAs($this->adri);

        $odber = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => 'verejny-klic', 'auth' => 'tajemstvi'],
        ];

        $this->postJson('/api/push/subscribe', $odber)->assertCreated();
        $this->postJson('/api/push/subscribe', $odber)->assertCreated();

        $this->assertSame(1, DB::table('push_subscriptions')->count());
    }

    public function test_odber_bez_prihlaseni_neprojde(): void
    {
        $this->postJson('/api/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => 'k', 'auth' => 'a'],
        ])->assertUnauthorized();
    }

    // ——— revize rozhodnutí ———

    public function test_upozorneni_jde_obema_partnerum(): void
    {
        $this->stav(['decs' => [
            ['id' => 'r1', 'title' => 'Jedno auto místo dvou', 'status' => 'k revizi'],
            ['id' => 'r2', 'title' => 'Zůstat v nájmu', 'status' => 'platí'],
        ]]);

        $push = $this->falesnyPush();
        $push->shouldReceive('sendToUser')->twice()->andReturn(1);

        $this->artisan('galerie:notify')->expectsOutputToContain('Odesláno 2')->assertSuccessful();
    }

    /** Zpráva o jednom rozhodnutí zní jinak než o pěti — v telefonu je vidět jen ona. */
    public function test_zprava_mluvi_o_poctu_rozhodnuti(): void
    {
        $this->stav(['decs' => [
            ['id' => 'r1', 'status' => 'k revizi'],
            ['id' => 'r2', 'status' => 'k revizi'],
        ]]);

        $push = $this->falesnyPush();
        $push->shouldReceive('sendToUser')
            ->twice()
            ->with(Mockery::any(), Mockery::on(
                fn (array $z) => str_contains($z['body'], '2 rozhodnutí') && $z['tag'] === 'galerie-revize',
            ))
            ->andReturn(1);

        $this->artisan('galerie:notify')->assertSuccessful();
    }

    /** Kdo se rozhodnutí nikdy nedotkl, nemá o čem dostávat upozornění. */
    public function test_bez_rozhodnuti_se_nic_neposila(): void
    {
        $this->stav([]);

        $push = $this->falesnyPush();
        $push->shouldReceive('sendToUser')->never();

        $this->artisan('galerie:notify')->expectsOutputToContain('Nic k odeslání')->assertSuccessful();
    }

    public function test_bez_klicu_vapid_se_nic_neposila(): void
    {
        $this->stav(['decs' => [['id' => 'r1', 'status' => 'k revizi']]]);

        $push = Mockery::mock(WebPushService::class);
        $push->shouldReceive('configured')->andReturn(false);
        $push->shouldReceive('sendToUser')->never();
        $this->app->instance(WebPushService::class, $push);

        $this->artisan('galerie:notify')->expectsOutputToContain('VAPID')->assertSuccessful();
    }

    // ——— vypršení domluv ———

    public function test_domluva_po_lhute_vyprsi(): void
    {
        $this->mechanismy([
            ['id' => 'e1', 'rule' => 'Nekomentovat nákupy pod tisíc', 'made' => '1. 1. 2020', 'days' => 0, 'eternal' => false],
        ]);

        $this->stav([]);

        $this->artisan('galerie:expire')->expectsOutputToContain('Vypršelo 1')->assertSuccessful();

        $this->assertTrue($this->prectiStav()->data['expDead']['e1']);
    }

    /** Domluva, které lhůta ještě neběží, zůstane. */
    public function test_bezici_domluva_zustane(): void
    {
        $this->mechanismy([
            ['id' => 'e1', 'rule' => 'Vážné věci se neřeší po 21:00', 'made' => now()->format('j. n. Y'), 'days' => 365, 'eternal' => false],
        ]);

        $this->stav([]);

        $this->artisan('galerie:expire')->expectsOutputToContain('Nic nevypršelo')->assertSuccessful();

        $this->assertArrayNotHasKey('expDead', $this->prectiStav()->data);
    }

    /** Obnovení posune lhůtu o rok — to je celý smysl toho tlačítka. */
    public function test_obnovena_domluva_nevyprsi(): void
    {
        $this->mechanismy([
            ['id' => 'e1', 'rule' => 'Kdo vaří, nemyje', 'made' => now()->subDays(400)->format('j. n. Y'), 'days' => 0, 'eternal' => false],
        ]);

        $this->stav(['expRen' => ['e1' => 1]]);

        $this->artisan('galerie:expire')->expectsOutputToContain('Nic nevypršelo')->assertSuccessful();

        $this->assertArrayNotHasKey('expDead', $this->prectiStav()->data);
    }

    /** Trvalá domluva nevyprší nikdy — ani ta, kterou pár udělal trvalou dodatečně. */
    public function test_trvala_domluva_nevyprsi(): void
    {
        $this->mechanismy([
            ['id' => 'e1', 'rule' => 'Nikdy na sebe nekřičet před rodinou', 'made' => '1. 1. 2010', 'days' => 0, 'eternal' => true],
            ['id' => 'e2', 'rule' => 'Kdo vaří, nemyje', 'made' => '1. 1. 2010', 'days' => 0, 'eternal' => false],
        ]);

        $this->stav(['expEt' => ['e2' => true]]);

        $this->artisan('galerie:expire')->expectsOutputToContain('Nic nevypršelo')->assertSuccessful();

        $this->assertArrayNotHasKey('expDead', $this->prectiStav()->data);
    }

    /**
     * Vypršení je tiché a druhý běh už nic nemění.
     *
     * Kdyby se `rev` zvedalo každou noc pro nic, otevřená aplikace by dostala
     * konflikt 409 při prvním uložení po půlnoci.
     */
    public function test_druhy_beh_nezvedne_rev(): void
    {
        $this->mechanismy([
            ['id' => 'e1', 'rule' => 'Kdo vaří, nemyje', 'made' => '1. 1. 2010', 'days' => 0, 'eternal' => false],
        ]);

        $this->stav([]);

        $this->artisan('galerie:expire')->assertSuccessful();
        $rev = $this->prectiStav()->rev;

        $this->artisan('galerie:expire')->expectsOutputToContain('Nic nevypršelo')->assertSuccessful();

        $this->assertSame($rev, $this->prectiStav()->rev, 'Běh, který nic nezměnil, nesmí zvednout rev.');
    }

    // ——— pomocné ———

    private function stav(array $data): CoupleState
    {
        $stav = CoupleState::forCouple($this->prostor->id);

        if ($data !== []) {
            $stav->applyPatch($data);
        }

        return $stav;
    }

    private function prectiStav(): CoupleState
    {
        return CoupleState::where('couple_id', $this->prostor->id)->sole();
    }

    private function mechanismy(array $pravidla): void
    {
        $cesta = storage_path('framework/testing/mechanismy-test.json');
        @mkdir(dirname($cesta), 0755, true);
        file_put_contents($cesta, json_encode(['EXPIRE' => $pravidla]));

        config(['galerie.mechanisms_path' => $cesta]);

        $this->beforeApplicationDestroyed(fn () => @unlink($cesta));
    }

    private function falesnyPush(): Mockery\MockInterface
    {
        $push = Mockery::mock(WebPushService::class);
        $push->shouldReceive('configured')->andReturn(true);
        $this->app->instance(WebPushService::class, $push);

        return $push;
    }
}
