<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Výpis z banky se zapíše do knihy plateb.
 *
 * Transakce slibovaly „Import z Revolutu ústí sem", ale výpis končil jen
 * v bankovním modulu, který kniha, rozpočet ani vyrovnání nečtou.
 */
class ImportVypisuTest extends TestCase
{
    use RefreshDatabase;

    private const HLAVICKA = "Type,Product,Started Date,Completed Date,Description,Amount,Fee,Currency,State,Balance\n";

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

    public function test_vypis_se_zapise_na_ucet_jako_nezarazene_platby(): void
    {
        $ucet = $this->ucet('Společný účet');

        $odpoved = $this->nahraj($ucet, self::HLAVICKA
            ."CARD_PAYMENT,Current,2026-09-05 12:00:00,2026-09-05 14:22:10,Albert,-432.50,2.00,CZK,COMPLETED,10000\n"
            ."TRANSFER,Current,2026-09-06 08:00:00,2026-09-06 08:00:05,Výplata,42800.00,0.00,CZK,COMPLETED,52800\n"
            ."CARD_PAYMENT,Current,2026-09-07 10:00:00,2026-09-07 10:00:00,Café de Paris,-12.00,0.00,EUR,COMPLETED,50\n")
            ->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('zapsano', 2)
            ->assertJsonPath('jinaMena', 1);

        $this->assertStringContainsString('2 platby zapsány na účet Společný účet', $odpoved->json('zprava'));
        $this->assertStringContainsString('v EUR vynechány', $odpoved->json('zprava'));

        $albert = Transaction::where('description', 'Albert')->sole();
        $this->assertSame('expense', $albert->type);
        $this->assertSame($ucet->id, $albert->wallet_from_id);
        $this->assertEquals(432.5, (float) $albert->amount_from);
        $this->assertEquals(2.0, (float) $albert->fee_amount);
        $this->assertSame('2026-09-05', $albert->occurred_at->toDateString());
        $this->assertNull($albert->category_id);
        $this->assertSame('Výpis z banky', $albert->provider);

        $vyplata = Transaction::where('description', 'Výplata')->sole();
        $this->assertSame('income', $vyplata->type);
        $this->assertSame($ucet->id, $vyplata->wallet_to_id);

        // Obrazovka dostane rovnou novou knihu: platby v Importovaných i Nezařazených.
        $this->assertCount(2, $odpoved->json('data.ATX.imp.rows'));
        $this->assertCount(2, $odpoved->json('data.ATX.un.rows'));
    }

    /** Opakovaný a překrývající se výpis nic nezdvojí. */
    public function test_tentyz_radek_se_zapise_jen_jednou(): void
    {
        $ucet = $this->ucet('Společný účet');
        $prvni = "CARD_PAYMENT,Current,2026-09-05 12:00:00,2026-09-05 14:22:10,Albert,-432.50,0.00,CZK,COMPLETED,10000\n";

        $this->nahraj($ucet, self::HLAVICKA.$prvni)->assertStatus(201);
        $this->nahraj($ucet, self::HLAVICKA.$prvni)
            ->assertOk()
            ->assertJsonPath('zapsano', 0)
            ->assertJsonPath('zprava', 'Nic nového — všechno z výpisu už v knize je');

        // Delší výpis se stejným začátkem přidá jen nový řádek.
        $this->nahraj($ucet, self::HLAVICKA.$prvni
            ."CARD_PAYMENT,Current,2026-09-08 09:00:00,2026-09-08 09:00:00,Lékárna,-312.00,0.00,CZK,COMPLETED,9688\n")
            ->assertStatus(201)
            ->assertJsonPath('zapsano', 1);

        $this->assertSame(2, Transaction::where('gallery_space_id', $this->prostor->id)->count());
    }

    public function test_bez_uctu_rekne_kam_ho_zalozit(): void
    {
        $this->postJson('/api/finance/import', ['vypis' => $this->soubor(self::HLAVICKA)])
            ->assertStatus(422)
            ->assertJsonPath('zprava', 'Nejdřív založte účet — bez něj není kam platby z výpisu zapsat.');
    }

    /** Účet cizí dvojice se nedá podstrčit. */
    public function test_cizi_ucet_neprojde(): void
    {
        $this->ucet('Společný účet');
        $jina = User::factory()->create();
        $cizi = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $jina->id]);
        $jejich = Wallet::create([
            'gallery_space_id' => $cizi->id, 'name' => 'Jejich', 'kind' => 'bank', 'currency' => 'CZK', 'is_active' => true,
        ]);

        $this->nahraj($jejich, self::HLAVICKA
            ."CARD_PAYMENT,Current,2026-09-05 12:00:00,2026-09-05 14:22:10,Albert,-432.50,0.00,CZK,COMPLETED,10000\n")
            ->assertStatus(422)
            ->assertJsonPath('zprava', 'Takový účet tu není.');

        $this->assertSame(0, Transaction::count());
    }

    /** Soubor bez data a částky vrátí srozumitelnou větu, ne chybu serveru. */
    public function test_necitelny_vypis_rekne_proc(): void
    {
        $ucet = $this->ucet('Společný účet');

        $this->nahraj($ucet, "Jméno,Poznámka\nAlbert,nákup\n")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'záhlaví s datem a částkou'));
    }

    public function test_ucet_nese_identifikator_pro_import(): void
    {
        $ucet = $this->ucet('Společný účet');

        $this->getJson('/api/data/finance')->assertOk()
            ->assertJsonPath('data.FIN.accounts.0.8', $ucet->uuid)
            // Česky, ne „upraveno 0 seconds ago".
            ->assertJsonPath('data.FIN.accounts.0.5', 'upraveno právě teď');
    }

    // ——— pomůcky ———

    private function ucet(string $jmeno): Wallet
    {
        return Wallet::create([
            'gallery_space_id' => $this->prostor->id, 'name' => $jmeno,
            'kind' => 'bank', 'currency' => 'CZK', 'opening_balance' => 0, 'is_active' => true, 'sort_order' => 0,
        ]);
    }

    private function nahraj(Wallet $ucet, string $obsah): TestResponse
    {
        return $this->post('/api/finance/import', ['vypis' => $this->soubor($obsah), 'ucet' => $ucet->uuid], ['Accept' => 'application/json']);
    }

    private function soubor(string $obsah): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('revolut.csv', $obsah);
    }
}
