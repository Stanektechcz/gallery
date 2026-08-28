<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\GallerySpace;
use App\Models\Partner;
use App\Models\Transaction;
use App\Models\TransactionShare;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Šablony rychlého zápisu a vlastní rozdělení mezi partnery.
 */
class FinanceTemplateTest extends TestCase
{
    use RefreshDatabase;

    private User $uzivatel;
    private GallerySpace $space;
    private Wallet $ucet;
    private Partner $adri;
    private Partner $maki;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uzivatel = User::factory()->create();
        $this->space = GallerySpace::create(['name' => 'Zkouška', 'owner_id' => $this->uzivatel->id]);
        $this->uzivatel->gallerySpaces()->syncWithoutDetaching([$this->space->id => ['role' => 'owner']]);
        $this->actingAs($this->uzivatel);

        $this->ucet = Wallet::create(['gallery_space_id' => $this->space->id, 'name' => 'EUR karta',
            'kind' => 'card', 'currency' => 'EUR', 'opening_balance' => 2000, 'is_active' => true]);

        $this->adri = Partner::create(['gallery_space_id' => $this->space->id, 'kind' => 'person', 'name' => 'Adri', 'is_active' => true]);
        $this->maki = Partner::create(['gallery_space_id' => $this->space->id, 'kind' => 'person', 'name' => 'Maki', 'is_active' => true]);

