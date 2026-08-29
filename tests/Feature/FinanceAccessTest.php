<?php

namespace Tests\Feature;

use App\Models\FinanceProject;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vlastní a společné rozpočty.
 *
 * Do jednoho prostoru patří dva lidé a tři různé situace: společné peníze, Makinčino
 * Německo a Adriho život v Česku. Testy míří na to, kde se viditelnost dá napsat
 * volněji, než má být — a kde by to nikdo nepoznal, protože „vidím všechno" vypadá
 * úplně stejně jako správně fungující seznam.
 */
class FinanceAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $makinka;

    private User $host;

    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adri']);
        $this->makinka = User::factory()->create(['name' => 'Makinka']);

        // Třetí člověk, který prostor nezaložil. Na něm se testuje právo jen ke čtení —
        // majitel prostoru ho z principu obejít smí, takže by na něm nešlo ověřit nic.
        $this->host = User::factory()->create(['name' => 'Host']);

        $this->space = GallerySpace::create(['name' => 'Zkouška', 'owner_id' => $this->adri->id]);

        foreach ([$this->adri, $this->makinka, $this->host] as $u) {
            $u->gallerySpaces()->syncWithoutDetaching([$this->space->id => ['role' => 'owner']]);
        }
    }

    public function test_spolecny_rozpocet_vidi_oba(): void
    {
        $this->actingAs($this->adri)
            ->postJson('/api/v1/rozpocet/rozpocty', [
                'name' => 'Společné', 'budget_kind' => 'monthly', 'currency' => 'CZK', 'amount' => 20000,
            ])->assertCreated();

        foreach ([$this->adri, $this->makinka] as $u) {
            $this->actingAs($u)->getJson('/api/v1/rozpocet/rozpocty')
                ->assertOk()->assertJsonPath('budgets.0.name', 'Společné');
        }
    }

    public function test_vlastni_rozpocet_druhy_nevidi(): void
    {
        $uuid = $this->vlastniRozpocetMakinky();

        $this->actingAs($this->adri)->getJson('/api/v1/rozpocet/rozpocty')
            ->assertOk()->assertJsonCount(0, 'budgets');

        $this->actingAs($this->makinka)->getJson('/api/v1/rozpocet/rozpocty')
            ->assertOk()->assertJsonPath('budgets.0.uuid', $uuid);
    }

    public function test_nasdileny_rozpocet_druhy_vidi_ale_nesmi_menit(): void
    {
        $uuid = $this->vlastniRozpocetMakinky();

        $this->actingAs($this->makinka)
            ->postJson("/api/v1/rozpocet/rozpocty/{$uuid}/sdileni", [
                'owner_user_id' => $this->makinka->id,
                'access' => [['user_id' => $this->host->id, 'can_edit' => false]],
            ])->assertOk();

        // Vidí ho — a obrazovka od serveru rovnou ví, že tlačítko Upravit nemá nabízet.
        $this->actingAs($this->host)->getJson('/api/v1/rozpocet/rozpocty')
            ->assertOk()
            ->assertJsonPath('budgets.0.uuid', $uuid)
            ->assertJsonPath('budgets.0.can_edit', false)
            ->assertJsonPath('budgets.0.owner_name', 'Makinka');

        $this->actingAs($this->host)
            ->patchJson("/api/v1/rozpocet/rozpocty/{$uuid}", ['amount' => 999])
            ->assertForbidden();
    }

    /**
     * Majitel prostoru se zamknout nedá.
     *
     * Bez téhle pojistky vznikne slepá ulička: kdo rozpočet vidí, ale nesmí ho měnit,
     * nemůže změnit ani to, kdo ho smí měnit. Když vlastník zrovna není u telefonu,
     * nedá se s tím udělat vůbec nic — a ve dvou lidech je to nepoužitelné.
     */
    public function test_majitel_prostoru_smi_upravit_i_cizi_rozpocet(): void
    {
        $uuid = $this->vlastniRozpocetMakinky();

        $this->actingAs($this->adri)
            ->patchJson("/api/v1/rozpocet/rozpocty/{$uuid}", ['amount' => 777])
            ->assertOk()->assertJsonPath('budget.limit', 777);
    }

    public function test_pravo_zapisu_se_da_dat(): void
    {
        $uuid = $this->vlastniRozpocetMakinky();

        $this->actingAs($this->makinka)
            ->postJson("/api/v1/rozpocet/rozpocty/{$uuid}/sdileni", [
                'owner_user_id' => $this->makinka->id,
                'access' => [['user_id' => $this->adri->id, 'can_edit' => true]],
            ])->assertOk();

        $this->actingAs($this->adri)
            ->patchJson("/api/v1/rozpocet/rozpocty/{$uuid}", ['amount' => 999])
            ->assertOk()->assertJsonPath('budget.limit', 999);
    }

    public function test_odebrani_pristupu_rozpocet_zase_schova(): void
    {
        $uuid = $this->vlastniRozpocetMakinky();

        foreach ([[['user_id' => $this->adri->id, 'can_edit' => false]], []] as $pristup) {
            $this->actingAs($this->makinka)
                ->postJson("/api/v1/rozpocet/rozpocty/{$uuid}/sdileni", [
                    'owner_user_id' => $this->makinka->id, 'access' => $pristup,
                ])->assertOk();
        }

        $this->actingAs($this->adri)->getJson('/api/v1/rozpocet/rozpocty')
            ->assertOk()->assertJsonCount(0, 'budgets');
    }

    /** Vlastník si přístup odebrat nemůže — jinak by přišel o vlastní rozpočet. */
    public function test_vlastnik_zustane_i_kdyz_se_posle_prazdny_seznam(): void
    {
        $uuid = $this->vlastniRozpocetMakinky();

        $this->actingAs($this->makinka)
            ->postJson("/api/v1/rozpocet/rozpocty/{$uuid}/sdileni", [
                'owner_user_id' => $this->makinka->id,
                'access' => [['user_id' => $this->makinka->id, 'can_edit' => false]],
            ])->assertOk();

        $this->actingAs($this->makinka)
            ->patchJson("/api/v1/rozpocet/rozpocty/{$uuid}", ['amount' => 500])
            ->assertOk();
    }

    /**
     * Předání vlastnictví a sdílení musí projít jedním uložením.
     *
     * Adri zakládá rozpočet pro Makinku a sobě si nechává náhled. Kdyby se přístupy
     * ukládaly druhým požadavkem, přišel by k němu Adri už jako cizí člověk a dostal
     * by 403 — rozpočet by vznikl, ale bez toho, že do něj vidí.
     */
    public function test_zalozeni_pro_druheho_ulozi_i_pristupy(): void
    {
        $uuid = $this->actingAs($this->adri)
            ->postJson('/api/v1/rozpocet/rozpocty', [
                'name' => 'Německo', 'budget_kind' => 'monthly', 'currency' => 'EUR', 'amount' => 480,
                'owner_user_id' => $this->makinka->id,
                'access' => [['user_id' => $this->host->id, 'can_edit' => false]],
            ])->assertCreated()->json('budget.uuid');

        $this->actingAs($this->host)->getJson('/api/v1/rozpocet/rozpocty')
            ->assertOk()
            ->assertJsonPath('budgets.0.uuid', $uuid)
            ->assertJsonPath('budgets.0.owner_name', 'Makinka')
            ->assertJsonPath('budgets.0.can_edit', false);
    }

    /**
     * Země se ukládá celým názvem, ne dvoupísmenným kódem.
     *
     * Sloupec byl `string(2)`, takže na MySQL neprošla žádná cesta se zemí delší než
     * dva znaky. Ve vývoji je SQLite, která délku textu nehlídá — tenhle test tedy
     * lokálně projde i s vadným sloupcem a hlídá hlavně úmysl: do „Země" patří to,
     * co tam člověk napíše, a stejné se to má vrátit.
     */
    public function test_zeme_se_ulozi_celym_nazvem(): void
    {
        $this->actingAs($this->adri)
            ->postJson('/api/v1/rozpocet/cesty', [
                'name' => 'Regensburg', 'starts_on' => '2026-09-01',
                'base_currency' => 'EUR', 'country' => 'Německo', 'city' => 'Regensburg',
            ])
            ->assertCreated()
            ->assertJsonPath('trip.country', 'Německo');

        $this->assertDatabaseHas('finance_projects', ['name' => 'Regensburg', 'country' => 'Německo']);
    }

    /** Rozpočet na cestu si bere období z cesty — jinak by neměl odkdy počítat. */
    public function test_rozpocet_na_cestu_prebira_obdobi(): void
    {
        $cesta = $this->actingAs($this->makinka)
            ->postJson('/api/v1/rozpocet/cesty', [
                'name' => 'Regensburg', 'starts_on' => '2026-09-01', 'ends_on' => '2027-02-28',
                'base_currency' => 'EUR',
            ])->assertCreated()->json('trip.uuid');

        $this->actingAs($this->makinka)
            ->postJson('/api/v1/rozpocet/rozpocty', [
                'name' => 'Německo', 'budget_kind' => 'trip', 'trip_uuid' => $cesta,
                'currency' => 'EUR', 'amount' => 2891.37, 'reserve_amount' => 150,
            ])
            ->assertCreated()
            ->assertJsonPath('budget.starts_on', '2026-09-01')
            ->assertJsonPath('budget.ends_on', '2027-02-28')
            ->assertJsonPath('budget.trip.name', 'Regensburg');
    }

    public function test_vlastni_cesta_se_druhemu_nenabizi_ani_ve_formulari(): void
    {
        $this->actingAs($this->makinka)
            ->postJson('/api/v1/rozpocet/cesty', [
                'name' => 'Regensburg', 'starts_on' => '2026-09-01', 'ends_on' => '2027-02-28',
                'base_currency' => 'EUR', 'owner_user_id' => $this->makinka->id,
            ])->assertCreated();

        $this->actingAs($this->adri)->getJson('/api/v1/rozpocet/cesty')
            ->assertOk()->assertJsonCount(0, 'trips');

        // Číselníky plní formulář nového záznamu. Cizí cesta v nabídce by znamenala
        // nabídnout zápis do rozpočtu, na který se ten člověk nedostane.
        $this->actingAs($this->adri)->getJson('/api/v1/rozpocet/ciselniky')
            ->assertOk()->assertJsonCount(0, 'trips');
    }

    public function test_cizi_cestu_nejde_smazat_ani_aktivovat(): void
    {
        $uuid = $this->actingAs($this->makinka)
            ->postJson('/api/v1/rozpocet/cesty', [
                'name' => 'Regensburg', 'starts_on' => '2026-09-01',
                'base_currency' => 'EUR', 'owner_user_id' => $this->makinka->id,
            ])->json('trip.uuid');

        $this->actingAs($this->host)->postJson("/api/v1/rozpocet/cesty/{$uuid}/aktivovat")->assertForbidden();
        $this->actingAs($this->host)->deleteJson("/api/v1/rozpocet/cesty/{$uuid}")->assertForbidden();

        $this->assertDatabaseHas('finance_projects', ['uuid' => $uuid, 'deleted_at' => null]);
    }

    /** Dosavadní cesty byly společné a musí takové zůstat — jinak by po nasazení zmizely. */
    public function test_cesta_bez_vlastnika_zustava_spolecna(): void
    {
        FinanceProject::create([
            'gallery_space_id' => $this->space->id, 'kind' => 'trip', 'name' => 'Drážďany',
            'starts_on' => '2026-05-01', 'base_currency' => 'EUR',
        ]);

        foreach ([$this->adri, $this->makinka] as $u) {
            $this->actingAs($u)->getJson('/api/v1/rozpocet/cesty')
                ->assertOk()->assertJsonPath('trips.0.name', 'Drážďany');
        }
    }

    private function vlastniRozpocetMakinky(): string
    {
        return $this->actingAs($this->makinka)
            ->postJson('/api/v1/rozpocet/rozpocty', [
                'name' => 'Německo', 'budget_kind' => 'monthly', 'currency' => 'EUR',
                'amount' => 480, 'owner_user_id' => $this->makinka->id,
            ])->assertCreated()->json('budget.uuid');
    }
}
