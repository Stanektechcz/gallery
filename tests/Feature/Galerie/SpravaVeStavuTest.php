<?php

namespace Tests\Feature\Galerie;

use App\Jobs\SpustPlanovanouUlohu;
use App\Models\BillingPlan;
use App\Models\CoupleState;
use App\Models\GallerySpace;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Administrace poslaná jako změna stavu.
 *
 * Prototyp administraci celou drží v komponentě: tlačítka volají `setState`
 * a ten stav se ukládá do `/api/state`. Na `/api/admin` nesáhne. Bez zpracování
 * na téhle cestě se po prvním kliknutí obrazovka a databáze **tiše rozejdou** —
 * stav tvrdil, že Makinka je host, databáze že správce.
 */
class SpravaVeStavuTest extends TestCase
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

    public function test_zmena_role_ve_stavu_se_provede_doopravdy(): void
    {
        $odpoved = $this->patchStav([
            'admUsers' => [
                ['id' => (string) $this->adri->id, 'name' => 'Adrian', 'role' => 'vlastník', 'state' => 'aktivní'],
                ['id' => (string) $this->makinka->id, 'name' => 'Makinka', 'role' => 'host', 'state' => 'aktivní'],
            ],
        ])->assertOk();

        $this->assertSame('viewer', $this->rolePivotu($this->makinka), 'Záměr z obrazovky se musí projevit v databázi.');

        $vracene = collect($odpoved->json('data.admUsers'))->firstWhere('name', 'Makinka');
        $this->assertSame('host', $vracene['role'], 'Odpověď musí nést skutečnost, ne to, co klient poslal.');
    }

    /**
     * Klientova kopie administrace se neukládá.
     *
     * Administrace má jediný zdroj pravdy (`/api/admin`); druhá kopie ve stavu
     * by se s tou první dřív nebo později rozešla. Odpověď skutečnost nese, aby
     * se starší klient měl z čeho srovnat, do databáze ale nejde.
     */
    public function test_stav_kopii_administrace_neuklada(): void
    {
        $odpoved = $this->patchStav([
            'admUsers' => [['id' => (string) $this->makinka->id, 'name' => 'Makinka', 'role' => 'host', 'state' => 'aktivní']],
            'admLog' => [['when' => 'dnes', 'who' => 'Adrian', 'what' => 'vymyšlený zápis']],
            'joy' => ['tohle uložit ano'],
        ])->assertOk();

        $ulozeno = CoupleState::first()->data;

        $this->assertSame(['tohle uložit ano'], $ulozeno['joy']);
        $this->assertArrayNotHasKey('admUsers', $ulozeno);
        $this->assertArrayNotHasKey('admLog', $ulozeno);

        // V odpovědi skutečnost je — a vymyšlený zápis v protokolu neprojde.
        $this->assertSame('host', collect($odpoved->json('data.admUsers'))->firstWhere('name', 'Makinka')['role']);
        $this->assertNotContains('vymyšlený zápis', array_column($odpoved->json('data.admLog'), 'what'));
    }

    /** Odpověď nese skutečnost, i když si klient přál něco jiného. */
    public function test_odpoved_prebije_prani_klienta(): void
    {
        $odpoved = $this->patchStav([
            'admUsers' => [
                ['id' => (string) $this->adri->id, 'name' => 'Adrian', 'role' => 'host', 'state' => 'aktivní'],
            ],
        ])->assertOk();

        // Vlastníkovi se role měnit nedá; odpověď to musí říct.
        $vracene = collect($odpoved->json('data.admUsers'))->firstWhere('name', 'Adrian');
        $this->assertSame('vlastník', $vracene['role']);
        $this->assertSame($this->adri->id, $this->prostor->fresh()->owner_id);
    }

    public function test_odebrani_pristupu_ve_stavu_zrusi_i_tokeny(): void
    {
        $this->makinka->createToken('telefon');

        $this->patchStav([
            'admUsers' => [
                ['id' => (string) $this->makinka->id, 'name' => 'Makinka', 'role' => 'správce', 'state' => 'bez přístupu'],
            ],
        ])->assertOk();

        $this->assertFalse($this->makinka->fresh()->is_active);
        $this->assertSame(0, $this->makinka->tokens()->count());
    }

    public function test_predani_vlastnictvi_ve_stavu_funguje(): void
    {
        $this->patchStav([
            'admUsers' => [
                ['id' => (string) $this->makinka->id, 'name' => 'Makinka', 'role' => 'vlastník', 'state' => 'aktivní'],
            ],
        ])->assertOk();

        $this->assertSame($this->makinka->id, $this->prostor->fresh()->owner_id);
    }

    public function test_pozvanka_ve_stavu_zalozi_ucet_i_clenstvi(): void
    {
        $this->patchStav([
            'admUsers' => [
                ['id' => 'u3'.time(), 'name' => 'Klára', 'mail' => 'klara@vzpominky.test', 'role' => 'host', 'state' => 'pozvaná'],
            ],
        ])->assertOk();

        $klara = User::where('email', 'klara@vzpominky.test')->sole();

        $this->assertTrue($this->prostor->members()->where('users.id', $klara->id)->exists());
    }

    /** Správce do účtů nesmí — a odpověď mu obrazovku vrátí zpátky. */
    public function test_spravce_ucty_ve_stavu_nezmeni(): void
    {
        Sanctum::actingAs($this->makinka);

        $odpoved = $this->patchStav([
            'admUsers' => [
                ['id' => (string) $this->makinka->id, 'name' => 'Makinka', 'role' => 'vlastník', 'state' => 'aktivní'],
            ],
        ])->assertOk();

        $this->assertSame($this->adri->id, $this->prostor->fresh()->owner_id);
        $this->assertSame('správce', collect($odpoved->json('data.admUsers'))->firstWhere('name', 'Makinka')['role']);
    }

    // ——— úlohy, rizika, klíče, tarif ———

    public function test_pozastaveni_ulohy_ve_stavu_plati(): void
    {
        $odpoved = $this->patchStav(['admJobPause' => ['trash-purge' => true]])->assertOk();

        $this->assertTrue($odpoved->json('data.admJobPause.trash-purge'));
        $this->assertSame('pozastavená', collect($odpoved->json('data.admJobs'))->firstWhere('id', 'trash-purge')['state']);
    }

    /** Tep plánovače pozastavit nejde — jinak by kontrola hlásila mrtvý plánovač. */
    public function test_tep_planovace_ve_stavu_pozastavit_nejde(): void
    {
        $odpoved = $this->patchStav(['admJobPause' => ['scheduler-heartbeat' => true]])->assertOk();

        $this->assertFalse($odpoved->json('data.admJobPause.scheduler-heartbeat'));
    }

    /**
     * „Spustit teď" úlohu opravdu spustí.
     *
     * Prototyp ji jen přepne na `běží` a po vteřině na `hotovo`; přepnutí je ale
     * jednoznačný záměr, takže se z něj pozná, co uživatel chtěl.
     */
    public function test_spusteni_ulohy_ve_stavu_ji_zaradi(): void
    {
        $this->patchStav(['admJobs' => [['id' => 'trash-purge', 'name' => 'Koš', 'state' => 'běží']]])->assertOk();

        Queue::assertPushed(SpustPlanovanouUlohu::class);
    }

    /** Vymyšlená úloha se nespustí — tlačítko nesmí být cestou ke spuštění čehokoli. */
    public function test_neznama_uloha_se_ve_stavu_nespusti(): void
    {
        $this->patchStav(['admJobs' => [['id' => 'rm -rf', 'state' => 'běží']]])->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_vyreseni_rizika_ve_stavu_spusti_uklid(): void
    {
        $this->patchStav(['admRisk' => ['r2' => true]])->assertOk();

        Queue::assertPushed(SpustPlanovanouUlohu::class);
    }

    public function test_zruseni_klice_ve_stavu_plati(): void
    {
        $this->adri->createToken('Mobilní aplikace');
        $klic = PersonalAccessToken::sole();

        $this->patchStav([
            'admKeys' => [['id' => (string) $klic->id, 'name' => 'Mobilní aplikace', 'state' => 'zrušený']],
        ])->assertOk();

        $this->assertTrue($klic->fresh()->expires_at->isPast());
    }

    /**
     * Placený tarif se přes stav nezmění.
     *
     * Kupuje se, nepřiděluje — jinak by dvojice dostala úložiště, které
     * nezaplatila. Odpověď vrátí ten skutečný, takže se výběr sám vrátí zpátky.
     */
    public function test_placeny_tarif_se_ve_stavu_neprideli(): void
    {
        $zdarma = BillingPlan::create(['code' => 'zdarma', 'name' => 'Zdarma', 'price_monthly' => 0, 'storage_limit_mb' => 5000, 'is_default' => true]);
        $placeny = BillingPlan::create(['code' => 'velky', 'name' => 'Velký', 'price_monthly' => 349, 'storage_limit_mb' => 1_000_000]);

        $odpoved = $this->patchStav(['admPlan' => (string) $placeny->id])->assertOk();

        $this->assertSame((string) $zdarma->id, $odpoved->json('data.admPlan'));
    }

    /** Protokol změn ukazuje skutečné zásahy, ne jen ty z téhle karty. */
    public function test_protokol_nese_skutecne_zasahy(): void
    {
        $odpoved = $this->patchStav([
            'admUsers' => [['id' => (string) $this->makinka->id, 'name' => 'Makinka', 'role' => 'host', 'state' => 'aktivní']],
        ])->assertOk();

        $protokol = $odpoved->json('data.admLog');

        $this->assertNotEmpty($protokol);
        $this->assertSame('Adrian', $protokol[0]['who']);
        $this->assertStringContainsString('roli host', $protokol[0]['what']);
    }

    /**
     * Uložená kopie administrace z dřívějška se zahodí.
     *
     * Administrace se do stavu chvíli ukládala, než dostala vlastní adresu.
     * Kdyby tam zůstala, zastarávala by a starší klient by z ní kreslil.
     */
    public function test_pozustatek_administrace_ve_stavu_zmizi(): void
    {
        $stav = CoupleState::forCouple($this->prostor->id);
        $stav->applyPatch([
            'admUsers' => [['id' => '1', 'name' => 'Kdosi', 'role' => 'vlastník']],
            'admLog' => [['what' => 'starý zápis']],
            'joy' => ['tohle zůstane'],
        ]);

        $this->patchStav(['events' => ['nová akce']])->assertOk();

        $ulozeno = CoupleState::first()->data;

        $this->assertArrayNotHasKey('admUsers', $ulozeno);
        $this->assertArrayNotHasKey('admLog', $ulozeno);
        $this->assertSame(['tohle zůstane'], $ulozeno['joy']);
        $this->assertSame(['nová akce'], $ulozeno['events']);
    }

    /** Běžný patch bez administrace se nesmí zdržovat ničím navíc. */
    public function test_bezny_patch_administraci_nevraci(): void
    {
        $odpoved = $this->patchStav(['joy' => ['výlet']])->assertOk();

        $this->assertArrayNotHasKey('admUsers', $odpoved->json('data'));
    }

    private function patchStav(array $data)
    {
        return $this->patchJson('/api/state', ['data' => $data]);
    }

    private function rolePivotu(User $kdo): string
    {
        return $this->prostor->members()->where('users.id', $kdo->id)->first()->pivot->role;
    }
}
