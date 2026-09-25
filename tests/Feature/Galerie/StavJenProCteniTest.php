<?php

namespace Tests\Feature\Galerie;

use App\Models\CoupleState;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Účet v režimu jen pro čtení nezapíše přes stav žádnou tabulku.
 *
 * Deník, dělba práce a desítky dalších adres `read_only_mode` hlídají, jenže
 * `PATCH /api/state` vede převodníky do skoro všech tabulek — popisky fotek,
 * fondy, kalendář, pravidla. Přes stav tak šlo zapsat to, co vlastní adresa
 * odmítne.
 *
 * Zápis se neodmítá celý: `galerie-api.js` bere 403 u stavu jako odebraný
 * přístup a ukáže přihlášení, 422 rozdělí patch po klíčích a každý ohlásí.
 * Klíče převodníků se proto zahodí (ani do stavu) a zbytek se uloží — tak,
 * jak s tím počítá i `UklidSpolecneTest`.
 */
class StavJenProCteniTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $host;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->host = User::factory()->create(['name' => 'Makinka', 'read_only_mode' => true]);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->host->id => ['role' => 'editor'],
        ]);
    }

    public function test_klice_prevodniku_se_zahodi_zbytek_ulozi(): void
    {
        $foto = $this->fotka();

        $odpoved = $this->actingAs($this->host)
            ->patchJson('/api/state', ['data' => [
                'grid' => 'big',
                'edits' => [$foto->uuid => ['caption' => 'Přepsáno']],
                'favs' => [$foto->uuid => true],
                'rules' => [['id' => 'r1', 'on' => true]],
                'evList' => [['id' => 'e1', 'title' => 'Schůzka']],
                '__zmenene' => ['rules' => ['r1']],
            ]])
            ->assertOk();

        $this->assertEqualsCanonicalizing(['edits', 'favs', 'rules', 'evList'], $odpoved->json('jen_cteni'));
        $this->assertNull($foto->fresh()->caption);
        $this->assertFalse(DB::table('user_favorites')->exists());
        $this->assertSame(0, DB::table('calendar_events')->count());

        $data = (array) $this->stav()->data;
        $this->assertSame('big', $data['grid']);
        // Ani do stavu: obrazovka by kreslila popisek, který v knihovně není.
        $this->assertArrayNotHasKey('edits', $data);
        // A zahozené klíče nedostanou revizi — partnerovi by jinak hlásily střet.
        $this->assertArrayNotHasKey('edits', (array) $this->stav()->rev_keys);
    }

    /** Patch jen z klíčů převodníků se neuloží vůbec — ani revize se nehne. */
    public function test_patch_jen_z_tabulek_nezmeni_nic(): void
    {
        $foto = $this->fotka();
        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['grid' => 'big']])->assertOk();
        $rev = $this->stav()->rev;

        $this->actingAs($this->host)
            ->patchJson('/api/state', ['data' => ['edits' => [$foto->uuid => ['caption' => 'Přepsáno']]]])
            ->assertOk()
            ->assertJsonPath('jen_cteni', ['edits'])
            ->assertJsonPath('rev', $rev);

        $this->assertSame($rev, $this->stav()->rev);
        $this->assertNull($foto->fresh()->caption);
    }

    /** Dluh zápisu se pod účtem jen pro čtení nepřehrává — počká na někoho, kdo smí. */
    public function test_dluh_se_pod_uctem_jen_pro_cteni_neprehraje(): void
    {
        $foto = $this->fotka();
        CoupleState::forCouple($this->prostor->id)
            ->forceFill(['data' => ['__dluh' => ['edits' => [$foto->uuid => ['caption' => 'Z dluhu']]]]])->save();

        $this->actingAs($this->host)->patchJson('/api/state', ['data' => ['grid' => 'big']])->assertOk();

        $this->assertNull($foto->fresh()->caption);
        $this->assertArrayHasKey('edits', $this->stav()->dluh());

        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['grid' => 'med']])->assertOk();

        $this->assertSame('Z dluhu', $foto->fresh()->caption);
    }

    /** Číst stav smí dál — jen pro čtení neznamená bez přístupu. */
    public function test_cteni_stavu_projde(): void
    {
        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['grid' => 'big']])->assertOk();

        $this->actingAs($this->host)->getJson('/api/state')
            ->assertOk()
            ->assertJsonPath('data.grid', 'big');
    }

    /** Běžný účet zapisuje dál a odpověď `jen_cteni` nenese. */
    public function test_bezny_ucet_zapisuje(): void
    {
        $foto = $this->fotka();

        $this->actingAs($this->adri)
            ->patchJson('/api/state', ['data' => ['edits' => [$foto->uuid => ['caption' => 'Ráno']]]])
            ->assertOk()
            ->assertJsonMissingPath('jen_cteni');

        $this->assertSame('Ráno', $foto->fresh()->caption);
    }

    private function stav(): CoupleState
    {
        return CoupleState::where('couple_id', $this->prostor->id)->sole();
    }

    private function fotka(): MediaItem
    {
        return MediaItem::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG_1.jpg',
            'safe_filename' => 'img-1.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'taken_at' => '2026-09-01 10:00:00',
            'uploaded_at' => '2026-09-01 11:00:00',
            'status' => 'ready',
            'storage_status' => 'local',
        ]);
    }
}
