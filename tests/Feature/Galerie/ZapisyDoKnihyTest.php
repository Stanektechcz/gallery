<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tři věci, které obrazovka ukazovala jako uložené a server o nich nevěděl.
 *
 *  - Menu na týden žilo v `ckMenu` a do plánu jídel (a tím do nákupního
 *    seznamu) se nedostalo.
 *  - Ručně připsaná položka nákupu se se `xRows.shopping` zahazovala.
 *  - Přeřazená transakce měla v knize dál starou kategorii, takže rozpočet
 *    počítal jinak, než ukazovala obrazovka Transakce.
 */
class ZapisyDoKnihyTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        // Středa: týden začíná pondělím 7. 9.
        CarbonImmutable::setTestNow('2026-09-09 10:00:00');

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // ——— menu na týden ———

    public function test_menu_na_tyden_se_zapise_do_planu_jidel(): void
    {
        $gulas = $this->recept('Guláš');
        $polevka = $this->recept('Polévka');

        $klice = collect($this->getJson('/api/data/kucharka')->assertOk()->json('data.RECIPE_BY_TITLE'));
        $odpoved = $this->getJson('/api/data/kucharka');
        $this->assertSame([], $odpoved->json('data.CKMENU'), 'Nic naplánováno je prázdné menu, ne ukázka.');
        $this->assertStringContainsString('"CKMENU":{}', $odpoved->getContent(), 'Objekt, ne pole — prototyp čte klíče dnů.');

        $this->patchJson('/api/state', ['data' => ['ckMenu' => ['Pondělí' => $klice['Guláš'], 'Středa' => $klice['Polévka']]]])->assertOk();

        // Středa je dnes; pondělí už tento týden bylo, takže je to příští pondělí.
        $jidla = DB::table('planned_meals')->orderBy('planned_for')->get();
        $this->assertCount(2, $jidla);
        $this->assertSame($polevka, (int) $jidla[0]->recipe_id);
        $this->assertStringStartsWith('2026-09-09', (string) $jidla[0]->planned_for);
        $this->assertSame($gulas, (int) $jidla[1]->recipe_id);
        $this->assertStringStartsWith('2026-09-14', (string) $jidla[1]->planned_for);

        // Ve stavu mapa dnů nezůstane — obrazovka menu čte ze serveru pro tenhle týden.
        $this->assertArrayNotHasKey('ckMenu', (array) $this->getJson('/api/state')->json('data'));
        $this->assertEquals(['Pondělí' => $klice['Guláš'], 'Středa' => $klice['Polévka']], $this->getJson('/api/data/kucharka')->json('data.CKMENU'));

        // Uvolnit středu, pondělí přehodit. Uvolnění je výslovné (`''`): den,
        // který v mapě jen chybí, je starší opis, ne „uvolnit" (KucharkaVeStavu).
        $this->patchJson('/api/state', ['data' => ['ckMenu' => ['Pondělí' => $klice['Polévka'], 'Středa' => '']]])->assertOk();

        $jidla = DB::table('planned_meals')->get();
        $this->assertCount(1, $jidla);
        $this->assertSame($polevka, (int) $jidla[0]->recipe_id);
    }

    /** Uvařené jídlo ani snídaně z plánovače menu na týden nepřepíše. */
    public function test_menu_nesaha_na_uvarene_ani_jina_jidla(): void
    {
        $gulas = $this->recept('Guláš');
        DB::table('planned_meals')->insert([
            ['uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'recipe_id' => $gulas, 'created_by' => $this->adri->id,
                'meal_type' => 'dinner', 'planned_for' => '2026-09-09 18:00:00', 'status' => 'cooked', 'created_at' => now(), 'updated_at' => now()],
            ['uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'recipe_id' => $gulas, 'created_by' => $this->adri->id,
                'meal_type' => 'breakfast', 'planned_for' => '2026-09-10 08:00:00', 'status' => 'planned', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->patchJson('/api/state', ['data' => ['ckMenu' => []]])->assertOk();

        $this->assertSame(2, DB::table('planned_meals')->count());
    }

    // ——— nákupní seznam ———

    public function test_pripsana_polozka_nakupu_se_ulozi_a_neduplikuje(): void
    {
        $radek = ['t' => 'Mléko 2 l', 'm' => 'ručně přidáno', 'g' => null, 'id' => 'shopping-n1'];

        $this->patchJson('/api/state', ['data' => ['xRows' => ['shopping' => [$radek]]]])->assertOk();
        // Klient posílá celý seznam znovu, dokud se nenačte — položka se nesmí zapsat dvakrát.
        $this->patchJson('/api/state', ['data' => ['xRows' => ['shopping' => [$radek, ['t' => 'Chléb', 'm' => '', 'id' => 'shopping-n2']]]]])->assertOk();

        $this->assertSame(['Chléb', 'Mléko 2 l'], DB::table('shopping_list_items')->orderBy('title')->pluck('title')->all());

        $seznam = collect($this->getJson('/api/data/kucharka')->assertOk()->json('data.AL.shopping'));
        $mleko = $seznam->firstWhere(0, 'Mléko 2 l');
        $this->assertNotNull($mleko, 'Připsaná položka se vrací i bez naplánovaných jídel.');
        $this->assertStringStartsWith('shopping-item:', $mleko[7]);
    }

    public function test_pripsana_polozka_jde_odskrtnout_a_smazat(): void
    {
        $this->patchJson('/api/state', ['data' => ['xRows' => ['shopping' => [['t' => 'Mléko', 'id' => 'shopping-n1']]]]])->assertOk();
        $uuid = DB::table('shopping_list_items')->value('uuid');

        $this->patchJson('/api/state', ['data' => ['rowDone' => ['shopping-item:'.$uuid => true, 'film-1' => true]]])->assertOk();
        $this->assertTrue((bool) DB::table('shopping_list_items')->value('is_checked'));
        $this->assertSame($this->adri->id, (int) DB::table('shopping_list_items')->value('checked_by'));
        // Cizí klíče v `rowDone` (zhlédnutý film) zůstávají ve stavu.
        $this->assertSame(['film-1' => true], (array) $this->getJson('/api/state')->json('data.rowDone'));

        // Seznam bez položky nic nemaže (mohl přijít ze staršího telefonu) — maže až `shopDel`.
        $this->patchJson('/api/state', ['data' => ['xRows' => ['shopping' => []]]])->assertOk();
        $this->assertSame(1, DB::table('shopping_list_items')->count());

        $this->patchJson('/api/state', ['data' => ['shopDel' => [$uuid => true]]])->assertOk();
        $this->assertSame(0, DB::table('shopping_list_items')->count());
        $this->assertArrayNotHasKey('shopDel', (array) $this->getJson('/api/state')->json('data'));
    }

    // ——— transakce ———

    public function test_prerazeni_transakce_se_propise_do_knihy_a_zpet_ho_vrati(): void
    {
        $jidlo = $this->kategorie('Potraviny');
        $this->kategorie('Restaurace');
        $uuid = $this->transakce(['category_id' => $jidlo]);

        $this->patchJson('/api/state', ['data' => ['txCat' => [$uuid => 'Restaurace']]])->assertOk();
        $this->assertSame('Restaurace', $this->kategorieTransakce($uuid));

        $this->patchJson('/api/state', ['data' => ['txCat' => [$uuid => 'Nezařazeno']]])->assertOk();
        $this->assertNull(DB::table('transactions')->where('uuid', $uuid)->value('category_id'));

        // „Zpět" až na začátek: transakce ze `txCat` zmizí → původní kategorie.
        $this->patchJson('/api/state', ['data' => ['txCat' => (object) []]])->assertOk();
        $this->assertSame('Potraviny', $this->kategorieTransakce($uuid));
    }

    public function test_neznama_kategorie_ani_cizi_transakce_se_nezapise(): void
    {
        $jidlo = $this->kategorie('Potraviny');
        $uuid = $this->transakce(['category_id' => $jidlo]);

        $jinyProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => User::factory()->create()->id]);
        $cizi = $this->transakce(['gallery_space_id' => $jinyProstor->id, 'category_id' => $jidlo]);

        $this->patchJson('/api/state', ['data' => ['txCat' => [$uuid => 'Kategorie, která není', $cizi => 'Nezařazeno']]])->assertOk();

        $this->assertSame('Potraviny', $this->kategorieTransakce($uuid));
        $this->assertSame($jidlo, (int) DB::table('transactions')->where('uuid', $cizi)->value('category_id'));
    }

    // ——— pomůcky ———

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
            'base_servings' => 4,
            'prep_minutes' => 0,
            'cook_minutes' => 0,
            'is_favorite' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function kategorie(string $nazev): int
    {
        return DB::table('finance_categories')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'name' => $nazev,
            'kind' => 'expense',
            'is_favourite' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function transakce(array $navic = []): string
    {
        $uuid = (string) Str::uuid();

        DB::table('transactions')->insert(array_merge([
            'uuid' => $uuid,
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'type' => 'expense',
            'occurred_at' => '2026-08-29',
            'amount_from' => 100,
            'currency_from' => 'CZK',
            'description' => 'Výdaj',
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));

        return $uuid;
    }

    private function kategorieTransakce(string $uuid): ?string
    {
        return DB::table('transactions as t')
            ->leftJoin('finance_categories as k', 'k.id', '=', 't.category_id')
            ->where('t.uuid', $uuid)
            ->value('k.name');
    }
}
