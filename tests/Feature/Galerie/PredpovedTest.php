<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Integrations\FreeTravelDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Předpověď počasí pro „co uvařit".
 *
 * Obrazovka podle ní řadí návrhy: teplá polévka do sychravého dne, focaccia,
 * když se dá sedět venku. Bylo to pět řádků napsaných v souboru s ukázkovými
 * daty, takže „první opravdu letní den týdne" platil i v listopadu.
 */
class PredpovedTest extends TestCase
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

    /**
     * Bez geotagovaných fotek se předpověď neposílá.
     *
     * Kde dvojice bydlí, se nikde nezadává. Vymyslet místo by znamenalo radit
     * podle počasí, které nikdo nemá za oknem.
     */
    public function test_bez_polohy_se_nic_neposila(): void
    {
        $this->fakePocasi();
        $this->recept();

        $this->assertArrayNotHasKey('WEATHER', $this->getJson('/api/data/kucharka')->assertOk()->json('data'));
    }

    /** Řádky nesou den, ikonu, teploty, režim a větu z čísel. */
    public function test_predpoved_se_prevede_na_radky(): void
    {
        $this->recept();
        $this->fotkySPolohou();

        $this->fakePocasi([
            'time' => [now()->toDateString(), now()->addDay()->toDateString(), now()->addDays(2)->toDateString()],
            'weather_code' => [61, 0, 3],
            'temperature_2m_max' => [12.4, 28.6, 20.1],
            'temperature_2m_min' => [7.2, 17.4, 13.0],
            'precipitation_probability_max' => [80, 0, 30],
        ]);

        $w = $this->getJson('/api/data/kucharka')->assertOk()->json('data.WEATHER');

        $this->assertCount(3, $w);

        // Déšť podle kódu i pravděpodobnosti.
        $this->assertSame('Dnes', $w[0][0]);
        $this->assertSame('ph-cloud-rain', $w[0][1]);
        $this->assertSame(12, $w[0][2]);
        $this->assertSame('déšť', $w[0][4]);
        $this->assertStringContainsString('déšť na 80 %', $w[0][5]);

        // Horko: nad 27 °C a bez srážek.
        $this->assertSame('Zítra', $w[1][0]);
        $this->assertSame('horko', $w[1][4]);
        $this->assertSame('jasno, přes den 29 °C', $w[1][5]);

        // Třetí den se jmenuje dnem v týdnu.
        $this->assertSame('teplo', $w[2][4]);
        $this->assertNotSame('Dnes', $w[2][0]);
    }

    /**
     * Režim je jedna ze čtyř hodnot, které obrazovka umí vážit.
     *
     * Skóre návrhu je porovnává s `fits` v katalogu receptů; pátá hodnota by
     * znamenala den, ke kterému se nenajde nic.
     */
    public function test_rezim_je_vzdy_ze_ctyr_hodnot(): void
    {
        $this->recept();
        $this->fotkySPolohou();

        $this->fakePocasi([
            'time' => [now()->toDateString(), now()->addDay()->toDateString()],
            'weather_code' => [0, 0],
            'temperature_2m_max' => [5.0, 22.0],
            'temperature_2m_min' => [-2.0, 12.0],
            'precipitation_probability_max' => [0, 10],
        ]);

        $w = $this->getJson('/api/data/kucharka')->assertOk()->json('data.WEATHER');

        $this->assertSame('chladno', $w[0][4]);
        $this->assertSame('teplo', $w[1][4]);
    }

    /** Nedostupná předpověď není chyba aplikace. */
    public function test_nedostupna_sluzba_obrazovku_neshodi(): void
    {
        $this->recept();
        $this->fotkySPolohou();

        $this->mock(FreeTravelDataService::class, function ($mock) {
            $mock->shouldReceive('weather')->andThrow(new \RuntimeException('mimo provoz'));
        });

        $data = $this->getJson('/api/data/kucharka')->assertOk()->json('data');

        $this->assertArrayNotHasKey('WEATHER', $data);
    }

    /** @param  array<string, mixed>  $denne */
    private function fakePocasi(array $denne = []): void
    {
        $this->mock(FreeTravelDataService::class, function ($mock) use ($denne) {
            $mock->shouldReceive('weather')->andReturn($denne === [] ? [] : ['daily' => $denne]);
        });
    }

    /** Čtyři fotky z jednoho místa — medián z nich vyjde tam. */
    private function fotkySPolohou(): void
    {
        foreach ([[49.84, 18.29], [49.83, 18.26], [49.85, 18.28], [49.84, 18.27]] as $i => $bod) {
            MediaItem::create([
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $this->prostor->id,
                'owner_user_id' => $this->adri->id,
                'uploaded_by' => $this->adri->id,
                'original_filename' => 'geo_'.$i.'.jpg',
                'safe_filename' => 'geo-'.$i.'.jpg',
                'extension' => 'jpg',
                'mime_type' => 'image/jpeg',
                'media_type' => 'photo',
                'size_bytes' => 1024,
                'latitude' => $bod[0],
                'longitude' => $bod[1],
                'taken_at' => now()->subDays(10 + $i),
                'uploaded_at' => now()->subDays(10 + $i),
                'status' => 'ready',
                'storage_status' => 'local',
            ]);
        }
    }

    /** Skupina kuchařky se posílá jen tam, kde je aspoň jeden recept. */
    private function recept(): void
    {
        DB::table('recipes')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Polévka',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
