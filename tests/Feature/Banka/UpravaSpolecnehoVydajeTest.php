<?php

namespace Tests\Feature\Banka;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Úprava výdaje ukládá čas i rozdělení stejně jako založení.
 *
 * Dřív šel `occurred_at` do sloupce tak, jak přišel (MySQL ve striktním
 * režimu odmítne „25 September 2026" → 500) a změna částky nechala staré
 * podíly — součet nesouhlasil a vyrovnání potichu přešlo na rovný díl.
 */
class UpravaSpolecnehoVydajeTest extends TestCase
{
    use RefreshDatabase;

    private User $vlastnik;

    private User $partner;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vlastnik = User::factory()->create(['role' => 'owner']);
        $this->partner = User::factory()->create(['role' => 'owner']);
        $this->prostor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Naše výdaje', 'slug' => 'nase-vydaje-uprava', 'owner_id' => $this->vlastnik->id]);
        $this->prostor->members()->attach($this->vlastnik->id, ['role' => 'owner', 'joined_at' => now()->subDays(2)]);
        $this->prostor->members()->attach($this->partner->id, ['role' => 'editor', 'joined_at' => now()->subDay()]);
        $this->actingAs($this->vlastnik);
    }

    private function zalozit(array $navic = []): string
    {
        return $this->postJson('/api/v1/shared-expenses', $navic + [
            'gallery_space_id' => $this->prostor->id, 'title' => 'Nákup', 'category' => 'food',
            'amount' => 300, 'currency' => 'CZK', 'occurred_at' => '2026-09-20',
        ])->assertCreated()->json('uuid');
    }

    public function test_cas_se_uklada_normalizovany_jako_pri_zalozeni(): void
    {
        $uuid = $this->zalozit();

        $this->patchJson("/api/v1/shared-expenses/{$uuid}", ['occurred_at' => '2026-09-25T10:00:00+02:00'])->assertOk();
        $this->assertSame('2026-09-25 10:00:00', (string) DB::table('shared_expenses')->where('uuid', $uuid)->value('occurred_at'));

        $this->patchJson("/api/v1/shared-expenses/{$uuid}", ['occurred_at' => '25 September 2026'])->assertOk();
        $this->assertSame('2026-09-25 00:00:00', (string) DB::table('shared_expenses')->where('uuid', $uuid)->value('occurred_at'));
    }

    public function test_zmena_castky_prepocita_rovny_dil(): void
    {
        $uuid = $this->zalozit();

        $this->patchJson("/api/v1/shared-expenses/{$uuid}", ['amount' => 500])->assertOk();

        $split = json_decode((string) DB::table('shared_expenses')->where('uuid', $uuid)->value('split'), true);
        $this->assertCount(2, $split);
        $this->assertEqualsWithDelta(500, array_sum(array_column($split, 'amount')), 0.001);
        $this->assertEqualsWithDelta(250, $split[0]['amount'], 0.001);
    }

    public function test_vlastni_podily_se_pri_zmene_castky_musi_upravit_take(): void
    {
        $uuid = $this->zalozit(['split_mode' => 'custom', 'split' => [
            ['user_id' => $this->vlastnik->id, 'amount' => 200], ['user_id' => $this->partner->id, 'amount' => 100],
        ]]);

        $this->patchJson("/api/v1/shared-expenses/{$uuid}", ['amount' => 600])->assertStatus(422);
        $this->assertEqualsWithDelta(300, (float) DB::table('shared_expenses')->where('uuid', $uuid)->value('amount'), 0.001);

        $this->patchJson("/api/v1/shared-expenses/{$uuid}", ['amount' => 600, 'split' => [
            ['user_id' => $this->vlastnik->id, 'amount' => 400], ['user_id' => $this->partner->id, 'amount' => 200],
        ]])->assertOk();
        $split = json_decode((string) DB::table('shared_expenses')->where('uuid', $uuid)->value('split'), true);
        $this->assertEqualsWithDelta(600, array_sum(array_column($split, 'amount')), 0.001);
    }
}
