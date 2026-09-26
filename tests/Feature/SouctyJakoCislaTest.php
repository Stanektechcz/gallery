<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Součty z databáze chodí ven jako čísla, ne jako text.
 *
 * `SUM()` vrací v MySQL DECIMAL a PDO z něj udělá řetězec (`"3"`,
 * `"1300.00"`); SQLite vrací číslo. Obrazovky s nimi počítají — `a + b`
 * z řetězců v prohlížeči skládá text. Tady (SQLite) testy procházejí i bez
 * převodu a hlídají tvar odpovědi; na MySQL v CI teprve ověří, že převod
 * v kontroleru opravdu je.
 */
class SouctyJakoCislaTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->prostor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Naše', 'slug' => 'nase-'.Str::random(6), 'owner_id' => $this->adri->id, 'is_default' => true]);
        $this->prostor->members()->attach($this->adri->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->actingAs($this->adri);

        $this->polozka('photo', 'a.jpg');
        $this->polozka('photo', 'b.jpg');
        $this->polozka('video', 'c.mp4');
    }

    public function test_kalendar_casove_osy_vraci_pocty_jako_cisla(): void
    {
        $den = $this->getJson('/api/v1/timeline/calendar?year=2026&month=5')->assertOk()->json('days.0');

        $this->assertSame(10, $den['day']);
        $this->assertSame(3, $den['total']);
        $this->assertSame(2, $den['photos']);
        $this->assertSame(1, $den['videos']);
    }

    public function test_statistiky_vraci_velikost_a_pocty_po_letech_jako_cisla(): void
    {
        $stats = $this->get('/stats')->assertOk()->viewData('page')['props']['stats'];

        $this->assertSame(3 * 4096, $stats['total_size']);
        $this->assertSame(2026, $stats['per_year'][0]['year']);
        $this->assertSame(2, $stats['per_year'][0]['photos']);
        $this->assertSame(1, $stats['per_year'][0]['videos']);
    }

    public function test_navrhy_uklidu_vraci_bajty_jako_cisla(): void
    {
        $kategorie = $this->getJson('/api/v1/recovery/cleanup')->assertOk()->json('categories');

        $this->assertNotEmpty($kategorie);
        foreach ($kategorie as $k) {
            $this->assertIsInt($k['bytes'], "Kategorie {$k['key']}: bajty nejsou číslo.");
        }
        $this->assertSame(3 * 4096, collect($kategorie)->firstWhere('key', 'unorganized')['bytes']);
    }

    public function test_rozpocet_udalosti_s_cestou_vraci_castky_jako_cisla(): void
    {
        $cesta = DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id, 'name' => 'Vídeň',
            'start_date' => now()->addMonth()->toDateString(), 'end_date' => now()->addMonth()->addDays(2)->toDateString(),
            'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson("/api/v1/trips/{$cesta}/expenses", ['title' => 'Hotel', 'category' => 'accommodation', 'amount' => 1300, 'state' => 'planned', 'paid_by_user_id' => $this->adri->id])->assertCreated();

        $udalost = $this->postJson('/api/v1/calendar/events', [
            'gallery_space_id' => $this->prostor->id, 'title' => 'Vídeň', 'trip_id' => $cesta,
            'starts_at' => now()->addMonth()->toDateTimeString(),
        ])->assertCreated()->json();

        $rozpocet = $this->getJson("/api/v1/calendar/events/{$udalost['uuid']}")->assertOk()->json('budget');

        // Na MySQL by bez převodu přišlo "1300.00". JSON celé číslo s nulovou
        // desetinnou částí píše bez ní, proto `1300` i pro float.
        $this->assertIsNotString($rozpocet['planned']);
        $this->assertEquals(1300, $rozpocet['planned']);
    }

    private function polozka(string $druh, string $nazev): void
    {
        MediaItem::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'owner_user_id' => $this->adri->id, 'uploaded_by' => $this->adri->id,
            'original_filename' => $nazev, 'safe_filename' => $nazev, 'extension' => pathinfo($nazev, PATHINFO_EXTENSION),
            'mime_type' => $druh === 'video' ? 'video/mp4' : 'image/jpeg',
            'media_type' => $druh, 'size_bytes' => 4096, 'status' => 'ready', 'storage_status' => 'local_only', 'is_hidden' => false,
            'taken_at' => '2026-05-10 10:00:00', 'uploaded_at' => now(),
        ]);
    }
}
