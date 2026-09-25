<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Support\SpaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Oblíbené počítá jen dvojici, ne každého hosta v prostoru.
 *
 * `space->members()` vrací všechny — vlastníky, editory i hosty (viewer,
 * contributor). Se sdíleným (`shared`) oblíbeným se tak nikdy nepočkalo:
 * potřebovalo by to i oblíbené hosta, který se nikdy nepřihlásí. A badge
 * partnera mohl ukázat jméno hosta místo skutečného partnera z dvojice.
 */
class FavoritesDvojiceTest extends TestCase
{
    use RefreshDatabase;

    private User $ja;

    private User $partner;

    private User $host;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        // Viz `ArchivVsTrezorTest` — statická mezipaměť prostorů se sama
        // nezneplatní mezi testy stejného procesu.
        SpaceContext::forget();

        $this->ja = User::factory()->create(['role' => 'owner', 'name' => 'Adri']);
        $this->prostor = GallerySpace::create(['name' => 'Naše galerie', 'owner_id' => $this->ja->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->ja->id => ['role' => 'owner']]);

        // Host se do prostoru přidává jako první, aby dřívější kód (bez třídění),
        // který sáhne po prvním nevlastním členovi, vrátil právě jeho jméno.
        $this->host = User::factory()->create(['role' => 'owner', 'name' => 'Soused Host']);
        $this->prostor->members()->syncWithoutDetaching([$this->host->id => ['role' => 'viewer']]);

        $this->partner = User::factory()->create(['role' => 'owner', 'name' => 'Maki']);
        $this->prostor->members()->syncWithoutDetaching([$this->partner->id => ['role' => 'editor']]);

        Sanctum::actingAs($this->ja);
    }

    private function fotka(): MediaItem
    {
        return MediaItem::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->ja->id,
            'uploaded_by' => $this->ja->id,
            'original_filename' => 'spolecna.jpg',
            'safe_filename' => 'spolecna.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1000,
            'status' => 'ready',
        ]);
    }

    public function test_sdilene_oblibene_nepotrebuji_hosta(): void
    {
        $fotka = $this->fotka();

        DB::table('user_favorites')->insert([
            ['user_id' => $this->ja->id, 'media_item_id' => $fotka->id, 'created_at' => now()],
            ['user_id' => $this->partner->id, 'media_item_id' => $fotka->id, 'created_at' => now()],
        ]);

        $odpoved = $this->get('/favorites');
        $odpoved->assertOk();

        $shared = collect($odpoved->viewData('page')['props']['shared_items'])->pluck('uuid');
        $this->assertContains($fotka->uuid, $shared, 'Sdílené oblíbené vyžadují i oblíbené hosta.');
    }

    public function test_badge_partnera_neukazuje_hosta(): void
    {
        $fotka = $this->fotka();

        $odpoved = $this->postJson('/favorites/'.$fotka->uuid.'/toggle');

        $odpoved->assertOk()->assertJsonPath('partner_name', 'Maki');
        $this->assertNotSame('Soused Host', $odpoved->json('partner_name'));
    }
}
