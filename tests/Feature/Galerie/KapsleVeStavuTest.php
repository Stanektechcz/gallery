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
 * Zapečetěný vzkaz se opravdu zapečetí.
 *
 * „Otevře se 4. září 2027," řekl prototyp — a uložil to do prohlížeče, odkud
 * se to při vymazání ztratí. Dopis, který má přijít za rok, je přesně ta věc,
 * u které na tom záleží nejvíc.
 */
class KapsleVeStavuTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->maki = User::factory()->create(['name' => 'Makinka']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    /** Nová kapsle skončí v tabulce, ne ve stavu. */
    public function test_nova_kapsle_se_ulozi(): void
    {
        $odpoved = $this->stav(['kapsules' => [[
            'id' => 'z1757000000000',
            'kind' => 'vzkaz',
            'from' => 'Adrian',
            'open' => '2027-09-04',
            'trig' => null,
            'title' => 'Až nám bude deset let',
            'body' => '',
        ]]])->assertOk();

        $radek = DB::table('time_capsules')->first();

        $this->assertSame('Až nám bude deset let', $radek->title);
        $this->assertSame('sealed', $radek->status);
        $this->assertSame($this->adri->id, $radek->created_by);
        $this->assertSame('2027-09-04', substr((string) $radek->deliver_at, 0, 10));

        $this->assertArrayNotHasKey('kapsules', (array) $this->getJson('/api/state')->assertOk()->json('data'));
        $this->assertContains('kapsules', $odpoved->json('docasne'));
    }

    /** Kapsle vázaná na událost dostane termín, jinak ji nikdy nic neotevře. */
    public function test_kapsle_bez_data_dostane_termin(): void
    {
        $this->stav(['kapsules' => [[
            'id' => 'k1757000000000',
            'from' => 'Makinka',
            'open' => null,
            'trig' => 'stehovani',
            'title' => 'Až se přestěhujeme',
            'body' => 'Vzpomeň si na ten radiátor.',
        ]]])->assertOk();

        $this->assertSame(
            now()->addYear()->toDateString(),
            substr((string) DB::table('time_capsules')->value('deliver_at'), 0, 10),
        );
        $this->assertSame($this->maki->id, (int) DB::table('time_capsules')->value('created_by'));
    }

    /** Zapečetěná kapsle se nemění a neruší — o to jde. */
    public function test_zapecetena_kapsle_zustava(): void
    {
        $uuid = (string) Str::uuid();
        $this->kapsle(['uuid' => $uuid, 'title' => 'Stará kapsle']);

        // Prázdný seznam by u jiných kolekcí znamenal smazání. Tady ne.
        $this->stav(['kapsules' => []])->assertOk();

        $this->assertSame(1, DB::table('time_capsules')->count());
        $this->assertSame('Stará kapsle', DB::table('time_capsules')->value('title'));
    }

    /** Tentýž patch po výpadku sítě kapsli nezdvojí. */
    public function test_opakovany_patch_kapsli_nezdvoji(): void
    {
        $patch = ['kapsules' => [[
            'id' => 'z1757000000000', 'from' => 'Adrian', 'open' => '2027-09-04',
            'title' => 'Dopis', 'body' => '',
        ]]];

        $prvni = $this->stav($patch)->assertOk();
        // Podruhé přijde seznam, jak ho vrátil server — s uuid, které už zná.
        $this->stav(['kapsules' => $prvni->json('data.kapsules')])->assertOk();

        $this->assertSame(1, DB::table('time_capsules')->count());
    }

    /** Vzkaz bez nadpisu není vzkaz. */
    public function test_kapsle_bez_nadpisu_se_neulozi(): void
    {
        $this->stav(['kapsules' => [[
            'id' => 'z1', 'from' => 'Adrian', 'open' => '2027-09-04', 'title' => '  ', 'body' => '',
        ]]])->assertOk();

        $this->assertSame(0, DB::table('time_capsules')->count());
    }

    // ——— pomůcky ———

    private function stav(array $patch)
    {
        return $this->patchJson('/api/state', ['data' => $patch]);
    }

    private function kapsle(array $navic = []): void
    {
        DB::table('time_capsules')->insert(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Kapsle',
            'message' => 'Text',
            'deliver_at' => now()->addYear(),
            'status' => 'sealed',
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }
}
