<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->user = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->space = GallerySpace::create([
            'uuid'     => \Str::uuid(),
            'name'     => 'Test',
            'slug'     => 'test',
            'owner_id' => $this->user->id,
        ]);
        $this->space->members()->attach($this->user->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true]);
    }

    /** @test */
    public function test_can_initiate_upload_session(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/uploads', [
                'filename'     => 'test.jpg',
                'mime_type'    => 'image/jpeg',
                'total_size'   => 1024 * 1024,
                'total_chunks' => 4,
            ]);

        $response->assertCreated();
        $response->assertJsonStructure(['uuid', 'total_chunks', 'received_chunks', 'status']);

        $this->assertDatabaseHas('upload_sessions', [
            'original_filename' => 'test.jpg',
            'user_id'           => $this->user->id,
            'status'            => 'pending',
        ]);
    }

    /** @test */
    public function test_can_upload_chunk(): void
    {
        // Initiate
        $init = $this->actingAs($this->user)
            ->postJson('/api/v1/uploads', [
                'filename'     => 'photo.jpg',
                'mime_type'    => 'image/jpeg',
                'total_size'   => 2048,
                'total_chunks' => 2,
            ]);

        $uuid = $init->json('uuid');

        // Upload chunk 0
        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/uploads/{$uuid}/chunks/0", [
                'chunk' => UploadedFile::fake()->createWithContent('chunk_0', str_repeat('A', 1024)),
            ]);

        $response->assertOk();
        $this->assertEquals(1, $response->json('received_chunks'));
        $this->assertFalse($response->json('complete'));
    }

    /** @test */
    public function test_can_get_session_status(): void
    {
        $init = $this->actingAs($this->user)
            ->postJson('/api/v1/uploads', [
                'filename'     => 'test.jpg',
                'mime_type'    => 'image/jpeg',
                'total_size'   => 1024,
                'total_chunks' => 1,
            ]);

        $uuid = $init->json('uuid');

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/uploads/{$uuid}");

        $response->assertOk();
        $response->assertJsonFragment(['uuid' => $uuid, 'status' => 'pending']);
    }

    /** @test */
    public function test_cannot_complete_incomplete_session(): void
    {
        $init = $this->actingAs($this->user)
            ->postJson('/api/v1/uploads', [
                'filename'     => 'test.jpg',
                'mime_type'    => 'image/jpeg',
                'total_size'   => 2048,
                'total_chunks' => 2,
            ]);

        $uuid = $init->json('uuid');

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/uploads/{$uuid}/complete");

        $response->assertUnprocessable();
        $response->assertJsonStructure(['error', 'received_chunks', 'total_chunks']);
    }

    /** @test */
    public function test_can_cancel_upload(): void
    {
        $init = $this->actingAs($this->user)
            ->postJson('/api/v1/uploads', [
                'filename'     => 'test.jpg',
                'mime_type'    => 'image/jpeg',
                'total_size'   => 1024,
                'total_chunks' => 1,
            ]);

        $uuid = $init->json('uuid');

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/uploads/{$uuid}");

        $response->assertOk();
        $this->assertDatabaseMissing('upload_sessions', ['uuid' => $uuid]);
    }

    /** @test */
    public function test_unauthenticated_cannot_upload(): void
    {
        $response = $this->postJson('/api/v1/uploads', [
            'filename'     => 'test.jpg',
            'mime_type'    => 'image/jpeg',
            'total_size'   => 1024,
            'total_chunks' => 1,
        ]);

        $response->assertUnauthorized();
    }
}
