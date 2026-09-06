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
 * Čtyři záložky obrazovky Transakce.
 *
 * Vše, Nezařazené, Opakované a Import kreslily z ukázky — dvojice tak
 * v „Opakovaných" viděla tři platby, které nikdy nenastavila.
 */
class ObsahTransakceZalozkyTest extends TestCase
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

    /** Bez financí zůstává ukázka — prázdná obrazovka vypadá jako rozbitá. */
    public function test_bez_financi_se_zalozky_neposilaji(): void
    {
        $this->assertSame([], $this->getJson('/api/data/finance')->assertOk()->json('data'));
    }

    /** Řádek nese den, popis, kategorii a částku se znaménkem. */
    public function test_radek_nese_castku_se_znamenkem(): void
    {
        $jidlo = $this->kategorie('Potraviny');

        $this->transakce(['description' => 'Albert · velký nákup', 'amount_from' => 1284, 'category_id' => $jidlo, 'occurred_at' => '2026-08-29']);
        $this->transakce(['description' => 'Výplata Adrian', 'amount_from' => 42800, 'type' => 'income', 'occurred_at' => '2026-08-27']);

        $z = $this->getJson('/api/data/finance')->assertOk()->json('data.ATX.all');

        $this->assertSame(['29. 8.', 'Albert · velký nákup', 'Potraviny', '−1 284 Kč'], $z['rows'][0]);
        $this->assertSame(['27. 8.', 'Výplata Adrian', 'Nezařazeno', '+42 800 Kč'], $z['rows'][1]);
        $this->assertSame('2 transakce · srpen 2026', $z['foot']);
        $this->assertSame('+41 516 Kč', $z['sum']);
    }

    /** Nezařazená je ta bez kategorie — a záložka řekne, kolik jich je. */
    public function test_nezarazene_bere_transakce_bez_kategorie(): void
    {
        $this->transakce(['description' => 'Platba kartou', 'amount_from' => 1240]);
        $this->transakce(['description' => 'Kavárna', 'amount_from' => 186, 'category_id' => $this->kategorie('Restaurace')]);

        $z = $this->getJson('/api/data/finance')->assertOk()->json('data.ATX.un');

        $this->assertCount(1, $z['rows']);
        $this->assertSame('Platba kartou', $z['rows'][0][1]);
        $this->assertSame('1 nezařazená transakce — zařaďte je', $z['foot']);
    }

    /** Když je všechno zařazené, záložka to řekne — a nespadne na ukázku. */
    public function test_prazdna_zalozka_se_posila_prazdna(): void
    {
        $this->transakce(['amount_from' => 100, 'category_id' => $this->kategorie('Potraviny')]);

        $data = $this->getJson('/api/data/finance')->assertOk()->json('data.ATX');

        $this->assertSame([], $data['un']['rows']);
        $this->assertSame('Všechno je zařazené', $data['un']['foot']);
        $this->assertSame('', $data['un']['sum']);
        $this->assertSame('Žádná opakovaná platba', $data['rec']['foot']);
        $this->assertSame('Zatím nic naimportováno', $data['imp']['foot']);
    }

    /** Opakovaná platba se pozná podle vazby na předpis a řekne to i u kategorie. */
    public function test_opakovana_platba_ma_u_kategorie_periodu(): void
    {
        $predpis = $this->predpis('Revolut · převod na spoření');

        $this->transakce([
            'description' => 'Revolut · převod na spoření',
            'amount_from' => 4000,
            'category_id' => $this->kategorie('Spoření'),
            'recurring_id' => $predpis,
        ]);
        $this->transakce(['description' => 'Kavárna', 'amount_from' => 186]);

        $z = $this->getJson('/api/data/finance')->assertOk()->json('data.ATX.rec');

        $this->assertCount(1, $z['rows']);
        $this->assertSame('Spoření · měsíčně', $z['rows'][0][2]);
        $this->assertSame('1 opakovaná platba', $z['foot']);
    }

    /** Import řekne, odkud přišel a kdy se naposledy synchronizovalo. */
    public function test_import_rekne_odkud_a_kdy(): void
    {
        DB::table('bank_connections')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'connected_by' => $this->adri->id,
            'provider' => 'revolut',
            'institution_name' => 'Revolut',
            'status' => 'active',
            'sync_enabled' => true,
            'last_synced_at' => today()->setTime(8, 14),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->transakce(['description' => 'Rohlík.cz', 'amount_from' => 1640, 'provider' => 'Revolut']);
        $this->transakce(['description' => 'Ručně zapsáno', 'amount_from' => 100]);

        $z = $this->getJson('/api/data/finance')->assertOk()->json('data.ATX.imp');

        $this->assertCount(1, $z['rows']);
        $this->assertSame('Revolut · import', $z['rows'][0][2]);
        $this->assertSame('1 importovaná · sync dnes 8:14', $z['foot']);
    }

    /** Záložky jsou úplné — server je dodává celé. */
    public function test_zalozky_jsou_uplne(): void
    {
        $this->transakce(['amount_from' => 100]);

        $this->assertContains('ATX', $this->getJson('/api/data/finance')->assertOk()->json('uplne'));
    }

    /**
     * Rozpočet bez jediné transakce záložky **neposílá**.
     *
     * Jsou úplná kolekce, takže prázdné by u klienta smazaly i ukázkové —
     * a obrazovka, která čte `ATX[key] || ATX.all` bez pojistky, spadne.
     */
    public function test_bez_transakci_se_zalozky_neposilaji(): void
    {
        DB::table('budgets')->insert([
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

        $odpoved = $this->getJson('/api/data/finance')->assertOk();

        $this->assertArrayNotHasKey('ATX', $odpoved->json('data'));
        $this->assertSame([], $odpoved->json('uplne'));
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

    private function predpis(string $nazev): int
    {
        return DB::table('finance_recurring')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'name' => $nazev,
            'type' => 'expense',
            'amount' => 4000,
            'currency' => 'CZK',
            'day_of_month' => 28,
            'starts_on' => now()->subYear()->toDateString(),
            'created_by' => $this->adri->id,
            'is_active' => true,
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
            'occurred_at' => '2026-08-29',
            'amount_from' => 100,
            'currency_from' => 'CZK',
            'description' => 'Výdaj',
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }
}
