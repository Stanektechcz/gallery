<?php

namespace Tests\Feature\Galerie;

use App\Jobs\SpustPlanovanouUlohu;
use App\Models\AuditLog;
use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\PersonalAccessToken;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Administrace prostoru.
 *
 * Prototyp má administraci celou na klientovi — tlačítka tam mění jen stav
 * v prohlížeči. Testy proto hlídají hlavně to, co se z obrazovky nepozná:
 * že odebraný přístup platí opravdu, že vlastník zůstane právě jeden a že se
 * placený tarif nedá získat kliknutím.
 */
class AdministraceTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $makinka;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->makinka = User::factory()->create(['name' => 'Makinka']);

        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->makinka->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    // ——— přehled ———

    public function test_prehled_ma_vsechny_klice_prototypu(): void
    {
        $odpoved = $this->getJson('/api/admin')->assertOk();

        foreach (['roles', 'roleNote', 'users', 'jobs', 'incidents', 'keys', 'plans', 'plan', 'usedGb', 'risks', 'log'] as $klic) {
            $this->assertArrayHasKey($klic, $odpoved->json('data'), "V přehledu chybí klíč {$klic}, který prototyp kreslí.");
        }
    }

    public function test_ucty_ukazuji_skutecne_cleny(): void
    {
        $odpoved = $this->getJson('/api/admin')->assertOk();

        $ucty = collect($odpoved->json('data.users'));

        $this->assertCount(2, $ucty);
        $this->assertSame('vlastník', $ucty->firstWhere('name', 'Adrian')['role']);
        $this->assertSame('správce', $ucty->firstWhere('name', 'Makinka')['role']);
    }

    /** Úlohy se berou z plánu, ne z druhé tabulky — jinak by přidaná chyběla. */
    public function test_ulohy_pochazeji_z_planu(): void
    {
        $ulohy = collect($this->getJson('/api/admin')->assertOk()->json('data.jobs'));

        $this->assertTrue($ulohy->contains('id', 'trash-purge'));
        $this->assertSame('denně 4:20', $ulohy->firstWhere('id', 'trash-purge')['cron']);
        $this->assertSame('nikdy', $ulohy->firstWhere('id', 'trash-purge')['last']);
    }

    /**
     * Do prohlížeče nesmí celý příkaz.
     *
     * Nese cestu k PHP na serveru a administraci pro dva lidi to k ničemu není —
     * je to jen informace navíc pro toho, kdo se k obrazovce dostane.
     */
    public function test_ulohy_neposilaji_prikaz_serveru(): void
    {
        $ulohy = $this->getJson('/api/admin')->assertOk()->json('data.jobs');

        $this->assertArrayNotHasKey('command', $ulohy[0]);
        $this->assertStringNotContainsString('artisan', json_encode($ulohy));
    }

    public function test_posledni_beh_pochazi_ze_zaznamu(): void
    {
        ScheduledTaskRun::create([
            'task' => 'trash-purge',
            'started_at' => now()->setTime(4, 20),
            'finished_at' => now()->setTime(4, 20, 42),
            'duration_ms' => 42_000,
            'state' => ScheduledTaskRun::HOTOVO,
            'exit_code' => 0,
        ]);

        $uloha = collect($this->getJson('/api/admin')->json('data.jobs'))->firstWhere('id', 'trash-purge');

        $this->assertSame('dnes 4:20', $uloha['last']);
        $this->assertSame('42 s', $uloha['dur']);
        $this->assertSame('hotovo', $uloha['state']);
    }

    public function test_incidenty_ukazuji_chybne_behy(): void
    {
        ScheduledTaskRun::create([
            'task' => 'mirror-backlog',
            'started_at' => now()->subDays(2),
            'finished_at' => now()->subDays(2),
            'state' => ScheduledTaskRun::CHYBA,
            'exit_code' => 1,
            'output' => 'externí disk nebyl připojen',
        ]);

        $incidenty = $this->getJson('/api/admin')->json('data.incidents');

        $this->assertCount(1, $incidenty);
        $this->assertStringContainsString('Kopie originálů', $incidenty[0]['what']);
        $this->assertSame('externí disk nebyl připojen', $incidenty[0]['fix']);
    }

    // ——— účty ———

    public function test_pozvanka_zalozi_ucet_i_clenstvi(): void
    {
        $odpoved = $this->postJson('/api/admin/users', ['email' => 'klara@vzpominky.test'])->assertOk();

        $klara = User::where('email', 'klara@vzpominky.test')->sole();

        $this->assertTrue($this->prostor->members()->where('users.id', $klara->id)->exists(),
            'Pozvaný účet musí do prostoru patřit hned — jinak se po přijetí přihlásí do prázdna.');
        $this->assertNotEmpty($odpoved->json('invite_url'));

        $ucet = collect($odpoved->json('data.users'))->firstWhere('mail', 'klara@vzpominky.test');
        $this->assertSame('pozvaná', $ucet['state']);
        $this->assertSame('host', $ucet['role']);
    }

    public function test_role_se_da_zmenit(): void
    {
        $this->patchJson('/api/admin/users/'.$this->makinka->id.'/role', ['role' => 'host'])->assertOk();

        $ucet = collect($this->getJson('/api/admin')->json('data.users'))->firstWhere('name', 'Makinka');
        $this->assertSame('host', $ucet['role']);
    }

    /** Vlastník musí být právě jeden — jeho role se necykluje. */
    public function test_vlastnikovi_nejde_zmenit_role(): void
    {
        $this->patchJson('/api/admin/users/'.$this->adri->id.'/role', ['role' => 'host'])
            ->assertStatus(422);

        $this->assertSame($this->adri->id, $this->prostor->fresh()->owner_id);
    }

    public function test_vlastnikovi_nejde_odebrat_pristup(): void
    {
        $this->postJson('/api/admin/users/'.$this->adri->id.'/access', ['active' => false])
            ->assertStatus(422);

        $this->assertTrue($this->adri->fresh()->is_active);
    }

    /**
     * Odebraný přístup platí hned.
     *
     * Bez zrušení tokenů by se telefon s uloženým přihlášením dostal dovnitř dál —
     * a to je přesně ta věc, kvůli které se tlačítko mačká.
     */
    public function test_odebrani_pristupu_zneplatni_i_ulozene_prihlaseni(): void
    {
        $token = $this->makinka->createToken('telefon')->plainTextToken;

        $this->postJson('/api/admin/users/'.$this->makinka->id.'/access', ['active' => false])->assertOk();

        $this->assertSame(0, $this->makinka->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/state')->assertUnauthorized();
    }

    public function test_predani_vlastnictvi_udela_z_predchoziho_spravce(): void
    {
        $this->postJson('/api/admin/users/'.$this->makinka->id.'/transfer')->assertOk();

        $this->assertSame($this->makinka->id, $this->prostor->fresh()->owner_id);

        $ucty = collect($this->getJson('/api/admin')->json('data.users'));
        $this->assertSame('vlastník', $ucty->firstWhere('name', 'Makinka')['role']);
        $this->assertSame('správce', $ucty->firstWhere('name', 'Adrian')['role']);
    }

    /** Vlastník je právě jeden i po předání. */
    public function test_po_predani_zustane_jeden_vlastnik(): void
    {
        $this->postJson('/api/admin/users/'.$this->makinka->id.'/transfer')->assertOk();

        $ucty = collect($this->getJson('/api/admin')->json('data.users'));

        $this->assertCount(1, $ucty->where('role', 'vlastník'));
    }

    public function test_spravce_nesmi_do_uctu(): void
    {
        Sanctum::actingAs($this->makinka);

        $this->postJson('/api/admin/users', ['email' => 'kdokoli@vzpominky.test'])->assertForbidden();
        $this->patchJson('/api/admin/users/'.$this->adri->id.'/role', ['role' => 'host'])->assertForbidden();
    }

    // ——— klíče ———

    public function test_klic_se_ukaze_cely_jen_jednou(): void
    {
        $odpoved = $this->postJson('/api/admin/keys', ['name' => 'Mobilní aplikace'])->assertOk();

        $this->assertNotEmpty($odpoved->json('token'));

        $klic = collect($odpoved->json('data.keys'))->firstWhere('name', 'Mobilní aplikace');
        $this->assertSame(substr($odpoved->json('token'), -4), $klic['suffix']);

        // Podruhé už jen čtyři znaky.
        $this->assertNull($this->getJson('/api/admin')->json('token'));
    }

    /**
     * Klíč vytvořený z obrazovky je použitelný.
     *
     * Prototyp si celý klíč skládal na klientovi (`gal_ + id + suffix`), takže
     * tlačítko „Kopírovat" podávalo řetězec, který nikde neplatil. Teď ho vydává
     * server a musí jím jít otevřít API — jinak je to pořád jen ozdoba.
     */
    public function test_klic_z_obrazovky_opravdu_otevre_api(): void
    {
        $token = $this->postJson('/api/admin/keys', ['name' => 'Mobilní aplikace'])
            ->assertOk()
            ->json('token');

        $this->assertNotEmpty($token);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/state')->assertOk();
    }

    /** Klíč jen pro čtení nesmí umět zapisovat. */
    public function test_klic_jen_pro_cteni_ma_omezeny_rozsah(): void
    {
        $odpoved = $this->postJson('/api/admin/keys', ['name' => 'Odečty', 'scope' => 'jen čtení'])->assertOk();

        $this->assertSame('jen čtení', collect($odpoved->json('data.keys'))->firstWhere('name', 'Odečty')['scope']);
        $this->assertSame(['read'], PersonalAccessToken::sole()->abilities);
    }

    public function test_zruseny_klic_zustane_v_seznamu_a_prestane_platit(): void
    {
        $token = $this->postJson('/api/admin/keys', ['name' => 'Mobilní aplikace'])->json('token');
        $id = PersonalAccessToken::sole()->id;

        $odpoved = $this->deleteJson('/api/admin/keys/'.$id)->assertOk();

        $klic = collect($odpoved->json('data.keys'))->firstWhere('id', (string) $id);
        $this->assertSame('zrušený', $klic['state'], 'Zrušený klíč má v seznamu zůstat, ne zmizet.');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/state')->assertUnauthorized();
    }

    public function test_novy_klic_zneplatni_stary(): void
    {
        $stary = $this->postJson('/api/admin/keys', ['name' => 'Mobilní aplikace'])->json('token');
        $id = PersonalAccessToken::sole()->id;

        $novy = $this->postJson('/api/admin/keys/'.$id.'/regenerate')->assertOk()->json('token');

        $this->assertNotSame($stary, $novy);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$stary)->getJson('/api/state')->assertUnauthorized();
    }

    // ——— úlohy ———

    public function test_rucni_spusteni_jde_do_fronty(): void
    {
        $this->postJson('/api/admin/jobs/trash-purge/run')->assertOk();

        Queue::assertPushed(SpustPlanovanouUlohu::class);
    }

    public function test_neznama_uloha_neprojde(): void
    {
        $this->postJson('/api/admin/jobs/vymyslena-uloha/run')->assertNotFound();
    }

    public function test_pozastavena_uloha_se_pozna_v_prehledu(): void
    {
        $this->postJson('/api/admin/jobs/trash-purge/pause')->assertOk();

        $uloha = collect($this->getJson('/api/admin')->json('data.jobs'))->firstWhere('id', 'trash-purge');

        $this->assertSame('pozastavená', $uloha['state']);
        $this->assertSame('pozastaveno', $uloha['cron']);

        // A zpátky.
        $this->postJson('/api/admin/jobs/trash-purge/pause')->assertOk();
        $uloha = collect($this->getJson('/api/admin')->json('data.jobs'))->firstWhere('id', 'trash-purge');
        $this->assertSame('denně 4:20', $uloha['cron']);
    }

    /** Bez tepu by kontrola hlásila, že plánovač neběží, i když stojí jedna úloha. */
    public function test_tep_planovace_nejde_pozastavit(): void
    {
        $this->postJson('/api/admin/jobs/scheduler-heartbeat/pause')->assertStatus(422);
    }

    // ——— zdraví systému ———

    /**
     * Pruhy zdraví jsou ze serveru, ne z ukázkových dat.
     *
     * Prototyp tu měl „dostupnost 99,98 %, disk 57 %" napsané v kódu, takže
     * obrazovka hlásila zdravý systém i tehdy, když zrovna nic neběželo.
     */
    public function test_zdravi_ukazuje_skutecna_cisla(): void
    {
        $zdravi = $this->getJson('/api/admin')->assertOk()->json('data.health');

        $this->assertNotEmpty($zdravi['bars']);
        $this->assertSame('Místo na disku serveru', $zdravi['bars'][0][0]);
        $this->assertStringContainsString('plánovač', $zdravi['summary']);
        $this->assertStringNotContainsString('99,98', json_encode($zdravi));
    }

    public function test_kontrola_systemu_zaradi_doktora(): void
    {
        $this->postJson('/api/admin/health/check')->assertOk();

        Queue::assertPushed(SpustPlanovanouUlohu::class);
    }

    /** Gigabajty pro pruhy rizika počítá server — prototyp je měl napevno. */
    public function test_prehled_nese_gigabajty_pro_rizika(): void
    {
        $data = $this->getJson('/api/admin')->assertOk()->json('data');

        foreach (['trashGb', 'singleGb', 'growthGb'] as $klic) {
            $this->assertArrayHasKey($klic, $data);
            $this->assertIsNumeric($data[$klic]);
        }
    }

    // ——— tarif ———

    /**
     * Placený tarif se nedá získat kliknutím.
     *
     * Prototyp tlačítkem jen přepsal stav v prohlížeči. Kdyby ho backend bral
     * doslova, dostala by dvojice úložiště, které nezaplatila.
     */
    public function test_placeny_tarif_se_neprideli_zdarma(): void
    {
        $zdarma = BillingPlan::create(['code' => 'zdarma', 'name' => 'Zdarma', 'price_monthly' => 0, 'storage_limit_mb' => 5000, 'is_default' => true]);
        $placeny = BillingPlan::create(['code' => 'velky', 'name' => 'Rodinný 1 TB', 'price_monthly' => 349, 'storage_limit_mb' => 1_000_000]);

        $this->postJson('/api/admin/plan', ['plan' => $placeny->id]);

        $this->assertSame(0, \App\Models\SpaceSubscription::where('billing_plan_id', $placeny->id)->where('status', 'active')->count(),
            'Placený tarif nesmí začít platit dřív, než je zaplacený.');
        $this->assertSame((string) $zdarma->id, $this->getJson('/api/admin')->json('data.plan'));
    }

    public function test_tarif_zdarma_se_prepne_rovnou(): void
    {
        BillingPlan::create(['code' => 'zaklad', 'name' => 'Základ', 'price_monthly' => 0, 'storage_limit_mb' => 5000, 'is_default' => true]);
        $druhy = BillingPlan::create(['code' => 'zkusebni', 'name' => 'Zkušební', 'price_monthly' => 0, 'storage_limit_mb' => 20000]);

        $this->postJson('/api/admin/plan', ['plan' => $druhy->id])->assertOk();

        $this->assertSame((string) $druhy->id, $this->getJson('/api/admin')->json('data.plan'));
    }

    public function test_spravce_nesmi_menit_tarif(): void
    {
        $tarif = BillingPlan::create(['code' => 'zaklad', 'name' => 'Základ', 'price_monthly' => 0, 'storage_limit_mb' => 5000]);

        Sanctum::actingAs($this->makinka);

        $this->postJson('/api/admin/plan', ['plan' => $tarif->id])->assertForbidden();
    }

    // ——— rizika ———

    public function test_vysypani_kose_posune_lhutu_a_spusti_uklid(): void
    {
        $media = MediaItem::create([
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'vylet.jpg',
            'safe_filename' => 'vylet.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'status' => 'ready',
            'trashed_at' => now(),
            'purge_after' => now()->addDays(30),
        ]);

        $this->postJson('/api/admin/risks/r2/fix')->assertOk();

        $this->assertTrue($media->fresh()->purge_after->isPast(), 'Vysypat teď znamená teď, ne za třicet dní.');
        Queue::assertPushed(SpustPlanovanouUlohu::class);
    }

    public function test_nezname_riziko_neprojde(): void
    {
        $this->postJson('/api/admin/risks/r9/fix')->assertNotFound();
    }

    // ——— protokol ———

    /** Administrace bez záznamu není administrace. */
    public function test_kazdy_zasah_je_v_protokolu_se_jmenem(): void
    {
        $this->patchJson('/api/admin/users/'.$this->makinka->id.'/role', ['role' => 'host'])->assertOk();

        $protokol = $this->getJson('/api/admin')->json('data.log');

        $this->assertNotEmpty($protokol);
        $this->assertSame('Adrian', $protokol[0]['who']);
        $this->assertStringContainsString('Makinka má nyní roli host', $protokol[0]['what']);
    }

    public function test_protokol_vidi_jen_zasahy_vlastniho_prostoru(): void
    {
        AuditLog::create([
            'user_id' => User::factory()->create()->id,
            'action' => 'admin.role',
            'payload' => ['popis' => 'Cizí zásah v cizí galerii'],
            'created_at' => now(),
        ]);

        $protokol = $this->getJson('/api/admin')->json('data.log');

        $this->assertEmpty($protokol);
    }

    public function test_bez_prihlaseni_neprojde(): void
    {
        $this->app['auth']->forgetGuards();
        auth()->guard('sanctum')->forgetUser();

        $this->getJson('/api/admin')->assertUnauthorized();
    }
}
