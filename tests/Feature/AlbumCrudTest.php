<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlbumCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $adrian;
    private User $makinka;
    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adrian = User::factory()->create([
            'name'      => 'Adrian',
            'email'     => 'adrian@test.local',
            'role'      => 'owner',
            'is_active' => true,
        ]);

        $this->makinka = User::factory()->create([
            'name'      => 'Makinka',
            'email'     => 'makinka@test.local',
            'role'      => 'partner',
            'is_active' => true,
        ]);

        $this->space = GallerySpace::create([
            'uuid'       => \Str::uuid(),
            'name'       => 'Naše galerie',
            'slug'       => 'nase-galerie',
            'owner_id'   => $this->adrian->id,
            'is_default' => true,
        ]);

        $this->space->members()->attach($this->adrian->id,  ['role' => 'owner',  'can_delete' => true,  'can_share' => true,  'joined_at' => now()]);
        $this->space->members()->attach($this->makinka->id, ['role' => 'editor', 'can_delete' => false, 'can_share' => true,  'joined_at' => now()]);
    }

    /** @test */
    public function test_adrian_can_create_album(): void
    {
        $response = $this->actingAs($this->adrian)
            ->post('/albums', [
                'title'      => 'Česká republika',
                'visibility' => 'private',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('albums', ['title' => 'Česká republika']);
    }

    /** @test */
    public function test_makinka_can_create_album(): void
    {
        $response = $this->actingAs($this->makinka)
            ->post('/albums', [
                'title'      => 'Makinkina alba',
                'visibility' => 'private',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('albums', ['title' => 'Makinkina alba']);
    }

    /** @test */
    public function test_nested_album_creation(): void
    {
        $this->actingAs($this->adrian)
            ->post('/albums', ['title' => 'Česká republika']);

        $cr = Album::where('title', 'Česká republika')->first();

        $this->actingAs($this->adrian)
            ->post('/albums', ['title' => 'Praha', 'parent_id' => $cr->id]);

        $praha = Album::where('title', 'Praha')->first();

        $this->assertEquals(1, $praha->depth);
        $this->assertEquals($cr->id, $praha->parent_id);
    }

    /** @test */
    public function test_album_tree_endpoint(): void
    {
        $this->actingAs($this->adrian)
            ->post('/albums', ['title' => 'Root']);

        $response = $this->actingAs($this->adrian)
            ->getJson('/albums/tree');

        $response->assertOk();
        $response->assertJsonStructure([['id', 'title', 'depth', 'children']]);
    }

    /** @test */
    public function test_unauthenticated_user_cannot_access_albums(): void
    {
        $response = $this->get('/albums');
        $response->assertRedirect('/login');
    }

    /** @test */
    public function test_album_rename_updates_path(): void
    {
        $this->actingAs($this->adrian)->post('/albums', ['title' => 'Old Name']);
        $album = Album::where('title', 'Old Name')->first();

        $this->actingAs($this->adrian)
            ->patch("/albums/{$album->uuid}", ['title' => 'New Name']);

        $album->refresh();
        $this->assertEquals('New Name', $album->title);
        $this->assertStringContainsString('New Name', $album->full_display_path);
    }
}
