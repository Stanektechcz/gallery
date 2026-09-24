<?php

namespace Tests\Feature\Galerie;

use App\Models\CoupleDecision;
use App\Models\CoupleState;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Auth\PristupDoGalerie;
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

    /**
     * Rozhodnutí se zapisují přes `PATCH /api/state` do `couple_decisions`.
     *
     * Testy dřív psaly `decs` rovnou do stavu páru. Jenže `decs` je serverový
     * klíč (`VztahVeStavu::SERVEROVE`): skutečný zápis ho ze stavu vyhodí a
     * uloží do databáze. Příkaz četl stav, a tak nikdy nic neposlal — a testy
     * to zakrývaly, protože do stavu zapsaly, co by tam skutečná aplikace nikdy
     * nenechala.
     */
    public function test_upozorneni_jde_obema_partnerum(): void
    {
        $this->rozhodnuti([
            ['id' => 'r1', 'title' => 'Jedno auto místo dvou', 'status' => 'k revizi'],
            ['id' => 'r2', 'title' => 'Zůstat v nájmu', 'status' => 'platí'],
        ]);

        $push = $this->falesnyPush();
        $push->shouldReceive('sendToUser')->once()->with(Mockery::on(fn (User $u) => $u->is($this->adri)), Mockery::any())->andReturn(1);
        $push->shouldReceive('sendToUser')->once()->with(Mockery::on(fn (User $u) => $u->is($this->makinka)), Mockery::any())->andReturn(1);

        $this->artisan('galerie:notify')->expectsOutputToContain('Odesláno 2')->assertSuccessful();
    }

    /** Zpráva o jednom rozhodnutí zní jinak než o pěti — v telefonu je vidět jen ona. */
    public function test_zprava_mluvi_o_poctu_rozhodnuti(): void
    {
        $this->rozhodnuti([
            ['id' => 'r1', 'title' => 'Jedno auto místo dvou', 'status' => 'k revizi'],
            ['id' => 'r2', 'title' => 'Zůstat v nájmu', 'status' => 'k revizi'],
        ]);

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

    /**
     * Starý řádek `decs` ve stavu páru upozornění nespustí.
     *
     * Stav není zdroj rozhodnutí — to, co v něm zůstalo z dob před databází,
     * je stará kopie, kterou obrazovka už nečte.
     */
    public function test_rozhodnuti_ve_stavu_se_nepocitaji(): void
    {
        $this->stav(['decs' => [['id' => 'r1', 'title' => 'Stará kopie', 'status' => 'k revizi']]]);

        $push = $this->falesnyPush();
        $push->shouldReceive('sendToUser')->never();

        $this->artisan('galerie:notify')->expectsOutputToContain('Nic k odeslání')->assertSuccessful();
    }

    /**
     * Upozornění jde jen těm, kdo do galerie smějí.
     *
     * Příkaz obcházel všechny členy prostoru — i hosta, který má vidět jen
     * sdílené odkazy, i člověka, kterému vlastník přístup odebral.
     */
    public function test_upozorneni_nejde_hostovi_ani_uctu_bez_pristupu(): void
    {
        $host = User::factory()->create();
        $this->prostor->members()->syncWithoutDetaching([$host->id => ['role' => 'viewer']]);
        $this->rozhodnuti([['id' => 'r1', 'title' => 'Jedno auto místo dvou', 'status' => 'k revizi']]);
        $this->makinka->forceFill(['is_active' => false])->save();

        $push = $this->falesnyPush();
        $push->shouldReceive('sendToUser')->once()->with(Mockery::on(fn (User $u) => $u->is($this->adri)), Mockery::any())->andReturn(1);

        $this->artisan('galerie:notify')->expectsOutputToContain('Odesláno 1')->assertSuccessful();
    }

    /**
     * Host s vlastní galerií jinde je tady pořád host.
     *
     * Přístup do aplikace se posuzuje podle prvního prostoru účtu — a tam je
     * takový host vlastníkem. O rozhodnutích téhle dvojice se ale nic dozvědět
     * nemá.
     */
    public function test_host_s_vlastni_galerii_upozorneni_nedostane(): void
    {
        $host = User::factory()->create();
        $vlastni = GallerySpace::create(['name' => 'Hostova galerie', 'owner_id' => $host->id]);
        // Výchozí prostor účtu — ten `PristupDoGalerie` posuzuje jako první.
        $vlastni->forceFill(['is_default' => true])->save();
        $vlastni->members()->syncWithoutDetaching([$host->id => ['role' => 'owner']]);
        $this->prostor->members()->syncWithoutDetaching([$host->id => ['role' => 'viewer']]);
        $this->assertNull(app(PristupDoGalerie::class)->proc($host), 'Host má mít vlastní galerii jako první prostor.');
        $this->rozhodnuti([['id' => 'r1', 'title' => 'Jedno auto místo dvou', 'status' => 'k revizi']]);

        $push = $this->falesnyPush();
        $push->shouldReceive('sendToUser')->twice()
            ->with(Mockery::on(fn (User $u) => ! $u->is($host)), Mockery::any())
            ->andReturn(1);

        $this->artisan('galerie:notify')->expectsOutputToContain('Odesláno 2')->assertSuccessful();
    }

    /** Rozhodnutí jedné dvojice nezvoní v telefonu druhé. */
    public function test_upozorneni_jde_jen_dvojici_ktere_rozhodnuti_patri(): void
    {
        $cizi = User::factory()->create();
        GallerySpace::create(['name' => 'Cizí galerie', 'owner_id' => $cizi->id])
            ->members()->syncWithoutDetaching([$cizi->id => ['role' => 'owner']]);
        $this->rozhodnuti([['id' => 'r1', 'title' => 'Jedno auto místo dvou', 'status' => 'k revizi']]);

        $push = $this->falesnyPush();
        $push->shouldReceive('sendToUser')->twice()
            ->with(Mockery::on(fn (User $u) => ! $u->is($cizi)), Mockery::any())
            ->andReturn(1);

        $this->artisan('galerie:notify')->expectsOutputToContain('Odesláno 2')->assertSuccessful();
    }

    public function test_bez_klicu_vapid_se_nic_neposila(): void
    {
        $this->rozhodnuti([['id' => 'r1', 'title' => 'Jedno auto místo dvou', 'status' => 'k revizi']]);

        $push = Mockery::mock(WebPushService::class);
        $push->shouldReceive('configured')->andReturn(false);
        $push->shouldReceive('sendToUser')->never();
        $this->app->instance(WebPushService::class, $push);

        $this->artisan('galerie:notify')->expectsOutputToContain('VAPID')->assertSuccessful();
    }

    // ——— vypršení domluv ———

    public function test_domluva_po_lhute_vyprsi(): void
    {
        $this->stav(['expExtra' => [
            ['id' => 'e1', 'rule' => 'Nekomentovat nákupy pod tisíc', 'made' => '1. 1. 2020', 'days' => 0, 'eternal' => false],
        ]]);

        $this->artisan('galerie:expire')->expectsOutputToContain('Vypršelo 1')->assertSuccessful();

        $this->assertTrue($this->prectiStav()->data['expDead']['e1']);
    }

    /**
     * Domluvy ukázkové dvojice do stavu skutečné dvojice nepatří.
     *
     * Příkaz četl `EXPIRE` z `mechanismy.json` a zapisoval `expDead` pro cizí
     * id do stavu každé dvojice — ta přitom tahle pravidla nikdy neviděla.
     */
    public function test_pravidla_z_ukazky_se_nevyhodnocuji(): void
    {
        $this->mechanismy([
            ['id' => 'u1', 'rule' => 'Pravidlo z ukázky', 'made' => '1. 1. 2010', 'days' => 0, 'eternal' => false],
        ]);

        $this->stav(['joy' => ['něco']]);

        $this->artisan('galerie:expire')->expectsOutputToContain('Nic nevypršelo')->assertSuccessful();

        $this->assertArrayNotHasKey('expDead', $this->prectiStav()->data);
    }

    /** Domluva, které lhůta ještě neběží, zůstane. */
    public function test_bezici_domluva_zustane(): void
    {
        $this->stav(['expExtra' => [
            ['id' => 'e1', 'rule' => 'Vážné věci se neřeší po 21:00', 'made' => now()->format('j. n. Y'), 'days' => 365, 'eternal' => false],
        ]]);

        $this->artisan('galerie:expire')->expectsOutputToContain('Nic nevypršelo')->assertSuccessful();

        $this->assertArrayNotHasKey('expDead', $this->prectiStav()->data);
    }

    /** Obnovení posune lhůtu o rok — to je celý smysl toho tlačítka. */
    public function test_obnovena_domluva_nevyprsi(): void
    {
        $this->stav([
            'expExtra' => [['id' => 'e1', 'rule' => 'Kdo vaří, nemyje', 'made' => now()->subDays(400)->format('j. n. Y'), 'days' => 0, 'eternal' => false]],
            'expRen' => ['e1' => 1],
        ]);

        $this->artisan('galerie:expire')->expectsOutputToContain('Nic nevypršelo')->assertSuccessful();

        $this->assertArrayNotHasKey('expDead', $this->prectiStav()->data);
    }

    /** Trvalá domluva nevyprší nikdy — ani ta, kterou pár udělal trvalou dodatečně. */
    public function test_trvala_domluva_nevyprsi(): void
    {
        $this->stav([
            'expExtra' => [
                ['id' => 'e1', 'rule' => 'Nikdy na sebe nekřičet před rodinou', 'made' => '1. 1. 2010', 'days' => 0, 'eternal' => true],
                ['id' => 'e2', 'rule' => 'Kdo vaří, nemyje', 'made' => '1. 1. 2010', 'days' => 0, 'eternal' => false],
            ],
            'expEt' => ['e2' => true],
        ]);

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
        $this->stav(['expExtra' => [
            ['id' => 'e1', 'rule' => 'Kdo vaří, nemyje', 'made' => '1. 1. 2010', 'days' => 0, 'eternal' => false],
        ]]);

        $this->artisan('galerie:expire')->assertSuccessful();
        $rev = $this->prectiStav()->rev;

        $this->artisan('galerie:expire')->expectsOutputToContain('Nic nevypršelo')->assertSuccessful();

        $this->assertSame($rev, $this->prectiStav()->rev, 'Běh, který nic nezměnil, nesmí zvednout rev.');
    }

    // ——— pomocné ———

    /** Rozhodnutí skutečnou cestou — tak, jak je zapíše obrazovka. */
    private function rozhodnuti(array $radky): void
    {
        Sanctum::actingAs($this->adri);

        $this->patchJson('/api/state', ['data' => ['decs' => $radky]])->assertOk();

        $this->assertSame(count($radky), CoupleDecision::where('gallery_space_id', $this->prostor->id)->count(),
            'Rozhodnutí se měla zapsat do databáze.');
    }

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
