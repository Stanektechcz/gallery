<?php

namespace Tests\Feature\Planovani;

use App\Models\CalendarEvent;
use App\Models\CoupleDateIdea;
use App\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Účet, který má vlastní galerii a v cizí je jen hostem.
 *
 * Brána (`dvojice`) posuzuje první prostor účtu — ten je jeho vlastní, takže
 * projde. Řadiče pak braly „kterýkoli prostor, kde je členem" a host tak
 * četl vzpomínky, randíčka, deník, přání i výročí cizí dvojice.
 */
class HostCiziGalerieTest extends TestCase
{
    use DvojiceSHostem;
    use RefreshDatabase;

    public function test_host_necte_vzpominky_milniky_ani_vyroci_cizi_galerie(): void
    {
        [$ucet, , $cizi, $ciziProstor] = $this->hostCiziGalerie();
        $vzpominka = (string) Str::uuid();
        DB::table('shared_memory_moments')->insert([
            'uuid' => $vzpominka, 'gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id,
            'title' => 'Cizí vzpomínka', 'media_item_ids' => '[]', 'is_favorite' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('relationship_milestones')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id,
            'title' => 'Cizí milník', 'occurred_on' => '2025-05-01', 'visibility' => 'shared', 'remind_annually' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($ucet);
        $this->assertStringNotContainsString('Cizí vzpomínka', (string) $this->getJson('/api/v1/shared-memory-moments')->assertOk()->getContent());
        $this->deleteJson('/api/v1/shared-memory-moments/'.$vzpominka.'/reflection')->assertNotFound();
        $this->postJson('/api/v1/shared-memory-moments', ['gallery_space_id' => $ciziProstor->id, 'title' => 'Podstrčená'])->assertNotFound();
        $this->assertStringNotContainsString('Cizí milník', (string) $this->getJson('/api/v1/relationship-milestones')->assertOk()->getContent());
        $this->postJson('/api/v1/relationship-milestones', ['gallery_space_id' => $ciziProstor->id, 'title' => 'Podstrčený', 'occurred_on' => '2025-01-01'])->assertNotFound();
        $this->getJson('/api/v1/relationship-milestones/relationship-anniversary?gallery_space_id='.$ciziProstor->id)->assertNotFound();
    }

    public function test_host_neplanuje_v_cizi_galerii(): void
    {
        [$ucet, , $cizi, $ciziProstor] = $this->hostCiziGalerie();
        $napad = CoupleDateIdea::create([
            'gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id, 'generation_key' => str_repeat('d', 64),
            'title' => 'Cizí rande', 'summary' => 'Jen pro ně.', 'theme' => 'romantic', 'status' => 'generated',
            'travel_scope' => 'home', 'transport_mode' => 'walk', 'estimated_cost' => 0, 'currency' => 'CZK',
            'estimated_minutes' => 90, 'novelty_percent' => 100, 'parameters' => [], 'plan' => ['blocks' => []],
        ]);
        DB::table('travel_wishlists')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id,
            'title' => 'Cizí přání', 'is_shared' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $akce = CalendarEvent::create([
            'gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id, 'title' => 'Cizí akce', 'type' => 'outing',
            'status' => 'planned', 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(2), 'is_private' => false,
        ]);

        $this->actingAs($ucet);
        $this->getJson('/api/v1/date-ideas?gallery_space_id='.$ciziProstor->id)->assertNotFound();
        $this->postJson('/api/v1/date-ideas/'.$napad->uuid.'/plan', [])->assertNotFound();
        $this->assertStringNotContainsString('Cizí přání', (string) $this->getJson('/api/v1/calendar/wishlists')->assertOk()->getContent());
        $this->postJson('/api/v1/calendar/wishlists', ['gallery_space_id' => $ciziProstor->id, 'title' => 'Podstrčené'])->assertNotFound();
        $this->postJson('/api/v1/reminders/events/'.$akce->uuid, ['minutes_before' => 60])->assertNotFound();
        $this->getJson('/api/v1/coordination/pulse?gallery_space_id='.$ciziProstor->id)->assertNotFound();
        $this->assertDatabaseMissing('event_reminders', ['user_id' => $ucet->id]);
    }

    public function test_host_necte_denik_ani_mista_cizi_galerie(): void
    {
        [$ucet, , $cizi, $ciziProstor] = $this->hostCiziGalerie();
        JournalEntry::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id,
            'title' => 'Cizí zápis', 'body' => 'Jen pro ně.', 'entry_date' => '2026-09-01', 'visibility' => JournalEntry::VISIBILITY_SHARED,
        ]);
        $fotka = $this->fotka($ciziProstor, $cizi, 'cizi.jpg', ['latitude' => 50.08, 'longitude' => 14.41]);

        $this->actingAs($ucet);
        $this->getJson('/api/v1/journal?gallery_space_id='.$ciziProstor->id)->assertNotFound();
        $this->getJson('/api/v1/media/'.$fotka->uuid.'/revisit-suggestions')->assertNotFound();
        $this->postJson('/api/v1/media/'.$fotka->uuid.'/revisit-suggestions', ['starts_at' => now()->addWeek()->toIso8601String()])->assertNotFound();
    }
}
