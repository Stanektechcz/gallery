<?php

namespace Tests\Feature\Galerie;

use App\Models\CoupleState;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dlouhý text z prohlížeče nesmí shodit celé ukládání stavu.
 *
 * Převodníky berou názvy rovnou z prohlížeče a zapisují je do sloupců, které
 * mají v MySQL 255 znaků. Vývojová SQLite delší řetězec mlčky přijme, produkční
 * MySQL ve striktním režimu ne — a protože se celý `PATCH /api/state` ukládá
 * najednou, spadlo by s ním i všechno ostatní z téhož zápisu. Stačilo by vlepit
 * do názvu domácí práce odstavec z e-mailu.
 */
class DlouheTextyVeStavuTest extends TestCase
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
    }

    public function test_dlouhe_nazvy_se_orezou_a_zbytek_zapisu_projde(): void
    {
        $dlouhy = str_repeat('Příliš žluťoučký kůň úpěl ďábelské ódy. ', 40);
        $this->assertGreaterThan(255, mb_strlen($dlouhy), 'Test má smysl jen s textem delším než sloupec.');

        $this->actingAs($this->adri)->patchJson('/api/state', [
            'data' => [
                'chores' => [[
                    'id' => 'c1', 'name' => $dlouhy, 'who' => 'Adrian', 'every' => $dlouhy,
                    'mins' => 20, 'icon' => $dlouhy, 'day' => 'po',
                ]],
                'proms' => [[
                    'id' => 'p1', 'who' => 'Adrian', 'to' => 'Makinka', 'what' => $dlouhy, 'state' => 'open',
                ]],
                'joy' => ['zbytek patche musí projít'],
            ],
        ])->assertOk();

        $prace = DB::table('house_chores')->where('gallery_space_id', $this->prostor->id)->first();
        $this->assertNotNull($prace, 'Práce se měla zapsat, ne spadnout na délce.');
        $this->assertSame(255, mb_strlen($prace->name));
        $this->assertLessThanOrEqual(40, mb_strlen((string) $prace->every));
        $this->assertLessThanOrEqual(60, mb_strlen((string) $prace->icon));
        // Ořez po znacích, ne po bajtech — jinak by v databázi skončil rozseknutý znak.
        $this->assertSame($prace->name, mb_convert_encoding($prace->name, 'UTF-8', 'UTF-8'));

        $slib = DB::table('couple_promises')->where('gallery_space_id', $this->prostor->id)->first();
        $this->assertNotNull($slib);
        $this->assertSame(255, mb_strlen($slib->what));

        // A to, co s dlouhým textem nesouviselo, se uložilo taky.
        $stav = CoupleState::first();
        $this->assertSame(['zbytek patche musí projít'], $stav->data['joy'] ?? null);
    }
}
