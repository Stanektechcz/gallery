<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\RozdeleniService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Rozdělení peněz podle pořadí důležitosti.
 *
 * Testy míří na to, co se stane, když peníze nevyjdou — a když naopak nečekaně
 * přibudou. Obojí se dá spočítat věrohodně a přitom špatně: rovnoměrné krácení vypadá
 * spravedlivě, ale znamená, že se nezaplatí celý nájem *ani* se pořádně nenajíme.
 */
class FinanceRozdeleniTest extends TestCase
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

    /** Když peníze nestačí, kráceno je odspodu — ne všechno o kousek. */
    public function test_pri_nedostatku_se_pokryva_odshora(): void
    {
        $r = (new RozdeleniService)->rozdel([
            $this->polozka('Nájem', 280, 10),
            $this->polozka('Jídlo', 200, 20),
            $this->polozka('Výlety', 100, 90),
        ], 400, 'EUR');

        $stavy = collect($r['rows'])->pluck('covered', 'name');

        $this->assertEqualsWithDelta(280, $stavy['Nájem'], 0.01, 'Nájem musí být celý.');
        $this->assertEqualsWithDelta(120, $stavy['Jídlo'], 0.01, 'Zbytek dostane druhá v pořadí.');
        $this->assertEqualsWithDelta(0, $stavy['Výlety'], 0.01, 'Na poslední nezbylo.');

        $this->assertSame('Jídlo', $r['first_uncovered']);
        $this->assertEqualsWithDelta(180, $r['missing'], 0.01);
        $this->assertEqualsWithDelta(0, $r['free'], 0.01);
    }

    /** Co zbude, se nikam nerozpustí — zůstane vidět jako volné peníze. */
    public function test_prebytek_zustane_volny(): void
    {
        $r = (new RozdeleniService)->rozdel([
            $this->polozka('Nájem', 280, 10),
            $this->polozka('Jídlo', 200, 20),
        ], 600, 'EUR');

        $this->assertEqualsWithDelta(120, $r['free'], 0.01);
        $this->assertEqualsWithDelta(0, $r['missing'], 0.01);
        $this->assertNull($r['first_uncovered']);
    }

    /**
     * Při shodné důležitosti dostane přednost větší položka.
     *
     * Dvě napůl pokryté položky nestačí ani na jedno, k čemu byly. Jedna celá aspoň
     * splní svůj účel.
     */
    public function test_pri_shodnem_poradi_rozhodne_vetsi_castka(): void
    {
        $r = (new RozdeleniService)->rozdel([
            $this->polozka('Malá', 100, 50),
            $this->polozka('Velká', 300, 50),
        ], 300, 'EUR');

        $poradi = collect($r['rows'])->pluck('covered', 'name');

        $this->assertEqualsWithDelta(300, $poradi['Velká'], 0.01);
        $this->assertEqualsWithDelta(0, $poradi['Malá'], 0.01);
    }

    /** Zbývá se počítá proti pokryté částce, ne proti plánu — sliby nejsou peníze. */
    public function test_zbyva_se_pocita_z_pokryte_castky(): void
    {
        $r = (new RozdeleniService)->rozdel([
            ['category_id' => 1, 'category_uuid' => 'a', 'name' => 'Jídlo', 'color' => null,
                'planned' => 200.0, 'spent' => 50.0, 'priority' => 10],
        ], 120, 'EUR');

        $radek = $r['rows'][0];

        $this->assertEqualsWithDelta(120, $radek['covered'], 0.01);
        $this->assertEqualsWithDelta(70, $radek['remaining'], 0.01, 'Zbývá z pokrytých 120, ne z plánovaných 200.');
        $this->assertSame('castecne', $radek['state']);
    }

    /**
     * Příjem se rozdělí sám a dorovná to, na co dřív nebylo.
     *
     * Tohle je celý smysl pořadí: bez něj by nová výplata ležela v rozpočtu stranou
     * a nikdo by nevěděl, kterou položku dorovnala.
     */
    public function test_prijem_dorovna_nepokryte_polozky(): void
    {
        $uuid = $this->rozpocet(400, incomeAdds: true);

        $pred = $this->getJson('/api/v1/rozpocet/rozpocty')->json('budgets.0.allocation');
        $this->assertSame('Jídlo', $pred['first_uncovered']);
        $this->assertEqualsWithDelta(180, $pred['missing'], 0.01);

        $this->prijem(300);

        $po = $this->getJson('/api/v1/rozpocet/rozpocty')->json('budgets.0.allocation');

        $this->assertNull($po['first_uncovered'], 'Po příjmu je pokryté všechno.');
        $this->assertEqualsWithDelta(0, $po['missing'], 0.01);
        $this->assertEqualsWithDelta(120, $po['free'], 0.01, 'Co zbylo, je volné.');
        $this->assertEqualsWithDelta(700, $po['available'], 0.01);

        unset($uuid);
    }

    /**
     * U měsíčního rozpočtu se výplata nepřičítá.
     *
     * Tam je příjem sám ten rozpočet a připočíst ho by znamenalo počítat s dvojnásobkem
     * toho, co člověk doopravdy má — chyba, která se projeví, až peníze dojdou dřív,
     * než rozpočet slibuje.
     */
    public function test_bez_prictani_prijem_rozpocet_nezvedne(): void
    {
        $this->rozpocet(400, incomeAdds: false);
        $this->prijem(300);

        $a = $this->getJson('/api/v1/rozpocet/rozpocty')->json('budgets.0');

        $this->assertEqualsWithDelta(400, $a['allocation']['available'], 0.01);
        $this->assertEqualsWithDelta(0, $a['income'], 0.01);
        $this->assertSame('Jídlo', $a['allocation']['first_uncovered']);
    }

    /** Pořadí se uloží a vrátí — jinak by se po znovunačtení tiše přeskládalo. */
    public function test_poradi_prezije_ulozeni(): void
    {
        $this->rozpocet(1000, incomeAdds: true);

        $radky = collect($this->getJson('/api/v1/rozpocet/rozpocty')->json('budgets.0.allocation.rows'));

        $this->assertSame(['Nájem', 'Jídlo', 'Výlety'], $radky->pluck('name')->all());
        $this->assertSame([10, 20, 90], $radky->pluck('priority')->all());
        $this->assertSame([1, 2, 3], $radky->pluck('order')->all());
    }

    /** @return array<string, mixed> */
    private function polozka(string $nazev, float $castka, int $poradi): array
    {
        return [
            'category_id' => crc32($nazev), 'category_uuid' => $nazev, 'name' => $nazev,
            'color' => null, 'planned' => $castka, 'spent' => 0.0, 'priority' => $poradi,
        ];
    }

    private function rozpocet(float $castka, bool $incomeAdds): string
    {
        $kategorie = fn (string $n) => FinanceCategory::firstOrCreate(
            ['gallery_space_id' => $this->space->id, 'name' => $n, 'kind' => 'expense'],
            ['is_active' => true],
        )->uuid;

        return $this->postJson('/api/v1/rozpocet/rozpocty', [
            'name' => 'Zkušební', 'budget_kind' => 'monthly', 'currency' => 'EUR',
            'amount' => $castka, 'income_adds' => $incomeAdds,
            'limits' => [
                ['category_uuid' => $kategorie('Nájem'), 'amount' => 280, 'priority' => 10],
                ['category_uuid' => $kategorie('Jídlo'), 'amount' => 200, 'priority' => 20],
                ['category_uuid' => $kategorie('Výlety'), 'amount' => 100, 'priority' => 90],
            ],
        ])->assertCreated()->json('budget.uuid');
    }

    private function prijem(float $castka): void
    {
        Transaction::create([
            'gallery_space_id' => $this->space->id,
            'type' => 'income',
            'occurred_at' => Carbon::today(),
            'wallet_to_id' => $this->ucet->id,
            'amount_to' => $castka, 'currency_to' => 'EUR',
            'amount_from' => $castka, 'currency_from' => 'EUR',
            'description' => 'Výplata',
            'created_by' => $this->uzivatel->id,
        ]);
    }
}
