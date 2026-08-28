<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\TransactionShare;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Odeslání zápisu, který čekal na spojení.
 *
 * Klient nemusí vědět, jestli první pokus prošel — požadavek mohl dojít a odpověď se
 * cestou ztratit. Bez ochrany by se výdaj zapsal dvakrát a nikdo by nepoznal, který
 * z nich je ten skutečný.
 *
 * Odhadovat duplicitu podle částky a času nejde: dva stejné nákupy za den jsou
 * legitimní a modul je jinde výslovně povoluje. Proto klíč od klienta.
 */
class FinanceOfflineTest extends TestCase
{
    use RefreshDatabase;

    private User $uzivatel;
    private GallerySpace $space;
    private Wallet $ucet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uzivatel = User::factory()->create();
        $this->space = GallerySpace::create(['name' => 'Zkouška', 'owner_id' => $this->uzivatel->id]);
        $this->uzivatel->gallerySpaces()->syncWithoutDetaching([$this->space->id => ['role' => 'owner']]);
        $this->actingAs($this->uzivatel);

        $this->ucet = Wallet::create(['gallery_space_id' => $this->space->id, 'name' => 'EUR',
            'kind' => 'card', 'currency' => 'EUR', 'opening_balance' => 1000, 'is_active' => true]);

        FinanceCategory::nachystej($this->space->id);
    }

    private function telo(array $navic = []): array
    {
        return array_merge([
            'type' => 'expense',
            'occurred_at' => now()->toDateString(),
            'wallet_from' => $this->ucet->uuid,
            'amount_from' => 12.5,
            'category' => FinanceCategory::where('gallery_space_id', $this->space->id)
                ->where('name', 'Potraviny')->value('uuid'),
        ], $navic);
    }

    /** Dvojí odeslání téhož klíče vytvoří jeden záznam. */
    public function test_stejny_klic_nevytvori_duplicitu(): void
    {
        $klic = (string) Str::uuid();

        $prvni = $this->postJson('/api/v1/rozpocet/transakce', $this->telo(['client_key' => $klic]))
            ->assertCreated();

        $druhy = $this->postJson('/api/v1/rozpocet/transakce', $this->telo(['client_key' => $klic]))
            ->assertOk();

        $this->assertSame(1, Transaction::count(), 'Zápis smí vzniknout jen jednou.');
        $this->assertSame($prvni->json('uuid'), $druhy->json('uuid'), 'Vrací se ten původní.');
        $this->assertTrue($druhy->json('duplicate'));

        // A zůstatek se nesmí snížit dvakrát.
        $zustatek = collect($this->getJson('/api/v1/rozpocet/prehled')->json('wallets'))->firstWhere('name', 'EUR');
        $this->assertEqualsWithDelta(987.5, $zustatek['balance'], 0.001);
    }

    /**
     * Dva stejné nákupy s různým klíčem jsou dvě transakce.
     *
     * Tohle je ta hranice, kvůli které se duplicita neodhaduje z částky a času —
     * dvakrát nakoupit za totéž se dá a modul to nesmí bránit.
     */
    public function test_ruzne_klice_daji_dva_zaznamy(): void
    {
        $this->postJson('/api/v1/rozpocet/transakce', $this->telo(['client_key' => (string) Str::uuid()]))
            ->assertCreated();

        $this->postJson('/api/v1/rozpocet/transakce', $this->telo([
            'client_key' => (string) Str::uuid(),
            'potvrzeno' => true,   // druhý stejný výdaj za den vyvolá varování
        ]))->assertCreated();

        $this->assertSame(2, Transaction::count());
    }

    /** Zápis bez klíče funguje jako dřív — klíč je nepovinný. */
    public function test_zapis_bez_klice(): void
    {
        $this->postJson('/api/v1/rozpocet/transakce', $this->telo())->assertCreated();

        $this->assertSame(1, Transaction::count());
        $this->assertNull(Transaction::first()->client_key);
    }

    /** Opakování se nesmí ptát na potvrzení znovu — už jednou se odklikalo. */
    public function test_opakovani_se_neptá_znovu(): void
    {
        $klic = (string) Str::uuid();

        // První zápis projde s potvrzením.
        $this->postJson('/api/v1/rozpocet/transakce', $this->telo(['client_key' => $klic, 'potvrzeno' => true]))
            ->assertCreated();

        // Opakování bez potvrzení nesmí skončit na 409 — záznam už existuje.
        $this->postJson('/api/v1/rozpocet/transakce', $this->telo(['client_key' => $klic]))
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, Transaction::count());
    }

    /** Vrácení posledního zápisu skutečně smaže i podíly a vrátí zůstatek. */
    public function test_vraceni_zapisu(): void
    {
        $uuid = $this->postJson('/api/v1/rozpocet/transakce', $this->telo(['amount_from' => 40]))
            ->assertCreated()->json('uuid');

        $this->deleteJson("/api/v1/rozpocet/transakce/{$uuid}", ['potvrzeno' => true])->assertOk();

        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, TransactionShare::count());

        $zustatek = collect($this->getJson('/api/v1/rozpocet/prehled')->json('wallets'))->firstWhere('name', 'EUR');
        $this->assertEqualsWithDelta(1000, $zustatek['balance'], 0.001, 'Zůstatek se vrátil, kde byl.');
    }

    /**
     * Vrácený zápis se stejným klíčem jde zapsat znovu.
     *
     * Kdo klepne na „Vrátit" a pak si to rozmyslí, nesmí narazit na to, že klíč už
     * je „použitý" — smazaný záznam je pryč a klíč s ním.
     */
    public function test_po_vraceni_jde_zapsat_znovu(): void
    {
        $klic = (string) Str::uuid();

        $uuid = $this->postJson('/api/v1/rozpocet/transakce', $this->telo(['client_key' => $klic]))
            ->assertCreated()->json('uuid');

        $this->deleteJson("/api/v1/rozpocet/transakce/{$uuid}", ['potvrzeno' => true])->assertOk();

        $this->postJson('/api/v1/rozpocet/transakce', $this->telo(['client_key' => $klic]))
            ->assertCreated();

        $this->assertSame(1, Transaction::count());
    }
}
