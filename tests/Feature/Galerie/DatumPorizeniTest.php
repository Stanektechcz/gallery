<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Datum pořízení fotky se musí vejít do sloupce — i to z roku 1965.
 *
 * `media_items.taken_at` byl `timestamp`, a ten má na MySQL rozsah jen
 * 1970-01-01 až 2038-01-19. Úklid přitom datuje skeny do let dávno před
 * tím (návrh „1965") a úprava fotky bere jakýkoli čtyřmístný rok. Na
 * produkci (MySQL, STRICT) takový zápis spadl, SQLite v testech spolkne
 * cokoli — proto se tu kontroluje schéma staticky, podobně jako
 * v `SirkySloupcuTest`.
 */
class DatumPorizeniTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Poslední definice `media_items.taken_at` napříč migracemi není `timestamp`.
     *
     * Bere se poslední výskyt, protože pozdější migrace sloupec mění
     * (`->change()`) — rozhoduje ta, která na produkci proběhne naposled.
     */
    public function test_datum_porizeni_neni_timestamp(): void
    {
        $posledni = null;
        $soubory = glob(database_path('migrations/*.php')) ?: [];
        sort($soubory);

        foreach ($soubory as $soubor) {
            // Jen `up()`: `down()` smí vracet starý typ, na produkci ale neběží.
            $kod = explode('function down(', (string) file_get_contents($soubor))[0];
            $casti = preg_split("/Schema::(?:create|table)\(\s*'([a-z0-9_]+)'/i", $kod, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

            for ($i = 1; $i < count($casti); $i += 2) {
                if ($casti[$i] !== 'media_items') {
                    continue;
                }

                if (preg_match_all("/->(\w+)\(\s*'taken_at'\s*[,)]/", $casti[$i + 1] ?? '', $shody)) {
                    $posledni = [basename($soubor), end($shody[1])];
                }
            }
        }

        $this->assertNotNull($posledni, 'Definice media_items.taken_at se v migracích nenašla.');
        $this->assertStringNotContainsStringIgnoringCase('timestamp', $posledni[1],
            'media_items.taken_at je naposledy definovaný jako '.$posledni[1].' v '.$posledni[0]
            .' — na MySQL to je rozsah 1970–2038 a sken z roku 1965 by zápis shodil.');
        $this->assertSame('dateTime', $posledni[1]);
    }

    /** Rok před 1970 se z úpravy fotky zapíše (na MySQL jen s DATETIME). */
    public function test_stary_rok_z_upravy_se_zapise(): void
    {
        $foto = $this->fotka();

        $this->upravit($foto, ['dateVal' => '1965-06-14']);

        $this->assertSame('1965-06-14', $foto->fresh()->taken_at->format('Y-m-d'));
    }

    /**
     * Nesmyslný rok se nezapíše vůbec — ne ořízne.
     *
     * Rok 9999 nebo 0001 je překlep, ne datum pořízení. Oříznout ho na
     * „příští rok" by fotku posunulo na místo, kam nepatří; nechá se proto
     * původní datum.
     */
    public function test_rok_mimo_rozumny_rozsah_se_nezapise(): void
    {
        foreach (['0001-01-01', '1799-12-31', '9999-12-31', now()->addYears(2)->toDateString()] as $datum) {
            $foto = $this->fotka();

            $this->upravit($foto, ['dateVal' => $datum]);

            $this->assertSame('2026-09-01 10:00', $foto->fresh()->taken_at->format('Y-m-d H:i'), 'Zapsalo se '.$datum);
        }
    }

    /** Hranice rozsahu projdou: 1800-01-01 a dnešek v Praze plus rok. */
    public function test_hranice_rozsahu_projdou(): void
    {
        $foto = $this->fotka();
        $this->upravit($foto, ['dateVal' => '1800-01-01']);
        $this->assertSame('1800-01-01', $foto->fresh()->taken_at->format('Y-m-d'));

        $foto = $this->fotka();
        $rok = now('Europe/Prague')->addYear()->toDateString();
        $this->upravit($foto, ['dateVal' => $rok]);
        $this->assertSame($rok, $foto->fresh()->taken_at->format('Y-m-d'));
    }

    /**
     * Fotka bez data a jen čas: den je dnešek **v Praze**, ne v UTC.
     *
     * Ve 23:30 UTC je v Praze už další den. Dřív se vzal UTC den a fotka
     * vyfocená po půlnoci skončila na časové ose včera.
     */
    public function test_dnesek_bez_data_je_prazsky(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-25 23:30:00', 'UTC'));
        $foto = $this->fotka(['taken_at' => null]);

        $this->upravit($foto, ['timeVal' => '10:00']);

        $this->assertSame('2026-09-26 10:00', $foto->fresh()->taken_at->format('Y-m-d H:i'));
    }

    // ——— pomůcky ———

    private function upravit(MediaItem $foto, array $uprava): void
    {
        $this->actingAs($this->adri)
            ->patchJson('/api/state', ['data' => ['edits' => [$foto->uuid => $uprava]]])
            ->assertOk();
    }

    private function fotka(array $navic = []): MediaItem
    {
        static $poradi = 0;
        $poradi++;

        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'SKEN_'.$poradi.'.jpg',
            'safe_filename' => 'sken-'.$poradi.'.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'taken_at' => '2026-09-01 10:00:00',
            'uploaded_at' => '2026-09-01 11:00:00',
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }
}
