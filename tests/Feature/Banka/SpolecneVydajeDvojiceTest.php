<?php

namespace Tests\Feature\Banka;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Banking\SharedExpenseSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Společné výdaje dělí jen dvojice — host ani odebraný účet partner nejsou.
 *
 * Dřív se výdaj dělil mezi všechny řádky členství: 300 Kč za večeři se
 * rozpočítalo 100/100/100 i na hosta, který jen prohlíží sdílené odkazy,
 * a host mohl být i plátcem.
 */
class SpolecneVydajeDvojiceTest extends TestCase
{
    use RefreshDatabase;

    private User $vlastnik;

    private User $partner;

    private User $host;

    private User $odebrany;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vlastnik = User::factory()->create(['role' => 'owner']);
        $this->partner = User::factory()->create(['role' => 'owner']);
        $this->host = User::factory()->create(['role' => 'owner']);
        $this->odebrany = User::factory()->create(['role' => 'owner', 'is_active' => false]);
        $this->prostor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Naše výdaje', 'slug' => 'nase-vydaje', 'owner_id' => $this->vlastnik->id]);
        $this->prostor->members()->attach($this->vlastnik->id, ['role' => 'owner', 'joined_at' => now()->subDays(4)]);
        $this->prostor->members()->attach($this->partner->id, ['role' => 'editor', 'joined_at' => now()->subDays(3)]);
        $this->prostor->members()->attach($this->host->id, ['role' => 'viewer', 'joined_at' => now()->subDays(2)]);
        $this->prostor->members()->attach($this->odebrany->id, ['role' => 'editor', 'joined_at' => now()->subDay()]);
        $this->actingAs($this->vlastnik);
    }

    private function vydaj(array $navic = []): TestResponse
    {
        return $this->postJson('/api/v1/shared-expenses', $navic + [
            'gallery_space_id' => $this->prostor->id, 'title' => 'Večeře', 'category' => 'food',
            'amount' => 300, 'currency' => 'CZK', 'occurred_at' => '2026-09-20',
        ]);
    }

    public function test_rovny_dil_a_navrh_vyrovnani_pocita_jen_dvojici(): void
    {
        $uuid = $this->vydaj()->assertCreated()->json('uuid');

        $split = json_decode((string) DB::table('shared_expenses')->where('uuid', $uuid)->value('split'), true);
        $this->assertEqualsCanonicalizing([
            ['user_id' => $this->vlastnik->id, 'amount' => 150],
            ['user_id' => $this->partner->id, 'amount' => 150],
        ], $split);

        $snapshot = app(SharedExpenseSettlementService::class)->snapshot($this->prostor->id);
        $this->assertCount(1, $snapshot);
        $this->assertEqualsCanonicalizing([$this->vlastnik->id, $this->partner->id], array_column($snapshot[0]['members'], 'user_id'));
        $this->assertCount(1, $snapshot[0]['proposals']);
        $this->assertSame($this->partner->id, $snapshot[0]['proposals'][0]['from_user_id']);
        $this->assertSame($this->vlastnik->id, $snapshot[0]['proposals'][0]['to_user_id']);
        $this->assertEqualsWithDelta(150, $snapshot[0]['proposals'][0]['amount'], 0.001);
    }

    public function test_host_ani_odebrany_ucet_nemuze_byt_platcem(): void
    {
        $this->vydaj(['paid_by_user_id' => $this->host->id])->assertStatus(422);
        $this->vydaj(['paid_by_user_id' => $this->odebrany->id])->assertStatus(422);
        $this->assertDatabaseCount('shared_expenses', 0);
    }

    public function test_vlastni_podil_nesmi_obsahovat_hosta(): void
    {
        $this->vydaj(['split_mode' => 'custom', 'split' => [
            ['user_id' => $this->vlastnik->id, 'amount' => 100],
            ['user_id' => $this->partner->id, 'amount' => 100],
            ['user_id' => $this->host->id, 'amount' => 100],
        ]])->assertStatus(422);
        $this->assertDatabaseCount('shared_expenses', 0);
    }

    public function test_host_s_vlastni_galerii_do_cizich_vydaju_nezapisuje(): void
    {
        $vlastni = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Hostova galerie', 'slug' => 'hostova-galerie',
            'owner_id' => $this->host->id, 'is_default' => true]);
        $vlastni->members()->attach($this->host->id, ['role' => 'owner', 'joined_at' => now()]);
        $uuid = $this->vydaj()->assertCreated()->json('uuid');

        $this->actingAs($this->host);
        $this->vydaj(['title' => 'Cizí'])->assertForbidden();
        $this->patchJson("/api/v1/shared-expenses/{$uuid}", ['amount' => 1])->assertNotFound();
        $this->assertDatabaseCount('shared_expenses', 1);
    }

    public function test_starsi_vydaj_rozdeleny_i_na_hosta_se_vyrovnava_jen_mezi_dvojici(): void
    {
        DB::table('shared_expenses')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->vlastnik->id,
            'paid_by_user_id' => $this->vlastnik->id, 'title' => 'Starý výdaj', 'category' => 'food', 'amount' => 300,
            'currency' => 'CZK', 'occurred_at' => '2026-09-01 00:00:00', 'source' => 'manual', 'split_mode' => 'equal',
            'split' => json_encode([
                ['user_id' => $this->vlastnik->id, 'amount' => 100], ['user_id' => $this->partner->id, 'amount' => 100],
                ['user_id' => $this->host->id, 'amount' => 100],
            ]), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $proposals = app(SharedExpenseSettlementService::class)->snapshot($this->prostor->id)[0]['proposals'];
        $this->assertCount(1, $proposals);
        $this->assertEqualsWithDelta(150, $proposals[0]['amount'], 0.001);
    }
}
