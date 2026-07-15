<?php

namespace Tests\Feature;

use App\Jobs\Drive\CreateDriveFolderJob;
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

class RelationshipAnniversaryRecapTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_confirmed_anniversary_selection_creates_one_connected_album_story_memory_and_calendar_link(): void
    {
        Carbon::setTestNow('2026-07-15 12:00:00');
        Queue::fake();
        $owner = User::factory()->create(['role' => 'owner']);
        $partner = User::factory()->create(['role' => 'partner']);
        $space = GallerySpace::create(['name' => 'My dva', 'slug' => 'my-dva', 'owner_id' => $owner->id]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_share' => true]);
        $space->members()->attach($partner->id, ['role' => 'editor', 'can_share' => true]);

        $this->actingAs($owner)->putJson('/api/v1/relationship-milestones/relationship-anniversary', [
            'gallery_space_id' => $space->id, 'started_on' => '2024-07-01', 'reminder_days' => [30, 7, 1],
        ])->assertOk();
        $summer = $this->media($space, $owner, 'léto.jpg', '2025-08-10 14:00:00', true, 5);
        $spring = $this->media($space, $owner, 'jaro.jpg', '2026-04-05 10:00:00', false, 4);
        $this->media($space, $owner, 'před-vztahem.jpg', '2024-05-01 10:00:00');
        $this->media($space, $owner, 'další-rok.jpg', '2026-07-10 10:00:00');

        $overview = $this->getJson('/api/v1/relationship-milestones/relationship-anniversary/recap?gallery_space_id=' . $space->id)
            ->assertOk()->assertJsonPath('available', true)->assertJsonPath('year', 2)
            ->assertJsonPath('period.starts_on', '2025-07-01')->assertJsonPath('period.ends_on', '2026-07-01')
            ->assertJsonCount(2, 'candidates')->json();
        $this->assertEqualsCanonicalizing([$summer->uuid, $spring->uuid], collect($overview['candidates'])->pluck('uuid')->all());
        $this->assertTrue(collect($overview['candidates'])->contains(fn ($item) => $item['uuid'] === $summer->uuid && $item['suggested']));
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.partner_hub.anniversary_recap.year', 2)
            ->where('data.partner_hub.anniversary_recap.candidate_count', 2));

        $created = $this->postJson('/api/v1/relationship-milestones/relationship-anniversary/recap', [
            'gallery_space_id' => $space->id, 'title' => 'Druhý rok nás dvou',
            'note' => 'Výlety, domácí pohoda a společné objevy.',
            'media_uuids' => [$summer->uuid, $spring->uuid], 'cover_media_uuid' => $summer->uuid,
        ])->assertCreated()->assertJsonPath('created', true)->assertJsonPath('story_created', true)
            ->assertJsonPath('album.media_count', 2)->json();

        $albumId = (int) DB::table('albums')->where('uuid', $created['album']['uuid'])->value('id');
        $this->assertDatabaseHas('albums', ['id' => $albumId, 'gallery_space_id' => $space->id, 'anniversary_year' => 2, 'cover_media_id' => $summer->id, 'story_mode' => true]);
        $this->assertDatabaseHas('album_media', ['album_id' => $albumId, 'media_item_id' => $summer->id, 'is_cover' => true]);
        $this->assertDatabaseHas('album_media', ['album_id' => $albumId, 'media_item_id' => $spring->id]);
        $this->assertGreaterThanOrEqual(4, DB::table('album_story_blocks')->where('album_id', $albumId)->count());
        $this->assertDatabaseHas('shared_memory_moments', ['album_id' => $albumId, 'is_favorite' => true, 'happened_on' => '2026-07-01']);
        $this->assertDatabaseHas('album_user_permissions', ['album_id' => $albumId, 'user_id' => $partner->id, 'role' => 'editor']);
        $this->assertSame($albumId, (int) DB::table('calendar_events')->where('type', 'anniversary')->whereNotNull('recurrence_rule')->value('album_id'));
        Queue::assertPushed(CreateDriveFolderJob::class);

        $blocksBefore = DB::table('album_story_blocks')->where('album_id', $albumId)->count();
        $this->postJson('/api/v1/relationship-milestones/relationship-anniversary/recap', [
            'gallery_space_id' => $space->id, 'title' => 'Náš druhý rok',
            'media_uuids' => [$spring->uuid, $summer->uuid], 'cover_media_uuid' => $spring->uuid,
        ])->assertOk()->assertJsonPath('created', false)->assertJsonPath('story_created', false);
        $this->assertSame(1, DB::table('albums')->where('gallery_space_id', $space->id)->where('anniversary_year', 2)->count());
        $this->assertSame(1, DB::table('shared_memory_moments')->where('album_id', $albumId)->count());
        $this->assertSame($blocksBefore, DB::table('album_story_blocks')->where('album_id', $albumId)->count());

        $this->actingAs($partner)->getJson('/api/v1/relationship-milestones/relationship-anniversary/recap?gallery_space_id=' . $space->id)
            ->assertOk()->assertJsonPath('album.uuid', $created['album']['uuid'])
            ->assertJsonPath('album.memory.uuid', $created['memory']['uuid']);
        $this->getJson('/api/v1/shared-memory-moments')->assertOk()
            ->assertJsonPath('0.album.uuid', $created['album']['uuid']);
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.partner_hub.anniversary_recap', null));
    }

    private function media(GallerySpace $space, User $owner, string $filename, string $takenAt, bool $favorite = false, ?int $rating = null): MediaItem
    {
        return MediaItem::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $space->id,
            'owner_user_id' => $owner->id, 'uploaded_by' => $owner->id,
            'original_filename' => $filename, 'safe_filename' => Str::ascii($filename),
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo',
            'size_bytes' => 4096, 'width' => 2400, 'height' => 1600, 'taken_at' => $takenAt,
            'uploaded_at' => $takenAt, 'status' => 'ready', 'storage_status' => 'local_only',
            'is_hidden' => false, 'is_favorite' => $favorite, 'rating' => $rating,
        ]);
    }
}
