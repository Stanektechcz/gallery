<?php

namespace Tests\Feature\Galerie;

use App\Jobs\SpustPlanovanouUlohu;
use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\ScheduledTaskRun;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SpaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Administrace: kdo smí nevratně mazat, co vidí v protokolu a v kolik.
 *
 * „Vysypat koš" z panelu rizik (přes `/api/admin` i přes stav) pouštělo
 * i běžného člena dvojice (`editor`) a přes stav mazalo i fotky z trezoru se
 * zamčeným trezorem. Protokol bral zápisy podle autorů, ne podle galerie,
 * takže ukázal i zásahy (s e-maily) z jejich jiných galerií. Časy se psaly
 * v UTC — po půlnoci „včera 22:30" místo „dnes 0:30".
 */
class SpravaOpravneniTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $makinka;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        SpaceContext::forget();
        Queue::fake();
        Notification::fake();

        $this->adri = User::factory()->create(['name' => 'Adrian', 'role' => 'owner']);
        // Jako skutečné účty: `users.role = owner` má každý, oprávnění to není.
        $this->makinka = User::factory()->create(['name' => 'Makinka', 'role' => 'owner']);

        $this->prostor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Naše vzpomínky', 'slug' => 'nase-'.Str::random(5), 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->makinka->id => ['role' => 'editor'],
        ]);
    }

    // ——— Vysypat koš z panelu rizik ———

    public function test_editor_kos_pres_stav_nevysype(): void
    {
        $fotka = $this->fotka(['trashed_at' => now(), 'purge_after' => now()->addDays(30)]);

        Sanctum::actingAs($this->makinka);
        $this->patchJson('/api/state', ['data' => ['admRisk' => ['r2' => true]]])->assertOk();

        $this->assertTrue($fotka->fresh()->purge_after->isFuture(), 'Editor nevratně nemaže.');
        Queue::assertNotPushed(SpustPlanovanouUlohu::class);
    }

    public function test_vlastnik_se_zamcenym_trezorem_pres_stav_trezor_nevysype(): void
    {
        $bezna = $this->fotka(['trashed_at' => now(), 'purge_after' => now()->addDays(30)]);
        $skryta = $this->fotka(['is_hidden' => true, 'trashed_at' => now(), 'purge_after' => now()->addDays(30)]);

        // Jen token: sezení (a tedy odemčený trezor) nemá.
        Sanctum::actingAs($this->adri);
        $this->patchJson('/api/state', ['data' => ['admRisk' => ['r2' => true]]])->assertOk();

        $this->assertTrue($bezna->fresh()->purge_after->isPast());
        $this->assertTrue($skryta->fresh()->purge_after->isFuture(), 'Fotka z trezoru se zamčeným trezorem nesmí jít ke smazání.');
    }

    public function test_editor_kos_pres_administraci_nevysype(): void
    {
        $fotka = $this->fotka(['trashed_at' => now(), 'purge_after' => now()->addDays(30)]);

        Sanctum::actingAs($this->makinka);
        $this->postJson('/api/admin/risks/r2/fix')->assertForbidden();

        $this->assertTrue($fotka->fresh()->purge_after->isFuture());
        Queue::assertNotPushed(SpustPlanovanouUlohu::class);
    }

    /** Záloha (r1) zařazuje úlohu — přes stav stejný limit jako přes `/api/admin`. */
    public function test_zaloha_pres_stav_ma_limit(): void
    {
        Sanctum::actingAs($this->adri);
        $this->patchJson('/api/state', ['data' => ['admRisk' => ['r1' => true]]])->assertOk();
        $this->patchJson('/api/state', ['data' => ['admRisk' => ['r1' => true]]])->assertOk();
        $this->postJson('/api/admin/risks/r1/fix')->assertStatus(429);

        Queue::assertPushed(SpustPlanovanouUlohu::class, 1);
    }

    // ——— Jedno pravidlo pro trvalé smazání a vracení z koše ———

    public function test_vlastnik_jen_pro_cteni_z_kose_prototypu_trvale_nesmaze(): void
    {
        $fotka = $this->fotka(['trashed_at' => now()]);
        $this->adri->forceFill(['read_only_mode' => true])->save();

        Sanctum::actingAs($this->adri);
        $this->postJson('/api/kos/odstranit', ['id' => $fotka->uuid])
            ->assertForbidden()
            ->assertJson(['ok' => false]);

        $this->assertNotNull(MediaItem::withoutGlobalScopes()->whereKey($fotka->id)->first());
    }

    public function test_ucet_jen_pro_cteni_z_kose_v1_nevrati(): void
    {
        $fotka = $this->fotka(['trashed_at' => now()]);
        $this->makinka->forceFill(['read_only_mode' => true])->save();

        Sanctum::actingAs($this->makinka);
        $this->postJson('/api/v1/trash/'.$fotka->uuid.'/restore')->assertForbidden();
        $this->postJson('/api/v1/trash/bulk-restore', ['uuids' => [$fotka->uuid]])->assertForbidden();

        $this->assertNotNull(MediaItem::withoutGlobalScopes()->whereKey($fotka->id)->value('trashed_at'));
    }

    public function test_clen_dvojice_z_kose_v1_vrati(): void
    {
        $fotka = $this->fotka(['trashed_at' => now()]);

        Sanctum::actingAs($this->makinka);
        $this->postJson('/api/v1/trash/'.$fotka->uuid.'/restore')->assertOk();

        $this->assertNull(MediaItem::withoutGlobalScopes()->whereKey($fotka->id)->value('trashed_at'));
    }

    // ——— Protokol jen z téhle galerie ———

    public function test_protokol_neukaze_zasahy_z_jine_galerie_clena(): void
    {
        $jina = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Rodina', 'slug' => 'rodina-'.Str::random(5), 'owner_id' => $this->adri->id]);
        $jina->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        $this->zapis($this->adri, $jina->id, 'Pozvánka pro teta@rodina.example');
        $this->zapis($this->adri, $this->prostor->id, 'Makinka má nyní roli host');
        // Starší zápisy bez galerie: autora s jedinou galerií lze přiřadit,
        // autora ve dvou galeriích ne — tam se nehádá.
        $this->zapis($this->makinka, null, 'Starý zápis Makinky');
        $this->zapis($this->adri, null, 'Starý zápis odjinud s bratr@rodina.example');

        Sanctum::actingAs($this->adri);
        $co = collect($this->getJson('/api/admin')->assertOk()->json('data.log'))->pluck('what')->all();

        $this->assertContains('Makinka má nyní roli host', $co);
        $this->assertContains('Starý zápis Makinky', $co);
        $this->assertNotContains('Pozvánka pro teta@rodina.example', $co);
        $this->assertNotContains('Starý zápis odjinud s bratr@rodina.example', $co);
    }

    // ——— Časy v pásmu dvojice ———

    public function test_casy_administrace_jsou_v_pasmu_dvojice(): void
    {
        // 0:30 v Praze je 22:30 předchozího dne v UTC.
        $this->travelTo(Carbon::parse('2026-09-25 00:30:00', 'Europe/Prague'));

        $this->makinka->forceFill(['last_seen_at' => now()])->save();
        SystemSetting::set('scheduler_last_heartbeat', now()->toIso8601String());
        ScheduledTaskRun::create(['task' => 'trash-purge', 'started_at' => now(), 'finished_at' => now(), 'duration_ms' => 10, 'state' => ScheduledTaskRun::CHYBA, 'exit_code' => 1, 'output' => 'chyba']);
        $this->zapis($this->adri, $this->prostor->id, 'Zásah po půlnoci');

        Sanctum::actingAs($this->adri);
        $data = $this->getJson('/api/admin')->assertOk()->json('data');

        $this->assertSame('dnes 0:30', collect($data['users'])->firstWhere('name', 'Makinka')['last']);
        $this->assertSame('dnes 0:30', $data['health']['checkedAt']);
        $this->assertSame('dnes 0:30', collect($data['jobs'])->firstWhere('id', 'trash-purge')['last']);
        $this->assertSame('25. 9. 0:30', $data['incidents'][0]['when']);
        $this->assertSame('25. 9. 0:30', collect($data['log'])->firstWhere('what', 'Zásah po půlnoci')['when']);
    }

    private function zapis(User $kdo, ?int $prostor, string $popis): void
    {
        AuditLog::create([
            'user_id' => $kdo->id,
            'gallery_space_id' => $prostor,
            'action' => 'admin.role',
            'payload' => ['popis' => $popis],
            'created_at' => now(),
        ]);
    }

    private function fotka(array $navic = []): MediaItem
    {
        return MediaItem::withoutGlobalScopes()->create(array_merge([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id, 'uploaded_by' => $this->adri->id,
            'original_filename' => 'vylet.jpg', 'safe_filename' => 'vylet.jpg', 'extension' => 'jpg',
            'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1024, 'status' => 'ready',
        ], $navic));
    }
}
