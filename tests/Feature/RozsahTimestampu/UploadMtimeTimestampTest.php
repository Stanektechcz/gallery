<?php

namespace Tests\Feature\RozsahTimestampu;

use App\Models\GallerySpace;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `upload_sessions.client_modified_at` nese mtime souboru z prohlížeče —
 * hodnotu, kterou uživatel nevyplňuje a klidně může být mimo rozsah MySQL
 * `TIMESTAMP` (špatně nastavené hodiny v telefonu, budoucí datum). Na rozdíl
 * od `happened_at`/`recorded_at` se tu upload kvůli tomu neodmítá — mimo
 * rozsah se hodnota jen zahodí na `null`.
 */
class UploadMtimeTimestampTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->space = GallerySpace::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Test', 'slug' => 'test-'.Str::random(5),
            'owner_id' => $this->user->id,
        ]);
        $this->space->members()->attach($this->user->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true]);
    }

    public function test_mtime_v_roce_2045_se_ulozi_jako_null_a_upload_projde(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/uploads', [
            'filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'total_size' => 1024,
            'total_chunks' => 1,
            'client_modified_at' => '2045-01-01T00:00:00Z',
        ]);

        $response->assertCreated();

        $session = UploadSession::where('uuid', $response->json('uuid'))->firstOrFail();
        $this->assertNull($session->client_modified_at,
            'Datum mimo rozsah TIMESTAMP se má zahodit, ne uložit nebo zamítnout upload.');
    }

    public function test_mtime_v_rozsahu_se_ulozi(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/uploads', [
            'filename' => 'test.jpg',
            'mime_type' => 'image/jpeg',
            'total_size' => 1024,
            'total_chunks' => 1,
            'client_modified_at' => '2026-09-20T12:00:00Z',
        ]);

        $response->assertCreated();

        $session = UploadSession::where('uuid', $response->json('uuid'))->firstOrFail();
        $this->assertNotNull($session->client_modified_at);
    }
}
