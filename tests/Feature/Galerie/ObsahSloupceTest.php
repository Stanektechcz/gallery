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
 * Sloupce rozpočtu: měsíc, rok, vyhrazené částky a předpověď čerpání.
 *
 * Čtyři obrazovky kreslily z ukázky, přestože počítají z téhož rozpočtu jako
 * hlavička nad nimi. Dvě různá čísla o téže kategorii jsou horší než jedno.
 */
class ObsahSloupceTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    private int $rozpocet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);

        $this->rozpocet = DB::table('budgets')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'name' => 'Rozpočet',
            'currency' => 'CZK',
            'starts_on' => now()->startOfMonth()->toDateString(),
            'ends_on' => now()->endOfMonth()->toDateString(),
            'is_shared' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Bez rozpočtu není co kreslit — a ukázka zůstává. */
    public function test_bez_rozpoctu_se_sloupce_neposilaji(): void
    {
        DB::table('budgets')->where('id', $this->rozpocet)->delete();
        $this->transakce(['amount_from' => 100]);

        $data = $this->getJson('/api/data/finance')->assertOk()->json('data');

        $this->assertArrayNotHasKey('ABARS', $data);
    }

    /** Měsíční sloupec ukazuje utraceno z limitu — a nad 95 % varuje. */
    public function test_mesicni_sloupec_pocita_z_limitu(): void
    {
        $jidlo = $this->limit('Potraviny', 6000);
        $restaurace = $this->limit('Restaurace', 2000);

        $this->transakce(['amount_from' => 4210, 'category_id' => $jidlo]);
        $this->transakce(['amount_from' => 1980, 'category_id' => $restaurace]);

        $s = collect($this->getJson('/api/data/finance')->assertOk()->json('data.ABARS.bud'))->keyBy(0);

        // „ze 6 000" — předložka se řídí tím, jak se číslo čte.
        $this->assertSame('4 210 ze 6 000 Kč', $s['Potraviny'][1]);
        $this->assertSame(70, $s['Potraviny'][2]);
        $this->assertSame(0, $s['Potraviny'][3]);
        // 99 % — barva 1 je varovná.
        $this->assertSame(99, $s['Restaurace'][2]);
        $this->assertSame(1, $s['Restaurace'][3]);
    }

    /** Rok se skládá po čtvrtletích a to letošní se pozná. */
    public function test_rok_se_sklada_po_ctvrtletich(): void
    {
        $this->limit('Potraviny', 6000);
        $this->transakce(['amount_from' => 4210, 'occurred_at' => now()->toDateString()]);

        $rok = $this->getJson('/api/data/finance')->assertOk()->json('data.ABARS.year');
        $ted = (int) ceil(now()->month / 3);
        $nase = collect($rok)->firstWhere(2, 100);

        $this->assertNotNull($nase);
        $this->assertSame('4 210 Kč', $nase[1]);
        // Právě běžící čtvrtletí je zvýrazněné.
        $this->assertSame(1, $nase[3]);
        // Posílají se jen čtvrtletí, která už letos nastala.
        $this->assertCount($ted, $rok);
    }

    /** Vyhrazené částky oddělí nedotknutelné a řeknou, co zbývá volné. */
    public function test_vyhrazene_castky_ukazuji_volne(): void
    {
        $this->limit('Nájem', 18000, 5);
        $this->limit('Kultura', 1200, 90);
        $this->prijem(30000);

        $s = $this->getJson('/api/data/finance')->assertOk()->json('data.ABARS.res');

        $this->assertSame('Nedotknutelné · nájem', $s[0][0]);
        $this->assertSame('18 000 Kč', $s[0][1]);
        $this->assertSame('Vyhrazeno · zbytek plánu', $s[1][0]);
        $this->assertSame('1 200 Kč', $s[1][1]);
        $this->assertSame(['Volné', '10 800 Kč'], [$s[2][0], $s[2][1]]);
    }

    /** Předpověď říká, kolik zbývá do konce měsíce a kdo utrácí rychleji. */
    public function test_predpoved_rozdeli_kategorie_podle_tempa(): void
    {
        $rychla = $this->limit('Restaurace', 2000);
        $this->limit('Cesty', 14000);

        // Skoro celý limit hned — to je rychleji, než měsíc ubíhá.
        $this->transakce(['amount_from' => 1980, 'category_id' => $rychla]);

        $s = collect($this->getJson('/api/data/finance')->assertOk()->json('data.ABARS.fc'))->keyBy(0);

        $this->assertStringContainsString('Kč na ', $s['Do konce měsíce zbývá'][1]);
        $this->assertSame('Restaurace', $s['Čerpáno rychleji než plán'][1]);
        $this->assertSame('Cesty', $s['S rezervou'][1]);
    }

    /** Sloupce jiného páru se do odpovědi nedostanou. */
    public function test_sloupce_jineho_paru_se_neposilaji(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $nase = $this->limit('Potraviny', 6000);
        $this->transakce(['amount_from' => 4210, 'category_id' => $nase]);
        $this->transakce(['amount_from' => 9999, 'gallery_space_id' => $ciziProstor->id]);

        $s = collect($this->getJson('/api/data/finance')->assertOk()->json('data.ABARS.bud'))->keyBy(0);

        $this->assertCount(1, $s);
        $this->assertSame('4 210 ze 6 000 Kč', $s['Potraviny'][1]);
    }

    // ——— pomůcky ———

    private function limit(string $nazev, int $castka, int $priorita = 50): int
    {
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
            'budget_id' => $this->rozpocet,
            'finance_category_id' => $kategorie,
            'amount' => $castka,
            'priority' => $priorita,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $kategorie;
    }

    private function prijem(int $castka): void
    {
        DB::table('budgets')->where('id', $this->rozpocet)->update(['monthly_income' => $castka]);
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
}
