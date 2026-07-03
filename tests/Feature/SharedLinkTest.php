<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $adrian;
    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adrian = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->space  = GallerySpace::create([
            'uuid'     => \Str::uuid(),
            'name'     => 'Test',
            'slug'     => 'test',
            'owner_id' => $this->adrian->id,
        ]);
        $this->space->members()->attach($this->adrian->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true]);
    }

    /** @test */
    public function test_can_create_shared_link(): void
    {
        $response = $this->actingAs($this->adrian)
            ->postJson('/shares', [
                'target_type'    => 'selection',
                'allow_download' => true,
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['token', 'url']);
        $this->assertDatabaseHas('shared_links', ['created_by' => $this->adrian->id, 'target_type' => 'selection']);
    }

    /** @test */
    public function test_shared_link_with_password(): void
    {
        $response = $this->actingAs($this->adrian)
            ->postJson('/shares', [
                'target_type' => 'selection',
                'password'    => 'secret1234',
            ]);

        $token = $response->json('token');
        $this->assertDatabaseHas('shared_links', ['token' => $token]);

        $link = \App\Models\SharedLink::where('token', $token)->first();
        $this->assertNotNull($link->password_hash);
        $this->assertTrue(\Hash::check('secret1234', $link->password_hash));
    }

    /** @test */
    public function test_shared_link_with_expiry(): void
    {
        $expiry = now()->addDay()->format('Y-m-d H:i:s');

        $response = $this->actingAs($this->adrian)
            ->postJson('/shares', [
                'target_type' => 'selection',
                'expires_at'  => $expiry,
            ]);

        $token = $response->json('token');
        $link  = \App\Models\SharedLink::where('token', $token)->first();
        $this->assertNotNull($link->expires_at);
    }

    /** @test */
    public function test_can_delete_shared_link(): void
    {
        $response = $this->actingAs($this->adrian)
            ->postJson('/shares', ['target_type' => 'selection']);

        $token = $response->json('token');
        $link  = \App\Models\SharedLink::where('token', $token)->first();

        $this->actingAs($this->adrian)
            ->deleteJson("/shares/{$link->id}")
            ->assertOk();

        $this->assertDatabaseMissing('shared_links', ['id' => $link->id]);
    }
}
