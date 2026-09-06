<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Účet radosti: co doopravdy vyrobilo dobré dny — a za kolik.
 *
 * Obrazovka o sobě říká: „Zdvih nálady se bere z korelací v sekci Nálada dvou,
 * útrata z transakcí, hodiny z kalendáře. Nic se nehodnotí dojmem." Chyběl
 * k tomu jediný údaj — co to za společnou věc vlastně bylo.
 */
class UcetRadostiTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->maki = User::factory()->create(['name' => 'Makinka']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    /** Bez zařazených událostí se nic neposílá. */
    public function test_bez_zarazeni_se_nic_neposila(): void
    {
        $this->udalost(null, now()->subDays(3));

        $this->assertArrayNotHasKey('JOY', $this->getJson('/api/data/klid')->assertOk()->json('data'));
    }

    /** Jednou nebo dvakrát to není zvyk, ze kterého se dá číst. */
    public function test_dve_udalosti_na_zaver_nestaci(): void
    {
        $this->udalost('Randíčko', now()->subDays(3));
        $this->udalost('Randíčko', now()->subDays(10));

        $this->assertArrayNotHasKey('JOY', $this->getJson('/api/data/klid')->assertOk()->json('data'));
    }

    /**
     * Hodiny z kalendáře, útrata z transakcí, zdvih z nálady.
     *
     * Nálada ve dnech s randíčkem je 5, jinak 3 — zdvih tedy +1,6 proti
     * průměru všech dnů.
     */
    public function test_pocita_hodiny_utratu_i_zdvih(): void
    {
        foreach ([3, 10, 17] as $i => $zpet) {
            $den = now()->subDays($zpet)->startOfDay()->addHours(18);
            $this->udalost('Randíčko', $den, $den->copy()->addHours(3));
            $this->nalada($den, 5);
            $this->transakce($den, 600);
        }

        // Dny bez randíčka: horší nálada, žádná útrata.
        foreach ([4, 5, 6, 7] as $zpet) {
            $this->nalada(now()->subDays($zpet)->startOfDay(), 3);
        }

        $joy = collect($this->getJson('/api/data/klid')->assertOk()->json('data.JOY'));
        $radek = $joy->firstWhere('name', 'Randíčko');

        $this->assertNotNull($radek);
        $this->assertSame(3, $radek['n']);
        // JSON vrací 3.0 jako 3 — porovnává se hodnota, ne typ.
        $this->assertEquals(3.0, $radek['hours']);
        $this->assertSame(600, $radek['cost']);
        $this->assertGreaterThan(1.0, $radek['lift']);
    }

    /**
     * Útrata se počítá jen ze dnů, kdy se dělo jen tohle jedno.
     *
     * Den, ve kterém je randíčko i velký nákup, aplikace rozdělit neumí —
     * a přiřadit celou útratu oběma by znamenalo tvrdit, že randíčko stálo
     * čtyři tisíce.
     */
    public function test_smiseny_den_se_do_utraty_nepocita(): void
    {
        foreach ([3, 10, 17] as $zpet) {
            $den = now()->subDays($zpet)->startOfDay()->addHours(18);
            $this->udalost('Randíčko', $den, $den->copy()->addHours(2));
            $this->transakce($den, 500);
        }

        // Čtvrtý den je randíčko i nákup — a útrata čtyři tisíce.
        $smiseny = now()->subDays(24)->startOfDay()->addHours(18);
        $this->udalost('Randíčko', $smiseny, $smiseny->copy()->addHours(2));
        $this->udalost('Velký nákup do bytu', $smiseny, $smiseny->copy()->addHours(2));
        $this->transakce($smiseny, 4000);

        $joy = collect($this->getJson('/api/data/klid')->assertOk()->json('data.JOY'));
        $radek = $joy->firstWhere('name', 'Randíčko');

        $this->assertSame(4, $radek['n']);
        $this->assertSame(500, $radek['cost'], 'Smíšený den se do útraty počítat nemá.');
    }

    /** Bez zapsané nálady je zdvih nula, ne odhad. */
    public function test_bez_nalady_je_zdvih_nula(): void
    {
        foreach ([3, 10, 17] as $zpet) {
            $den = now()->subDays($zpet)->startOfDay()->addHours(18);
            $this->udalost('Sport nebo procházka', $den, $den->copy()->addHours(1));
        }

        $joy = collect($this->getJson('/api/data/klid')->assertOk()->json('data.JOY'));

        $this->assertEquals(0.0, $joy->firstWhere('name', 'Sport nebo procházka')['lift']);
    }

    private function udalost(?string $cinnost, $od, $do = null): void
    {
        DB::table('calendar_events')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => $cinnost ?? 'Něco',
            'type' => 'event',
            'activity_kind' => $cinnost,
            'status' => 'planned',
            'starts_at' => $od,
            'ends_at' => $do,
            'all_day' => false,
            'timezone' => 'Europe/Prague',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function nalada($den, int $hodnota): void
    {
        DB::table('wellbeing_moods')->insert([
            'gallery_space_id' => $this->prostor->id,
            'user_id' => $this->adri->id,
            'day' => $den->toDateString(),
            'value' => $hodnota,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function transakce($den, int $castka): void
    {
        DB::table('transactions')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'type' => 'expense',
            'occurred_at' => $den,
            'amount_from' => $castka,
            'currency_from' => 'CZK',
            'description' => 'Výdaj',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
