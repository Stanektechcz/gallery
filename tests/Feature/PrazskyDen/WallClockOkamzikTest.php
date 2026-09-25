<?php

namespace Tests\Feature\PrazskyDen;

use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `starts_at` je zapsaný podle pražských hodin, ale Carbon ho čte jako
 * okamžik v UTC — srovnání „už začalo" i výpočet připomínky musí pásmo
 * převést zpátky, jinak jsou vedle o dvě hodiny (v létě) nebo hodinu (v zimě).
 */
class WallClockOkamzikTest extends TestCase
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

    /** Akce, která už podle pražských hodin začala, nesmí nabízet „připravit se". */
    public function test_experience_dalsi_krok_pouziva_skutecny_okamzik_zacatku(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00', 'Europe/Prague'));
        $event = CalendarEvent::create([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'title' => 'Snídaně', 'status' => 'planned', 'starts_at' => '2026-07-15 09:00:00',
        ]);

        $response = $this->getJson("/api/v1/calendar/events/{$event->uuid}")->assertOk();

        $this->assertNotSame('prepare', $response->json('experience.next_action'), 'Akce, která už začala, nabízí „připravit se".');
    }

    /** Ruční připomínka se počítá od skutečného okamžiku začátku, ne od pražských hodin vydávaných za UTC. */
    public function test_pripominka_pocita_od_skutecneho_okamziku(): void
    {
        // Léto (CEST, UTC+2): akce v 20:00 pražského času je ve skutečnosti 18:00 UTC.
        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00', 'Europe/Prague'));
        $event = CalendarEvent::create([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'title' => 'Večeře', 'status' => 'planned', 'starts_at' => '2026-07-15 20:00:00',
        ]);

        $this->postJson("/api/v1/reminders/events/{$event->uuid}", ['minutes_before' => 60])
            ->assertCreated();

        $this->assertDatabaseHas('event_reminders', ['event_id' => $event->id, 'remind_at' => '2026-07-15 17:00:00']);
    }
}