        FinanceCategory::nachystej($this->space->id);
    }

    private function kategorie(string $nazev): FinanceCategory
    {
        return FinanceCategory::where('gallery_space_id', $this->space->id)->where('name', $nazev)->first();
    }

    public function test_zalozeni_sablony(): void
    {
        $odpoved = $this->postJson('/api/v1/rozpocet/sablony', [
            'name' => 'Nákup',
            'category_uuid' => $this->kategorie('Potraviny')->uuid,
            'wallet_uuid' => $this->ucet->uuid,
            'split' => 'equal',
        ])->assertCreated();

        $s = collect($odpoved->json('templates'))->firstWhere('name', 'Nákup');

        $this->assertSame('Potraviny', $s['category']['name']);
        $this->assertSame('EUR karta', $s['wallet']['name']);
        $this->assertSame('equal', $s['split']);
        $this->assertSame(0, $s['used_count']);
    }

    /** Šablona nikdy nenese částku — je to jediný údaj, který se pokaždé liší. */
    public function test_sablona_neobsahuje_castku(): void
    {
        $odpoved = $this->postJson('/api/v1/rozpocet/sablony', [
            'name' => 'MHD',
            'category_uuid' => $this->kategorie('Doprava')->uuid,
            'amount' => 2.90,   // pošle se, ale nesmí se uložit
        ])->assertCreated();

        $s = collect($odpoved->json('templates'))->firstWhere('name', 'MHD');

        $this->assertArrayNotHasKey('amount', $s);
        $this->assertSame(['uuid', 'name', 'type', 'category', 'wallet', 'payer', 'split', 'used_count'],
            array_keys($s), 'Šablona nesmí nést částku.');
    }

    /** Často používané šablony se drží nahoře. */
    public function test_poradi_podle_pouziti(): void
    {
        $this->postJson('/api/v1/rozpocet/sablony', ['name' => 'Málo používaná']);
        $casta = $this->postJson('/api/v1/rozpocet/sablony', ['name' => 'Častá'])->json('templates');

        $uuid = collect($casta)->firstWhere('name', 'Častá')['uuid'];

        foreach (range(1, 3) as $i) {
            $this->postJson("/api/v1/rozpocet/sablony/{$uuid}/pouzito")->assertOk();
        }

        $sablony = collect($this->getJson('/api/v1/rozpocet/sablony')->json('templates'));

        $this->assertSame('Častá', $sablony->first()['name']);
        $this->assertSame(3, $sablony->first()['used_count']);
    }

    public function test_smazani_sablony(): void
    {
        $uuid = $this->postJson('/api/v1/rozpocet/sablony', ['name' => 'Dočasná'])->json('templates.0.uuid');

        $odpoved = $this->deleteJson("/api/v1/rozpocet/sablony/{$uuid}")->assertOk();

        $this->assertSame([], $odpoved->json('templates'));
    }

    // ---------------------------------------------------- vlastní rozdělení

    /** Vlastní poměr 70/30 se uloží jako částky, ne jako procenta. */
    public function test_vlastni_pomer(): void
    {
        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'expense', 'occurred_at' => now()->toDateString(),
            'wallet_from' => $this->ucet->uuid, 'amount_from' => 100,
            'category' => $this->kategorie('Potraviny')->uuid,
            'split' => [
                ['partner_id' => $this->adri->id, 'amount' => 70, 'basis' => 'percent'],
                ['partner_id' => $this->maki->id, 'amount' => 30, 'basis' => 'percent'],
            ],
        ])->assertCreated();

        $podily = TransactionShare::all();

        $this->assertCount(2, $podily);
        $this->assertEqualsWithDelta(70, $podily->firstWhere('partner_id', $this->adri->id)->amount, 0.001);
        $this->assertEqualsWithDelta(30, $podily->firstWhere('partner_id', $this->maki->id)->amount, 0.001);
    }

    /**
     * Nedělitelná částka: 33,33 na třetiny.
     *
     * Zaokrouhlovací haléř musí někam padnout a musí padat pořád stejně. Kdyby se
     * přiděloval náhodně, dvě uložení téže částky by dala jiné saldo.
     */
    public function test_zaokrouhleni_je_deterministicke(): void
    {
        $castka = 33.33;
        $druhy = round($castka * 0.5, 2);          // 16,67 (PHP zaokrouhluje nahoru)
        $prvni = round($castka - $druhy, 2);       // 16,66

        foreach (range(1, 2) as $i) {
            $this->postJson('/api/v1/rozpocet/transakce', [
                'type' => 'expense', 'occurred_at' => now()->toDateString(),
                'wallet_from' => $this->ucet->uuid, 'amount_from' => $castka,
                'category' => $this->kategorie('Potraviny')->uuid,
                'potvrzeno' => true,   // druhý zápis je „duplicita", což je tady legitimní
                'split' => [
                    ['partner_id' => $this->adri->id, 'amount' => $prvni, 'basis' => 'equal'],
                    ['partner_id' => $this->maki->id, 'amount' => $druhy, 'basis' => 'equal'],
                ],
            ])->assertCreated();
        }

        // Součet podílů musí dát přesně dvojnásobek částky — ani o haléř víc.
        $this->assertEqualsWithDelta(2 * $castka, (float) TransactionShare::sum('amount'), 0.001);

        // A oba zápisy musí rozdělit stejně.
        $poTransakcich = TransactionShare::get()->groupBy('transaction_id');
        $this->assertCount(2, $poTransakcich);

        foreach ($poTransakcich as $skupina) {
            $this->assertEqualsWithDelta($prvni, $skupina->firstWhere('partner_id', $this->adri->id)->amount, 0.001);
        }
    }

    /** Rozdělení, které nedá celek, neprojde ani u vlastního poměru. */
    public function test_vlastni_pomer_musi_dat_celek(): void
    {
        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'expense', 'occurred_at' => now()->toDateString(),
            'wallet_from' => $this->ucet->uuid, 'amount_from' => 100,
            'category' => $this->kategorie('Potraviny')->uuid,
            'split' => [
                ['partner_id' => $this->adri->id, 'amount' => 70, 'basis' => 'percent'],
                ['partner_id' => $this->maki->id, 'amount' => 20, 'basis' => 'percent'],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, Transaction::count());
    }

    // ----------------------------------------------------------- filtry

    /** Rozsah částky a místo filtrují, jak mají. */
    public function test_filtr_castky_a_mista(): void
    {
        foreach ([[15, 'Drážďany'], [120, 'Berlín'], [300, 'Drážďany']] as [$c, $misto]) {
            Transaction::create([
                'gallery_space_id' => $this->space->id, 'type' => 'expense',
                'occurred_at' => now()->toDateString(), 'wallet_from_id' => $this->ucet->id,
                'amount_from' => $c, 'currency_from' => 'EUR', 'place' => $misto,
                'state' => 'approved', 'created_by' => $this->uzivatel->id,
            ]);
        }

        $velke = $this->getJson('/api/v1/rozpocet/transakce?od_castky=100')->json('transactions');
        $this->assertCount(2, $velke);

        $rozsah = $this->getJson('/api/v1/rozpocet/transakce?od_castky=100&do_castky=200')->json('transactions');
        $this->assertCount(1, $rozsah);
        $this->assertEqualsWithDelta(120, $rozsah[0]['from']['amount'], 0.001);

        $misto = $this->getJson('/api/v1/rozpocet/transakce?misto=Drážďany')->json('transactions');
        $this->assertCount(2, $misto);
    }
}
