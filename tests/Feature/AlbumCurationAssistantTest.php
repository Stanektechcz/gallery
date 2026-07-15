<?php

namespace Tests\Feature;

use App\Jobs\Media\EnqueueAlbumDriveSyncJob;
use App\Jobs\Media\RepairAlbumMediaPreviewsJob;
use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\StorageConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlbumCurationAssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $partner;
    private GallerySpace $space;
    private Album $album;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->partner = User::factory()->create(['role' => 'partner']);
        $this->space = GallerySpace::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Naše galerie',
            'slug' => 'nase-galerie',
            'owner_id' => $this->owner->id,
        ]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->space->members()->attach($this->partner->id, ['role' => 'editor', 'can_delete' => false, 'can_share' => true, 'joined_at' => now()]);
        $this->album = Album::create([
            'gallery_space_id' => $this->space->id,
            'title' => 'Vídeň ve dvou',
            'slug' => 'viden-ve-dvou',
            'visibility' => 'shared',
            'created_by' => $this->owner->id,
            'updated_by' => $this->owner->id,
        ]);
        $this->actingAs($this->owner);
    }

    public function test_it_recommends_an_explainable_cover_and_deduplicates_a_burst_in_shortlist(): void
    {
        $best = $this->media('nejhezci.jpg', [
            'is_favorite' => true,
            'rating' => 5,
            'width' => 6000,
            'height' => 4000,
            'taken_at' => '2026-06-27 18:00:00',
            'latitude' => 48.2082,
            'longitude' => 16.3738,
            'drive_file_id' => 'drive-best',
            'storage_status' => 'synced',
            'last_verified_at' => now(),
        ], ['original', 'thumbnail', 'large']);
        $similar = $this->media('podobna.jpg', ['rating' => 4, 'width' => 5500, 'height' => 3600, 'taken_at' => '2026-06-27 18:00:01'], ['original', 'thumbnail']);
        $broken = $this->media('bez-nahledu.jpg', ['rating' => 1, 'processing_error' => 'preview failed'], []);
        $video = $this->media('moment.mp4', ['media_type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'width' => 3840, 'height' => 2160], ['original', 'video_poster']);

        $stackId = DB::table('media_stacks')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->space->id,
            'name' => 'Série u Dunaje',
            'cover_media_id' => $best->id,
            'created_by' => $this->owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([$best, $similar] as $order => $media) {
            DB::table('media_stack_items')->insert(['media_stack_id' => $stackId, 'media_item_id' => $media->id, 'sort_order' => $order, 'is_cover' => $media->is($best), 'created_at' => now(), 'updated_at' => now()]);
        }

        $response = $this->getJson("/api/v1/albums/{$this->album->uuid}/curation-assistant")
            ->assertOk()
            ->assertJsonPath('summary.media_count', 4)
            ->assertJsonPath('cover_recommendation.uuid', $best->uuid)
            ->assertJsonPath('backup.coverage_percent', 25)
            ->assertJsonPath('backup.missing_original', 1)
            ->assertJsonPath('quality.missing_preview', 1);

        $payload = $response->json();
        $shortlistUuids = collect($payload['shortlist'])->pluck('uuid');
        $this->assertTrue($shortlistUuids->contains($best->uuid));
        $this->assertFalse($shortlistUuids->contains($similar->uuid), 'Ze stejné série má být ve shortlistu jen nejlepší záběr.');
        $this->assertContains('označeno jako oblíbené', $payload['cover_recommendation']['reasons']);

        $this->putJson("/api/v1/albums/{$this->album->uuid}/cover", ['media_uuid' => $best->uuid])
            ->assertOk()
            ->assertJsonPath('cover.media_uuid', $best->uuid);
        $this->assertDatabaseHas('albums', ['id' => $this->album->id, 'cover_media_id' => $best->id]);

        $board = $this->postJson("/api/v1/albums/{$this->album->uuid}/curation-shortlist")
            ->assertCreated()
            ->assertJsonPath('board.title', 'Společný výběr · Vídeň ve dvou')
            ->json('board');
        $this->assertCount(3, $board['items']);
        $this->assertDatabaseHas('curation_boards', ['album_id' => $this->album->id, 'purpose' => 'album_selection']);

        $this->actingAs($this->partner)
            ->putJson("/api/v1/curation-boards/{$board['uuid']}/items/{$board['items'][0]['id']}/vote", ['is_selected' => true])
            ->assertOk()
            ->assertJsonPath('selected', 1);
        $this->getJson("/api/v1/albums/{$this->album->uuid}/curation-assistant")
            ->assertOk()
            ->assertJsonPath('board.items.0.votes.selected', 1);
    }

    public function test_album_actions_queue_non_blocking_backup_and_preview_repairs_and_reject_foreign_cover(): void
    {
        Queue::fake();
        $local = $this->media('lokalni.jpg', ['width' => 3000, 'height' => 2000], ['original']);
        $foreignAlbum = Album::create([
            'gallery_space_id' => $this->space->id,
            'title' => 'Jiné album',
            'slug' => 'jine-album',
            'created_by' => $this->owner->id,
            'updated_by' => $this->owner->id,
        ]);
        $foreign = $this->media('cizi.jpg', [], ['original', 'thumbnail'], $foreignAlbum);
        StorageConnection::create([
            'provider' => 'google_drive',
            'owner_user_id' => $this->owner->id,
            'connection_status' => 'healthy',
            'root_folder_id' => 'root-folder',
            'last_successful_request_at' => now(),
        ]);

        $this->postJson("/api/v1/albums/{$this->album->uuid}/backup")
            ->assertAccepted()
            ->assertJsonPath('queued', 1);
        $this->postJson("/api/v1/albums/{$this->album->uuid}/repair-previews")
            ->assertAccepted()
            ->assertJsonPath('queued', 1);
        Queue::assertPushed(EnqueueAlbumDriveSyncJob::class);
        Queue::assertPushed(RepairAlbumMediaPreviewsJob::class);

        $this->putJson("/api/v1/albums/{$this->album->uuid}/cover", ['media_uuid' => $foreign->uuid])->assertNotFound();
        $this->assertDatabaseMissing('albums', ['id' => $this->album->id, 'cover_media_id' => $foreign->id]);
        $this->assertDatabaseHas('media_items', ['id' => $local->id, 'drive_file_id' => null]);
    }

    private function media(string $filename, array $attributes = [], array $variants = ['original', 'thumbnail'], ?Album $album = null): MediaItem
    {
        $album ??= $this->album;
        $media = MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->space->id,
            'owner_user_id' => $this->owner->id,
            'uploaded_by' => $this->owner->id,
            'primary_album_id' => $album->id,
            'original_filename' => $filename,
            'safe_filename' => $filename,
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1_000_000,
            'status' => 'ready',
            'storage_status' => 'local_only',
            'is_hidden' => false,
            'uploaded_at' => now(),
        ], $attributes));
        DB::table('album_media')->insert([
            'album_id' => $album->id,
            'media_item_id' => $media->id,
            'sort_order' => DB::table('album_media')->where('album_id', $album->id)->count(),
            'is_cover' => false,
            'added_at' => now(),
            'added_by' => $this->owner->id,
        ]);
        foreach ($variants as $type) {
            DB::table('media_variants')->insert([
                'media_item_id' => $media->id,
                'type' => $type,
                'disk' => 'public',
                'path' => "media/{$media->uuid}/{$type}.jpg",
                'width' => $media->width,
                'height' => $media->height,
                'format' => $type === 'video_poster' ? 'jpg' : $media->extension,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $album->update([
            'media_count' => DB::table('album_media')->where('album_id', $album->id)->count(),
            'total_size_bytes' => DB::table('media_items')->where('primary_album_id', $album->id)->sum('size_bytes'),
        ]);

        return $media->fresh();
    }
}
