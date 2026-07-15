<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TravelJournalRecordingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $partner;
    private GallerySpace $space;
    private int $tripId;
    private int $albumId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->partner = User::factory()->create(['role' => 'partner']);
        $this->space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Náš prostor', 'slug' => 'nas-prostor', 'owner_id' => $this->owner->id]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->space->members()->attach($this->partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Pálava', 'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(), 'timezone' => 'Europe/Prague', 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('trip_days')->insert(['trip_id' => $this->tripId, 'date' => now()->toDateString(), 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->albumId = DB::table('albums')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'trip_id' => $this->tripId, 'title' => 'Naše Pálava', 'slug' => 'nase-palava', 'visibility' => 'shared', 'story_mode' => true, 'created_by' => $this->owner->id, 'updated_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->owner);
    }

    public function test_real_voice_moment_follows_privacy_and_story_lifecycle(): void
    {
        $entry = $this->post("/api/v1/trips/{$this->tripId}/journal-recordings", [
            'recording' => UploadedFile::fake()->create('moment.webm', 32, 'audio/webm'),
            'duration_ms' => 12500, 'content' => 'Tohle ráno si chceme pamatovat.',
            'visibility' => 'shared', 'mood' => 'grateful', 'is_story_worthy' => 1,
            'latitude' => 48.8747, 'longitude' => 16.6718,
        ])->assertCreated()->assertJsonPath('recording_duration_ms', 12500)->json();

        $recording = DB::table('travel_journal_recordings')->where('journal_entry_id', $entry['id'])->first();
        $this->assertNotNull($recording);
        Storage::disk('local')->assertExists($recording->path);
        $this->getJson("/api/v1/trips/{$this->tripId}/now")->assertOk()
            ->assertJsonPath('journal.0.recording_url', "/api/v1/trips/{$this->tripId}/journal/{$entry['id']}/recording")
            ->assertJsonPath('journal.0.mood', 'grateful');
        $story = json_decode(DB::table('album_story_blocks')->where('album_id', $this->albumId)->value('content'), true);
        $this->assertSame($entry['id'], $story['source_journal_entry_id']);
        $this->assertSame("/api/v1/trips/{$this->tripId}/journal/{$entry['id']}/recording", $story['audio_url']);

        $this->actingAs($this->partner)->get("/api/v1/trips/{$this->tripId}/journal/{$entry['id']}/recording")
            ->assertOk()->assertHeader('Content-Type', 'audio/webm');
        $this->getJson("/api/v1/trips/{$this->tripId}/now")->assertOk()->assertJsonFragment(['id' => $entry['id'], 'visibility' => 'shared']);

        $this->actingAs($this->owner)->patchJson("/api/v1/trips/{$this->tripId}/journal/{$entry['id']}", ['visibility' => 'private'])
            ->assertOk()->assertJsonPath('is_story_worthy', 0);
        $this->assertDatabaseMissing('album_story_blocks', ['album_id' => $this->albumId]);
        $this->actingAs($this->partner)->getJson("/api/v1/trips/{$this->tripId}/now")->assertOk()->assertJsonCount(0, 'journal');
        $this->get("/api/v1/trips/{$this->tripId}/journal/{$entry['id']}/recording")->assertNotFound();
        $this->actingAs($this->owner)->get("/api/v1/trips/{$this->tripId}/journal/{$entry['id']}/recording")->assertOk();

        $this->patchJson("/api/v1/trips/{$this->tripId}/journal/{$entry['id']}", ['visibility' => 'shared', 'is_story_worthy' => true, 'content' => 'Upravený společný popis.'])->assertOk();
        $updatedStory = json_decode(DB::table('album_story_blocks')->where('album_id', $this->albumId)->value('content'), true);
        $this->assertSame('Upravený společný popis.', $updatedStory['quote']);
        $this->assertArrayHasKey('audio_url', $updatedStory);

        $this->deleteJson("/api/v1/trips/{$this->tripId}/journal/{$entry['id']}")->assertNoContent();
        Storage::disk('local')->assertMissing($recording->path);
        $this->assertDatabaseMissing('travel_journal_recordings', ['journal_entry_id' => $entry['id']]);
        $this->assertDatabaseMissing('album_story_blocks', ['album_id' => $this->albumId]);
    }

    public function test_voice_upload_rejects_unsupported_files_and_read_only_writes(): void
    {
        $this->post("/api/v1/trips/{$this->tripId}/journal-recordings", ['recording' => UploadedFile::fake()->create('payload.exe', 2, 'application/octet-stream'), 'duration_ms' => 1000])->assertUnprocessable();
        $this->owner->update(['read_only_mode' => true]);
        $this->post("/api/v1/trips/{$this->tripId}/journal-recordings", ['recording' => UploadedFile::fake()->create('moment.webm', 2, 'audio/webm'), 'duration_ms' => 1000])->assertForbidden();
    }
}
