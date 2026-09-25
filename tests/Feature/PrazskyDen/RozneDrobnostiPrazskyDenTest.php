<?php

namespace Tests\Feature\PrazskyDen;

use App\Models\CalendarEvent;
use App\Models\CycleDay;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Chat\MentionSearchService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Několik dalších drobných míst, kde „dnes" počítal server v UTC, ne Praha. */
class RozneDrobnostiPrazskyDenTest extends TestCase
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

    /** Nedokončená (aktivní) cesta se nabídne k ohlédnutí, jakmile skončila podle pražského dne. */
    public function test_dashboard_pripominka_ohlednuti_pouziva_prazsky_dnesek(): void
    {
        // Server v UTC má ještě 24. září — cesta skončená ten den by bez opravy
        // ještě nevypadala jako ukončená.
        $this->travelTo(CarbonImmutable::parse('2026-09-25 00:30', 'Europe/Prague'));
        DB::table('trips')->insert([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Loňský výlet',
            'status' => 'active', 'start_date' => '2026-09-20', 'end_date' => '2026-09-24', 'currency' => 'CZK',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get('/prehled')->assertOk()
            ->assertInertia(fn ($page) => $page->where('data.partner_hub.reflection_prompt.name', 'Loňský výlet'));
    }

    /** Nová událost dnes má nula dní dopředu podle pražského, ne UTC dne. */
    public function test_dny_dopredu_u_nove_udalosti_pocitaji_prazsky_dnesek(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 00:30', 'Europe/Prague'));
        DB::table('automation_rules')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'name' => 'Dnešní akce', 'trigger' => 'event.created', 'action' => 'todo.create',
            'action_config' => json_encode(['title' => 'Připravit']),
            'conditions' => json_encode([['field' => 'days_ahead', 'operator' => 'equals', 'value' => '0']]),
            'is_enabled' => true, 'run_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        CalendarEvent::create([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'title' => 'Dnešní schůzka', 'status' => 'planned', 'starts_at' => '2026-09-25 08:00:00',
        ]);

        $this->assertDatabaseHas('automation_runs', ['gallery_space_id' => $this->space->id, 'succeeded' => true]);
    }

    /** „@dnes" v chatové zmínce ukazuje pražský den, ne UTC. */
    public function test_zminka_dnes_pouziva_prazsky_den(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 00:30', 'Europe/Prague'));
        $event = CalendarEvent::create([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'title' => 'Snídaně', 'status' => 'planned', 'starts_at' => '2026-09-25 08:00:00',
        ]);

        $result = app(MentionSearchService::class)->search($this->space, $this->owner, '');

        $this->assertStringContainsString('25. září', $result['label']);
        $this->assertContains($event->uuid, collect($result['items'])->pluck('id')->all());
    }

    /** Přehled cyklu bere „dnes" v pásmu dvojice jako výchozí den. */
    public function test_cyklus_pocita_dnesek_podle_prahy(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 00:30', 'Europe/Prague'));
        CycleDay::create(['user_id' => $this->owner->id, 'gallery_space_id' => $this->space->id, 'day' => '2026-09-25', 'is_cycle_start' => true, 'flow' => 'medium']);

        $this->getJson('/api/v1/cyklus')->assertOk()->assertJsonPath('mine.today.cycle_day', 1);
    }
}
