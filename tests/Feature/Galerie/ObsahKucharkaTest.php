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
 * Kuchařka ve tvaru, ve kterém ji kreslí prototyp.
 *
 * Aplikace má recepty se surovinami, postupem i záznamy z každého vaření —
 * prototyp z toho neukazoval nic. Pět napsaných receptů včetně hodnocení
 * „9/10 · 3 vaření", které nikdo nikdy nedal.
 */
class ObsahKucharkaTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    /** Bez receptů se nic neposílá — klient si nechá ukázková data. */
    public function test_bez_receptu_se_skupina_neposila(): void
    {
        $this->assertSame([], $this->getJson('/api/data/kucharka')->assertOk()->json('data'));
    }

    /** Recept nese druh, čas, počet porcí i popis. */
    public function test_recept_ma_tvar_ktery_prototyp_kresli(): void
    {
        $this->recept([
            'title' => 'Rajčatová polévka',
            'category' => 'soup',
            'dietary_tags' => json_encode(['vegetariánské']),
            'prep_minutes' => 15,
            'cook_minutes' => 25,
            'base_servings' => 4,
            'summary' => 'Klářin recept, který jsme si upravili.',
            'source_name' => 'Od Kláry, upraveno',
            'is_favorite' => true,
        ]);

        $r = $this->getJson('/api/data/kucharka')->assertOk()->json('data.RECIPES.rajcatovapolevka');

        $this->assertSame('Rajčatová polévka', $r['title']);
        $this->assertSame('Polévka · vegetariánské', $r['kind']);
        $this->assertSame('40 min', $r['time']);
        $this->assertSame(4, $r['base']);
        $this->assertSame('porce', $r['unitLabel']);
        $this->assertSame('oblíbené', $r['tag']);
        $this->assertStringStartsWith('Od Kláry, upraveno · ', $r['source']);
        $this->assertSame('Klářin recept, který jsme si upravili.', $r['desc']);
    }

    /**
     * Surovina bez množství má `null`, ne nulu.
     *
     * Prototyp podle toho pozná „sůl dle chuti" a číslo u ní vůbec nekreslí;
     * nula by se roznásobila počtem porcí a vypsala jako „0 sůl".
     */
    public function test_surovina_bez_mnozstvi_ma_null(): void
    {
        $recept = $this->recept(['title' => 'Polévka']);

        foreach ([
            ['name' => 'loupaná rajčata', 'quantity' => 800, 'unit' => 'g', 'preparation' => 'z konzervy', 'sort_order' => 0],
            ['name' => 'sůl', 'quantity' => null, 'unit' => null, 'quantity_note' => 'dle chuti', 'sort_order' => 1, 'is_pantry' => true],
            ['name' => 'bazalka', 'quantity' => 1, 'unit' => 'hrst', 'sort_order' => 2, 'is_optional' => true],
        ] as $s) {
            DB::table('recipe_ingredients')->insert(array_merge([
                'recipe_id' => $recept,
                'quantity_note' => null,
                'preparation' => null,
                'is_scalable' => true,
                'is_optional' => false,
                'is_pantry' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ], $s));
        }

        $ing = $this->getJson('/api/data/kucharka')->assertOk()->json('data.RECIPES.polevka.ing');

        $this->assertSame([800, 'g', 'loupaná rajčata', 'z konzervy'], $ing[0]);
        $this->assertNull($ing[1][0]);
        $this->assertSame('dle chuti', $ing[1][3]);
        // Nepovinná surovina je poznat, aby se u ní nestálo v obchodě.
        $this->assertSame('bazalka (nepovinné)', $ing[2][2]);
    }

    /** Postup jde ven v pořadí a s nadpisem kroku. */
    public function test_postup_ma_poradi_i_nadpisy(): void
    {
        $recept = $this->recept(['title' => 'Polévka']);

        DB::table('recipe_steps')->insert([
            ['uuid' => (string) Str::uuid(), 'recipe_id' => $recept, 'title' => 'Rajčata', 'instruction' => 'Vařte 25 minut.', 'sort_order' => 1, 'temperature_unit' => 'C', 'created_at' => now(), 'updated_at' => now()],
            ['uuid' => (string) Str::uuid(), 'recipe_id' => $recept, 'title' => 'Základ', 'instruction' => 'Zpěňte cibuli.', 'sort_order' => 0, 'temperature_unit' => 'C', 'created_at' => now(), 'updated_at' => now()],
            ['uuid' => (string) Str::uuid(), 'recipe_id' => $recept, 'title' => null, 'instruction' => 'Podávejte.', 'sort_order' => 2, 'temperature_unit' => 'C', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $kroky = $this->getJson('/api/data/kucharka')->assertOk()->json('data.RECIPES.polevka.steps');

        $this->assertSame(['Základ', 'Zpěňte cibuli.'], $kroky[0]);
        $this->assertSame(['Rajčata', 'Vařte 25 minut.'], $kroky[1]);
        // Krok bez nadpisu se pojmenuje pořadím, ne prázdnem.
        $this->assertSame('Krok 3', $kroky[2][0]);
    }

    /**
     * Historie a čísla se počítají z vaření, ne ukládají.
     *
     * „Naposledy 12. 8." a „3 vaření" jsou pohled na záznamy; druhá kopie by po
     * prvním uvaření lhala.
     */
    public function test_historie_a_cisla_se_pocitaji_z_vareni(): void
    {
        $recept = $this->recept(['title' => 'Polévka', 'base_servings' => 4, 'prep_minutes' => 40]);

        $this->vareni($recept, ['cooked_at' => '2026-08-12 18:00:00', 'overall_rating' => 4.5, 'changes_made' => 'víc česneku, tak to zůstane', 'actual_cost' => 152]);
        $this->vareni($recept, ['cooked_at' => '2026-06-24 18:00:00', 'overall_rating' => 4.0, 'notes' => 'k focaccii']);
        $this->vareni($recept, ['cooked_at' => null]);

        $r = $this->getJson('/api/data/kucharka')->assertOk()->json('data.RECIPES.polevka');
        $cisla = collect($r['stats'])->keyBy(0)->map(fn ($x) => $x[1]);

        $this->assertSame('40 min', $cisla['Čas přípravy']);
        $this->assertSame('38 Kč', $cisla['Cena za porci']);
        $this->assertSame('12. 8. 2026', $cisla['Naposledy']);
        $this->assertSame('9/10 · 2 vaření', $cisla['Hodnocení']);

        $this->assertSame('12. 8. 2026', $r['history'][0][0]);
        $this->assertSame('víc česneku, tak to zůstane', $r['history'][0][1]);
        $this->assertSame('9/10', $r['history'][0][2]);
        $this->assertSame('k focaccii', $r['history'][1][1]);
    }

    /**
     * Cena za porci se posílá jen když se doopravdy ví.
     *
     * Odhadnout ji ze surovin by znamenalo vymyslet číslo, podle kterého se
     * dvojice rozhoduje, co uvaří.
     */
    public function test_cena_bez_zapisu_se_neposila(): void
    {
        $recept = $this->recept(['title' => 'Polévka']);
        $this->vareni($recept, ['cooked_at' => now(), 'actual_cost' => null]);

        $cisla = collect($this->getJson('/api/data/kucharka')->assertOk()->json('data.RECIPES.polevka.stats'))
            ->keyBy(0);

        $this->assertArrayNotHasKey('Cena za porci', $cisla->all());
    }

    /** Neuvařený recept nemá historii ani hodnocení, ale čas přípravy ano. */
    public function test_neuvareny_recept_nema_historii(): void
    {
        $this->recept(['title' => 'Nikdy nevařené', 'prep_minutes' => 90]);

        $r = $this->getJson('/api/data/kucharka')->assertOk()->json('data.RECIPES.nikdynevarene');

        $this->assertSame([], $r['history']);
        $this->assertSame([['Čas přípravy', '1 h 30 min']], $r['stats']);
    }

    /** Rejstřík ukazuje na skutečné klíče, ne na ukázkové. */
    public function test_rejstrik_ukazuje_na_skutecne_klice(): void
    {
        $this->recept(['title' => 'Chorvatský peka']);

        $data = $this->getJson('/api/data/kucharka')->assertOk()->json('data');

        $this->assertSame('chorvatskypeka', $data['RECIPE_BY_TITLE']['Chorvatský peka']);
        $this->assertContains('RECIPES', $this->getJson('/api/data/kucharka')->json('uplne'));
    }

    /** Archivovaný recept se do kuchařky nevrací. */
    public function test_archivovany_recept_se_neposila(): void
    {
        $this->recept(['title' => 'Platí']);
        $this->recept(['title' => 'Archiv', 'status' => 'archived']);

        $nazvy = collect($this->getJson('/api/data/kucharka')->assertOk()->json('data.RECIPES'))->pluck('title');

        $this->assertSame(['Platí'], $nazvy->values()->all());
    }

    /** Recepty jiného páru se do odpovědi nedostanou. */
    public function test_recepty_jineho_paru_se_neposilaji(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $this->recept(['title' => 'Náš']);
        $this->recept(['title' => 'Cizí', 'gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id]);

        $nazvy = collect($this->getJson('/api/data/kucharka')->assertOk()->json('data.RECIPES'))->pluck('title');

        $this->assertSame(['Náš'], $nazvy->values()->all());
    }

    // ——— pomůcky ———

    private function recept(array $navic = []): int
    {
        return DB::table('recipes')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Recept',
            'category' => 'main_course',
            'difficulty' => 'medium',
            'status' => 'published',
            'base_servings' => 2,
            'prep_minutes' => 0,
            'cook_minutes' => 0,
            'is_favorite' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }

    private function vareni(int $recept, array $navic = []): void
    {
        DB::table('recipe_cooking_sessions')->insert(array_merge([
            'uuid' => (string) Str::uuid(),
            'recipe_id' => $recept,
            'created_by' => $this->adri->id,
            'status' => 'done',
            'servings' => 4,
            'currency' => 'CZK',
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }
}
