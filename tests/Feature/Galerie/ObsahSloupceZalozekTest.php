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
 * Sloupcové záložky, které na telefonu zůstávaly prázdné.
 *
 * Kapacita týdne i přehled cyklu mají v aplikaci čísla, ale klíč `cap`
 * v ukázce vůbec není a `cycle` v ní byl vymyšlený. Široké rozvržení pro obojí
 * má vlastní obrazovku, úzké kreslí sloupce z `ABARS`.
 */
class ObsahSloupceZalozekTest extends TestCase
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

    /**
     * Den se jménem a volnem obou — a nejvolnější den je sto procent.
     *
     * Týden má sedm dní, i když dvojice přepsala jen dva; zbylých pět počítá
     * kalendář. Fixture je proto celá, aby se sto procent porovnávalo
     * s napsanými čísly, ne s dopočítanými.
     */
    public function test_kapacita_tydne_ma_vlastni_sloupce(): void
    {
        $this->prace();
        $this->tyden(['po' => [2.5, 1.5], 'út' => [3.0, 0.5]], 'Oba v práci do 17.');

        $s = collect($this->getJson('/api/data/domacnost')->assertOk()->json('data.ABARS.cap'))->keyBy(0);

        $pondeli = $s->first();

        $this->assertStringStartsWith('Pondělí ', $pondeli[0]);
        $this->assertSame('Adrian 2,5 h · Makinka 1,5 h', $pondeli[1]);
        // 4 h proti nejvolnějším 4 h → sto procent.
        $this->assertSame(100, $pondeli[2]);
    }

    /** Den, kde ani jeden nemá hodinu volna, je varovný. */
    public function test_nabity_den_varuje(): void
    {
        $this->prace();
        $this->tyden(['po' => [4.0, 3.0], 'út' => [0.5, 0.5]], 'Oba do večera.');

        $s = collect($this->getJson('/api/data/domacnost')->assertOk()->json('data.ABARS.cap'));

        $this->assertSame(1, $s->get(1)[3], 'Úterý — ani jeden nemá hodinu volna.');
        $this->assertSame(0, $s->get(0)[3]);
    }

    /** Bez zapsaného týdne se sloupce neposílají. */
    public function test_bez_tydne_se_kapacita_neposila(): void
    {
        $this->prace();

        $this->assertArrayNotHasKey('ABARS', $this->getJson('/api/data/domacnost')->assertOk()->json('data'));
    }

    /** Přehled cyklu stojí na zaznamenaných začátcích. */
    public function test_prehled_cyklu_pocita_z_dat(): void
    {
        $this->zacatek(now()->subDays(56));
        $this->zacatek(now()->subDays(28));
        $this->zacatek(now());

        $s = collect($this->getJson('/api/data/zdravi')->assertOk()->json('data.ABARS.cycle'))->keyBy(0);

        $this->assertSame('den 1 z 28', $s['Aktuální den cyklu'][1]);
        $this->assertSame('28 dne · 2 zaznamenané cykly', $s['Průměrná délka'][1]);
        $this->assertSame(now()->addDays(28)->format('j. n.'), $s['Předpověď příště'][1]);
    }

    /**
     * Ze dvou začátků je jedna délka — a z jedné délky se průměr nedělá.
     *
     * Odhad z jednoho čísla vypadá stejně jistě jako odhad z roku záznamů.
     */
    public function test_kratka_rada_prehled_neposila(): void
    {
        $this->zacatek(now()->subDays(28));
        $this->zacatek(now());

        $data = $this->getJson('/api/data/zdravi')->assertOk()->json('data');

        $this->assertArrayNotHasKey('ABARS', $data);
    }

    // ——— pomůcky ———

    private function prace(): void
    {
        DB::table('house_chores')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'name' => 'Vyprat',
            'every' => 'týdně',
            'minutes' => 30,
            'rotate' => false,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Celý týden ručně: napsané dny podle zadání, zbytek na nulu.
     *
     * Bez zbylých pěti by o sto procentech rozhodl den, který nikdo nevyplnil
     * a kterému kalendář dopočítal celé bdělé okno.
     *
     * @param  array<string, array{0: float, 1: float}>  $dny
     */
    private function tyden(array $dny, string $poznamka): void
    {
        foreach (['po', 'út', 'st', 'čt', 'pá', 'so', 'ne'] as $klic) {
            [$a, $m] = $dny[$klic] ?? [0.0, 0.0];
            $this->den($klic, isset($dny[$klic]) ? $poznamka : '', $a, $m);
        }
    }

    private function den(string $klic, string $poznamka, float $a, float $m): void
    {
        $den = DB::table('house_week')->insertGetId([
            'gallery_space_id' => $this->prostor->id,
            'weekday' => $klic,
            'note' => $poznamka,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([[$this->adri->id, $a], [$this->maki->id, $m]] as [$kdo, $hodin]) {
            DB::table('house_week_capacity')->insert([
                'house_week_id' => $den,
                'user_id' => $kdo,
                'free_hours' => $hodin,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function zacatek($den): void
    {
        DB::table('cycle_days')->insert([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->adri->id,
            'gallery_space_id' => $this->prostor->id,
            'day' => $den->toDateString(),
            'is_cycle_start' => true,
            'is_predicted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
