<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Notifications\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Upozornění do telefonu končí tam, kde končí přihlášení.
 *
 * Odhlášení, „odhlásit ostatní" i odebraný přístup rušily sezení a klíče,
 * ale odběr upozornění (`push_subscriptions`) zůstal. Telefon, který už do
 * aplikace nesmí, tak dál dostával „Revize rozhodnutí" a připomínky druhého —
 * text upozornění se ukáže i na zamčené obrazovce. A odesílač nekontroloval,
 * jestli adresát do galerie vůbec ještě smí.
 */
class PushPoOdhlaseniTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->maki = User::factory()->create(['name' => 'Makinka']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);
    }

    /** Komu vlastník odebral přístup, tomu telefon přestane zvonit hned. */
    public function test_odebrani_pristupu_zrusi_odbery_upozorneni(): void
    {
        $this->odber($this->maki, 'maki-telefon');
        $this->odber($this->adri, 'adri-telefon');
        Sanctum::actingAs($this->adri);

        $this->postJson('/api/admin/users/'.$this->maki->id.'/access', ['active' => false])->assertOk();

        $this->assertSame(0, $this->odbery($this->maki), 'Účet bez přístupu nesmí dál dostávat upozornění.');
        $this->assertSame(1, $this->odbery($this->adri), 'Vlastníkův odběr se tím nemění.');
    }

    /**
     * „Odhlásit ostatní" v aplikaci zruší i odběry.
     *
     * Požadavek zatím neříká, který odběr patří tomuhle zařízení, takže se
     * ruší všechny — tohle zařízení si odběr při dalším otevření obnoví.
     * Partnerův odběr zůstává: odhlašuju sebe, ne jeho.
     */
    public function test_odhlaseni_ostatnich_zrusi_odbery(): void
    {
        $this->odber($this->adri, 'adri-telefon');
        $this->odber($this->adri, 'adri-tablet');
        $this->odber($this->maki, 'maki-telefon');
        Sanctum::actingAs($this->adri);

        $this->postJson('/api/zamek/odhlasit-ostatni')->assertOk();

        $this->assertSame(0, $this->odbery($this->adri), 'Ostatní zařízení nesmí dál dostávat upozornění.');
        $this->assertSame(1, $this->odbery($this->maki));
    }

    /** Když klient pošle adresu svého odběru, jeho vlastní upozornění zůstanou. */
    public function test_odhlaseni_ostatnich_necha_odber_tohoto_zarizeni(): void
    {
        $this->odber($this->adri, 'adri-telefon');
        $this->odber($this->adri, 'adri-tablet');
        Sanctum::actingAs($this->adri);

        $this->postJson('/api/zamek/odhlasit-ostatni', ['endpoint' => $this->adresa('adri-telefon')])->assertOk();

        $this->assertSame(
            [$this->adresa('adri-telefon')],
            DB::table('push_subscriptions')->where('user_id', $this->adri->id)->pluck('endpoint')->all(),
        );
    }

    /** Staré rozhraní má „odhlásit ostatní" taky — a stejnou díru. */
    public function test_odhlaseni_ostatnich_ve_starem_rozhrani_zrusi_odbery(): void
    {
        $this->odber($this->adri, 'adri-telefon');

        $this->actingAs($this->adri)
            ->postJson('/settings/security/sessions/revoke-others')
            ->assertOk();

        $this->assertSame(0, $this->odbery($this->adri));
    }

    /**
     * Odhlášení s adresou odběru zruší odběr tohoto zařízení.
     *
     * Po odhlášení ležel telefon dál v `push_subscriptions` a zvonil — i když
     * se na něm mezitím přihlásil někdo jiný. Cizí odběr se adresou zrušit
     * nedá, jen vlastní.
     */
    public function test_odhlaseni_s_adresou_zrusi_odber_zarizeni(): void
    {
        $this->odber($this->adri, 'adri-telefon');
        $this->odber($this->adri, 'adri-tablet');
        $this->odber($this->maki, 'maki-telefon');
        Sanctum::actingAs($this->adri);

        $this->postJson('/api/logout', ['endpoint' => $this->adresa('adri-telefon')])
            ->assertOk()->assertJsonPath('status', 'signed-out');
        $this->postJson('/api/logout', ['endpoint' => $this->adresa('maki-telefon')])->assertOk();

        $this->assertSame(
            [$this->adresa('adri-tablet')],
            DB::table('push_subscriptions')->where('user_id', $this->adri->id)->pluck('endpoint')->all(),
            'Zrušit se má jen odběr zařízení, které se odhlásilo.',
        );
        $this->assertSame(1, $this->odbery($this->maki), 'Cizí odběr se odhlášením zrušit nesmí.');
    }

    /** Odhlášení bez adresy funguje jako dřív — starý klient ji neposílá. */
    public function test_odhlaseni_bez_adresy_projde(): void
    {
        $this->odber($this->adri, 'adri-telefon');
        Sanctum::actingAs($this->adri);

        $this->postJson('/api/logout')->assertOk()->assertJsonPath('status', 'signed-out');

        $this->assertSame(1, $this->odbery($this->adri));
    }

    /**
     * Odesílač se nejdřív ptá, jestli adresát do galerie smí.
     *
     * Odběr mohl zůstat z doby před odebráním přístupu (nebo po změně role na
     * hosta) — upozornění o obsahu dvojice pak nesmí odejít.
     */
    public function test_push_neodejde_uctu_bez_pristupu_ani_hostovi(): void
    {
        config(['push.public_key' => 'x', 'push.private_key' => 'y', 'push.subject' => 'mailto:test@galerie.test']);
        $host = User::factory()->create(['name' => 'Host']);
        $this->prostor->members()->syncWithoutDetaching([$host->id => ['role' => 'viewer']]);
        $this->maki->forceFill(['is_active' => false])->save();

        $zarizeni = fn () => collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "push_subscriptions"'))->count();

        DB::enableQueryLog();
        $this->assertSame(0, app(WebPushService::class)->sendToUser($this->maki->fresh(), ['title' => 'Revize', 'body' => 'x']));
        $this->assertSame(0, app(WebPushService::class)->sendToUser($host->fresh(), ['title' => 'Revize', 'body' => 'x']));
        $this->assertSame(0, $zarizeni(), 'Účtu bez přístupu do galerie se zařízení ani nehledají.');

        DB::flushQueryLog();
        app(WebPushService::class)->sendToUser($this->adri->fresh(), ['title' => 'Revize', 'body' => 'x']);
        $this->assertSame(1, $zarizeni(), 'Člen dvojice upozornění dostává dál.');
    }

    // ——— pomocné ———

    private function adresa(string $zarizeni): string
    {
        return 'https://fcm.googleapis.com/fcm/send/'.$zarizeni;
    }

    private function odber(User $kdo, string $zarizeni): void
    {
        $adresa = $this->adresa($zarizeni);

        DB::table('push_subscriptions')->insert([
            'user_id' => $kdo->id,
            'endpoint' => $adresa,
            'endpoint_hash' => hash('sha256', $adresa),
            'keys' => json_encode(['p256dh' => 'verejny-klic', 'auth' => 'tajemstvi']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function odbery(User $kdo): int
    {
        return DB::table('push_subscriptions')->where('user_id', $kdo->id)->count();
    }
}
