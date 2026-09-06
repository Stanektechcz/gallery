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
 * Co se ty dny dělo — a s čím chodí horší nálada.
 *
 * Ukázka měla dny napsané dopředu („Přesčas po 20:00" třikrát), takže ta
 * korelace vycházela vždycky stejně a o dvojici neříkala nic.
 */
class ObsahKlidTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    /**
     * Bez zapsané nálady není s čím korelovat — a značky se neposílají.
     *
     * Samotné „byli jsme na cestě" nic neříká, dokud u toho není, jak nám bylo.
     */
    public function test_bez_nalady_se_udalosti_neposilaji(): void
    {
        $this->cesta(now()->subDays(3), now()->subDay());

        $data = $this->getJson('/api/data/zdravi')->assertOk()->json('data');

        $this->assertArrayNotHasKey('KL_EV', $data);
    }

    /** Den na cestě dostane značku za každý den, který do těch čtrnácti spadá. */
    public function test_cesta_znaci_kazdy_svuj_den(): void
    {
        $this->nalada(now(), 4);
        $this->cesta(now()->subDays(2), now());

        $u = collect($this->getJson('/api/data/zdravi')->assertOk()->json('data.KL_EV'))
            ->where('kind', 'Na cestě');

        // Čtrnáctý den je dnešek; cesta trvala tři dny.
        $this->assertSame([11, 12, 13], $u->pluck('d')->values()->all());
        $this->assertTrue($u->first()['good']);
    }

    /** Večer, který skončil po osmé, se pozná — a jiný ne. */
    public function test_vecer_mimo_domov_se_pozna(): void
    {
        $this->nalada(now(), 4);
        $this->udalost(now()->subDay()->setTime(19, 0), now()->subDay()->setTime(22, 30));
        $this->udalost(now()->subDays(2)->setTime(9, 0), now()->subDays(2)->setTime(17, 0));

        $u = collect($this->getJson('/api/data/zdravi')->assertOk()->json('data.KL_EV'))
            ->where('kind', 'Večer mimo domov');

        // Čtrnáctý den je dnešek, takže včerejšek je dvanáctý.
        $this->assertSame([12], $u->pluck('d')->values()->all());
        $this->assertFalse($u->first()['good']);
    }

    /** Narozeniny nejsou večer mimo domov, i když trvají do noci. */
    public function test_narozeniny_maji_vlastni_druh(): void
    {
        $this->nalada(now(), 4);
        $this->udalost(now()->subDay()->setTime(18, 0), now()->subDay()->setTime(23, 0), 'birthday');

        $druhy = collect($this->getJson('/api/data/zdravi')->assertOk()->json('data.KL_EV'))->pluck('kind');

        $this->assertSame(['Narozeniny nebo výročí'], $druhy->all());
    }

    /**
     * Přes limit se hlásí ten pohyb, který hranici překročil — ne každý další.
     *
     * Jinak by měsíc po překročení limitu vypadal jako řada špatných dnů.
     */
    public function test_prekroceni_limitu_se_hlasi_jednou(): void
    {
        $this->nalada(now(), 4);
        $kategorie = $this->limit('Restaurace', 1000);

        $this->transakce(500, $kategorie, now()->subDays(3));
        $this->transakce(700, $kategorie, now()->subDays(2));
        $this->transakce(300, $kategorie, now()->subDay());

        $u = collect($this->getJson('/api/data/zdravi')->assertOk()->json('data.KL_EV'))
            ->where('kind', 'Výdaj přes limit');

        // Hranici překročil ten druhý pohyb, tedy předevčírem.
        $this->assertSame([11], $u->pluck('d')->values()->all());
    }

    /** Bez rozpočtu se „přes limit" nehlásí — žádná hranice není. */
    public function test_bez_limitu_se_utrata_nehlasi(): void
    {
        $this->nalada(now(), 4);
        $this->transakce(9000, null, now()->subDay());

        $druhy = collect($this->getJson('/api/data/zdravi')->assertOk()->json('data.KL_EV'))->pluck('kind');

        $this->assertNotContains('Výdaj přes limit', $druhy);
    }

    // ——— pomůcky ———

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

    private function cesta($od, $do): void
    {
        DB::table('trips')->insert([
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'name' => 'Výlet',
            'start_date' => $od->toDateString(),
            'end_date' => $do->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function udalost($od, $do, string $druh = 'event'): void
    {
        DB::table('calendar_events')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Událost',
            'type' => $druh,
            'status' => 'planned',
            'starts_at' => $od,
            'ends_at' => $do,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function limit(string $nazev, int $castka): int
    {
        $rozpocet = DB::table('budgets')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'name' => 'Rozpočet',
            'currency' => 'CZK',
            'starts_on' => now()->startOfMonth()->toDateString(),
            'is_shared' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $kategorie = DB::table('finance_categories')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'name' => $nazev,
            'kind' => 'expense',
            'is_favourite' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('budget_category_limits')->insert([
            'budget_id' => $rozpocet,
            'finance_category_id' => $kategorie,
            'amount' => $castka,
            'priority' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $kategorie;
    }

    private function transakce(int $castka, ?int $kategorie, $kdy): void
    {
        DB::table('transactions')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'type' => 'expense',
            'occurred_at' => $kdy->toDateString(),
            'amount_from' => $castka,
            'currency_from' => 'CZK',
            'category_id' => $kategorie,
            'description' => 'Výdaj',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
