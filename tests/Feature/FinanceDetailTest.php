<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\FinanceProject;
use App\Models\GallerySpace;
use App\Models\Partner;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Detail účtu a detail cesty.
 *
 * Vývoj zůstatku se počítá zpětně od dneška — to je místo, kde se dá snadno splést
 * o jeden pohyb a křivka pak celá sedí vedle. Test proto kontroluje konkrétní dny,
 * ne jen poslední hodnotu.
 */
class FinanceDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $uzivatel;
    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uzivatel = User::factory()->create();
        $this->space = GallerySpace::create(['name' => 'Zkouška', 'owner_id' => $this->uzivatel->id]);
        $this->uzivatel->gallerySpaces()->syncWithoutDetaching([$this->space->id => ['role' => 'owner']]);
        $this->actingAs($this->uzivatel);

        FinanceCategory::nachystej($this->space->id);
    }

    private function ucet(string $jmeno, string $mena, float $pocatek = 0, ?int $partner = null): Wallet
    {
        return Wallet::create([
            'gallery_space_id' => $this->space->id, 'name' => $jmeno, 'kind' => 'card',
            'currency' => $mena, 'opening_balance' => $pocatek, 'partner_id' => $partner, 'is_active' => true,
        ]);
    }

    private function vydaj(Wallet $z, float $castka, string $den, ?int $cesta = null, ?string $kategorie = null): Transaction
    {
        $k = $kategorie
            ? FinanceCategory::where('gallery_space_id', $this->space->id)->where('name', $kategorie)->value('id')
            : null;

        return Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'expense', 'occurred_at' => $den,
            'wallet_from_id' => $z->id, 'amount_from' => $castka, 'currency_from' => $z->currency,
            'category_id' => $k, 'finance_project_id' => $cesta,
            'state' => 'approved', 'created_by' => $this->uzivatel->id,
        ]);
    }

    /** Vývoj zůstatku musí navazovat na skutečné pohyby, den po dni. */
    public function test_vyvoj_zustatku_uctu(): void
    {
        $u = $this->ucet('EUR', 'EUR', 1000);

        $this->vydaj($u, 100, Carbon::today()->subDays(10)->toDateString());
        $this->vydaj($u, 50, Carbon::today()->subDays(3)->toDateString());

        $odpoved = $this->getJson("/api/v1/rozpocet/ucty/{$u->uuid}")->assertOk();

        $this->assertEqualsWithDelta(850, $odpoved->json('wallet.balance'), 0.001);

        $historie = collect($odpoved->json('history'))->keyBy('date');

        $this->assertCount(90, $odpoved->json('history'), 'Devadesát dnů zpátky.');

        // Před prvním výdajem byl zůstatek 1000, mezi výdaji 900, po druhém 850.
        $this->assertEqualsWithDelta(1000, $historie[Carbon::today()->subDays(11)->toDateString()]['balance'], 0.001);
        $this->assertEqualsWithDelta(900, $historie[Carbon::today()->subDays(10)->toDateString()]['balance'], 0.001);
        $this->assertEqualsWithDelta(900, $historie[Carbon::today()->subDays(4)->toDateString()]['balance'], 0.001);
        $this->assertEqualsWithDelta(850, $historie[Carbon::today()->subDays(3)->toDateString()]['balance'], 0.001);
        $this->assertEqualsWithDelta(850, $historie[Carbon::today()->toDateString()]['balance'], 0.001);
    }

    /** Poplatek placený navíc patří do vývoje taky — ze zůstatku odešel. */
    public function test_vyvoj_zapocita_poplatek(): void
    {
        $banka = $this->ucet('Banka', 'EUR', 500);
        $hotovost = $this->ucet('Hotovost', 'EUR', 0);

        Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'transfer',
            'occurred_at' => Carbon::today()->subDays(2)->toDateString(),
            'wallet_from_id' => $banka->id, 'wallet_to_id' => $hotovost->id,
            'amount_from' => 200, 'currency_from' => 'EUR',
            'amount_to' => 200, 'currency_to' => 'EUR',
            'fee_amount' => 3, 'fee_currency' => 'EUR', 'fee_included' => false,
            'state' => 'approved', 'created_by' => $this->uzivatel->id,
        ]);

        $historie = collect($this->getJson("/api/v1/rozpocet/ucty/{$banka->uuid}")->json('history'))->keyBy('date');

        $this->assertEqualsWithDelta(500, $historie[Carbon::today()->subDays(3)->toDateString()]['balance'], 0.001);
        $this->assertEqualsWithDelta(297, $historie[Carbon::today()->toDateString()]['balance'], 0.001,
            '500 − 200 převod − 3 poplatek.');
    }

    /** Pohyby účtu mají znaménko z pohledu toho účtu — u převodu je každá strana jiná. */
    public function test_smer_pohybu_podle_uctu(): void
    {
        $z = $this->ucet('Odkud', 'EUR', 500);
        $do = $this->ucet('Kam', 'EUR', 0);

        Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'transfer',
            'occurred_at' => Carbon::today()->toDateString(),
            'wallet_from_id' => $z->id, 'wallet_to_id' => $do->id,
            'amount_from' => 120, 'currency_from' => 'EUR',
            'amount_to' => 120, 'currency_to' => 'EUR',
            'state' => 'approved', 'created_by' => $this->uzivatel->id,
        ]);

        $zdroj = $this->getJson("/api/v1/rozpocet/ucty/{$z->uuid}")->json('recent.0');
        $cil = $this->getJson("/api/v1/rozpocet/ucty/{$do->uuid}")->json('recent.0');

        $this->assertSame('out', $zdroj['direction']);
        $this->assertSame('Kam', $zdroj['other_side']);
        $this->assertSame('in', $cil['direction']);
        $this->assertSame('Odkud', $cil['other_side']);
    }

    /** Souhrn účtu za období počítá jen pohyby toho účtu v tom rozsahu. */
    public function test_souhrn_uctu_za_obdobi(): void
    {
        $u = $this->ucet('EUR', 'EUR', 1000);

        $this->vydaj($u, 300, Carbon::today()->subMonths(2)->toDateString());
        $this->vydaj($u, 40, Carbon::today()->toDateString());

        $obdobi = $this->getJson("/api/v1/rozpocet/ucty/{$u->uuid}?obdobi=dnes")->json('period');

        $this->assertEqualsWithDelta(40, $obdobi['out'], 0.001);
        $this->assertSame(1, $obdobi['count']);
        $this->assertSame('Dnes', $obdobi['label']);
    }

    // ---------------------------------------------------------- detail cesty

    /** Denní vývoj cesty končí dneškem, ne koncem pobytu. */
    public function test_denni_vyvoj_cesty_konci_dneskem(): void
    {
        $u = $this->ucet('EUR', 'EUR', 2000);

        $cesta = FinanceProject::create([
            'gallery_space_id' => $this->space->id, 'kind' => 'trip', 'name' => 'Drážďany',
            'starts_on' => Carbon::today()->subDays(4)->toDateString(),
            'ends_on' => Carbon::today()->addDays(10)->toDateString(),
            'base_currency' => 'EUR', 'budget_amount' => 600,
        ]);

        $this->vydaj($u, 50, Carbon::today()->subDays(3)->toDateString(), $cesta->id, 'Potraviny');
        $this->vydaj($u, 70, Carbon::today()->toDateString(), $cesta->id, 'Doprava');

        $d = $this->getJson("/api/v1/rozpocet/cesty/{$cesta->uuid}/detail")->assertOk();

        // Pět dnů: od začátku do dneška. Ne patnáct — nuly za dny, které nebyly, by
        // srazily průměr na třetinu.
        $this->assertCount(5, $d->json('daily'));
        $this->assertSame(Carbon::today()->toDateString(), collect($d->json('daily'))->last()['date']);

        $this->assertEqualsWithDelta(120, $d->json('trip.spent'), 0.001);
        $this->assertSame(2, $d->json('transactions'));
    }

    /** Předpověď počítá tempo z uplynulých dnů, ne z délky pobytu. */
    public function test_predpoved_pocita_z_uplynulych_dnu(): void
    {
        $u = $this->ucet('EUR', 'EUR', 2000);

        // Čtrnáctidenní cesta, čtvrtý den, utraceno 200 → tempo 50/den.
        $cesta = FinanceProject::create([
            'gallery_space_id' => $this->space->id, 'kind' => 'trip', 'name' => 'Berlín',
            'starts_on' => Carbon::today()->subDays(3)->toDateString(),
            'ends_on' => Carbon::today()->addDays(10)->toDateString(),
            'base_currency' => 'EUR', 'budget_amount' => 500,
        ]);

        $this->vydaj($u, 200, Carbon::today()->subDays(1)->toDateString(), $cesta->id, 'Potraviny');

        $p = $this->getJson("/api/v1/rozpocet/cesty/{$cesta->uuid}/detail")->json('prediction');

        $this->assertSame(4, $p['days_elapsed']);
        $this->assertSame(14, $p['days_total']);
        $this->assertEqualsWithDelta(50, $p['pace'], 0.001, '200 za čtyři dny.');
        $this->assertEqualsWithDelta(700, $p['expected_total'], 0.001, '50 × 14 dní.');
        $this->assertEqualsWithDelta(-200, $p['expected_left'], 0.001, 'Při tomhle tempu se přesáhne.');
        $this->assertSame('rough', $p['quality'], 'Ze čtyř dnů je odhad jen orientační.');

        // A ví, kdy peníze dojdou: zbývá 300, tempo 50 → za 6 dní.
        $this->assertSame(Carbon::today()->addDays(6)->toDateString(), $p['runs_out_on']);
    }

    /** Spolehlivost se hlásí slovem podle počtu dnů, ne procentem. */
    public function test_kvalita_predpovedi(): void
    {
        $u = $this->ucet('EUR', 'EUR', 2000);

        foreach ([[1, 'low'], [5, 'rough'], [20, 'stable']] as [$dnu, $ocekavano]) {
            $cesta = FinanceProject::create([
                'gallery_space_id' => $this->space->id, 'kind' => 'trip', 'name' => "Cesta {$dnu}",
                'starts_on' => Carbon::today()->subDays($dnu - 1)->toDateString(),
                'ends_on' => Carbon::today()->addDays(10)->toDateString(),
                'base_currency' => 'EUR', 'budget_amount' => 1000,
            ]);

            $this->vydaj($u, 30, Carbon::today()->toDateString(), $cesta->id, 'Potraviny');

            $this->assertSame($ocekavano,
                $this->getJson("/api/v1/rozpocet/cesty/{$cesta->uuid}/detail")->json('prediction.quality'),
                "Po {$dnu} dnech.");
        }
    }

    /** Cesta, která ještě nezačala, se nepředpovídá. */
    public function test_budouci_cesta_nema_predpoved(): void
    {
        $cesta = FinanceProject::create([
            'gallery_space_id' => $this->space->id, 'kind' => 'trip', 'name' => 'Příští měsíc',
            'starts_on' => Carbon::today()->addDays(20)->toDateString(),
            'ends_on' => Carbon::today()->addDays(30)->toDateString(),
            'base_currency' => 'EUR', 'budget_amount' => 800,
        ]);

        $d = $this->getJson("/api/v1/rozpocet/cesty/{$cesta->uuid}/detail")->assertOk();

        $this->assertSame('not_started', $d->json('prediction.quality'));
        $this->assertNull($d->json('prediction.expected_total'));
        $this->assertSame([], $d->json('daily'), 'Ještě není co kreslit.');
    }

    /** Detail cesty vidí jen transakce té cesty. */
    public function test_detail_cesty_je_filtrovany(): void
    {
        $u = $this->ucet('EUR', 'EUR', 2000);

        $cesta = FinanceProject::create([
            'gallery_space_id' => $this->space->id, 'kind' => 'trip', 'name' => 'Drážďany',
            'starts_on' => Carbon::today()->subDays(2)->toDateString(),
            'ends_on' => Carbon::today()->addDays(5)->toDateString(),
            'base_currency' => 'EUR', 'budget_amount' => 400,
        ]);

        $this->vydaj($u, 60, Carbon::today()->toDateString(), $cesta->id, 'Potraviny');
        $this->vydaj($u, 999, Carbon::today()->toDateString(), null, 'Ubytování');   // mimo cestu

        $d = $this->getJson("/api/v1/rozpocet/cesty/{$cesta->uuid}/detail")->assertOk();

        $this->assertSame(1, $d->json('transactions'));
        $this->assertEqualsWithDelta(60, $d->json('trip.spent'), 0.001);
        $this->assertCount(1, $d->json('categories'));
        $this->assertSame('Potraviny', $d->json('categories.0.name'));
    }
}
