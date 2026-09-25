<?php

namespace Tests\Feature\Cesty;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Společná příprava pro testy cest: dvojice (vlastník + editor), host
 * (`viewer`) v témže prostoru a jedna cesta s hlavní kalendářovou kartou.
 */
abstract class CestyTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $partner;

    protected User $guest;

    protected GallerySpace $space;

    protected int $tripId;

    protected int $tripEventId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->partner = User::factory()->create(['role' => 'partner']);
        $this->guest = User::factory()->create(['role' => 'owner']);
        $this->space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Cesty', 'slug' => 'cesty-'.Str::random(5), 'owner_id' => $this->owner->id]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()->subDays(3)]);
        $this->space->members()->attach($this->partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()->subDays(2)]);
        $this->space->members()->attach($this->guest->id, ['role' => 'viewer', 'can_delete' => false, 'can_share' => false, 'joined_at' => now()->subDay()]);
        $this->actingAs($this->owner);
    }

    /** Cesta s hlavní kartou v kalendáři (`type = trip`). */
    protected function cesta(string $start, string $end, array $atributy = []): int
    {
        $this->tripId = DB::table('trips')->insertGetId(array_merge([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Vídeň',
            'start_date' => $start, 'end_date' => $end, 'timezone' => 'Europe/Prague', 'currency' => 'CZK',
            'status' => 'planned', 'created_at' => now(), 'updated_at' => now(),
        ], $atributy));
        $this->tripEventId = DB::table('calendar_events')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'trip_id' => $this->tripId, 'title' => 'Vídeň', 'type' => 'trip', 'status' => 'planned',
            'starts_at' => Carbon::parse($start.' 09:00:00'), 'ends_at' => Carbon::parse($end.' 20:00:00'),
            'timezone' => 'Europe/Prague', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->tripId;
    }

    protected function media(array $overrides = []): int
    {
        return DB::table('media_items')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id,
            'owner_user_id' => $this->owner->id, 'uploaded_by' => $this->owner->id,
            'original_filename' => Str::random(10).'.jpg', 'safe_filename' => Str::random(10).'.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1000,
            'status' => 'ready', 'storage_status' => 'ready', 'taken_at' => now()->subYear(),
            'is_favorite' => false, 'is_archived' => false, 'is_hidden' => false,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }
}
