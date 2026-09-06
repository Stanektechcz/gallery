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
 * Napsané přání se opravdu napíše — a chystaný dárek zůstane utajený.
 *
 * Obrazovky čtou `state.wishes || GIFT_WISHES`, takže první napsané přání
 * zastínilo celou sekci: druhý o něm nevěděl a po zavření záložky zmizelo.
 */
class DarkyVeStavuTest extends TestCase
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

    /** Přání je veřejné — o to jde, aby ho druhý viděl. */
    public function test_prani_je_verejne(): void
    {
        $odpoved = $this->stav(['wishes' => [[
            'id' => 'w1757000000000',
            'who' => 'Adrian',
            'title' => 'Kurz keramiky',
            'price' => 2400,
            'note' => 'Ten v Zábrdovicích.',
        ]]])->assertOk();

        $radek = DB::table('gift_ideas')->first();

        $this->assertSame('Kurz keramiky', $radek->title);
        $this->assertSame('wish', $radek->status);
        $this->assertNull($radek->private_to_user_id);
        $this->assertSame($this->adri->id, $radek->created_by);
        $this->assertSame('big', $odpoved->json('data.wishes.0.size'));
        $this->assertContains('wishes', $odpoved->json('docasne'));
    }

    /**
     * Chystaný dárek je soukromý toho, kdo ho pořizuje.
     *
     * Prozrazený dárek se nedá vzít zpět — proto je tohle nejdůležitější
     * tvrzení v celé sekci.
     */
    public function test_chystany_darek_druhy_neuvidi(): void
    {
        $this->stav(['buys' => [[
            'id' => 'b1757000000000',
            'owner' => 'Adrian',
            'what' => 'Kurz keramiky',
            'price' => 2400,
            'occasion' => 'Vánoce 2026',
            'status' => 'reserved',
            'where' => 've skříni v ložnici',
        ]]])->assertOk();

        $this->assertSame($this->adri->id, (int) DB::table('gift_ideas')->value('private_to_user_id'));

        // Makinka o něm z API nic nezjistí.
        Sanctum::actingAs($this->maki);
        $data = $this->getJson('/api/data/darky')->assertOk()->json('data');

        $this->assertArrayNotHasKey('GIFT_BUYS', $data);
    }

    /** Nápad se dá povýšit na přání — v tabulce, ne jen na obrazovce. */
    public function test_napad_se_promeni_v_prani(): void
    {
        $uuid = (string) Str::uuid();
        $this->darek(['uuid' => $uuid, 'title' => 'Kurz keramiky', 'status' => 'idea']);

        $this->stav([
            'ideas' => [],
            'wishes' => [['id' => $uuid, 'who' => 'Makinka', 'title' => 'Kurz keramiky', 'price' => 2400, 'note' => 'Z nápadu']],
        ])->assertOk();

        $radek = DB::table('gift_ideas')->where('uuid', $uuid)->first();

        $this->assertSame('wish', $radek->status);
        $this->assertSame($this->maki->id, $radek->created_by);
        $this->assertSame(1, DB::table('gift_ideas')->count());
    }

    /** Zahozený nápad zmizí z tabulky. */
    public function test_zahozeny_napad_zmizi(): void
    {
        $this->darek(['title' => 'Zahozený', 'status' => 'idea']);

        $this->stav(['ideas' => []])->assertOk();

        $this->assertSame(0, DB::table('gift_ideas')->count());
    }

    /**
     * Cizí schovaný dárek se odsud smazat nedá.
     *
     * V seznamu, který přijde od Adriana, Makinčin schovaný dárek není —
     * a smazat ho proto, že o něm neví, by bylo to nejhorší, co tahle vrstva
     * může udělat.
     */
    public function test_cizi_schovany_darek_prezije(): void
    {
        $this->darek([
            'title' => 'Náhrdelník pro Adriana',
            'status' => 'reserved',
            'created_by' => $this->maki->id,
            'private_to_user_id' => $this->maki->id,
        ]);

        $this->stav(['buys' => []])->assertOk();

        $this->assertSame(1, DB::table('gift_ideas')->count());
    }

    /** Přání druhého páru zůstane, kde bylo. */
    public function test_darky_jineho_paru_se_nemeni(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $this->darek([
            'title' => 'Cizí přání',
            'status' => 'wish',
            'gallery_space_id' => $ciziProstor->id,
            'created_by' => $cizi->id,
        ]);

        $this->stav(['wishes' => []])->assertOk();

        $this->assertSame(1, DB::table('gift_ideas')->count());
    }

    // ——— pomůcky ———

    private function stav(array $patch)
    {
        return $this->patchJson('/api/state', ['data' => $patch]);
    }

    private function darek(array $navic = []): void
    {
        DB::table('gift_ideas')->insert(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Dárek',
            'budget' => 1000,
            'currency' => 'CZK',
            'status' => 'idea',
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }
}
