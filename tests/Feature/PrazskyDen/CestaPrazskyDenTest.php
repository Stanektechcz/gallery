<?php

namespace Tests\Feature\PrazskyDen;

use App\Models\GallerySpace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Datum bez zadané hodnoty patřilo k UTC dni serveru, ne k pražskému dni dvojice. */
class CestaPrazskyDenTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Naše', 'slug' => 'nase-'.Str::random(6), 'owner_id' => $this->owner->id, 'is_default' => true]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->actingAs($this->owner);
    }

    public function test_naklad_na_vozidlo_bez_data_se_zapise_k_prazskemu_dni(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 00:30', 'Europe/Prague'));
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Autovýlet', 'start_date' => '2026-09-24', 'end_date' => '2026-09-26', 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);

        $item = $this->postJson("/api/v1/trips/{$tripId}/vehicle-costs", ['type' => 'fuel', 'title' => 'Benzín', 'amount' => 500])
            ->assertCreated()->json();

        $this->assertSame('2026-09-25', $item['occurred_on']);
    }

    /** Hlasová vzpomínka na cestě patří ke dni podle pásma cesty, ne UTC serveru. */
    public function test_hlasova_vzpominka_patri_ke_dni_podle_pasma_cesty(): void
    {
        // V New Yorku (UTC-4) je ještě 24. září večer, i když v UTC už je 25.
        $this->travelTo(CarbonImmutable::parse('2026-09-25 01:30', 'UTC'));
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'New York', 'start_date' => '2026-09-20', 'end_date' => '2026-09-30', 'timezone' => 'America/New_York', 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('trip_days')->insert(['trip_id' => $tripId, 'date' => '2026-09-24', 'created_at' => now(), 'updated_at' => now()]);

        $file = UploadedFile::fake()->create('memo.webm', 10, 'audio/webm');
        $this->postJson("/api/v1/trips/{$tripId}/journal-recordings", ['recording' => $file, 'duration_ms' => 1200])
            ->assertCreated();

        $this->assertDatabaseHas('travel_journal_entries', ['trip_id' => $tripId, 'type' => 'voice']);
        $tripDayId = DB::table('trip_days')->where('trip_id', $tripId)->where('date', '2026-09-24')->value('id');
        $this->assertDatabaseHas('travel_journal_entries', ['trip_id' => $tripId, 'type' => 'voice', 'trip_day_id' => $tripDayId]);
    }
}
