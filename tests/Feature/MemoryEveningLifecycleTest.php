<?php

namespace Tests\Feature;

use App\Jobs\Drive\CreateDriveFolderJob;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MemoryEveningLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_gallery_memory_becomes_shared_calendar_ritual_album_and_reflection(): void
    {
        Queue::fake(); [$owner, $partner, $space] = $this->couple();
        $media = collect([
            $this->media($space, $owner, 'vylet-1.jpg', now()->subYears(2)->setTime(12, 0)),
            $this->media($space, $owner, 'vylet-2.jpg', now()->subYears(2)->setTime(13, 0)),
            $this->media($space, $owner, 'vylet-video.mp4', now()->subYears(2)->setTime(14, 0), 'video'),
        ]);
        $scheduled = now()->addWeek()->setTime(19, 30);
        $evening = $this->actingAs($owner)->postJson('/api/v1/memory-evenings', [
            'gallery_space_id' => $space->id, 'fingerprint' => hash('sha256', 'our-trip'), 'source_type' => 'trip_anniversary',
            'title' => 'Dva roky od našeho výletu', 'description' => 'Chceme si připomenout nejlepší momenty.',
            'source_happened_on' => now()->subYears(2)->toDateString(), 'scheduled_for' => $scheduled->toIso8601String(),
            'repeat_annually' => true, 'media_uuids' => $media->pluck('uuid')->all(),
        ])->assertCreated()->assertJsonPath('status', 'planned')->assertJsonCount(3, 'items')->json();

        $this->assertDatabaseHas('calendar_events', ['uuid' => $evening['event']['uuid'], 'type' => 'memory_evening']);
        $this->assertDatabaseCount('event_participants', 2); $this->assertDatabaseCount('event_reminders', 4);
        $this->assertDatabaseCount('curation_board_items', 3);
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.partner_hub.memory_evening.uuid', $evening['uuid'])
            ->where('data.partner_hub.memory_evening.media_count', 3));

        $this->actingAs($partner)->postJson('/api/v1/memory-evenings/' . $evening['uuid'] . '/start')->assertOk()->assertJsonPath('status', 'active');
        $this->putJson('/api/v1/memory-evenings/' . $evening['uuid'] . '/media/' . $media[0]->uuid, ['is_selected' => true])->assertOk()->assertJsonPath('items.0.my_vote', true);
        $this->putJson('/api/v1/memory-evenings/' . $evening['uuid'] . '/media/' . $media[1]->uuid, ['is_selected' => true])->assertOk();
        $this->putJson('/api/v1/memory-evenings/' . $evening['uuid'] . '/reflection', ['mood' => 'grateful', 'note' => 'Připomnělo mi to, jak dobře nám spolu je.'])->assertOk();
        $this->actingAs($owner)->putJson('/api/v1/memory-evenings/' . $evening['uuid'] . '/reflection', ['mood' => 'nostalgic', 'note' => 'Příště si tento výlet zopakujeme.'])->assertOk();

        $completed = $this->postJson('/api/v1/memory-evenings/' . $evening['uuid'] . '/complete')->assertOk()
            ->assertJsonPath('status', 'completed')->assertJsonPath('items.0.status', 'selected')->assertJsonPath('items.2.status', 'rejected')->json();
        $this->assertNotEmpty($completed['album']['uuid']); $this->assertNotEmpty($completed['shared_memory']['uuid']);
        $albumId = DB::table('albums')->where('uuid', $completed['album']['uuid'])->value('id');
        $this->assertDatabaseCount('album_media', 2);
        $this->assertDatabaseHas('calendar_events', ['uuid' => $evening['event']['uuid'], 'status' => 'completed', 'album_id' => $albumId]);
        $this->assertDatabaseHas('shared_memory_moments', ['uuid' => $completed['shared_memory']['uuid'], 'calendar_event_id' => DB::table('calendar_events')->where('uuid', $evening['event']['uuid'])->value('id'), 'is_favorite' => true]);
        $this->assertDatabaseCount('shared_memory_reflections', 2);
        $this->assertDatabaseHas('album_user_permissions', ['album_id' => $albumId, 'user_id' => $owner->id, 'role' => 'editor']);
        $this->assertDatabaseHas('album_user_permissions', ['album_id' => $albumId, 'user_id' => $partner->id, 'role' => 'editor']);
        Queue::assertPushed(CreateDriveFolderJob::class);

        $this->getJson('/api/v1/calendar/events/' . $evening['event']['uuid'])->assertOk()
            ->assertJsonPath('origin.kind', 'memory_evening')->assertJsonPath('origin.memory_evening.uuid', $evening['uuid']);
    }

    public function test_memory_evening_is_idempotent_and_respects_space_and_read_only_permissions(): void
    {
        [$owner, $partner, $space] = $this->couple(); $media = $this->media($space, $owner, 'moment.jpg', now()->subYear());
        $payload = ['gallery_space_id' => $space->id, 'fingerprint' => hash('sha256', 'same'), 'source_type' => 'on_this_day', 'title' => 'Před rokem', 'scheduled_for' => now()->addWeek()->toIso8601String(), 'media_uuids' => [$media->uuid]];
        $first = $this->actingAs($owner)->postJson('/api/v1/memory-evenings', $payload)->assertCreated()->json();
        $this->postJson('/api/v1/memory-evenings', $payload)->assertOk()->assertJsonPath('uuid', $first['uuid']);
        $this->assertDatabaseCount('memory_evenings', 1); $this->assertDatabaseCount('calendar_events', 1);

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->getJson('/api/v1/memory-evenings/' . $first['uuid'])->assertNotFound();
        $partner->update(['read_only_mode' => true]);
        $this->actingAs($partner)->postJson('/api/v1/memory-evenings/' . $first['uuid'] . '/start')->assertForbidden();
    }

    private function media(GallerySpace $space, User $owner, string $filename, $takenAt, string $type = 'photo'): MediaItem
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        return MediaItem::create(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $space->id, 'owner_user_id' => $owner->id, 'uploaded_by' => $owner->id,
            'original_filename' => $filename, 'safe_filename' => $filename, 'extension' => $extension, 'mime_type' => $type === 'video' ? 'video/mp4' : 'image/jpeg',
            'media_type' => $type, 'size_bytes' => 4096, 'status' => 'ready', 'storage_status' => 'local_only', 'is_hidden' => false, 'taken_at' => $takenAt, 'uploaded_at' => now()]);
    }

    private function couple(): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]); $partner = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $space = GallerySpace::create(['name' => 'Naše vzpomínky', 'slug' => 'memory-couple', 'owner_id' => $owner->id, 'is_default' => true]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $space->members()->attach($partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        return [$owner, $partner, $space];
    }
}
