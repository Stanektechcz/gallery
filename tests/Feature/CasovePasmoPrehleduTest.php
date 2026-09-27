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
 * Přehled a schránka počítají den i pozdrav v pásmu dvojice, ne v UTC.
 *
 * `DashboardController` bral hodinu z `now()->hour` (UTC) pro pozdrav a
 * `now()->toDateString()` (UTC datum) pro „dnešek" u cesty a výročí.
 * `InboxController` dělalo totéž pro `travel_inbox_items`. V zimě je posun
 * jen hodina, ale těsně po půlnoci v Praze UTC pořád ukazuje včerejšek —
 * cesta, která dnes skončila, tak vypadala jako už dávno po termínu, a
 * výročí, které je dnes, vycházelo jako „zítra".
 *
 * Od 27. 9. 2026 stránka `/prehled` nic nevykresluje a vede do aplikace
 * (`PresmerujStareRozhrani`), takže `DashboardController` se nespustí a jeho
 * výpočty nejsou odnikud dosažitelné. Testy tu hlídají, že i v hraničních
 * okamžicích (pražská půlnoc) stránka jen přesměruje; dnešek a pozdrav
 * aplikace počítá `Obsah\Dnes` a hlídají ho jeho testy.
 */
class CasovePasmoPrehleduTest extends TestCase
{
    use RefreshDatabase;

    private User $uzivatel;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        // Viz `ArchivVsTrezorTest` — statická mezipaměť prostorů se sama
        // nezneplatní mezi testy stejného procesu.
        SpaceContext::forget();

        $this->uzivatel = User::factory()->create(['role' => 'owner']);
        $this->prostor = GallerySpace::create(['name' => 'Naše galerie', 'owner_id' => $this->uzivatel->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->uzivatel->id => ['role' => 'owner']]);
    }

    public function test_pozdrav_pouziva_mistni_hodinu(): void
    {
        // 5:30 v Praze v zimě (+1) = 4:30 UTC. Místní hodina 5 je „Dobré ráno",
        // UTC hodina 4 je pořád „Dobrou noc" — hranice, na které se bug pozná.
        $this->travelTo(CarbonImmutable::parse('2026-01-15 05:30', 'Europe/Prague'));

        $this->actingAs($this->uzivatel)->get('/prehled')->assertRedirect('/');
    }

    public function test_nadchazejici_cesta_pocita_dnesek_mistne(): void
    {
        // 00:30 v Praze 1. 7. = 22:30 UTC 30. 6. Cesta končící 30. 6. je podle
        // pražského kalendáře už včera — neměla by se ukazovat jako nadcházející.
        $this->travelTo(CarbonImmutable::parse('2026-07-01 00:30', 'Europe/Prague'));

        DB::table('trips')->insert([
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->uzivatel->id,
            'name' => 'Víkend',
            'start_date' => '2026-06-28',
            'end_date' => '2026-06-30',
            'status' => 'planned',
            'timezone' => 'Europe/Prague',
            'currency' => 'CZK',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->uzivatel)->get('/prehled')
            ->assertRedirect('/')
            ->assertDontSee('Víkend');
    }

    public function test_vyroci_dnes_ma_nula_dni_do_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-01 00:30', 'Europe/Prague'));

        DB::table('relationship_milestones')->insert([
            'gallery_space_id' => $this->prostor->id,
            'uuid' => (string) Str::uuid(),
            'title' => 'Výročí',
            'occurred_on' => '2020-07-01',
            'remind_annually' => true,
            'visibility' => 'shared',
            'created_by' => $this->uzivatel->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->uzivatel)->get('/prehled')
            ->assertRedirect('/')
            ->assertDontSee('Výročí');
    }
}
