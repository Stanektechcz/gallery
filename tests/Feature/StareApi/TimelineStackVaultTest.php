<?php

namespace Tests\Feature\StareApi;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\MediaStack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nález 4: stack, jehož titulní fotka je v trezoru nebo v koši, nesmí
 * schovat i ostatní fotky stacku z časové osy.
 */
class TimelineStackVaultTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$this->prostor->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    public function test_polozka_stacku_je_videt_kdyz_je_titulni_fotka_v_kosi(): void
    {
        $a = $this->fotka(['trashed_at' => now()]);
        $b = $this->fotka();

        $stack = MediaStack::create(['gallery_space_id' => $this->prostor->id, 'cover_media_id' => $a->id]);
        DB::table('media_stack_items')->insert([
            ['media_stack_id' => $stack->id, 'media_item_id' => $a->id, 'is_cover' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['media_stack_id' => $stack->id, 'media_item_id' => $b->id, 'is_cover' => false, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $odpoved = $this->getJson('/api/v1/timeline')->assertOk();
        $ids = collect($odpoved->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($b->id), 'Fotka B ze stacku zmizela, protože titulní A je v koši.');
    }

    public function test_show_a_setcover_vraci_jen_viditelne_polozky(): void
    {
        $viditelna = $this->fotka();
        $vTrezoru = $this->fotka(['is_hidden' => true]);
        $stack = MediaStack::create(['gallery_space_id' => $this->prostor->id, 'cover_media_id' => $viditelna->id]);
        DB::table('media_stack_items')->insert([
            ['media_stack_id' => $stack->id, 'media_item_id' => $viditelna->id, 'is_cover' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['media_stack_id' => $stack->id, 'media_item_id' => $vTrezoru->id, 'is_cover' => false, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $odpoved = $this->getJson('/api/v1/media-stacks/'.$stack->uuid)->assertOk();
        $ids = collect($odpoved->json('items'))->pluck('id');
        $this->assertFalse($ids->contains($vTrezoru->id), 'Detail stacku vydal fotku z trezoru.');

        $this->patchJson('/api/v1/media-stacks/'.$stack->uuid.'/cover', ['media_id' => $vTrezoru->id])
            ->assertStatus(422);
    }

    private function fotka(array $navic = []): MediaItem
    {
        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG_'.Str::random(4).'.jpg',
            'safe_filename' => 'img.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'uploaded_at' => now(),
            'taken_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
            'is_archived' => false,
            'is_hidden' => false,
        ], $navic));
    }
}
