<?php

namespace Tests\Feature\PrazskyDen;

use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Planning\CalendarEventLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Kalendář počítal „dnes" podle serveru v UTC, ne podle Prahy — hned po
 * půlnoci se tak posunul den, začátek měsíce, „tento týden" i „v tento den".
 */
class CalendarPrazskyDenTest extends TestCase
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

    /** Poznámka bez zadaného data patří k dnešku Prahy, ne k UTC dni serveru. */
    public function test_denni_poznamka_se_uklada_pod_dnesni_prazsky_den(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 00:30', 'Europe/Prague'));

        $this->putJson('/api/v1/calendar/day-note', [
            'gallery_space_id' => $this->space->id,
            'content' => 'Nezapomenout na dárek',
        ])->assertOk()->assertJson(['date' => '2026-09-25']);

        $this->assertDatabaseHas('shared_day_notes', ['gallery_space_id' => $this->space->id, 'note_date' => '2026-09-25']);
    }

    /** Akce skončená včera podle pražských hodin nesmí čekat na půlnoc v UTC, aby zmizela z „aktivních". */
    public function test_uplynula_akce_se_uzavre_hned_po_prazske_pulnoci(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 00:30', 'Europe/Prague'));

        $event = CalendarEvent::create([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'title' => 'Večeře', 'status' => 'planned',
            'starts_at' => '2026-09-24 20:00:00', 'ends_at' => '2026-09-24 22:00:00',
        ]);

        app(CalendarEventLifecycleService::class)->completeElapsedPlans([$this->space->id]);

        $this->assertSame('completed', $event->fresh()->status);
    }

    /** Výchozí rozsah měsíce v přehledu jde podle pražského dneška, ne podle UTC. */
    public function test_vychozi_mesic_v_kalendari_jde_podle_prazskeho_dneska(): void
    {
        // Prahy je už 1. října, ale server v UTC má ještě 30. září.
        $this->travelTo(CarbonImmutable::parse('2026-10-01 00:30', 'Europe/Prague'));

        $event = $this->postJson('/api/v1/calendar/events', [
            'gallery_space_id' => $this->space->id, 'title' => 'Říjnová schůzka',
            'starts_at' => '2026-10-01 08:00:00',
        ])->assertCreated()->json();

        $calendar = $this->getJson('/api/v1/calendar/events')->assertOk()->json();

        $uuids = collect($calendar['events'])->pluck('uuid')->all();
        $this->assertContains($event['uuid'], $uuids, 'Akce z dnešního pražského dne chybí ve výchozím rozsahu měsíce.');
    }

    /** „V tento den" v týdenním přehledu srovnává podle pražského data, ne UTC. */
    public function test_v_tento_den_pouziva_prazske_datum(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 00:30', 'Europe/Prague'));

        $media = MediaItem::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'owner_user_id' => $this->owner->id, 'uploaded_by' => $this->owner->id,
            'original_filename' => 'stara.jpg', 'safe_filename' => 'stara.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg',
            'media_type' => 'photo', 'size_bytes' => 4096, 'status' => 'ready', 'storage_status' => 'local_only', 'is_hidden' => false,
            'taken_at' => '2025-09-25 10:00:00', 'uploaded_at' => now(),
        ]);

        $overview = $this->getJson('/api/v1/calendar/weekly-overview')->assertOk()->json();

        $uuids = collect($overview['on_this_day'])->pluck('uuid')->all();
        $this->assertContains($media->uuid, $uuids, 'Loňská fotka ze stejného pražského dne v „v tento den" chybí.');
    }

    /**
     * `starts_at` je zapsaný podle hodin v Praze, ale Carbon ho čte jako okamžik v UTC —
     * srovnání „už začalo" proto muselo přepočítat pásmo zpátky, jinak akce večer
     * vypadala jako budoucí ještě hodinu po svém skutečném začátku.
     */
    public function test_spolecnou_vzpominku_lze_ulozit_hned_po_skutecnem_zacatku_akce(): void
    {
        // Léto (CEST, UTC+2): akce začala v 9:00 pražského času, teď je 10:00 pražského času.
        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00', 'Europe/Prague'));

        $event = CalendarEvent::create([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'title' => 'Snídaně', 'status' => 'planned',
            'starts_at' => '2026-07-15 09:00:00',
        ]);

        $this->postJson("/api/v1/calendar/events/{$event->uuid}/shared-memory", [])
            ->assertCreated();
    }
}
