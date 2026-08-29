<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Slučování zdvojených kategorií a účtů.
 *
 * Testy hlídají jedno především: **že se slitím nic neztratí.** Sloučení je nevratné
 * a jediná chyba v přepojení znamená útraty, které zmizely z historie — a nikdo si
 * toho nevšimne, protože obě čísla vypadají věrohodně.
 */
class FinanceSlucovaniTest extends TestCase
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

    /** Náhled nic nemění — jinak by se „co se stane" stalo. */
    public function test_nahled_nic_nemeni(): void
    {
        [$z, $do] = [$this->kategorie('Bydlení'), $this->kategorie('Ubytování')];
        $this->vydajVKategorii($z, 500);

        $this->postJson('/api/v1/rozpocet/sloucit', [
            'kind' => 'category', 'from' => $z->uuid, 'to' => $do->uuid,
        ])->assertOk()
            ->assertJsonPath('merged', false)
            ->assertJsonPath('preview.transactions', 1);

        $this->assertDatabaseHas('finance_categories', ['id' => $z->id, 'deleted_at' => null]);
        $this->assertSame(1, Transaction::where('category_id', $z->id)->count());
    }

    /** Transakce se přepojí, prázdná kategorie zmizí a útrata zůstane. */
    public function test_sloucena_kategorie_prenese_utraty(): void
    {
        [$z, $do] = [$this->kategorie('Bydlení'), $this->kategorie('Ubytování')];
        $this->vydajVKategorii($z, 500);
        $this->vydajVKategorii($do, 300);

        $this->postJson('/api/v1/rozpocet/sloucit', [
            'kind' => 'category', 'from' => $z->uuid, 'to' => $do->uuid, 'potvrzeno' => true,
        ])->assertOk()->assertJsonPath('merged', true);

        $this->assertSame(0, Transaction::where('category_id', $z->id)->count());
        $this->assertSame(2, Transaction::where('category_id', $do->id)->count());
        $this->assertEqualsWithDelta(800, Transaction::where('category_id', $do->id)->sum('amount_from'), 0.01);
    }

    /**
     * Limity rozpočtů se sčítají, ne přepisují.
     *
     * Když měly obě kategorie svůj limit, výsledek má pokrýt obojí. Přepsat jeden
     * druhým by tiše ubralo peníze z plánu a nikdo by nevěděl proč.
     */
    public function test_limity_se_scitaji(): void
    {
        [$z, $do] = [$this->kategorie('Bydlení'), $this->kategorie('Ubytování')];

        $rozpocet = $this->postJson('/api/v1/rozpocet/rozpocty', [
            'name' => 'Zkušební', 'budget_kind' => 'monthly', 'currency' => 'CZK', 'amount' => 20000,
            'limits' => [
                ['category_uuid' => $z->uuid, 'amount' => 4000, 'priority' => 50],
                ['category_uuid' => $do->uuid, 'amount' => 1000, 'priority' => 10],
            ],
        ])->assertCreated()->json('budget.uuid');

        $this->postJson('/api/v1/rozpocet/sloucit', [
            'kind' => 'category', 'from' => $z->uuid, 'to' => $do->uuid, 'potvrzeno' => true,
        ])->assertOk();

        $radky = collect($this->getJson('/api/v1/rozpocet/rozpocty')->json('budgets.0.allocation.rows'));

        $this->assertCount(1, $radky, 'Zůstane jedna položka, ne dvě.');
        $this->assertEqualsWithDelta(5000, $radky->first()['planned'], 0.01, 'Limity se sečetly.');
        $this->assertSame(10, $radky->first()['priority'], 'Platí vyšší důležitost, tedy nižší číslo.');

        unset($rozpocet);
    }

    /** Příjem a výdaj se sloučit nedají — sečetlo by se to, co se má odečítat. */
    public function test_prijem_a_vydaj_se_neslijou(): void
    {
        $vydaj = $this->kategorie('Bydlení');
        $prijem = FinanceCategory::where('gallery_space_id', $this->space->id)
            ->where('kind', 'income')->first();

        $this->postJson('/api/v1/rozpocet/sloucit', [
            'kind' => 'category', 'from' => $vydaj->uuid, 'to' => $prijem->uuid, 'potvrzeno' => true,
        ])->assertStatus(422);
    }

    /** Účty se slijí i s počátečními zůstatky — jinak by se ztratily peníze od začátku. */
    public function test_ucty_slijou_i_pocatecni_zustatek(): void
    {
        $z = $this->ucet('EUR karta', 'EUR', 'card', 200);
        $do = $this->ucet('Eura na kartě', 'EUR', 'bank', 300);

        $this->postJson('/api/v1/rozpocet/sloucit', [
            'kind' => 'wallet', 'from' => $z->uuid, 'to' => $do->uuid, 'potvrzeno' => true,
        ])->assertOk()->assertJsonPath('merged', true);

        $this->assertEqualsWithDelta(500, $do->fresh()->opening_balance, 0.01);
        $this->assertSoftDeleted('wallets', ['id' => $z->id]);
    }

    /** Různé měny se sečíst nedají bez kurzu, který si tenhle systém nevymýšlí. */
    public function test_ucty_v_ruznych_menach_se_neslijou(): void
    {
        $z = $this->ucet('Koruny', 'CZK', 'bank', 1000);
        $do = $this->ucet('Eura', 'EUR', 'bank', 100);

        $this->postJson('/api/v1/rozpocet/sloucit', [
            'kind' => 'wallet', 'from' => $z->uuid, 'to' => $do->uuid, 'potvrzeno' => true,
        ])->assertStatus(422);
    }

    /** Duplicity se nabídnou, nespouštějí se samy. */
    public function test_duplicity_se_nabidnou(): void
    {
        $this->ucet('EUR karta', 'EUR', 'card', 0);
        $this->ucet('Eura na kartě', 'EUR', 'bank', 0);
        $this->ucet('Eura v hotovosti', 'EUR', 'cash', 0);

        $navrhy = $this->getJson('/api/v1/rozpocet/duplicity')->assertOk()->json('wallets');

        $this->assertCount(1, $navrhy, 'Hotovost není totéž co karta, ta se nenabízí.');
        $this->assertCount(2, $navrhy[0]['wallets']);
        $this->assertSame('EUR', $navrhy[0]['currency']);
    }

    private function kategorie(string $nazev): FinanceCategory
    {
        return FinanceCategory::firstOrCreate(
            ['gallery_space_id' => $this->space->id, 'name' => $nazev, 'kind' => 'expense'],
            ['is_active' => true],
        );
    }

    private function ucet(string $nazev, string $mena, string $druh, float $pocatek): Wallet
    {
        return Wallet::create([
            'gallery_space_id' => $this->space->id, 'name' => $nazev, 'kind' => $druh,
            'currency' => $mena, 'opening_balance' => $pocatek, 'is_active' => true,
        ]);
    }

    private function vydajVKategorii(FinanceCategory $k, float $castka): void
    {
        $ucet = Wallet::where('gallery_space_id', $this->space->id)->first()
            ?? $this->ucet('CZK', 'CZK', 'bank', 10000);

        Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'expense',
            'occurred_at' => Carbon::today(),
            'wallet_from_id' => $ucet->id,
            'amount_from' => $castka, 'currency_from' => $ucet->currency,
            'amount_to' => $castka, 'currency_to' => $ucet->currency,
            'category_id' => $k->id, 'created_by' => $this->uzivatel->id,
        ]);
    }
}
