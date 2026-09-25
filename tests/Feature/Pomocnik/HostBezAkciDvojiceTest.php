<?php

namespace Tests\Feature\Pomocnik;

use App\Models\GallerySpace;
use App\Models\Place;
use App\Models\Recipe;
use App\Models\User;
use App\Support\SpaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Host galerie (viewer/contributor) nepatří k akcím dvojice.
 *
 * `CalendarEventCreationService` hosty z „celého prostoru" vyřazuje, jenže
 * import ICS a naplánované vaření si účastníky i připomínky skládaly samy
 * z celého `gallery_space_user`. Noční rozesílka připomínek pak hostovi
 * poslala název akce i místo. Totéž album hodnocení podniku: host dostal
 * roli `editor`.
 */
class HostBezAkciDvojiceTest extends TestCase
{
    use RefreshDatabase;

    private User $vlastnik;

    private User $partnerka;

    private User $host;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();
        SpaceContext::forget();

        $this->vlastnik = User::factory()->create(['role' => 'owner']);
        $this->partnerka = User::factory()->create(['role' => 'partner']);
        $this->host = User::factory()->create(['role' => 'partner']);
        $this->prostor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Galerie', 'slug' => 'galerie', 'owner_id' => $this->vlastnik->id]);
        $this->prostor->members()->attach($this->vlastnik->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->prostor->members()->attach($this->partnerka->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->prostor->members()->attach($this->host->id, ['role' => 'viewer', 'can_delete' => false, 'can_share' => false, 'joined_at' => now()]);
        $this->actingAs($this->vlastnik);
    }

    public function test_sdileny_import_ics_nepripomina_hostovi(): void
    {
        $zacatek = now()->addDays(5)->format('Ymd\THis\Z');
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:tajna-vecere@example.test\r\nSUMMARY:Tajná večeře\r\nLOCATION:Naše místo\r\nDTSTART:{$zacatek}\r\nEND:VEVENT\r\nEND:VCALENDAR";

        $this->postJson('/api/v1/calendar/ics-import', [
            'gallery_space_id' => $this->prostor->id, 'ics' => $ics, 'share_with_space' => 1, 'reminder_minutes' => 10,
        ])->assertOk()->assertJsonPath('created', 1)->assertJsonPath('reminders_created', 2)->assertJsonPath('shared_member_count', 1);

        $this->assertSame(0, DB::table('event_reminders')->where('user_id', $this->host->id)->count());
        $this->assertSame(0, DB::table('event_participants')->where('user_id', $this->host->id)->count());
        $this->assertSame(1, DB::table('event_reminders')->where('user_id', $this->partnerka->id)->count());
        $this->assertSame(1, DB::table('event_participants')->where('user_id', $this->partnerka->id)->count());
    }

    public function test_naplanovane_vareni_nepripomina_hostovi(): void
    {
        $recept = $this->recept('Svíčková');

        $this->postJson("/api/v1/recipes/{$recept->uuid}/cooking-sessions/schedule", [
            'planned_for' => now()->addDays(2)->toIso8601String(), 'servings' => 2,
        ])->assertCreated();

        $this->assertSame(0, DB::table('event_reminders')->where('user_id', $this->host->id)->count());
        $this->assertSame(0, DB::table('event_participants')->where('user_id', $this->host->id)->count());
        $this->assertSame(1, DB::table('event_reminders')->where('user_id', $this->partnerka->id)->count());
        $this->assertSame(1, DB::table('event_reminders')->where('user_id', $this->vlastnik->id)->count());
        $this->assertSame(1, DB::table('event_participants')->where('user_id', $this->partnerka->id)->count());
    }

    public function test_album_hodnoceni_podniku_nedava_hostovi_editora(): void
    {
        Queue::fake();
        $podnik = Place::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Bistro', 'type' => 'restaurant', 'city' => 'Brno', 'created_by' => $this->vlastnik->id]);

        $album = $this->postJson("/api/v1/places/{$podnik->id}/review-album")->assertCreated()->json('album');

        $this->assertDatabaseMissing('album_user_permissions', ['album_id' => $album['id'], 'user_id' => $this->host->id]);
        $this->assertDatabaseHas('album_user_permissions', ['album_id' => $album['id'], 'user_id' => $this->partnerka->id, 'role' => 'editor']);
    }

    public function test_dlouhy_nazev_receptu_se_do_nazvu_akce_vejde(): void
    {
        $recept = $this->recept(str_repeat('Ž', 180));

        $this->postJson("/api/v1/recipes/{$recept->uuid}/cooking-sessions/schedule", [
            'planned_for' => now()->addDays(2)->toIso8601String(), 'servings' => 2,
        ])->assertCreated();

        // calendar_events.title je varchar(160); SQLite délku nehlídá, MySQL by spadl.
        $nazev = (string) DB::table('calendar_events')->value('title');
        $this->assertLessThanOrEqual(160, mb_strlen($nazev));
        $this->assertStringStartsWith('Vaření · Ž', $nazev);
    }

    private function recept(string $nazev): Recipe
    {
        return Recipe::create([
            'gallery_space_id' => $this->prostor->id, 'created_by' => $this->vlastnik->id, 'updated_by' => $this->vlastnik->id,
            'title' => $nazev, 'category' => 'main_course', 'difficulty' => 'medium', 'status' => 'published',
            'base_servings' => 2, 'prep_minutes' => 20, 'cook_minutes' => 40, 'currency' => 'CZK',
        ]);
    }
}
