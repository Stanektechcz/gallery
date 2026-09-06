<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
