<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\SharedLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class InnovationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Galerie', 'slug' => 'galerie', 'owner_id' => $this->user->id, 'is_default' => true]);
        $this->space->members()->attach($this->user->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->actingAs($this->user);
    }

    public function test_raw_pair_can_be_previewed_and_stacked(): void
    {
        $this->media(['original_filename' => 'DSC_1001.cr3', 'safe_filename' => 'DSC_1001.cr3', 'extension' => 'cr3', 'mime_type' => 'image/x-canon-cr3', 'is_raw' => true]);
        $this->media(['original_filename' => 'DSC_1001.jpg', 'safe_filename' => 'DSC_1001.jpg', 'extension' => 'jpg', 'is_raw' => false]);
        $preview = $this->getJson('/api/v1/media-stacks/preview')->assertOk()->assertJsonPath('count', 1);
        $this->postJson('/api/v1/media-stacks/apply', ['candidate_keys' => [$preview->json('groups.0.key')]])->assertCreated()->assertJsonPath('created', 1);
        $this->assertDatabaseCount('media_stacks', 1);
        $this->assertDatabaseCount('media_stack_items', 2);
    }

    public function test_trip_now_accepts_a_journal_note(): void
    {
        $trip = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->user->id, 'name' => 'Dnešní výlet', 'start_date' => today(), 'end_date' => today(), 'status' => 'active', 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson("/api/v1/trips/{$trip}/now")->assertOk()->assertJsonPath('trip.name', 'Dnešní výlet');
        $this->postJson("/api/v1/trips/{$trip}/journal", ['type' => 'note', 'content' => 'Výhled z kopce'])->assertCreated();
        $this->assertDatabaseHas('travel_journal_entries', ['trip_id' => $trip, 'content' => 'Výhled z kopce']);
    }

    public function test_guest_upload_waits_for_owner_approval(): void
    {
        Storage::fake('local'); Storage::fake('public');
        $link = SharedLink::create(['created_by' => $this->user->id, 'gallery_space_id' => $this->space->id, 'target_type' => 'selection', 'allow_guest_upload' => true, 'is_active' => true]);
        auth()->logout();
        $response = $this->post("/s/{$link->token}/upload", ['contributor_name' => 'Eva', 'files' => [UploadedFile::fake()->create('vylet.jpg', 10, 'image/jpeg')]], ['Accept' => 'application/json']);
        $response->assertCreated()->assertJsonPath('status', 'pending_review');
        $uuid = DB::table('guest_uploads')->value('uuid');
        $this->actingAs($this->user)->postJson("/api/v1/guest-uploads/{$uuid}/approve")->assertCreated();
        $this->assertDatabaseHas('guest_uploads', ['uuid' => $uuid, 'status' => 'approved']);
        $this->assertDatabaseCount('media_items', 1);
    }

    public function test_legacy_plan_requires_a_contact_before_ready_state(): void
    {
        $this->patchJson('/privacy/legacy', ['status' => 'ready', 'inactivity_months' => 12])->assertUnprocessable();
        $this->patchJson('/privacy/legacy', ['status' => 'ready', 'inactivity_months' => 12, 'contact_name' => 'Eva', 'contact_email' => 'eva@example.test'])->assertOk();
        $this->assertDatabaseHas('legacy_plans', ['user_id' => $this->user->id, 'status' => 'ready']);
    }

    public function test_ticket_pages_and_provider_fallbacks_never_return_404(): void
    {
        $this->get('/tickets')->assertOk()->assertInertia(fn ($page) => $page->component('Tickets/Index'));
        $this->get('/jizdenky')->assertOk()->assertInertia(fn ($page) => $page->component('Tickets/Index'));

        Cache::put('rj_cities_v2', [], 60);
        Cache::put('fb_city:' . md5('praha'), [], 60);
        Cache::put('fb_city:' . md5('brno'), [], 60);
        $response = $this->getJson('/api/v1/tickets/search?from=Praha&to=Brno&date=2026-08-01&adults=1')->assertOk();
        $carriers = collect($response->json())->pluck('carrier');
        $this->assertTrue($carriers->contains('RegioJet'));
        $this->assertTrue($carriers->contains('FlixBus'));
        $this->assertTrue($carriers->contains('České dráhy'));
    }

    private function media(array $overrides = []): int
    {
        return DB::table('media_items')->insertGetId(array_merge(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'owner_user_id' => $this->user->id, 'uploaded_by' => $this->user->id, 'original_filename' => Str::random(8).'.jpg', 'safe_filename' => Str::random(8).'.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1000, 'status' => 'ready', 'storage_status' => 'ready', 'taken_at' => '2026-07-07 12:00:00', 'width' => 4000, 'height' => 3000, 'is_raw' => false, 'is_favorite' => false, 'is_archived' => false, 'is_hidden' => false, 'created_at' => now(), 'updated_at' => now()], $overrides));
    }
}
