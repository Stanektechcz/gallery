<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Provoz\RozboryVeStavu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sezónní fondy se mění v databázi, ne jen na obrazovce.
 *
 * `seasonVals()` čte `state.season || SEASON`, takže po prvním kliknutí
 * přestal platit `budget_goals` a začala platit kopie z prohlížeče — dvojice
 * pak v Rozpočtech viděla jiný stav fondu než v jeho vlastní obrazovce.
 */
class RozboryVeStavuTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    private int $rozpocet;

    protected function setUp(): void
    {
        parent::setUp();

        // Poledne UTC: termíny se počítají od pražského dneška, testy od `now()`.
        // Mezi 22:00 a půlnocí UTC by se ty dva dny rozešly a testy by náhodně padaly.
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'UTC'));

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);

        $this->rozpocet = DB::table('budgets')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'name' => 'Rozpočet',
            'currency' => 'CZK',
            'starts_on' => now()->startOfMonth()->toDateString(),
            'is_shared' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->transakce();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** „Obálka utracena" vynuluje uspořené — v tabulce, ne v prohlížeči. */
    public function test_utracena_obalka_se_vynuluje(): void
    {
        $uuid = $this->fond(['saved_amount' => 38200]);

        $this->stav(['season' => [['id' => $uuid, 'saved' => 0, 'per' => 0]]])->assertOk();

        $this->assertSame(0.0, (float) DB::table('budget_goals')->where('uuid', $uuid)->value('saved_amount'));
        $this->assertArrayNotHasKey('season', (array) $this->getJson('/api/state')->assertOk()->json('data'));
    }

    /**
     * „Odkládat o 250 víc" znamená, že fond bude plný dřív.
     *
     * Měsíční částka není sloupec, je to podíl — uložit ji nejde. Posune se
     * proto termín tak, aby při nové částce vyšla.
     */
    public function test_vyssi_castka_posune_termin(): void
    {
        // Chybí 6 800; při 1 700 měsíčně to jsou čtyři měsíce.
        $puvodni = now()->addMonths(4)->toDateString();
        $uuid = $this->fond(['target_amount' => 45000, 'saved_amount' => 38200, 'target_on' => $puvodni]);

        // 6 800 / 1 950 vyjde po zaokrouhlení nahoru pořád na čtyři měsíce —
        // termín se nehne. Jinak by ho každé načtení obrazovky posouvalo.
        $this->stav(['season' => [['id' => $uuid, 'saved' => 38200, 'per' => 1950]]])->assertOk();

        $this->assertSame($puvodni, substr((string) DB::table('budget_goals')->where('uuid', $uuid)->value('target_on'), 0, 10));

        // Dvojnásobek termín posune na dva měsíce.
        $odpoved = $this->stav(['season' => [['id' => $uuid, 'saved' => 38200, 'per' => 3400]]])->assertOk();

        $this->assertSame(
            now()->addMonths(2)->toDateString(),
            substr((string) DB::table('budget_goals')->where('uuid', $uuid)->value('target_on'), 0, 10),
        );
        // A obrazovka dostane zpátky přesně tu částku, o kterou požádala.
        $this->assertSame(3400, $odpoved->json('data.season.0.per'));
    }

    /** Odpověď nese fondy spočítané znovu, aby obrazovka neblikla. */
    public function test_odpoved_nese_prepocitane_fondy(): void
    {
        $uuid = $this->fond(['name' => 'Dovolená', 'saved_amount' => 38200]);

        $odpoved = $this->stav(['season' => [['id' => $uuid, 'saved' => 0, 'per' => 0]]])->assertOk();

        $this->assertSame('Dovolená', $odpoved->json('data.season.0.name'));
        $this->assertSame(0, $odpoved->json('data.season.0.saved'));
        // Do lokální kopie ale nepatří — tam by zastínily `budget_goals`.
        $this->assertContains('season', $odpoved->json('docasne'));
    }

    /** Fond druhého páru se odsud změnit nedá. */
    public function test_cizi_fond_se_nezmeni(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $ciziRozpocet = DB::table('budgets')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $ciziProstor->id,
            'created_by' => $cizi->id,
            'name' => 'Cizí rozpočet',
            'currency' => 'CZK',
            'starts_on' => now()->startOfMonth()->toDateString(),
            'is_shared' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $uuid = $this->fond(['saved_amount' => 10000], $ciziRozpocet);

        $this->stav(['season' => [['id' => $uuid, 'saved' => 0, 'per' => 0]]])->assertOk();

        $this->assertSame(10000.0, (float) DB::table('budget_goals')->where('uuid', $uuid)->value('saved_amount'));
    }

    /**
     * Starý opis v kartě nepřepíše vklad, který mezitím udělal ten druhý.
     *
     * Prohlížeč posílá celý seznam fondů; fond, který v něm nezměnil, se
     * nesmí vrátit na hodnotu z doby načtení stránky.
     */
    public function test_nezmeneny_fond_ze_stare_karty_se_neprepise(): void
    {
        $termin = now()->addMonths(4)->toDateString();
        $uuid = $this->fond(['saved_amount' => 1500, 'target_on' => $termin]);

        $this->stav([
            'season' => [['id' => $uuid, 'saved' => 1000, 'per' => 250]],
            '__zmenene' => ['season' => []],
        ])->assertOk();

        $this->assertSame(1500.0, (float) DB::table('budget_goals')->where('uuid', $uuid)->value('saved_amount'));
        $this->assertSame($termin, substr((string) DB::table('budget_goals')->where('uuid', $uuid)->value('target_on'), 0, 10));
    }

    /**
     * Uspořenou částku obrazovka umí jen vynulovat („obálka utracena").
     *
     * Jiné číslo je opis z doby načtení — vklady chodí přes Rozpočty
     * (`FinanceAkceController::vklad`). Bez tohohle starší klient, který
     * `__zmenene` neposílá, vracel fond z 1 500 zpátky na 1 000.
     */
    public function test_nenulova_usporena_castka_se_nezapise(): void
    {
        $uuid = $this->fond(['saved_amount' => 1500]);

        $this->stav(['season' => [['id' => $uuid, 'saved' => 1000, 'per' => 0]]])->assertOk();

        $this->assertSame(1500.0, (float) DB::table('budget_goals')->where('uuid', $uuid)->value('saved_amount'));
    }

    /** Fond ve změněných položkách se zapíše jako dřív. */
    public function test_zmeneny_fond_se_zapise(): void
    {
        $uuid = $this->fond(['saved_amount' => 1500]);

        $this->stav([
            'season' => [['id' => $uuid, 'saved' => 0, 'per' => 0]],
            '__zmenene' => ['season' => [$uuid]],
        ])->assertOk();

        $this->assertSame(0.0, (float) DB::table('budget_goals')->where('uuid', $uuid)->value('saved_amount'));
    }

    /**
     * Soukromý rozpočet partnera se přes stav změnit nedá.
     *
     * `FinanceAkceController::vklad` to hlídá přes `FinanceAccess::smiUpravit`,
     * stav to obcházel: kdokoli z prostoru vynuloval obálku v cizím rozpočtu.
     */
    public function test_cizi_soukromy_rozpocet_se_nezmeni(): void
    {
        $maki = User::factory()->create(['name' => 'Makinka']);
        $this->prostor->members()->syncWithoutDetaching([$maki->id => ['role' => 'editor']]);
        DB::table('budgets')->where('id', $this->rozpocet)->update(['owner_user_id' => $this->adri->id, 'is_shared' => false]);
        $termin = now()->addMonths(4)->toDateString();
        $uuid = $this->fond(['saved_amount' => 1500, 'target_on' => $termin]);

        Sanctum::actingAs($maki);
        $this->stav(['season' => [['id' => $uuid, 'saved' => 0, 'per' => 5000]]])->assertOk();

        $this->assertSame(1500.0, (float) DB::table('budget_goals')->where('uuid', $uuid)->value('saved_amount'));
        $this->assertSame($termin, substr((string) DB::table('budget_goals')->where('uuid', $uuid)->value('target_on'), 0, 10));

        // Vlastník svůj rozpočet mění dál.
        Sanctum::actingAs($this->adri);
        $this->stav(['season' => [['id' => $uuid, 'saved' => 0, 'per' => 0]]])->assertOk();

        $this->assertSame(0.0, (float) DB::table('budget_goals')->where('uuid', $uuid)->value('saved_amount'));
    }

    /**
     * Nový termín se počítá od dneška **v Praze**.
     *
     * 30. září ve 23:30 UTC je v Praze už 1. října. Od UTC dneška by dva
     * měsíce vyšly na 30. listopadu, od pražského na 1. prosince.
     */
    public function test_termin_se_pocita_od_prazskeho_dneska(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 23:30:00', 'UTC'));

        // Chybí 6 800; při 3 400 měsíčně dva měsíce.
        $uuid = $this->fond(['target_amount' => 45000, 'saved_amount' => 38200, 'target_on' => '2027-06-01']);

        $this->stav(['season' => [['id' => $uuid, 'saved' => 38200, 'per' => 3400]]])->assertOk();

        $this->assertSame('2026-12-01', substr((string) DB::table('budget_goals')->where('uuid', $uuid)->value('target_on'), 0, 10));
    }

    /**
     * Vklad, který mezitím udělal ten druhý přes Rozpočty, se vynulováním nesmí zahodit.
     *
     * `zpracuj()` dřív obálku vynuloval podle toho, co přečetl na začátku —
     * bez ohledu na to, co do ní mezitím přibylo. Vynulování tu simulujeme
     * napřímo přes `vynulujFond()`, protože SQLite v jednom vlákně opravdový
     * souběh dvou požadavků nepředvede: `saved_amount` v databázi (500) už
     * neodpovídá tomu, co si metoda myslí, že tam pořád je (300).
     */
    public function test_vynulovani_fondu_nezahodi_mezitimni_vklad(): void
    {
        $uuid = $this->fond(['saved_amount' => 500]);
        $id = (int) DB::table('budget_goals')->where('uuid', $uuid)->value('id');

        $rozbory = app(RozboryVeStavu::class);
        $metoda = new \ReflectionMethod($rozbory, 'vynulujFond');
        $metoda->setAccessible(true);

        $this->assertFalse($metoda->invoke($rozbory, $id, '300'));
        $this->assertSame(500.0, (float) DB::table('budget_goals')->where('id', $id)->value('saved_amount'));

        // Normální cesta — očekávaná hodnota sedí — pořád vynuluje.
        $this->assertTrue($metoda->invoke($rozbory, $id, '500'));
        $this->assertSame(0.0, (float) DB::table('budget_goals')->where('id', $id)->value('saved_amount'));
    }

    // ——— pomůcky ———

    private function stav(array $patch)
    {
        return $this->patchJson('/api/state', ['data' => $patch]);
    }

    private function fond(array $navic = [], ?int $rozpocet = null): string
    {
        $uuid = (string) Str::uuid();

        DB::table('budget_goals')->insert(array_merge([
            'uuid' => $uuid,
            'budget_id' => $rozpocet ?? $this->rozpocet,
            'name' => 'Dovolená',
            'target_amount' => 45000,
            'saved_amount' => 0,
            'currency' => 'CZK',
            'target_on' => now()->addMonths(4)->toDateString(),
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));

        return $uuid;
    }

    private function transakce(): void
    {
        DB::table('transactions')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'type' => 'expense',
            'occurred_at' => now()->toDateString(),
            'amount_from' => 100,
            'currency_from' => 'CZK',
            'description' => 'Výdaj',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
