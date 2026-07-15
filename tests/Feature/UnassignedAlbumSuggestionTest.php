<?php

namespace Tests\Feature;

use App\Jobs\Drive\CreateDriveFolderJob;
use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UnassignedAlbumSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_event_cluster_becomes_one_album_story_memory_and_shared_context(): void
    {
        Carbon::setTestNow('2026-07-15 12:00:00');
        Queue::fake();
        [$owner, $partner, $space] = $this->couple();
        $event = CalendarEvent::create([
            'gallery_space_id' => $space->id, 'created_by' => $owner->id, 'title' => 'Výlet k přehradě',
            'starts_at' => '2026-07-12 09:00:00', 'ends_at' => '2026-07-12 19:00:00',
            'place_name' => 'Brněnská přehrada', 'is_private' => false,
        ]);
        $media = collect([
            $this->media($space, $owner, 'u-vody.jpg', '2026-07-12 10:00:00', 'photo', true),
            $this->media($space, $owner, 'obed.jpg', '2026-07-12 13:00:00'),
            $this->media($space, $owner, 'zapad.mp4', '2026-07-12 17:00:00', 'video'),
        ]);
        $this->media($space, $owner, 'soukrome.jpg', '2026-07-12 14:00:00', 'photo', false, true);
        $this->media($space, $owner, 'jiny-den.jpg', '2026-07-14 14:00:00');

        $payload = $this->actingAs($owner)->getJson('/api/v1/album-suggestions?gallery_space_id=' . $space->id)
            ->assertOk()->assertJsonPath('available', true)->assertJsonCount(1, 'suggestions')
            ->assertJsonPath('suggestions.0.title', 'Výlet k přehradě')
            ->assertJsonPath('suggestions.0.media_count', 3)
            ->assertJsonPath('suggestions.0.video_count', 1)
            ->assertJsonPath('suggestions.0.context.type', 'event')->json('suggestions.0');
        $this->assertEqualsCanonicalizing($media->pluck('uuid')->all(), collect($payload['media'])->pluck('uuid')->all());

        $this->get('/albums')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Albums/Index')->has('albumSuggestions', 1)
            ->where('albumSuggestions.0.fingerprint', $payload['fingerprint']));
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.partner_hub.album_suggestion.fingerprint', $payload['fingerprint']));

        $created = $this->postJson('/api/v1/album-suggestions/' . $payload['fingerprint'] . '/accept', [
            'gallery_space_id' => $space->id, 'title' => 'Náš den u přehrady',
            'description' => 'Slunce, voda a společný oběd.', 'media_uuids' => $media->pluck('uuid')->all(),
            'cover_media_uuid' => $media[0]->uuid, 'create_memory' => true,
        ])->assertCreated()->assertJsonPath('created', true)->assertJsonPath('story_created', true)
            ->assertJsonPath('album.media_count', 3)->json();

        $albumId = (int) DB::table('albums')->where('uuid', $created['album']['uuid'])->value('id');
        $this->assertDatabaseHas('albums', ['id' => $albumId, 'title' => 'Náš den u přehrady', 'cover_media_id' => $media[0]->id, 'story_mode' => true, 'visibility' => 'shared']);
        foreach ($media as $item) {
            $this->assertDatabaseHas('album_media', ['album_id' => $albumId, 'media_item_id' => $item->id]);
            $this->assertDatabaseHas('event_attachments', ['event_id' => $event->id, 'media_item_id' => $item->id, 'kind' => 'memory']);
            $this->assertSame($albumId, (int) $item->fresh()->primary_album_id);
        }
        $this->assertSame($albumId, (int) $event->fresh()->album_id);
        $this->assertGreaterThanOrEqual(4, DB::table('album_story_blocks')->where('album_id', $albumId)->count());
        $this->assertDatabaseHas('shared_memory_moments', ['album_id' => $albumId, 'calendar_event_id' => $event->id, 'title' => 'Náš den u přehrady']);
        $this->assertDatabaseHas('album_user_permissions', ['album_id' => $albumId, 'user_id' => $owner->id, 'role' => 'editor']);
        $this->assertDatabaseHas('album_user_permissions', ['album_id' => $albumId, 'user_id' => $partner->id, 'role' => 'editor']);
        $this->assertDatabaseHas('album_suggestion_decisions', ['gallery_space_id' => $space->id, 'fingerprint' => $payload['fingerprint'], 'action' => 'accepted', 'album_id' => $albumId]);
        Queue::assertPushed(CreateDriveFolderJob::class);

        $this->getJson('/api/v1/album-suggestions?gallery_space_id=' . $space->id)->assertOk()->assertJsonCount(0, 'suggestions');
        $this->postJson('/api/v1/album-suggestions/' . $payload['fingerprint'] . '/accept', [
            'gallery_space_id' => $space->id, 'media_uuids' => $media->pluck('uuid')->all(),
        ])->assertOk()->assertJsonPath('already_decided', true)->assertJsonPath('album.uuid', $created['album']['uuid']);
        $this->actingAs($partner)->get('/albums/' . $created['album']['uuid'])->assertOk();
    }

    public function test_suggestion_can_be_dismissed_and_foreign_media_cannot_be_injected(): void
    {
        Carbon::setTestNow('2026-07-15 12:00:00');
        [$owner, , $space] = $this->couple();
        $cluster = collect([
            $this->media($space, $owner, 'a.jpg', '2026-07-10 10:00:00'),
            $this->media($space, $owner, 'b.jpg', '2026-07-10 11:00:00'),
            $this->media($space, $owner, 'c.jpg', '2026-07-10 12:00:00'),
        ]);
        $other = $this->media($space, $owner, 'mimo.jpg', '2026-07-13 10:00:00');
        $suggestion = $this->actingAs($owner)->getJson('/api/v1/album-suggestions?gallery_space_id=' . $space->id)->json('suggestions.0');

        $this->postJson('/api/v1/album-suggestions/' . $suggestion['fingerprint'] . '/accept', [
            'gallery_space_id' => $space->id, 'media_uuids' => $cluster->pluck('uuid')->push($other->uuid)->all(),
        ])->assertStatus(422);
        $this->assertDatabaseCount('albums', 0);

        $this->postJson('/api/v1/album-suggestions/' . $suggestion['fingerprint'] . '/dismiss', ['gallery_space_id' => $space->id])
            ->assertOk()->assertJsonPath('dismissed', true);
        $this->assertDatabaseHas('album_suggestion_decisions', ['fingerprint' => $suggestion['fingerprint'], 'action' => 'dismissed']);
        $this->getJson('/api/v1/album-suggestions?gallery_space_id=' . $space->id)->assertOk()->assertJsonCount(0, 'suggestions');
    }

    private function couple(): array
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $partner = User::factory()->create(['role' => 'partner']);
        $space = GallerySpace::create(['name' => 'My dva', 'slug' => 'my-dva', 'owner_id' => $owner->id]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_share' => true]);
        $space->members()->attach($partner->id, ['role' => 'editor', 'can_share' => true]);
        return [$owner, $partner, $space];
    }

    private function media(GallerySpace $space, User $owner, string $filename, string $takenAt, string $type = 'photo', bool $favorite = false, bool $hidden = false): MediaItem
    {
        $extension = $type === 'video' ? 'mp4' : 'jpg';
        return MediaItem::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $space->id,
            'owner_user_id' => $owner->id, 'uploaded_by' => $owner->id,
            'original_filename' => $filename, 'safe_filename' => $filename, 'extension' => $extension,
            'mime_type' => $type === 'video' ? 'video/mp4' : 'image/jpeg', 'media_type' => $type,
            'size_bytes' => 4096, 'width' => 2400, 'height' => 1600, 'taken_at' => $takenAt,
            'uploaded_at' => $takenAt, 'status' => 'ready', 'storage_status' => 'local_only',
            'is_hidden' => $hidden, 'is_favorite' => $favorite,
        ]);
    }
}
