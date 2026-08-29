<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\FinanceProject;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Rady, co s penězi dělat.
 *
 * Testy hlídají hlavně to, kdy rada **nevznikne**. Vymyšlená rada je horší než žádná:
 * člověk se podle ní zařídí stejně jistě jako podle správné, jen se mýlí. Druhá půlka
 * je pořadí — nahoře musí být to, co nejvíc rozhoduje, jestli peníze vydrží.
 */
class FinanceStrategieTest extends TestCase
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

        $this->ucet = Wallet::create([
            'gallery_space_id' => $this->space->id, 'name' => 'EUR', 'kind' => 'card',
            'currency' => 'EUR', 'opening_balance' => 10000, 'is_active' => true,
        ]);

        FinanceCategory::nachystej($this->space->id);
    }

    /** Bez zapsaných útrat se neradí — není z čeho. */
    public function test_bez_utrat_zadne_rady_o_tempu(): void
    {
        $this->rozpocet(1000, rezerva: 100);

        $klice = collect($this->rady())->pluck('key');

        $this->assertFalse($klice->contains('tempo'), 'Tempo bez útrat nejde odvodit.');
        $this->assertFalse($klice->contains('dojdou'));
    }

    /** Rozpočet bez rezervy dostane radu, aby ji měl — s konkrétní částkou. */
    public function test_chybejici_rezerva_se_pripomene(): void
    {
        $this->rozpocet(1000, rezerva: 0);

        $rada = collect($this->rady())->firstWhere('key', 'bez-rezervy');

        $this->assertNotNull($rada);
        $this->assertEqualsWithDelta(50, $rada['amount'], 0.01, 'Doporučuje se pět procent.');
        $this->assertStringContainsString('50,00', $rada['text'], 'Rada nese číslo, ne jen výzvu.');
    }

    /** S rezervou se o ní mlčí — rada, která nic neřeší, jen zabírá místo. */
    public function test_s_rezervou_se_o_ni_mlci(): void
    {
        $this->rozpocet(1000, rezerva: 100);

        $this->assertNull(collect($this->rady())->firstWhere('key', 'bez-rezervy'));
    }

    /**
     * Rychlé utrácení se pozná a řekne se, kdy peníze dojdou.
     *
     * Datum je konkrétnější než procento: „vyčerpáno na 78 %" si nikdo nepřevede na
     * „do konce ledna to nedáme", i když je to totéž.
     */
    public function test_pri_rychlem_tempu_varuje_ze_penize_nedojdou(): void
    {
        // Delší období, ať je co nevydržet: v běžícím měsíci zbývá pár dní a na ty
        // peníze stačí skoro vždycky.
        $this->rozpocetNaCestu(1000);
        $cesta = FinanceProject::where('name', 'Zkušební cesta')->value('id');

        foreach (range(1, 8) as $i) {
            $this->vydaj('Potraviny', 100, Carbon::today()->startOfMonth()->addDays($i - 1), $cesta);
        }

        $rady = collect($this->rady());
        $varovani = $rady->firstWhere('key', 'dojdou');

        $this->assertNotNull($varovani, 'Při tomhle tempu peníze do konce nevydrží.');
        $this->assertSame('spatne', $varovani['tone']);
        $this->assertSame('dojdou', $rady->first()['key'], 'Nejdůležitější rada je nahoře.');
    }

    /**
     * Pevné platby se poznají a řekne se, kolik zbývá na všechno ostatní.
     *
     * Nájem se nedá ušetřit chytrým nakupováním; jediná páka je levnější bydlení.
     * Bez téhle rady lidi šetří na rohlících, zatímco problém je jinde.
     */
    public function test_velky_podil_pevnych_plateb_se_rekne(): void
    {
        $uuid = $this->rozpocet(1000, rezerva: 0);

        $this->limit($uuid, 'Bydlení', 600, priorita: 10);
        $this->limit($uuid, 'Potraviny', 400, priorita: 20);
        $this->vydaj('Bydlení', 600, Carbon::today()->startOfMonth());

        $rada = collect($this->rady())->firstWhere('key', 'pevne-naklady');

        $this->assertNotNull($rada);
        $this->assertStringContainsString('60 %', $rada['title']);
        $this->assertStringContainsString('levnější bydlení', $rada['text']);
    }

    /** Rad je nejvýš šest — seznam, který nikdo nedočte, je k ničemu. */
    public function test_rad_neni_vic_nez_sest(): void
    {
        $uuid = $this->rozpocet(1000, rezerva: 0);
        $this->limit($uuid, 'Bydlení', 800, priorita: 10);

        foreach (range(1, 10) as $i) {
            $this->vydaj('Potraviny', 60, Carbon::today()->startOfMonth()->addDays($i - 1));
        }

        $this->assertLessThanOrEqual(6, count($this->rady()));
    }

    /**
     * Graf čerpání nekreslí skutečnost do budoucna.
     *
     * Táhnout ji dál než ke dnešku by znamenalo tvrdit, že se od zítřka neutratí nic —
     * nejhorší možná lež v rozpočtu, protože vypadá optimisticky.
     */
    public function test_prubeh_konci_dneskem(): void
    {
        $this->rozpocet(1000, rezerva: 0);
        $this->vydaj('Potraviny', 100, Carbon::today()->startOfMonth());

        $prubeh = $this->getJson('/api/v1/rozpocet/rozpocty')->json('budgets.0.burndown');
        $body = collect($prubeh['points']);

        $this->assertGreaterThan(1, $body->count());
        $this->assertNotNull($body->first()['spent']);
        $this->assertNull($body->last()['spent'], 'Poslední den období je v budoucnu — skutečnost tam není.');

        // Plán je rovná čára od nuly k celé částce, ne předpověď.
        $this->assertEqualsWithDelta(1000, $body->last()['plan'], 1.0);
    }

    /** @return array<int, array<string, mixed>> */
    private function rady(): array
    {
        return $this->getJson('/api/v1/rozpocet/rozpocty')->assertOk()->json('budgets.0.advice');
    }

    /** Rozpočet na cestu, která běží od začátku měsíce a končí až za dva měsíce. */
    private function rozpocetNaCestu(float $castka): string
    {
        $cesta = $this->postJson('/api/v1/rozpocet/cesty', [
            'name' => 'Zkušební cesta',
            'starts_on' => Carbon::today()->startOfMonth()->toDateString(),
            'ends_on' => Carbon::today()->addMonths(2)->toDateString(),
            'base_currency' => 'EUR',
        ])->assertCreated()->json('trip.uuid');

        return $this->postJson('/api/v1/rozpocet/rozpocty', [
            'name' => 'Zkušební', 'budget_kind' => 'trip', 'trip_uuid' => $cesta,
            'currency' => 'EUR', 'amount' => $castka, 'income_adds' => false,
        ])->assertCreated()->json('budget.uuid');
    }

    private function rozpocet(float $castka, float $rezerva): string
    {
        return $this->postJson('/api/v1/rozpocet/rozpocty', [
            'name' => 'Zkušební', 'budget_kind' => 'monthly', 'currency' => 'EUR',
            'amount' => $castka, 'reserve_amount' => $rezerva,
        ])->assertCreated()->json('budget.uuid');
    }

    private function limit(string $rozpocet, string $kategorie, float $castka, int $priorita): void
    {
        $this->patchJson("/api/v1/rozpocet/rozpocty/{$rozpocet}/vyhrazeni", [
            'category_uuid' => $this->kategorie($kategorie)->uuid,
            'amount' => $castka, 'priority' => $priorita,
        ])->assertOk();
    }

    private function vydaj(string $kategorie, float $castka, Carbon $kdy, ?int $cesta = null): void
    {
        Transaction::create([
            'gallery_space_id' => $this->space->id,
            'type' => 'expense',
            'occurred_at' => $kdy,
            'wallet_from_id' => $this->ucet->id,
            'amount_from' => $castka, 'currency_from' => 'EUR',
            'amount_to' => $castka, 'currency_to' => 'EUR',
            'category_id' => $this->kategorie($kategorie)->id,
            'finance_project_id' => $cesta,
            'created_by' => $this->uzivatel->id,
        ]);
    }

    private function kategorie(string $nazev): FinanceCategory
    {
        return FinanceCategory::firstOrCreate(
            ['gallery_space_id' => $this->space->id, 'name' => $nazev, 'kind' => 'expense'],
            ['is_active' => true],
        );
    }
}
