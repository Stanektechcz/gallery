<?php

namespace Tests\Feature\Galerie;

use App\Models\CoupleState;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Komentáře u fotek: tabulka `media_comments`, ne sdílený stav.
 *
 * Počítač držel vlákno ve stavu dvojice (`lbCom`) — staré rozhraní ho nevidělo
 * a smazat šel i cizí komentář. Od 18. kola čte a píše přes API; co už bylo
 * napsané, převede datová migrace.
 */
class KomentareKFotkamTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian Stanek']);
        $this->maki = User::factory()->create(['name' => 'Markéta Nová']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    public function test_komentar_se_zapise_precte_a_cizi_nejde_smazat(): void
    {
        $foto = $this->fotka();

        $this->postJson('/api/v1/media/'.$foto->uuid.'/comments', ['body' => 'Tuhle do fotoknihy.'])->assertCreated();

        Sanctum::actingAs($this->maki);
        $vlakno = $this->getJson('/api/v1/media/'.$foto->uuid.'/comments')->assertOk()->json();
        $this->assertSame('Tuhle do fotoknihy.', $vlakno[0]['body']);
        $this->assertSame('Adrian Stanek', $vlakno[0]['user_name']);
        $this->assertFalse($vlakno[0]['is_mine']);

        $this->deleteJson('/api/v1/media/'.$foto->uuid.'/comments/'.$vlakno[0]['id'])->assertNotFound();
        $this->assertSame(1, DB::table('media_comments')->count());
    }

    public function test_migrace_prevede_komentare_ze_stavu(): void
    {
        $foto = $this->fotka();
        CoupleState::forCouple($this->prostor->id)->update(['data' => [
            'lbCom' => [
                $foto->uuid => [
                    ['who' => 'Markéta', 'text' => 'Tuhle bych dala jako první stránku.', 'when' => 'včera', 'mine' => false],
                    ['who' => 'Adrian', 'text' => 'Souhlas.', 'when' => 'včera', 'mine' => true],
                    ['who' => 'Adrian', 'text' => '   ', 'when' => 'včera', 'mine' => true],
                ],
                'neexistujici-fotka' => [['who' => 'Adrian', 'text' => 'Nikam', 'when' => '', 'mine' => true]],
            ],
            'pauseOn' => false,
        ]]);

        (require database_path('migrations/2026_09_22_100000_komentare_ze_stavu_do_tabulky.php'))->up();

        $radky = DB::table('media_comments')->orderBy('id')->get();
        $this->assertCount(2, $radky);
        $this->assertSame($this->maki->id, (int) $radky[0]->user_id);
        $this->assertSame('Souhlas.', $radky[1]->body);
        $this->assertSame($this->adri->id, (int) $radky[1]->user_id);

        $data = CoupleState::where('couple_id', $this->prostor->id)->sole()->data;
        $this->assertArrayNotHasKey('lbCom', $data);
        $this->assertArrayHasKey('pauseOn', $data);
    }

    private function fotka(): MediaItem
    {
        return MediaItem::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'foto.jpg',
            'safe_filename' => 'foto.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1000,
            'uploaded_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
        ]);
    }
}
