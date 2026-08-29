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

    /**
     * Předpověď mlčí, dokud není z čeho počítat.
     *
     * Z jednoho nákupu za 60 € nejde odvodit měsíc. Číslo by vyšlo, vypadalo by
     * věrohodně a bylo by nesmyslné — a podle nesmyslu by se pak přerozdělovalo.
     */
    public function test_predpoved_mlci_prvni_dva_dny(): void
    {
        $r = (new RozdeleniService)->rozdel(
            [$this->polozka('Jídlo', 300, 10, spent: 60)], 300, 'EUR', dni: 30, uteklo: 2,
        );

        $this->assertNull($r['rows'][0]['projected']);
        $this->assertSame('unknown', $r['rows'][0]['verdict']);
        $this->assertEqualsWithDelta(0, $r['release']['moved'], 0.01, 'Bez předpovědi se nepřerozděluje.');
    }

    /** Tempo se přepočítá na celé období, ne na uběhlou část. */
    public function test_predpoved_pocita_z_dosavadniho_tempa(): void
    {
        $r = (new RozdeleniService)->rozdel(
            [$this->polozka('Jídlo', 300, 10, spent: 100)], 300, 'EUR', dni: 30, uteklo: 10,
        );

        // 100 € za 10 dní → 300 € za 30 dní. Vyjde přesně, tedy „těsně".
        $this->assertEqualsWithDelta(300, $r['rows'][0]['projected'], 0.01);
        $this->assertSame('tesne', $r['rows'][0]['verdict']);
    }

    /**
     * Uvolňuje se z toho, co je nejmíň důležité, a dorovnává to nejdůležitější.
     *
     * Opačné pořadí by znamenalo vzít peníze nájmu ve prospěch výletů — to je přesně
     * ten druh „automatizace", po které rozpočet přestane platit.
     */
    public function test_uvolnuje_se_odspodu_a_dorovnava_odshora(): void
    {
        $r = (new RozdeleniService)->rozdel([
            // Nájem má jediný zápis, takže se nepředpovídá — a právě proto se od něj
            // ani nebere. Jednorázová platba natažená na měsíc by vyšla šestinásobně.
            $this->polozka('Nájem', 280, 10, spent: 280, pocet: 1),
            $this->polozka('Jídlo', 200, 20, spent: 150, pocet: 12),   // odhad 300 → chybí 100
            $this->polozka('Výlety', 120, 90, spent: 10, pocet: 3),    // odhad 20 → zbude 100
        ], 600, 'EUR', dni: 30, uteklo: 15);

        $u = $r['release'];

        $this->assertSame(['Výlety'], array_column($u['givers'], 'name'), 'Dává nejmíň důležitá.');
        $this->assertSame(['Jídlo'], array_column($u['receivers'], 'name'), 'Bere ta, které chybí.');
        $this->assertEqualsWithDelta(100, $u['moved'], 0.01);
        $this->assertEqualsWithDelta(20, $u['givers'][0]['new_planned'], 0.01);
        $this->assertEqualsWithDelta(300, $u['receivers'][0]['new_planned'], 0.01);
    }

    /**
     * Uvolnit se dá i ve prospěch kategorie, na kterou peníze vůbec nezbyly.
     *
     * Potřeba je dvojí: buď kategorie podle tempa přeteče svůj plán, nebo se na ni
     * nedostalo peněz. Kdyby se počítala jen ta první, seděla by doprava na tisícovce,
     * kterou neutratí, zatímco na volný čas by nebylo nic — a nabídka by mlčela.
     */
    public function test_uvolneni_pokryje_i_to_na_co_penize_nezbyly(): void
    {
        $r = (new RozdeleniService)->rozdel([
            $this->polozka('Doprava', 2000, 50, spent: 300, pocet: 5),   // odhad 600 → zbude 1 400
            $this->polozka('Výlety', 2000, 90, spent: 0, pocet: 0),      // nepokryto, chybí 1 000
        ], 3000, 'EUR', dni: 30, uteklo: 15);

        $u = $r['release'];

        $this->assertSame([], $u['receivers'], 'Nikdo neutrácí nad plán, takže nikdo nedostává jmenovitě.');
        $this->assertEqualsWithDelta(1000, $u['moved'], 0.01, 'Uvolní se přesně to, co chybí.');
        $this->assertEqualsWithDelta(1000, $u['frees_up'], 0.01);
        $this->assertSame('Výlety', $u['covers']);
        $this->assertEqualsWithDelta(1000, $u['givers'][0]['new_planned'], 0.01, 'Dopravě zůstane 1 000.');
    }

    /** Když se nesejde dost, řekne se to — ne že se přesune část a mlčí se. */
    public function test_kdyz_nestaci_rekne_kolik_porad_chybi(): void
    {
        $r = (new RozdeleniService)->rozdel([
            $this->polozka('Jídlo', 100, 10, spent: 100),   // odhad 200 → chybí 100
            $this->polozka('Výlety', 40, 90, spent: 10),    // odhad 20 → zbude 20
        ], 140, 'EUR', dni: 30, uteklo: 15);

        $this->assertEqualsWithDelta(20, $r['release']['moved'], 0.01);
        $this->assertEqualsWithDelta(80, $r['release']['still_short'], 0.01);
    }

    /** Přerozdělení přepíše plán a po něm už není co přesouvat. */
    public function test_prerozdeleni_zapise_novy_plan(): void
    {
        $uuid = $this->rozpocet(1000, incomeAdds: false);

        // Jídlo má vyhrazeno 200 a utratilo se v něm 250 nadvakrát… tedy natřikrát:
        // pod třemi zápisy se tempo neodvozuje a předpověď by mlčela.
        foreach ([100, 100, 50] as $castka) {
            $this->vydaj('Jídlo', $castka);
        }

        $pred = $this->getJson('/api/v1/rozpocet/rozpocty')->json('budgets.0.allocation');
        $this->assertGreaterThan(0, $pred['release']['moved'], 'Je z čeho brát i komu dát.');

        $odpoved = $this->postJson("/api/v1/rozpocet/rozpocty/{$uuid}/prerozdelit")->assertOk();

        $this->assertGreaterThan(0, $odpoved->json('moved'));
        $this->assertEqualsWithDelta(
            0, $odpoved->json('budget.allocation.release.moved'), 0.01,
            'Po přerozdělení plán sedí a přesouvat není co.',
        );
    }

    /** Jedna částka jde změnit samostatně, bez posílání celého rozpočtu. */
    public function test_jednu_castku_jde_zmenit_zvlast(): void
    {
        $uuid = $this->rozpocet(1000, incomeAdds: false);
        $jidlo = FinanceCategory::where('gallery_space_id', $this->space->id)->where('name', 'Jídlo')->first();

        $this->patchJson("/api/v1/rozpocet/rozpocty/{$uuid}/vyhrazeni", [
            'category_uuid' => $jidlo->uuid, 'amount' => 350,
        ])->assertOk();

        $radky = collect($this->getJson('/api/v1/rozpocet/rozpocty')->json('budgets.0.allocation.rows'));

        $this->assertEqualsWithDelta(350, $radky->firstWhere('name', 'Jídlo')['planned'], 0.01);
        $this->assertCount(3, $radky, 'Ostatní položky zůstaly.');
    }

    /** Nula položku zruší — vyhrazená nula a žádná vyhrazená částka je totéž. */
    public function test_nula_polozku_zrusi(): void
    {
        $uuid = $this->rozpocet(1000, incomeAdds: false);
        $vylety = FinanceCategory::where('gallery_space_id', $this->space->id)->where('name', 'Výlety')->first();

        $this->patchJson("/api/v1/rozpocet/rozpocty/{$uuid}/vyhrazeni", [
            'category_uuid' => $vylety->uuid, 'amount' => 0,
        ])->assertOk();

        $radky = collect($this->getJson('/api/v1/rozpocet/rozpocty')->json('budgets.0.allocation.rows'));

        $this->assertCount(2, $radky);
        $this->assertNull($radky->firstWhere('name', 'Výlety'));
    }

    /** Kdo smí jen číst, plán nepřepíše — ani jednou částkou, ani přerozdělením. */
    public function test_bez_prava_upravy_to_neprojde(): void
    {
        $druhy = User::factory()->create(['name' => 'Druhý']);
        $druhy->gallerySpaces()->syncWithoutDetaching([$this->space->id => ['role' => 'owner']]);

        $uuid = $this->postJson('/api/v1/rozpocet/rozpocty', [
            'name' => 'Moje', 'budget_kind' => 'monthly', 'currency' => 'EUR', 'amount' => 500,
            'owner_user_id' => $this->uzivatel->id,
            'access' => [['user_id' => $druhy->id, 'can_edit' => false]],
        ])->assertCreated()->json('budget.uuid');

        $kategorie = FinanceCategory::where('gallery_space_id', $this->space->id)->first();

        $this->actingAs($druhy)
            ->patchJson("/api/v1/rozpocet/rozpocty/{$uuid}/vyhrazeni", [
                'category_uuid' => $kategorie->uuid, 'amount' => 999,
            ])->assertForbidden();

        $this->actingAs($druhy)
            ->postJson("/api/v1/rozpocet/rozpocty/{$uuid}/prerozdelit")->assertForbidden();
    }

    /** Utrácení v kategorii bez vyhrazené částky nesmí z tabulky zmizet. */
    public function test_utraty_mimo_plan_jsou_videt(): void
    {
        $this->rozpocet(1000, incomeAdds: false);
        $this->vydaj('Zábava', 80);

        $mimo = collect($this->getJson('/api/v1/rozpocet/rozpocty')->json('budgets.0.unplanned'));

        $this->assertSame('Zábava', $mimo->firstWhere('name', 'Zábava')['name']);
        $this->assertEqualsWithDelta(80, $mimo->firstWhere('name', 'Zábava')['spent'], 0.01);
    }

    private function vydaj(string $kategorie, float $castka): void
    {
        $k = FinanceCategory::firstOrCreate(
            ['gallery_space_id' => $this->space->id, 'name' => $kategorie, 'kind' => 'expense'],
            ['is_active' => true],
        );

        Transaction::create([
            'gallery_space_id' => $this->space->id,
            'type' => 'expense',
            'occurred_at' => Carbon::today(),
            'wallet_from_id' => $this->ucet->id,
            'amount_from' => $castka, 'currency_from' => 'EUR',
            'amount_to' => $castka, 'currency_to' => 'EUR',
            'category_id' => $k->id,
            'created_by' => $this->uzivatel->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function polozka(string $nazev, float $castka, int $poradi, float $spent = 0.0, int $pocet = 12): array
    {
        return [
            'category_id' => crc32($nazev), 'category_uuid' => $nazev, 'name' => $nazev,
            'color' => null, 'planned' => $castka, 'spent' => $spent, 'priority' => $poradi, 'count' => $pocet,
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
