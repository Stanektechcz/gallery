<?php

namespace Tests\Feature\Nahravani;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Kontrola duplicity neprozradí trezor a nepřeskočí fotku, která nefunguje.
 *
 * Stačil hash souboru a odpověď řekla „už existuje" i s názvem — i pro
 * fotku zamčenou v trezoru. A selhaná kopie blokovala nové nahrání: klient
 * soubor přeskočil a v galerii nebylo nic.
 */
class KontrolaDuplicityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->space = GallerySpace::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Naše', 'slug' => 'nase', 'owner_id' => $this->user->id, 'is_default' => true,
        ]);
        $this->space->members()->attach($this->user->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
    }

    public function test_fotka_v_zamcenem_trezoru_se_neprozradi(): void
    {
        $this->media(['sha256' => str_repeat('a', 64), 'is_hidden' => true, 'original_filename' => 'tajne-foto.jpg']);

        $odpoved = $this->actingAs($this->user)->postJson('/api/v1/uploads/check-duplicate', ['sha256' => str_repeat('a', 64)])
            ->assertOk()
            ->assertJsonPath('exists', false);

        $odpoved->assertDontSee('tajne-foto');
        $this->assertNull($odpoved->json('media_uuid'));
    }

    public function test_odemceny_trezor_duplicitu_pozna(): void
    {
        $this->media(['sha256' => str_repeat('a', 64), 'is_hidden' => true]);

        // Sezení (a s ním trezor) má /api/v1 jen pro požadavky z vlastní stránky.
        config(['sanctum.stateful' => ['localhost']]);
        $this->actingAs($this->user)->withSession($this->odemcenyTrezor($this->user))
            ->withHeader('Referer', 'http://localhost/')
            ->postJson('/api/v1/uploads/check-duplicate', ['sha256' => str_repeat('a', 64)])
            ->assertOk()
            ->assertJsonPath('exists', true);
    }

    public function test_selhana_kopie_nahrani_neblokuje(): void
    {
        $this->media(['sha256' => str_repeat('b', 64), 'status' => 'failed', 'original_filename' => 'rozbita.jpg']);

        $odpoved = $this->actingAs($this->user)->postJson('/api/v1/uploads/check-duplicate', ['sha256' => str_repeat('b', 64)])
            ->assertOk()
            ->assertJsonPath('exists', false);

        $odpoved->assertDontSee('rozbita');
    }

    public function test_hotova_viditelna_fotka_je_duplicita(): void
    {
        $media = $this->media(['sha256' => str_repeat('c', 64)]);

        $this->actingAs($this->user)->postJson('/api/v1/uploads/check-duplicate', ['sha256' => str_repeat('c', 64)])
            ->assertOk()
            ->assertJsonPath('exists', true)
            ->assertJsonPath('media_uuid', $media->uuid);
    }

    private function media(array $atributy = []): MediaItem
    {
        return MediaItem::create($atributy + [
            'gallery_space_id' => $this->space->id, 'owner_user_id' => $this->user->id, 'uploaded_by' => $this->user->id,
            'original_filename' => 'x.jpg', 'safe_filename' => 'x.jpg', 'extension' => 'jpg',
            'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1,
            'status' => 'ready', 'storage_status' => 'local_only', 'uploaded_at' => now(),
        ]);
    }
}
