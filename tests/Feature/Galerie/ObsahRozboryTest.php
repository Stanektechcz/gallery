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
 * Rozbory nad financemi: obálka pro sebe, vlastní inflace, sezónní fondy
 * a cena cesty.
 *
 * Všechno se počítá z transakcí, limitů a cílů, které dvojice už má — žádná
 * nová tabulka. A co spočítat nejde, se **neposílá**: vymyšlené číslo, podle
 * kterého se dvojice rozhoduje o penězích, je horší než ukázka.
 */
class ObsahRozboryTest extends TestCase
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

    /** Bez transakcí se nic neposílá. */
    public function test_bez_financi_se_skupina_neposila(): void
    {
        $this->assertSame([], $this->getJson('/api/data/rozbory')->assertOk()->json('data'));
    }

    /**
     * Bez kategorie pojmenované jako obálka se obálka neposílá.
     *
     * Vymyslet, kolik si kdo „smí vzít, aniž by se ptal", je to poslední, co by
     * měl dělat server.
     */
    public function test_bez_osobni_kategorie_se_obalka_neposila(): void
    {
        $kategorie = $this->kategorie('Restaurace');
        $this->transakce(['category_id' => $kategorie, 'amount_from' => 500]);

        $data = $this->getJson('/api/data/rozbory')->assertOk()->json('data');

        $this->assertArrayNotHasKey('ENV', $data);
    }

    /** Obálka dělí útratu podle toho, kdo platil. */
    public function test_obalka_deli_utratu_podle_platce(): void
    {
        $kategorie = $this->kategorie('Osobní obálka');
        $adrian = $this->partner($this->adri);
        $makinka = $this->partner($this->maki);

        $this->transakce(['category_id' => $kategorie, 'amount_from' => 1100, 'payer_partner_id' => $adrian, 'occurred_at' => now()]);
        $this->transakce(['category_id' => $kategorie, 'amount_from' => 640, 'payer_partner_id' => $makinka, 'occurred_at' => now()]);
        $this->transakce(['category_id' => $kategorie, 'amount_from' => 300, 'payer_partner_id' => $adrian, 'occurred_at' => now()->subMonth()]);

        $obalka = $this->getJson('/api/data/rozbory')->assertOk()->json('data.ENV');
        $tento = collect($obalka['months'])->last();

        $this->assertCount(6, $obalka['months']);
        $this->assertSame(1100, $tento['a']);
        $this->assertSame(640, $tento['k']);
        // Minulý měsíc jen Adrian.
        $this->assertSame(300, $obalka['months'][4]['a']);
        $this->assertSame(0, $obalka['months'][4]['k']);
    }

    /**
     * Vlastní inflace bere medián, ne průměr.
     *
     * Jeden velký nákup by z rohlíků jinak udělal luxusní zboží.
     */
    public function test_inflace_bere_median(): void
    {
        $jidlo = $this->kategorie('Jídlo');
        $loni = now()->subYear();

        foreach ([60, 62, 64, 900] as $castka) {
            $this->transakce(['description' => 'Káva v podniku', 'amount_from' => $castka, 'occurred_at' => $loni, 'category_id' => $jidlo]);
        }

        foreach ([78, 79, 80] as $castka) {
            $this->transakce(['description' => 'Káva v podniku', 'amount_from' => $castka, 'occurred_at' => now(), 'category_id' => $jidlo]);
        }

        $i = collect($this->getJson('/api/data/rozbory')->assertOk()->json('data.INFL'))
            ->firstWhere('name', 'Káva v podniku');

        $this->assertSame(63, $i['y25']);
        $this->assertSame(79, $i['y26']);
        $this->assertSame(3, $i['qty']);
        $this->assertSame('jídlo', $i['cat']);
    }

    /** Co se loni nekupovalo, se do inflace nepočítá — není s čím porovnávat. */
    public function test_inflace_potrebuje_oba_roky(): void
    {
        $this->transakce(['description' => 'Novinka', 'amount_from' => 100, 'occurred_at' => now()]);
        $this->transakce(['description' => 'Káva', 'amount_from' => 60, 'occurred_at' => now()->subYear()]);
        $this->transakce(['description' => 'Káva', 'amount_from' => 79, 'occurred_at' => now()]);

        $nazvy = collect($this->getJson('/api/data/rozbory')->assertOk()->json('data.INFL'))->pluck('name');

        $this->assertSame(['Káva'], $nazvy->all());
    }

    /**
     * Kolik měsíčně na sezónní fond se počítá, ne ukládá.
     *
     * Uložené číslo by bylo po každém vkladu o kus vedle.
     */
    public function test_sezonni_fond_pocita_mesicni_castku(): void
    {
        $rozpocet = $this->rozpocet();

        DB::table('budget_goals')->insert([
            'uuid' => (string) Str::uuid(),
            'budget_id' => $rozpocet,
            'name' => 'Dovolená',
            'target_amount' => 45000,
            'saved_amount' => 38200,
            'currency' => 'CZK',
            'target_on' => now()->addMonths(4)->toDateString(),
            'note' => 'Letos Chorvatsko.',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->transakce(['amount_from' => 100]);

        $f = $this->getJson('/api/data/rozbory')->assertOk()->json('data.SEASON.0');

        $this->assertSame('Dovolená', $f['name']);
        $this->assertSame(45000, $f['target']);
        $this->assertSame(38200, $f['saved']);
        // Chybí 6 800 do čtyř měsíců.
        $this->assertSame(1700, $f['per']);
        $this->assertSame('ph-umbrella-simple', $f['icon']);
    }

    /**
     * Cena cesty se porovnává s předchozí cestou.
     *
     * „O tisíc na den víc než ve Vídni" je věta, se kterou se dá něco dělat;
     * průměr za všechny cesty není.
     */
    public function test_cena_cesty_se_porovnava_s_predchozi(): void
    {
        $viden = $this->cesta('Vídeň 2025', now()->subYear(), now()->subYear()->addDay());
        $this->utrata($viden, ['category' => 'food', 'amount' => 2000]);
        $this->utrata($viden, ['category' => 'transport', 'amount' => 1780]);

        $chorvatsko = $this->cesta('Chorvatsko 2026', now()->subDays(10), now()->subDays(3));
        $this->utrata($chorvatsko, ['category' => 'lodging', 'amount' => 11200]);
        $this->utrata($chorvatsko, ['category' => 'food', 'amount' => 6420]);

        $c = $this->getJson('/api/data/rozbory')->assertOk()->json('data.TRIPCOST.chorvatsko2026');

        $this->assertSame(17620, $c['total']);
        $this->assertSame(8, $c['days']);
        $this->assertSame(2, $c['people']);
        $this->assertSame('Vídeň 2025', $c['prev']);
        // 3 780 Kč za dva dny.
        $this->assertSame(1890, $c['prevPerDay']);
        $this->assertSame('Ubytování', $c['items'][0]['name']);
        $this->assertSame(11200, $c['items'][0]['amount']);
    }

    /** Cesta bez útrat se do rozboru nedostane — není co počítat. */
    public function test_cesta_bez_utrat_se_neposila(): void
    {
        $this->cesta('Bez útrat', now()->subDays(5), now()->subDays(3));
        $this->transakce(['amount_from' => 100]);

        $data = $this->getJson('/api/data/rozbory')->assertOk()->json('data');

        $this->assertArrayNotHasKey('TRIPCOST', $data);
    }

    /** Rozbory jiného páru se do odpovědi nedostanou. */
    public function test_rozbory_jineho_paru_se_neposilaji(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $this->transakce(['description' => 'Naše', 'amount_from' => 60, 'occurred_at' => now()->subYear()]);
        $this->transakce(['description' => 'Naše', 'amount_from' => 79, 'occurred_at' => now()]);

        foreach ([60, 79] as $i => $castka) {
            $this->transakce([
                'description' => 'Cizí', 'amount_from' => $castka,
                'occurred_at' => $i ? now() : now()->subYear(),
                'gallery_space_id' => $ciziProstor->id,
            ]);
        }

        $nazvy = collect($this->getJson('/api/data/rozbory')->assertOk()->json('data.INFL'))->pluck('name');

        $this->assertSame(['Naše'], $nazvy->all());
    }

    // ——— pomůcky ———

    private function kategorie(string $nazev): int
    {
        return DB::table('finance_categories')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'name' => $nazev,
            'kind' => 'expense',
            'is_favourite' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function partner(User $kdo): int
    {
        return DB::table('partners')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'kind' => 'person',
            'name' => $kdo->name,
            'user_id' => $kdo->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rozpocet(): int
    {
        return DB::table('budgets')->insertGetId([
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
    }

    private function transakce(array $navic = []): void
    {
        DB::table('transactions')->insert(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'type' => 'expense',
            'occurred_at' => now()->toDateString(),
            'amount_from' => 100,
            'currency_from' => 'CZK',
            'description' => 'Výdaj',
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }

    private function cesta(string $nazev, $od, $do): int
    {
        return DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'name' => $nazev,
            'start_date' => $od->toDateString(),
            'end_date' => $do->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function utrata(int $cesta, array $navic = []): void
    {
        DB::table('trip_expenses')->insert(array_merge([
            'trip_id' => $cesta,
            'created_by' => $this->adri->id,
            'title' => 'Útrata',
            'category' => 'other',
            'amount' => 100,
            'currency' => 'CZK',
            'state' => 'actual',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }
}
