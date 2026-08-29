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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Statistiky.
 *
 * Testuje hlavně to, co má statistika **odmítnout** spočítat: srovnání bez
 * srovnatelného základu, procenta z drobných částek, měny sečtené do jednoho grafu.
 * Špatný poznatek zní stejně věrohodně jako správný, takže se nesmí objevit vůbec.
 */
class FinanceStatsTest extends TestCase
{
    use RefreshDatabase;

    private User $uzivatel;
    private GallerySpace $space;
    private Wallet $eur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uzivatel = User::factory()->create();
        $this->space = GallerySpace::create(['name' => 'Zkouška', 'owner_id' => $this->uzivatel->id]);
        $this->uzivatel->gallerySpaces()->syncWithoutDetaching([$this->space->id => ['role' => 'owner']]);
        $this->actingAs($this->uzivatel);

        $this->eur = Wallet::create(['gallery_space_id' => $this->space->id, 'name' => 'EUR', 'kind' => 'card',
            'currency' => 'EUR', 'opening_balance' => 5000, 'is_active' => true]);

        FinanceCategory::nachystej($this->space->id);
    }

    private function vydaj(float $castka, string $den, ?string $kategorie = null, ?Wallet $ucet = null): Transaction
    {
        $u = $ucet ?? $this->eur;
        $k = $kategorie ? FinanceCategory::where('gallery_space_id', $this->space->id)->where('name', $kategorie)->first() : null;

        return Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'expense', 'occurred_at' => $den,
            'wallet_from_id' => $u->id, 'amount_from' => $castka, 'currency_from' => $u->currency,
            'category_id' => $k?->id, 'state' => 'approved', 'created_by' => $this->uzivatel->id,
        ]);
    }

    public function test_souhrn_a_prumer_na_den(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20'));

        $this->vydaj(100, '2026-08-05', 'Potraviny');
        $this->vydaj(200, '2026-08-10', 'Doprava');

        Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'income', 'occurred_at' => '2026-08-02',
            'wallet_to_id' => $this->eur->id, 'amount_to' => 500, 'currency_to' => 'EUR', 'currency_from' => 'EUR',
            'state' => 'approved', 'created_by' => $this->uzivatel->id,
        ]);

        $s = $this->getJson('/api/v1/rozpocet/statistiky')->assertOk();

        $this->assertEqualsWithDelta(500, $s->json('summary.income'), 0.001);
        $this->assertEqualsWithDelta(300, $s->json('summary.expense'), 0.001);
        $this->assertEqualsWithDelta(200, $s->json('summary.net'), 0.001);
        /*
         * 300 za dvacet uběhlých dní, ne za celý srpen.
         *
         * Dělit délkou období znamená tvářit se, že jedenáct budoucích dnů už proběhlo
         * s nulovou útratou. Průměr pak vychází nižší, než jaký doopravdy je, a do
         * pravdy se dostane až poslední den v měsíci — kdy už je k ničemu.
         */
        $this->assertEqualsWithDelta(15.0, $s->json('summary.per_day'), 0.01);
        $this->assertSame(20, $s->json('summary.days_elapsed'));

        // Skončené období se počítá celé.
        Carbon::setTestNow(Carbon::parse('2026-09-15'));
        $minuly = $this->getJson('/api/v1/rozpocet/statistiky?obdobi=minuly-mesic')->assertOk();
        $this->assertSame(31, $minuly->json('summary.days_elapsed'));

        Carbon::setTestNow();
    }

    /** Krok grafu se volí podle délky období — 365 sloupců se nedá číst. */
    public function test_krok_grafu_podle_delky_obdobi(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20'));
        $this->vydaj(50, '2026-08-05');

        $this->assertSame('den', $this->getJson('/api/v1/rozpocet/statistiky?obdobi=mesic')->json('flow.step'));

        $dlouhe = $this->getJson('/api/v1/rozpocet/statistiky?obdobi=vlastni&od=2025-01-01&do=2026-08-20');
        $this->assertSame('mesic', $dlouhe->json('flow.step'));

        $stredni = $this->getJson('/api/v1/rozpocet/statistiky?obdobi=vlastni&od=2026-05-01&do=2026-08-20');
        $this->assertSame('tyden', $stredni->json('flow.step'));

        Carbon::setTestNow();
    }

    /** Měny se nesčítají — každá má vlastní řádek a graf jednu z nich. */
    public function test_meny_se_neslucuji(): void
    {
        $czk = Wallet::create(['gallery_space_id' => $this->space->id, 'name' => 'CZK', 'kind' => 'bank',
            'currency' => 'CZK', 'opening_balance' => 50000, 'is_active' => true]);

        $this->vydaj(100, now()->toDateString(), 'Potraviny');
        $this->vydaj(2000, now()->toDateString(), 'Potraviny', $czk);

        $s = $this->getJson('/api/v1/rozpocet/statistiky')->assertOk();

        $this->assertCount(2, $s->json('by_currency'));
        $this->assertContains('EUR', $s->json('currencies'));
        $this->assertContains('CZK', $s->json('currencies'));

        // Graf počítá jen zvolenou měnu.
        $vEurech = $this->getJson('/api/v1/rozpocet/statistiky?mena=EUR');
        $this->assertEqualsWithDelta(100, $vEurech->json('summary.expense'), 0.001);

        $vKorunach = $this->getJson('/api/v1/rozpocet/statistiky?mena=CZK');
        $this->assertEqualsWithDelta(2000, $vKorunach->json('summary.expense'), 0.001);
    }

    /** Poznatek vznikne jen ze srovnatelných čísel. */
    public function test_postrehy_jen_ze_srovnatelnych_dat(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20'));

        // Minulý měsíc 100, tenhle 150 → nárůst o 50 %, to je poznatek.
        $this->vydaj(100, '2026-07-10', 'Restaurace a kavárny');
        $this->vydaj(150, '2026-08-10', 'Restaurace a kavárny');

        // Drobné částky se nesrovnávají — z 5 na 10 je „o sto procent" a neznamená nic.
        $this->vydaj(5, '2026-07-11', 'Doprava');
        $this->vydaj(10, '2026-08-11', 'Doprava');

        // Kategorie, která minule neexistovala, taky ne.
        $this->vydaj(300, '2026-08-12', 'Ubytování');

        $postrehy = collect($this->getJson('/api/v1/rozpocet/statistiky?obdobi=vlastni&od=2026-08-01&do=2026-08-31')->json('insights'));

        $this->assertCount(1, $postrehy, 'Jen jeden poznatek je poctivě srovnatelný.');

        // Název kategorie stojí samostatně, ne uvnitř věty — „Za doprava" by po
        // předložce potřebovalo čtvrtý pád, který se z názvu odvodit nedá.
        $this->assertSame('Restaurace a kavárny', $postrehy->first()['category']);
        $this->assertSame('o 50 % víc než minule', $postrehy->first()['text']);
        $this->assertSame('up', $postrehy->first()['direction']);

        // Částky jsou čísla; formátuje je obrazovka, aby z nich byla značka měny.
        $this->assertEqualsWithDelta(150, $postrehy->first()['now'], 0.001);
        $this->assertEqualsWithDelta(100, $postrehy->first()['before'], 0.001);

        Carbon::setTestNow();
    }

    public function test_bez_minuleho_obdobi_zadne_postrehy(): void
    {
        $this->vydaj(200, now()->toDateString(), 'Potraviny');

        $this->assertSame([], $this->getJson('/api/v1/rozpocet/statistiky')->json('insights'),
            'Bez čeho srovnávat se nesrovnává.');
    }

    /** Největší výdaje jsou seřazené a nesou odkaz na svůj záznam. */
    public function test_nejvetsi_vydaje(): void
    {
        $this->vydaj(30, now()->toDateString(), 'Potraviny');
        $this->vydaj(310, now()->toDateString(), 'Ubytování');
        $this->vydaj(90, now()->toDateString(), 'Doprava');

        $nejvetsi = collect($this->getJson('/api/v1/rozpocet/statistiky')->json('largest'));

        $this->assertEqualsWithDelta(310, $nejvetsi->first()['amount'], 0.001);
        $this->assertSame('Ubytování', $nejvetsi->first()['category']);
        $this->assertNotNull($nejvetsi->first()['uuid'], 'Musí jít dohledat.');
    }

    /** Rozdělení partnerů odlišuje „zaplatil" od „nesl". */
    public function test_rozdeleni_partneru(): void
    {
        $adri = Partner::create(['gallery_space_id' => $this->space->id, 'kind' => 'person', 'name' => 'Adri', 'is_active' => true]);
        $maki = Partner::create(['gallery_space_id' => $this->space->id, 'kind' => 'person', 'name' => 'Maki', 'is_active' => true]);

        $ucetAdri = Wallet::create(['gallery_space_id' => $this->space->id, 'name' => 'Adri EUR', 'kind' => 'card',
            'currency' => 'EUR', 'opening_balance' => 1000, 'partner_id' => $adri->id, 'is_active' => true]);

        $t = $this->vydaj(100, now()->toDateString(), 'Potraviny', $ucetAdri);

        foreach ([$adri, $maki] as $p) {
            TransactionShare::create(['transaction_id' => $t->id, 'partner_id' => $p->id,
                'amount' => 50, 'currency' => 'EUR', 'basis' => 'equal']);
        }

        $r = $this->getJson('/api/v1/rozpocet/statistiky')->json('partners');

        $a = collect($r['partners'])->firstWhere('name', 'Adri');
        $this->assertEqualsWithDelta(100, $a['paid'], 0.001, 'Adri zaplatil celou stovku.');
        $this->assertEqualsWithDelta(50, $a['owes'], 0.001, 'Ale nese jen polovinu.');

        $this->assertEqualsWithDelta(100, $r['shared'], 0.001, 'Je to společný výdaj.');
        $this->assertSame('Maki', $r['settlement'][0]['from']);
    }

    /** Filtr platí pro celou statistiku najednou. */
    public function test_filtr_plati_pro_vsechno(): void
    {
        $this->vydaj(500, now()->subMonths(2)->toDateString(), 'Potraviny');
        $this->vydaj(40, now()->toDateString(), 'Doprava');

        $s = $this->getJson('/api/v1/rozpocet/statistiky?obdobi=dnes')->assertOk();

        $this->assertEqualsWithDelta(40, $s->json('summary.expense'), 0.001);
        $this->assertCount(1, $s->json('categories'));
        $this->assertCount(1, $s->json('largest'));
        $this->assertSame(1, $s->json('transactions'));
    }
}
