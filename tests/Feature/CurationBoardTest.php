<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CurationBoardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $partner;
    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->partner = User::factory()->create(['role' => 'partner']);
        $this->space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Galerie', 'slug' => 'galerie', 'owner_id' => $this->owner->id]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->space->members()->attach($this->partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->actingAs($this->owner);
    }

    public function test_shared_curation_board_supports_safe_media_selection_and_partner_vote(): void
    {
        $mediaUuid = $this->media($this->space->id);
        $board = $this->postJson('/api/v1/curation-boards', ['title' => 'Fotokniha'])->assertCreated()->json();
        $this->postJson("/api/v1/curation-boards/{$board['uuid']}/items", ['media_uuids' => [$mediaUuid]])->assertCreated()->assertJsonPath('items_count', 1);
        $itemId = DB::table('curation_board_items')->where('curation_board_id', $board['id'])->value('id');
        $this->patchJson("/api/v1/curation-boards/{$board['uuid']}/items/{$itemId}", ['status' => 'shortlisted', 'note' => 'Na titulku'])->assertOk();

        $this->actingAs($this->partner)->putJson("/api/v1/curation-boards/{$board['uuid']}/items/{$itemId}/vote", ['is_selected' => true])->assertOk()->assertJsonPath('selected', 1);
        $this->getJson("/api/v1/curation-boards/{$board['uuid']}")->assertOk()->assertJsonPath('items.0.status', 'shortlisted')->assertJsonPath('items.0.votes.selected', 1);
    }

    public function test_private_curation_board_is_not_visible_to_other_member_and_foreign_media_is_rejected(): void
    {
        $private = $this->postJson('/api/v1/curation-boards', ['title' => 'Překvapení', 'visibility' => 'private'])->assertCreated()->json();
        $foreignSpace = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Cizí', 'slug' => 'cizi', 'owner_id' => $this->owner->id]);
        $foreignMedia = $this->media($foreignSpace->id);
        $this->postJson("/api/v1/curation-boards/{$private['uuid']}/items", ['media_uuids' => [$foreignMedia]])->assertUnprocessable();
        $this->actingAs($this->partner)->getJson("/api/v1/curation-boards/{$private['uuid']}")->assertNotFound();
    }

    private function media(int $spaceId): string
    {
        $uuid = (string) Str::uuid();
        DB::table('media_items')->insert(['uuid' => $uuid, 'gallery_space_id' => $spaceId, 'owner_user_id' => $this->owner->id, 'uploaded_by' => $this->owner->id, 'original_filename' => 'vylet.jpg', 'safe_filename' => 'vylet.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 100, 'status' => 'ready', 'storage_status' => 'ready', 'is_hidden' => false, 'created_at' => now(), 'updated_at' => now()]);
        return $uuid;
    }
}
