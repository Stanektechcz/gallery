<?php

namespace Tests\Feature\Planovani;

use App\Models\CalendarEvent;
use App\Models\CoupleDateIdea;
use App\Models\MemoryEvening;
use App\Services\Memories\MemoryEveningService;
use App\Services\Planning\CalendarEventTripService;
use App\Services\Planning\DateIdeaPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Dvojklik nebo oba partneři naráz.
 *
 * Každý požadavek si načte svou kopii záznamu dřív, než ten druhý zapíše.
 * Kontrola „už hotovo?" nad touhle kopií obě volání pustila dál a vznikla dvě
 * alba, dvě cesty nebo dvě upozornění.
 */
class DvojityKlikTest extends TestCase
{
    use DvojiceSHostem;
    use RefreshDatabase;

    public function test_vecer_se_vzpominkami_dokonceny_dvakrat_naraz_da_jedno_album(): void
    {
        Queue::fake();
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'vylet.jpg');
        $uuid = $this->actingAs($vlastnik)->postJson('/api/v1/memory-evenings', [
            'gallery_space_id' => $prostor->id, 'fingerprint' => hash('sha256', 'dvojklik'), 'source_type' => 'on_this_day',
            'title' => 'Před rokem', 'scheduled_for' => now()->addWeek()->toIso8601String(), 'media_uuids' => [$fotka->uuid],
        ])->assertCreated()->json('uuid');

        $prvni = MemoryEvening::where('uuid', $uuid)->firstOrFail();
        $druhy = MemoryEvening::where('uuid', $uuid)->firstOrFail();
        $sluzba = app(MemoryEveningService::class);

        $sluzba->complete($prvni, $vlastnik);
        $vysledek = $sluzba->complete($druhy, $partner);

        $this->assertSame('completed', $vysledek->status);
        $this->assertSame(1, DB::table('albums')->count());
        $this->assertSame(1, DB::table('shared_memory_moments')->count());
    }

    public function test_cesta_z_akce_zalozena_dvakrat_naraz_je_jedna(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $akce = CalendarEvent::create([
            'gallery_space_id' => $prostor->id, 'created_by' => $vlastnik->id, 'title' => 'Víkend v Brně', 'type' => 'outing',
            'status' => 'planned', 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addDay(), 'is_private' => false,
        ]);
        $prvni = CalendarEvent::findOrFail($akce->id);
        $druha = CalendarEvent::findOrFail($akce->id);
        $sluzba = app(CalendarEventTripService::class);

        [$cesta] = $sluzba->createFromEvent($prvni, $vlastnik->id);
        [$znovu, $zalozena] = $sluzba->createFromEvent($druha, $vlastnik->id);

        $this->assertFalse($zalozena);
        $this->assertSame((int) $cesta->id, (int) $znovu->id);
        $this->assertSame(1, DB::table('trips')->count());
        $this->assertSame((int) $cesta->id, (int) $druha->trip_id);
    }

    public function test_randicko_naplanovane_dvakrat_naraz_upozorni_partnera_jednou(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $napad = CoupleDateIdea::create([
            'gallery_space_id' => $prostor->id, 'created_by' => $vlastnik->id, 'generation_key' => str_repeat('e', 64),
            'title' => 'Kino a večeře', 'summary' => 'Klasika.', 'theme' => 'romantic', 'status' => 'generated',
            'travel_scope' => 'city', 'transport_mode' => 'transit', 'estimated_cost' => 600, 'currency' => 'CZK',
            'estimated_minutes' => 180, 'novelty_percent' => 50, 'parameters' => [], 'plan' => ['blocks' => []],
        ]);
        $prvni = CoupleDateIdea::findOrFail($napad->id);
        $druhy = CoupleDateIdea::findOrFail($napad->id);
        $sluzba = app(DateIdeaPlanningService::class);
        $moznosti = ['starts_at' => now()->addWeek()->setTime(19, 0)->toIso8601String(), 'create_trip' => false];

        $sluzba->plan($prvni, $vlastnik, $moznosti);
        $sluzba->plan($druhy, $vlastnik, $moznosti);

        $this->assertSame(1, DB::table('calendar_events')->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $partner->id)->count());
        $this->assertSame(1, DB::table('event_reminders')->where('user_id', $partner->id)->count());
    }
}
