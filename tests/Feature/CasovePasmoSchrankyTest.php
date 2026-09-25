<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use App\Support\SpaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Schránka posuzuje „dnešek" cestovních podkladů v pásmu dvojice, ne v UTC.
 *
 * `$today = now()->startOfDay()` bere půlnoc UTC dne, kdy zrovna leží `now()`.
 * V Praze je ale místní půlnoc dřív než ta UTC (+1/+2 h) — takže `$today` byl
 * až o den zpátky proti skutečné místní půlnoci a jako „ještě aktuální" tak
 * prošla i akce, která místně skončila už včera.
 */
class CasovePasmoSchrankyTest extends TestCase
{
    use RefreshDatabase;

    public function test_podklad_k_vcerejsi_udalosti_uz_neni_ve_schrance(): void
    {
        // 00:30 v Praze 1. 7. = 22:30 UTC 30. 6. Místní půlnoc dneška je
        // 2026-06-30 22:00 UTC; UTC půlnoc kalendářního dne, ve kterém `now()`
        // leží, je ale 2026-06-30 00:00 UTC — o 22 hodin dřív.
        $this->travelTo(CarbonImmutable::parse('2026-07-01 00:30', 'Europe/Prague'));

        // Statická mezipaměť prostorů se mezi testy stejného procesu sama
        // nezneplatní a SQLite po `RefreshDatabase` recykluje ID.
        SpaceContext::forget();

        $uzivatel = User::factory()->create(['role' => 'owner']);
        $prostor = GallerySpace::create(['name' => 'Naše galerie', 'owner_id' => $uzivatel->id]);
        $prostor->members()->syncWithoutDetaching([$uzivatel->id => ['role' => 'owner']]);

        // Skončila 2026-06-30 10:00 UTC — to je před místní půlnocí (22:00 UTC),
        // takže místně už včera. Zůstává ale po UTC-kalendářní půlnoci (00:00 UTC).
        $eventId = DB::table('calendar_events')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'created_by' => $uzivatel->id,
            'title' => 'Odpolední prohlídka',
            'starts_at' => '2026-06-30 08:00:00',
            'ends_at' => '2026-06-30 10:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('travel_inbox_items')->insert([
            'gallery_space_id' => $prostor->id,
            'uuid' => (string) Str::uuid(),
            'title' => 'Vstupenky na prohlídku',
            'state' => 'inbox',
            'event_id' => $eventId,
            'added_by' => $uzivatel->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $odpoved = $this->actingAs($uzivatel)->get('/inbox');
        $odpoved->assertOk();

        $polozky = collect($odpoved->viewData('page')['props']['actionItems']);
        $this->assertFalse(
            $polozky->contains(fn ($p) => $p['title'] === 'Vstupenky na prohlídku'),
            'Podklad ke včerejší (místně) události zůstává ve schránce kvůli UTC půlnoci.'
        );
    }
}
