<?php

namespace Tests\Feature\Cesty;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kurzy měn jsou společné celé instalaci a úprava místa v deníku či
 * itineráři nesmí vrátit řádek cizí galerie.
 */
class KurzyAZaznamyTest extends CestyTestCase
{
    public function test_kurz_meny_smi_zapsat_jen_provozovatel(): void
    {
        $kurz = ['base_currency' => 'EUR', 'quote_currency' => 'CZK', 'rate' => 1, 'effective_on' => '2026-09-01'];

        // `users.role = owner` má každý zaregistrovaný účet — to oprávnění není.
        $this->postJson('/api/v1/currency-rates', $kurz)->assertForbidden();
        $this->assertSame(0, DB::table('currency_rates')->count());

        config(['gallery.operator_emails' => $this->owner->email]);
        $this->postJson('/api/v1/currency-rates', $kurz)->assertOk();
        $this->assertSame(1, DB::table('currency_rates')->count());
    }

    public function test_uprava_cizi_udalosti_deniku_a_mista_itinerare_vrati_404(): void
    {
        $cizi = User::factory()->create(['role' => 'owner']);
        $ciziProstor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Cizí', 'slug' => 'cizi-'.Str::random(4), 'owner_id' => $cizi->id]);
        $ciziProstor->members()->attach($cizi->id, ['role' => 'owner', 'joined_at' => now()]);
        $udalost = DB::table('journey_events')->insertGetId(['gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id, 'title' => 'Tajná cesta do Benátek', 'event_date' => '2026-05-01', 'created_at' => now(), 'updated_at' => now()]);
        $misto = DB::table('itinerary_places')->insertGetId(['gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id, 'name' => 'Tajné místo v Lisabonu', 'visited' => false, 'created_at' => now(), 'updated_at' => now()]);

        $odpoved = $this->patchJson("/api/v1/journey/{$udalost}", [])->assertNotFound();
        $this->assertStringNotContainsString('Benátek', $odpoved->getContent());
        $odpoved = $this->patchJson("/api/v1/itinerary/{$misto}", [])->assertNotFound();
        $this->assertStringNotContainsString('Lisabonu', $odpoved->getContent());

        // Vlastní záznam se upravit dá dál.
        $vlastni = DB::table('journey_events')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'title' => 'Naše', 'event_date' => '2026-05-01', 'created_at' => now(), 'updated_at' => now()]);
        $this->patchJson("/api/v1/journey/{$vlastni}", ['title' => 'Naše Brno'])->assertOk()->assertJsonPath('title', 'Naše Brno');
        $vlastniMisto = DB::table('itinerary_places')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Brno', 'visited' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->patchJson("/api/v1/itinerary/{$vlastniMisto}", ['priority' => 'soon'])->assertOk()->assertJsonPath('priority', 'soon');
    }
}
