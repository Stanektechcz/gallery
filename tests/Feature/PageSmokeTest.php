<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PageSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $space = GallerySpace::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Testovací galerie', 'slug' => 'testovaci-galerie',
            'owner_id' => $this->user->id, 'is_default' => true,
        ]);
        $space->members()->attach($this->user->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        DB::table('media_items')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $space->id, 'owner_user_id' => $this->user->id, 'uploaded_by' => $this->user->id,
            'original_filename' => 'statistika.jpg', 'safe_filename' => 'statistika.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg',
            'media_type' => 'photo', 'size_bytes' => 1024, 'status' => 'ready', 'storage_status' => 'ready', 'taken_at' => now()->subYear(),
            'is_archived' => false, 'is_hidden' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->user);
    }

    public function test_all_static_authenticated_pages_render_without_server_error(): void
    {
        foreach ([
            '/', '/home', '/timeline', '/albums', '/albums/create', '/compare', '/tv', '/print', '/curation', '/milestones', '/shared-memories',
            '/trips', '/tickets', '/jizdenky', '/map', '/search', '/calendar', '/travel-inbox', '/weekly', '/planning', '/stats', '/inbox',
            '/people', '/places', '/activity', '/journey', '/itinerary', '/tags', '/recovery', '/privacy',
            '/favorites', '/trash', '/archive', '/vault', '/memories', '/shares', '/settings/storage/google', '/settings/security',
            '/admin', '/admin/storage-risk', '/admin/users', '/admin/jobs', '/admin/audit', '/admin/health', '/admin/integrations',
        ] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_public_pages_and_health_endpoints_are_reachable(): void
    {
        $this->app['auth']->guard()->logout();
        $this->get('/login')->assertOk();
        $this->get('/forgot-password')->assertOk();
        $this->get('/health/live')->assertOk();
        $this->get('/health/ready')->assertOk();
    }
}
