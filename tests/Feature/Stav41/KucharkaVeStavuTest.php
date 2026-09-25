<?php

namespace Tests\Feature\Stav41;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Obsah\Kucharka;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Menu na týden mění jen dny, které prohlížeč poslal.
 *
 * Den, který v `ckMenu` chyběl, se bral jako „uvolnit" — a naplánovaná
 * večeře se smazala. `{"ckMenu":{}}` tak vyprázdnilo celý týden a karta,
 * ve které ještě nebyla středa od Makinky, ji úpravou pondělí zahodila.
 */
class KucharkaVeStavuTest extends TestCase
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

    public function test_chybejici_den_vecere_nesmaze(): void
    {
        $r1 = $this->recept('Rizoto');
        $r2 = $this->recept('Guláš');
        $this->jidlo($r2, 2);

        $klic = $this->klic('Rizoto');

        $this->stav(['ckMenu' => ['Pondělí' => $klic]])->assertOk();

        $streda = $this->vecere(2);
        $this->assertNotNull($streda, 'Středeční večeře od Makinky zmizela.');
        $this->assertSame($r2, (int) $streda->recipe_id);
        $this->assertSame($r1, (int) $this->vecere(0)?->recipe_id);
    }

    public function test_prazdne_menu_tyden_nevyprazdni(): void
    {
        $this->jidlo($this->recept('Guláš'), 4);

        $this->stav(['ckMenu' => []])->assertOk();

        $this->assertSame(1, DB::table('planned_meals')->where('meal_type', 'dinner')->count());
    }

    /** Výslovné uvolnění (`''` i `null`) den pořád uvolní. */
    public function test_vyslovne_uvolneni_den_uvolni(): void
    {
        $recept = $this->recept('Guláš');
        $this->jidlo($recept, 1);
        $this->jidlo($recept, 5);

        $this->stav(['ckMenu' => ['Úterý' => '', 'Sobota' => null]])->assertOk();

        $this->assertSame(0, DB::table('planned_meals')->where('meal_type', 'dinner')->count());
    }

    // ——— pomůcky ———

    private function stav(array $patch)
    {
        return $this->patchJson('/api/state', ['data' => $patch]);
    }

    private function klic(string $nazev): string
    {
        $recepty = $this->getJson('/api/data/kucharka')->assertOk()->json('data.RECIPES');

        return (string) collect($recepty)->search(fn ($r) => ($r['title'] ?? null) === $nazev);
    }

    private function vecere(int $poradi): ?object
    {
        $den = Kucharka::datumDne($poradi);

        return DB::table('planned_meals')
            ->where('meal_type', 'dinner')
            ->where('planned_for', '>=', $den->startOfDay())
            ->where('planned_for', '<', $den->addDay()->startOfDay())
            ->first();
    }

    private function jidlo(int $recept, int $poradi): void
    {
        DB::table('planned_meals')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'recipe_id' => $recept,
            'meal_type' => 'dinner',
            'status' => 'planned',
            'planned_for' => Kucharka::datumDne($poradi)->setTime(18, 0),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function recept(string $nazev): int
    {
        return DB::table('recipes')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => $nazev,
            'category' => 'main_course',
            'difficulty' => 'medium',
            'status' => 'published',
            'base_servings' => 2,
            'prep_minutes' => 0,
            'cook_minutes' => 0,
            'is_favorite' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
