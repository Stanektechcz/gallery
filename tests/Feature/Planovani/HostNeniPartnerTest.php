<?php

namespace Tests\Feature\Planovani;

use App\Models\CoupleDateIdea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Host galerie (`viewer`) není „partner".
 *
 * Automatická alba, randíčka, přání, rozhodnutí i výročí zapisovala každého
 * člena prostoru — host tak dostal právo `editor` na album z večera se
 * vzpomínkami, pozvánku a připomínky na randíčko dvojice i úkol k vyřízení.
 */
class HostNeniPartnerTest extends TestCase
{
    use DvojiceSHostem;
    use RefreshDatabase;

    public function test_vecer_se_vzpominkami_hosta_neupozorni_ani_mu_neda_album(): void
    {
        Queue::fake();
        [$vlastnik, $partner, $host, $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'vylet.jpg');

        $vecer = $this->actingAs($vlastnik)->postJson('/api/v1/memory-evenings', [
            'gallery_space_id' => $prostor->id, 'fingerprint' => hash('sha256', 'host'), 'source_type' => 'on_this_day',
            'title' => 'Před rokem', 'scheduled_for' => now()->addWeek()->toIso8601String(), 'media_uuids' => [$fotka->uuid],
        ])->assertCreated()->json();
        $hotovo = $this->postJson('/api/v1/memory-evenings/'.$vecer['uuid'].'/complete')->assertOk()->json();
        $albumId = (int) DB::table('albums')->where('uuid', $hotovo['album']['uuid'])->value('id');

        $this->assertSame(0, $this->upozorneni($host));
        $this->assertSame(1, $this->upozorneni($partner));
        $this->assertDatabaseMissing('album_user_permissions', ['album_id' => $albumId, 'user_id' => $host->id]);
        $this->assertDatabaseHas('album_user_permissions', ['album_id' => $albumId, 'user_id' => $partner->id, 'role' => 'editor']);
    }

    public function test_vyrocni_album_ani_akce_vyroci_hosta_nezapisou(): void
    {
        Queue::fake();
        $this->travelTo('2026-07-15 12:00:00');
        [$vlastnik, $partner, $host, $prostor] = $this->dvojiceSHostem();

        $this->actingAs($vlastnik)->putJson('/api/v1/relationship-milestones/relationship-anniversary', [
            'gallery_space_id' => $prostor->id, 'started_on' => '2025-12-01', 'reminder_days' => [7, 1],
        ])->assertOk();
        $this->assertDatabaseMissing('event_participants', ['user_id' => $host->id]);
        $this->assertDatabaseMissing('event_reminders', ['user_id' => $host->id]);
        $this->assertDatabaseHas('event_participants', ['user_id' => $partner->id, 'role' => 'editor']);

        $this->travelTo('2027-01-15 12:00:00');
        $fotka = $this->fotka($prostor, $vlastnik, 'leto.jpg', ['taken_at' => '2026-06-20 12:00:00']);
        $vysledek = $this->postJson('/api/v1/relationship-milestones/relationship-anniversary/recap', [
            'gallery_space_id' => $prostor->id, 'media_uuids' => [$fotka->uuid],
        ])->assertSuccessful()->json();
        $albumId = (int) DB::table('albums')->where('uuid', $vysledek['album']['uuid'])->value('id');

        $this->assertDatabaseMissing('album_user_permissions', ['album_id' => $albumId, 'user_id' => $host->id]);
        $this->assertDatabaseHas('album_user_permissions', ['album_id' => $albumId, 'user_id' => $partner->id, 'role' => 'editor']);
    }

    public function test_naplanovane_randicko_hosta_nepozve_ani_neupozorni(): void
    {
        [$vlastnik, $partner, $host, $prostor] = $this->dvojiceSHostem();
        $napad = CoupleDateIdea::create([
            'gallery_space_id' => $prostor->id, 'created_by' => $vlastnik->id, 'generation_key' => str_repeat('c', 64),
            'title' => 'Večer u řeky', 'summary' => 'Procházka a večeře.', 'theme' => 'romantic', 'status' => 'generated',
            'travel_scope' => 'city', 'transport_mode' => 'walk', 'estimated_cost' => 400, 'currency' => 'CZK',
            'estimated_minutes' => 120, 'novelty_percent' => 80, 'parameters' => [], 'plan' => ['blocks' => []],
        ]);

        $this->actingAs($vlastnik)->postJson('/api/v1/date-ideas/'.$napad->uuid.'/plan', [
            'starts_at' => now()->addWeek()->setTime(18, 0)->toIso8601String(), 'create_trip' => false,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('event_participants', ['user_id' => $host->id]);
        $this->assertDatabaseMissing('event_reminders', ['user_id' => $host->id]);
        $this->assertSame(0, $this->upozorneni($host));
        $this->assertDatabaseHas('event_participants', ['user_id' => $partner->id]);
        $this->assertDatabaseHas('event_reminders', ['user_id' => $partner->id]);
    }

    public function test_prani_a_rozhodnuti_prevedene_na_akci_hosta_nezapisou(): void
    {
        [$vlastnik, $partner, $host, $prostor] = $this->dvojiceSHostem();
        $this->actingAs($vlastnik);

        $seznam = $this->postJson('/api/v1/calendar/wishlists', ['gallery_space_id' => $prostor->id, 'title' => 'Kam spolu'])->assertCreated()->json();
        $prani = $this->postJson('/api/v1/calendar/wishlists/'.$seznam['uuid'].'/items', ['title' => 'Karlštejn'])->assertCreated()->json();
        $this->postJson('/api/v1/calendar/wishlists/'.$seznam['uuid'].'/items/'.$prani['id'].'/plan', ['starts_at' => now()->addWeeks(2)->toIso8601String()])->assertCreated();

        $anketa = $this->postJson('/api/v1/calendar/polls', [
            'gallery_space_id' => $prostor->id, 'question' => 'Kam v sobotu?',
            'options' => [['title' => 'Kino'], ['title' => 'Výlet']],
        ])->assertCreated()->json();
        $moznost = DB::table('decision_poll_options')->where('poll_id', $anketa['id'])->orderBy('sort_order')->value('id');
        $this->postJson('/api/v1/calendar/polls/'.$anketa['uuid'].'/options/'.$moznost.'/plan', ['starts_at' => now()->addWeeks(3)->toIso8601String()])->assertCreated();

        $this->assertDatabaseMissing('event_participants', ['user_id' => $host->id]);
        $this->assertDatabaseMissing('event_reminders', ['user_id' => $host->id]);
        $this->assertSame(2, DB::table('event_participants')->where('user_id', $partner->id)->count());
        $this->assertSame(2, DB::table('event_reminders')->where('user_id', $partner->id)->count());
    }

    public function test_krok_nelze_priradit_hostovi_a_host_neni_v_rozdeleni_prace(): void
    {
        [$vlastnik, $partner, $host, $prostor] = $this->dvojiceSHostem();

        $this->actingAs($vlastnik)->patchJson('/api/v1/coordination/actions/shared_todo/neexistuje', [
            'gallery_space_id' => $prostor->id, 'assigned_to' => $host->id,
        ])->assertStatus(422);

        $clenove = collect($this->getJson('/api/v1/coordination/pulse?gallery_space_id='.$prostor->id)->assertOk()->json('members'))->pluck('id');
        $this->assertNotContains($host->id, $clenove);
        $this->assertContains($partner->id, $clenove);
    }

    private function upozorneni(User $kdo): int
    {
        return DB::table('notifications')->where('notifiable_id', $kdo->id)->count();
    }
}
