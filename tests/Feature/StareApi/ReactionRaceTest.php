<?php

namespace Tests\Feature\StareApi;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nález 8: souběžná reakce nesmí spadnout na duplicitní klíč a nesmí
 * vytvořit druhý řádek pro stejného člověka a fotku.
 */
class ReactionRaceTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    private MediaItem $fotka;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$this->prostor->id => ['role' => 'owner']]);

        $this->fotka = MediaItem::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG_1.jpg',
            'safe_filename' => 'img.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'uploaded_at' => now(),
            'taken_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
        ]);

        Sanctum::actingAs($this->adri);
    }

    public function test_opakovana_stejna_reakce_neskonci_500_a_nezdvoji_radek(): void
    {
        // Řádek, jako by ho vytvořil souběžný požadavek, který mezitím doběhl.
        DB::table('media_reactions')->insert([
            'media_item_id' => $this->fotka->id,
            'user_id' => $this->adri->id,
            'reaction' => 'love',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/media/'.$this->fotka->uuid.'/react', ['reaction' => 'love'])
            ->assertSuccessful();

        $this->assertSame(1, DB::table('media_reactions')
            ->where('media_item_id', $this->fotka->id)
            ->where('user_id', $this->adri->id)
            ->count());
    }
}
