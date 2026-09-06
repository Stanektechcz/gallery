<?php

namespace Tests\Feature\Galerie;

use App\Jobs\ObnovKvotuDisku;
use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Čísla postranního panelu.
 *
 * Panel měl „57 %", „114,5 GB ze 200 GB" a „3 originály čekají" napsané v designu.
 * Bylo to vidět na každé obrazovce, takže se ta tři čísla četla nejčastěji ze všeho —
 * a nikdy neplatila.
 */
class UlozisteTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        BillingPlan::create([
            'code' => 'duo', 'name' => 'Duo', 'price_monthly' => 0,
            'storage_limit_mb' => 25_000, 'is_default' => true,
        ]);

        Sanctum::actingAs($this->adri);
    }

    // ——— bez Google Disku ———

    public function test_bez_disku_se_pocita_podle_tarifu(): void
    {
        $this->media(2_000_000_000);

        $data = $this->getJson('/api/storage')->assertOk()->json('data');

        $this->assertSame('tarif', $data['zdroj']);
        $this->assertSame('2 GB z 25 GB', $data['label']);
        $this->assertSame('8 %', $data['pct']);
    }

    /**
     * Kapacita v panelu a v Tarifech musí souhlasit.
     *
     * `limit_bytes` násobí 1024×1024, takže tarif „25 GB" by v panelu vyšel na
     * 24,4 GB — dvě různá čísla pro totéž místo jsou horší než jedno nepřesné.
     */
    public function test_kapacita_souhlasi_se_zalozkou_tarify(): void
    {
        $panel = $this->getJson('/api/storage')->assertOk()->json('data.label');
        $tarify = collect($this->getJson('/api/admin')->assertOk()->json('data.plans'))->firstWhere('name', 'Duo');

        $this->assertStringContainsString($tarify['gb'].' GB', $panel);
    }

    /** Mlčet by znamenalo tvrdit, že je všechno zálohované. */
    public function test_bez_disku_panel_rekne_ze_druha_kopie_neni(): void
    {
        $data = $this->getJson('/api/storage')->assertOk()->json('data');

        $this->assertSame('Druhá kopie není nastavená', $data['sync']);
        $this->assertSame('ph-cloud-slash', $data['syncIcon']);
    }

    // ——— s Google Diskem ———

    public function test_s_diskem_se_pocita_podle_nej(): void
    {
        $this->media(2_000_000_000);
        $this->disk(zabrano: 60_000_000_000, celkem: 200_000_000_000);

        $data = $this->getJson('/api/storage')->assertOk()->json('data');

        $this->assertSame('disk', $data['zdroj']);
        $this->assertSame('60 GB z 200 GB na Google Disku', $data['label']);
        $this->assertSame('30 %', $data['pct']);
        $this->assertSame('disk@vzpominky.test', $data['ucet']);
    }

    /** Firemní účet bez limitu nemá co zaplnit — pruh by u něj nic neříkal. */
    public function test_ucet_bez_limitu_ukaze_jen_objem(): void
    {
        $this->disk(zabrano: 60_000_000_000, celkem: 0);

        $data = $this->getJson('/api/storage')->assertOk()->json('data');

        $this->assertSame('60 GB na Google Disku', $data['label']);
        $this->assertSame('0 %', $data['pct']);
        $this->assertNull($data['limitBytes']);
    }

    public function test_ceka_ukazuje_kolik_originalu_neni_v_cloudu(): void
    {
        $this->disk(zabrano: 1_000_000_000, celkem: 200_000_000_000);
        $this->media(1_000, 'local_only');
        $this->media(1_000, 'local_only');
        $this->media(1_000, 'mirrored');

        $data = $this->getJson('/api/storage')->assertOk()->json('data');

        $this->assertSame('2 originály čekají', $data['sync']);
        $this->assertSame('ph-cloud-arrow-up', $data['syncIcon']);
    }

    public function test_vse_zkopirovane_se_pozna(): void
    {
        $this->disk(zabrano: 1_000_000_000, celkem: 200_000_000_000);
        $this->media(1_000, 'mirrored');

        $data = $this->getJson('/api/storage')->assertOk()->json('data');

        $this->assertSame('Vše je ve dvou kopiích', $data['sync']);
        $this->assertSame('ok', $data['syncTon']);
    }

    /**
     * Účet bez zapsané kvóty ještě nic neříká.
     *
     * Do prvního obnovení se počítá podle tarifu — horší odhad, ale pravdivý.
     */
    public function test_disk_bez_kvoty_spadne_zpet_na_tarif(): void
    {
        $this->disk(zabrano: null, celkem: null);

        $this->assertSame('tarif', $this->getJson('/api/storage')->assertOk()->json('data.zdroj'));
    }

    /**
     * Stará kvóta se obnoví na pozadí.
     *
     * Zapsala se při připojení účtu a od té chvíle ji nikdo neaktualizoval, takže
     * panel by ukazoval stav toho dne, i kdyby se Disk mezitím zaplnil.
     */
    public function test_stara_kvota_se_zaradi_k_obnove(): void
    {
        $this->disk(zabrano: 1_000_000_000, celkem: 200_000_000_000, obnoveno: now()->subHours(3));

        $this->getJson('/api/storage')->assertOk();

        Queue::assertPushed(ObnovKvotuDisku::class);
    }

    public function test_cerstva_kvota_se_neobnovuje(): void
    {
        $this->disk(zabrano: 1_000_000_000, celkem: 200_000_000_000, obnoveno: now()->subMinutes(5));

        $this->getJson('/api/storage')->assertOk();

        Queue::assertNotPushed(ObnovKvotuDisku::class);
    }

    // ——— přístup ———

    public function test_bez_prihlaseni_neprojde(): void
    {
        $this->app['auth']->forgetGuards();
        auth()->guard('sanctum')->forgetUser();

        $this->getJson('/api/storage')->assertUnauthorized();
    }

    /** Panel vidí každý člen — na rozdíl od administrace. */
    public function test_panel_vidi_i_beznny_clen(): void
    {
        $host = User::factory()->create();
        $this->prostor->members()->syncWithoutDetaching([$host->id => ['role' => 'viewer']]);

        Sanctum::actingAs($host);

        $this->getJson('/api/storage')->assertOk();
        // Do administrace tentýž člověk nesmí — panel a administrace jsou dvě
        // různá oprávnění, proto i dvě různé adresy.
        $this->postJson('/api/admin/users', ['email' => 'kdokoli@vzpominky.test'])->assertForbidden();
    }

    // ——— pomocné ———

    private function disk(?int $zabrano, ?int $celkem, ?CarbonInterface $obnoveno = null): StorageConnection
    {
        return StorageConnection::create([
            'provider' => 'google_drive',
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'account_email' => 'disk@vzpominky.test',
            'connection_status' => 'healthy',
            'root_folder_id' => 'slozka-123',
            'quota_used' => $zabrano,
            'quota_total' => $celkem,
            'quota_refreshed_at' => $obnoveno ?? now(),
            'connected_at' => now(),
        ]);
    }

    private function media(int $bajtu, string $stav = 'local_only'): MediaItem
    {
        return MediaItem::create([
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'vylet.jpg',
            'safe_filename' => 'vylet.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => $bajtu,
            'status' => 'ready',
            'storage_status' => $stav,
        ]);
    }
}
