<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\GallerySpace;
use App\Models\Partner;
use App\Models\Transaction;
use App\Models\TransactionShare;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pevné scénáře A–K ze zadání, spočítané proti databázi.
 *
 * Každý z nich je místo, kde se dá spočítat špatně tak, že to vypadá věrohodně:
 * poplatek započtený dvakrát, směna zaúčtovaná jako příjem, saldo počítané z plátce
 * místo z podílů. Čísla jsou zadaná přesně tak, jak je zadání vyžaduje — když se
 * některé rozejde, je to chyba v modulu, ne v testu.
 */
class FinanceScenarioTest extends TestCase
{
    use RefreshDatabase;

    private User $uzivatel;
    private GallerySpace $space;
    private FinanceService $sluzba;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uzivatel = User::factory()->create();
        $this->space = GallerySpace::create(['name' => 'Zkouška', 'owner_id' => $this->uzivatel->id]);
        $this->uzivatel->gallerySpaces()->syncWithoutDetaching([$this->space->id => ['role' => 'owner']]);
        $this->actingAs($this->uzivatel);
        $this->sluzba = app(FinanceService::class);

        FinanceCategory::nachystej($this->space->id);
    }

    private function ucet(string $jmeno, string $mena, float $pocatek = 0, ?int $partner = null, string $druh = 'bank'): Wallet
    {
        return Wallet::create([
            'gallery_space_id' => $this->space->id, 'name' => $jmeno, 'kind' => $druh,
            'currency' => $mena, 'opening_balance' => $pocatek, 'partner_id' => $partner, 'is_active' => true,
        ]);
    }

    private function zustatek(string $jmeno): float
    {
        return (float) collect($this->sluzba->balances($this->space)['wallets'])
            ->firstWhere('name', $jmeno)['balance'];
    }

    private function pohyby()
    {
        return Transaction::where('gallery_space_id', $this->space->id)
            ->with(['walletFrom', 'walletTo', 'shares', 'category', 'refundOf'])->get();
    }

    private function souhrn(string $mena): array
    {
        return collect($this->sluzba->summary($this->pohyby()))->firstWhere('currency', $mena)
            ?? ['income' => 0.0, 'expense' => 0.0, 'fees' => 0.0, 'spent' => 0.0, 'net' => 0.0];
    }

    private function kategorie(string $nazev): FinanceCategory
    {
        return FinanceCategory::where('gallery_space_id', $this->space->id)->where('name', $nazev)->first();
    }

    /** Test A — jednoduchý eurový výdaj. */
    public function test_a_jednoduchy_eur_vydaj(): void
    {
        $eur = $this->ucet('EUR', 'EUR', 1000);

        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'expense', 'occurred_at' => now()->toDateString(),
            'wallet_from' => $eur->uuid, 'amount_from' => 12.5,
            'category' => $this->kategorie('Potraviny')->uuid,
        ])->assertCreated();

        $this->assertEqualsWithDelta(987.5, $this->zustatek('EUR'), 0.001);
        $this->assertEqualsWithDelta(12.5, $this->souhrn('EUR')['expense'], 0.001);
    }

    /** Test B — výběr z bankomatu s poplatkem placeným navíc. */
    public function test_b_vyber_z_bankomatu(): void
    {
        $banka = $this->ucet('EUR banka', 'EUR', 1000);
        $hotovost = $this->ucet('EUR hotovost', 'EUR', 50, null, 'cash');

        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'transfer', 'occurred_at' => now()->toDateString(),
            'wallet_from' => $banka->uuid, 'wallet_to' => $hotovost->uuid,
            'amount_from' => 200, 'amount_to' => 200,
            'fee_amount' => 2, 'fee_currency' => 'EUR', 'fee_included' => false,
        ])->assertCreated();

        $this->assertEqualsWithDelta(798, $this->zustatek('EUR banka'), 0.001);
        $this->assertEqualsWithDelta(250, $this->zustatek('EUR hotovost'), 0.001);

        $s = $this->souhrn('EUR');
        $this->assertEqualsWithDelta(0, $s['expense'], 0.001, 'Převáděná částka není výdaj.');
        $this->assertEqualsWithDelta(0, $s['income'], 0.001, 'Ani příjem.');
        $this->assertEqualsWithDelta(2, $s['spent'], 0.001, 'Spotřebou je jen poplatek.');
    }

    /** Test C — směna CZK → EUR s poplatkem navíc. */
    public function test_c_smena_s_poplatkem_navic(): void
    {
        $czk = $this->ucet('CZK', 'CZK', 100000);
        $eur = $this->ucet('EUR', 'EUR', 0);

        $uuid = $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'exchange', 'occurred_at' => now()->toDateString(),
            'wallet_from' => $czk->uuid, 'wallet_to' => $eur->uuid,
            'amount_from' => 50000, 'amount_to' => 2075,
            'fee_amount' => 120, 'fee_currency' => 'CZK', 'fee_included' => false,
        ])->assertCreated()->json('uuid');

        // Účet klesne o jistinu i poplatek: 100 000 − 50 120.
        $this->assertEqualsWithDelta(49880, $this->zustatek('CZK'), 0.001);
        $this->assertEqualsWithDelta(2075, $this->zustatek('EUR'), 0.001);

        $t = Transaction::where('uuid', $uuid)->first();
        $k = $this->sluzba->exchangeRate($t);

        $this->assertEqualsWithDelta(50120, $k['spent'], 0.001, 'Celkové náklady včetně poplatku.');
        $this->assertEqualsWithDelta(2075, $k['received'], 0.001);
        $this->assertEqualsWithDelta(24.1542, $k['effective'], 0.0001);

        $s = $this->souhrn('CZK');
        $this->assertEqualsWithDelta(0, $s['expense'], 0.001, 'Jistina není výdaj.');
        $this->assertEqualsWithDelta(120, $s['fees'], 0.001);
        $this->assertNull(collect($this->sluzba->summary($this->pohyby()))->firstWhere('currency', 'EUR'),
            'Směna nesmí vyrobit eurový příjem.');
    }

    /** Test D — poplatek už zahrnutý v odepsané částce. */
    public function test_d_poplatek_zahrnuty(): void
    {
        $czk = $this->ucet('CZK', 'CZK', 100000);
        $eur = $this->ucet('EUR', 'EUR', 0);

        $uuid = $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'exchange', 'occurred_at' => now()->toDateString(),
            'wallet_from' => $czk->uuid, 'wallet_to' => $eur->uuid,
            'amount_from' => 50000, 'amount_to' => 2075,
            'fee_amount' => 120, 'fee_currency' => 'CZK', 'fee_included' => true,
        ])->assertCreated()->json('uuid');

        // Účet klesne přesně o 50 000 — poplatek už je uvnitř a nesmí se odečíst znovu.
        $this->assertEqualsWithDelta(50000, $this->zustatek('CZK'), 0.001);

        $k = $this->sluzba->exchangeRate(Transaction::where('uuid', $uuid)->first());

        $this->assertEqualsWithDelta(50000, $k['spent'], 0.001);
        $this->assertEqualsWithDelta(24.0964, $k['effective'], 0.0001);

        // Poplatek se eviduje, ale do spotřeby nevstupuje — už je v jistině.
        $this->assertEqualsWithDelta(0, $this->souhrn('CZK')['fees'], 0.001);
        $this->assertEqualsWithDelta(120, (float) Transaction::where('uuid', $uuid)->value('fee_amount'), 0.001,
            'Analyticky zůstává zapsaný.');
    }

    /** Test E — vážený pořizovací kurz eur. */
    public function test_e_vazeny_kurz(): void
    {
        $czk = $this->ucet('CZK', 'CZK', 200000);
        $eur = $this->ucet('EUR', 'EUR', 0);

        foreach ([[24000, 1000, '2026-08-01'], [25000, 1000, '2026-08-05']] as [$z, $k, $den]) {
            Transaction::create([
                'gallery_space_id' => $this->space->id, 'type' => 'exchange', 'occurred_at' => $den,
                'wallet_from_id' => $czk->id, 'wallet_to_id' => $eur->id,
                'amount_from' => $z, 'currency_from' => 'CZK',
                'amount_to' => $k, 'currency_to' => 'EUR',
                'state' => 'approved', 'created_by' => $this->uzivatel->id,
            ]);
        }

        $p = $this->sluzba->eurAcquisition($this->space);

        $this->assertEqualsWithDelta(2000, $p['held_eur'], 0.001);
        $this->assertEqualsWithDelta(49000, $p['cost_czk'], 0.001);
        $this->assertEqualsWithDelta(24.5, $p['average_rate'], 0.0001);

        // Útrata 500 € sníží zásobu i pořizovací hodnotu poměrně, průměr zůstane.
        Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'expense', 'occurred_at' => '2026-08-10',
            'wallet_from_id' => $eur->id, 'amount_from' => 500, 'currency_from' => 'EUR',
            'state' => 'approved', 'created_by' => $this->uzivatel->id,
        ]);

        $po = $this->sluzba->eurAcquisition($this->space);

        $this->assertEqualsWithDelta(1500, $po['held_eur'], 0.001);
        $this->assertEqualsWithDelta(36750, $po['cost_czk'], 0.01, 'Zbývající pořizovací hodnota.');
        $this->assertEqualsWithDelta(24.5, $po['average_rate'], 0.0001, 'Průměr se útratou nemění.');
    }

    /** Test F — společný výdaj placený Adrim ze svého účtu. */
    public function test_f_partnersky_vydaj(): void
    {
        $adri = Partner::create(['gallery_space_id' => $this->space->id, 'kind' => 'person', 'name' => 'Adri', 'is_active' => true]);
        $maki = Partner::create(['gallery_space_id' => $this->space->id, 'kind' => 'person', 'name' => 'Maki', 'is_active' => true]);
        $ucetAdri = $this->ucet('Adri EUR', 'EUR', 1000, $adri->id);

        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'expense', 'occurred_at' => now()->toDateString(),
            'wallet_from' => $ucetAdri->uuid, 'amount_from' => 60,
            'category' => $this->kategorie('Potraviny')->uuid,
            'split' => [
                ['partner_id' => $adri->id, 'amount' => 30, 'basis' => 'equal'],
                ['partner_id' => $maki->id, 'amount' => 30, 'basis' => 'equal'],
            ],
        ])->assertCreated();

        $this->assertEqualsWithDelta(60, $this->souhrn('EUR')['expense'], 0.001);

        $saldo = $this->sluzba->partnerBalance($this->pohyby(), collect([$adri, $maki]));
        $eur = collect($saldo['by_currency'])->firstWhere('currency', 'EUR');

        $this->assertSame('Maki', $eur['settlement'][0]['from']);
        $this->assertSame('Adri', $eur['settlement'][0]['to']);
        $this->assertEqualsWithDelta(30, $eur['settlement'][0]['amount'], 0.001);
    }

    /** Test G — týž výdaj ze společného účtu nevytvoří dluh. */
    public function test_g_spolecny_ucet(): void
    {
        $adri = Partner::create(['gallery_space_id' => $this->space->id, 'kind' => 'person', 'name' => 'Adri', 'is_active' => true]);
        $maki = Partner::create(['gallery_space_id' => $this->space->id, 'kind' => 'person', 'name' => 'Maki', 'is_active' => true]);
        $spolecny = $this->ucet('Společný EUR', 'EUR', 1000);   // bez partner_id

        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'expense', 'occurred_at' => now()->toDateString(),
            'wallet_from' => $spolecny->uuid, 'amount_from' => 60,
            'category' => $this->kategorie('Potraviny')->uuid,
            'split' => [
                ['partner_id' => $adri->id, 'amount' => 30, 'basis' => 'equal'],
                ['partner_id' => $maki->id, 'amount' => 30, 'basis' => 'equal'],
            ],
        ])->assertCreated();

        $this->assertEqualsWithDelta(60, $this->souhrn('EUR')['expense'], 0.001);

        $saldo = $this->sluzba->partnerBalance($this->pohyby(), collect([$adri, $maki]));
        $eur = collect($saldo['by_currency'])->firstWhere('currency', 'EUR');

        $this->assertSame([], $eur['settlement'], 'Ze společného účtu dluh nevzniká.');
    }

    /** Test H — refundace. */
    public function test_h_refundace(): void
    {
        $eur = $this->ucet('EUR', 'EUR', 1000);
        $potraviny = $this->kategorie('Potraviny');

        $vydaj = $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'expense', 'occurred_at' => now()->toDateString(),
            'wallet_from' => $eur->uuid, 'amount_from' => 50,
            'category' => $potraviny->uuid,
        ])->assertCreated()->json('uuid');

        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'income', 'occurred_at' => now()->toDateString(),
            'wallet_to' => $eur->uuid, 'amount_to' => 20,
            'refund_of' => $vydaj,
        ])->assertCreated();

        // Na účtu se 20 € vrátilo.
        $this->assertEqualsWithDelta(970, $this->zustatek('EUR'), 0.001);

        // Čisté čerpání kategorie je 30 €.
        $rozpad = collect($this->sluzba->byCategory($this->pohyby(), 'EUR'))->firstWhere('name', 'Potraviny');
        $this->assertEqualsWithDelta(30, $rozpad['amount'], 0.001);
        $this->assertEqualsWithDelta(50, $rozpad['gross'], 0.001);
        $this->assertEqualsWithDelta(20, $rozpad['refunded'], 0.001);

        // Oba záznamy zůstávají dohledatelné.
        $this->assertSame(2, Transaction::count());
    }

    /** Test I — bezpečně na den. */
    public function test_i_bezpecne_na_den(): void
    {
        // Deset dní zbývá včetně dneška: od dneška do dneška+9.
        $b = $this->sluzba->safeDaily(
            1000, 200, 100,
            Carbon::today()->subDays(5), Carbon::today()->addDays(9), Carbon::today(),
        );

        $this->assertSame('ok', $b['state']);
        $this->assertSame(10, $b['days_left']);
        $this->assertEqualsWithDelta(70, $b['per_day'], 0.001, '(1000 − 200 − 100) / 10');
    }

    /** Test J — vlastní rozdělení 70/30, platí Maki. */
    public function test_j_vlastni_rozdeleni(): void
    {
        $adri = Partner::create(['gallery_space_id' => $this->space->id, 'kind' => 'person', 'name' => 'Adri', 'is_active' => true]);
        $maki = Partner::create(['gallery_space_id' => $this->space->id, 'kind' => 'person', 'name' => 'Maki', 'is_active' => true]);
        $ucetMaki = $this->ucet('Maki EUR', 'EUR', 1000, $maki->id);

        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'expense', 'occurred_at' => now()->toDateString(),
            'wallet_from' => $ucetMaki->uuid, 'amount_from' => 100,
            'category' => $this->kategorie('Potraviny')->uuid,
            'split' => [
                ['partner_id' => $adri->id, 'amount' => 70, 'basis' => 'percent'],
                ['partner_id' => $maki->id, 'amount' => 30, 'basis' => 'percent'],
            ],
        ])->assertCreated();

        $saldo = $this->sluzba->partnerBalance($this->pohyby(), collect([$adri, $maki]));
        $eur = collect($saldo['by_currency'])->firstWhere('currency', 'EUR');

        $this->assertSame('Adri', $eur['settlement'][0]['from']);
        $this->assertSame('Maki', $eur['settlement'][0]['to']);
        $this->assertEqualsWithDelta(70, $eur['settlement'][0]['amount'], 0.001);
    }

    /** Test K — editace starší směny přepočítá navazující historii. */
    public function test_k_editace_starsi_smeny(): void
    {
        $czk = $this->ucet('CZK', 'CZK', 200000);
        $eur = $this->ucet('EUR', 'EUR', 0);

        $prvni = $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'exchange', 'occurred_at' => '2026-08-01',
            'wallet_from' => $czk->uuid, 'wallet_to' => $eur->uuid,
            'amount_from' => 24000, 'amount_to' => 1000,
        ])->assertCreated()->json('uuid');

        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'exchange', 'occurred_at' => '2026-08-05',
            'wallet_from' => $czk->uuid, 'wallet_to' => $eur->uuid,
            'amount_from' => 25000, 'amount_to' => 1000,
        ])->assertCreated();

        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'expense', 'occurred_at' => '2026-08-10',
            'wallet_from' => $eur->uuid, 'amount_from' => 500,
            'category' => $this->kategorie('Potraviny')->uuid,
        ])->assertCreated();

        $this->assertEqualsWithDelta(24.5, $this->sluzba->eurAcquisition($this->space)['average_rate'], 0.0001);
        $this->assertEqualsWithDelta(1500, $this->zustatek('EUR'), 0.001);

        // Oprava první směny: přišlo 1 200 €, ne 1 000.
        $this->patchJson("/api/v1/rozpocet/transakce/{$prvni}", [
            'type' => 'exchange', 'occurred_at' => '2026-08-01',
            'wallet_from' => $czk->uuid, 'wallet_to' => $eur->uuid,
            'amount_from' => 24000, 'amount_to' => 1200,
            'potvrzeno' => true,
        ])->assertOk();

        // Zůstatek se posunul o dvě stě eur, ne o dvojnásobek.
        $this->assertEqualsWithDelta(1700, $this->zustatek('EUR'), 0.001);
        $this->assertEqualsWithDelta(151000, $this->zustatek('CZK'), 0.001);

        // Kurz první směny je teď 20,00 a průměr se přepočítal.
        $k = $this->sluzba->exchangeRate(Transaction::where('uuid', $prvni)->first());
        $this->assertEqualsWithDelta(20.0, $k['effective'], 0.0001);

        // Pořízeno 2 200 € za 49 000 Kč, utraceno 500 € → zbývá 1 700 €.
        $p = $this->sluzba->eurAcquisition($this->space);
        $this->assertEqualsWithDelta(1700, $p['held_eur'], 0.001);
        $this->assertEqualsWithDelta(22.2727, $p['average_rate'], 0.001, '49 000 / 2 200');

        // A nevznikl duplicitní pohyb.
        $this->assertSame(3, Transaction::count());
    }

    /**
     * Proklik z kategorie musí najít právě ty transakce, ze kterých to číslo vzniklo.
     *
     * Přehled dřív posílal číselné `category_id`, ale filtr hledá podle `uuid` — seznam
     * vyšel prázdný s hláškou „tomuhle výběru nic neodpovídá", která zněla úplně
     * věrohodně. Test proto jde stejnou cestou jako obrazovka: vezme uuid z rozpadu
     * a pošle ho do filtru.
     */
    public function test_proklik_z_kategorie_najde_sve_transakce(): void
    {
        $eur = $this->ucet('EUR', 'EUR', 1000);

        foreach ([[40, 'Potraviny'], [25, 'Potraviny'], [60, 'Doprava']] as [$c, $k]) {
            $this->postJson('/api/v1/rozpocet/transakce', [
                'type' => 'expense', 'occurred_at' => now()->toDateString(),
                'wallet_from' => $eur->uuid, 'amount_from' => $c,
                'category' => $this->kategorie($k)->uuid, 'potvrzeno' => true,
            ])->assertCreated();
        }

        $rozpad = collect($this->getJson('/api/v1/rozpocet/prehled?obdobi=dnes')->json('categories'))
            ->firstWhere('name', 'Potraviny');

        $this->assertNotNull($rozpad['category_uuid'], 'Rozpad musí nést uuid, jinak se proklik nemá čím filtrovat.');

        $nalezene = $this->getJson("/api/v1/rozpocet/transakce?obdobi=dnes&kategorie={$rozpad['category_uuid']}")->json();

        $this->assertSame(2, $nalezene['found'], 'Proklik najde právě ty dvě transakce.');
        $this->assertEqualsWithDelta($rozpad['amount'],
            collect($nalezene['summary'])->firstWhere('currency', 'EUR')['spent'], 0.001,
            'A jejich součet sedí s číslem v grafu.');
    }

    /** Konzistence: součet transakcí musí sedět s přehledem pro totéž období. */
    public function test_konzistence_prehled_versus_transakce(): void
    {
        $eur = $this->ucet('EUR', 'EUR', 2000);

        foreach ([[40, 'Potraviny'], [65, 'Doprava'], [12, 'Potraviny']] as [$c, $k]) {
            $this->postJson('/api/v1/rozpocet/transakce', [
                'type' => 'expense', 'occurred_at' => now()->toDateString(),
                'wallet_from' => $eur->uuid, 'amount_from' => $c,
                'category' => $this->kategorie($k)->uuid, 'potvrzeno' => true,
            ])->assertCreated();
        }

        $prehled = $this->getJson('/api/v1/rozpocet/prehled?obdobi=dnes')->json();
        $transakce = $this->getJson('/api/v1/rozpocet/transakce?obdobi=dnes')->json();
        $statistiky = $this->getJson('/api/v1/rozpocet/statistiky?obdobi=dnes')->json();

        $zPrehledu = collect($prehled['summary'])->firstWhere('currency', 'EUR')['spent'];
        $zTransakci = collect($transakce['summary'])->firstWhere('currency', 'EUR')['spent'];
        $zeStatistik = $statistiky['summary']['expense'];
        $zKategorii = collect($prehled['categories'])->sum('amount');

        $this->assertEqualsWithDelta(117, $zPrehledu, 0.001);
        $this->assertEqualsWithDelta($zPrehledu, $zTransakci, 0.001, 'Přehled a Transakce musí sedět.');
        $this->assertEqualsWithDelta($zPrehledu, $zeStatistik, 0.001, 'A Statistiky taky.');
        $this->assertEqualsWithDelta($zPrehledu, $zKategorii, 0.001, 'Součet kategorií je týž součet.');
    }
}
