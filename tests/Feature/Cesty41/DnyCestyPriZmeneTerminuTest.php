<?php

namespace Tests\Feature\Cesty41;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Cesty\CestyTestCase;

/**
 * Změna termínu cesty posouvá i její dny (`trip_days`).
 *
 * Dřív se posunuly jen události kalendáře: dny zůstaly na starém termínu,
 * „Teď" ukazovalo první starý den, deník během cesty nenašel svůj den
 * a galerie k zastaralým dnům přidávala nové — smíšený itinerář s duplicitním
 * pořadím. Zkrácení nesmí smazat den, na kterém něco je (smazání dne smaže
 * i jeho body programu).
 */
class DnyCestyPriZmeneTerminuTest extends CestyTestCase
{
    /** @var array<int, int> pořadí dne (0 = první) → id řádku */
    private array $dny = [];

    private int $aktivita;

    private int $zaznam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cesta('2026-10-01', '2026-10-03');
        foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $i => $datum) {
            $this->dny[$i] = DB::table('trip_days')->insertGetId([
                'trip_id' => $this->tripId, 'date' => $datum, 'title' => 'Den '.($i + 1), 'sort_order' => $i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->aktivita = DB::table('trip_activities')->insertGetId([
            'trip_day_id' => $this->dny[1], 'created_by' => $this->owner->id, 'type' => 'activity',
            'title' => 'Pálava', 'status' => 'planned', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->zaznam = DB::table('travel_journal_entries')->insertGetId([
            'trip_id' => $this->tripId, 'trip_day_id' => $this->dny[1], 'user_id' => $this->owner->id,
            'type' => 'note', 'content' => 'Víno na Pálavě', 'visibility' => 'shared',
            'recorded_at' => '2026-10-02 18:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_posun_terminu_posune_dny_i_s_obsahem(): void
    {
        $this->patchJson("/api/v1/trips/{$this->tripId}", ['start_date' => '2026-10-08', 'end_date' => '2026-10-10'])->assertOk();

        $this->assertSame(['2026-10-08', '2026-10-09', '2026-10-10'], $this->datumy());
        $this->assertSame(array_values($this->dny), DB::table('trip_days')->where('trip_id', $this->tripId)->orderBy('date')->pluck('id')->map('intval')->all(),
            'Řádky dnů zůstávají — posouvá se jejich datum, ne mazání a nové zakládání.');
        $this->assertSame('2026-10-09', $this->datumDneAktivity());
        $this->assertSame($this->dny[1], (int) DB::table('travel_journal_entries')->where('id', $this->zaznam)->value('trip_day_id'));
        $this->assertSame([0, 1, 2], DB::table('trip_days')->where('trip_id', $this->tripId)->orderBy('date')->pluck('sort_order')->map('intval')->all());
    }

    public function test_zkraceni_nesmaze_den_s_obsahem(): void
    {
        $this->patchJson("/api/v1/trips/{$this->tripId}", ['start_date' => '2026-10-08', 'end_date' => '2026-10-10'])->assertOk();
        $this->patchJson("/api/v1/trips/{$this->tripId}", ['end_date' => '2026-10-09'])->assertOk();

        $this->assertSame(['2026-10-08', '2026-10-09'], $this->datumy());
        $this->assertSame('2026-10-09', $this->datumDneAktivity());

        // Zkrácení na jediný den: prázdný třetí den zmizí, den s programem zůstane.
        $this->patchJson("/api/v1/trips/{$this->tripId}", ['end_date' => '2026-10-08'])->assertOk();

        $this->assertSame(['2026-10-08', '2026-10-09'], $this->datumy());
        $this->assertTrue(DB::table('trip_activities')->where('id', $this->aktivita)->exists(), 'Smazání dne by smazalo i jeho body programu.');
    }

    public function test_zkraceni_nechava_den_s_vlastnim_nazvem_nebo_poznamkou(): void
    {
        DB::table('trip_days')->where('id', $this->dny[2])->update(['title' => 'Lednice']);
        DB::table('trip_activities')->where('id', $this->aktivita)->delete();
        DB::table('travel_journal_entries')->where('id', $this->zaznam)->delete();
        DB::table('trip_days')->where('id', $this->dny[1])->update(['notes' => 'Vzít kola']);

        $this->patchJson("/api/v1/trips/{$this->tripId}", ['end_date' => '2026-10-01'])->assertOk();

        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03'], $this->datumy());
    }

    public function test_prodlouzeni_doplni_chybejici_dny_s_vychozim_nazvem(): void
    {
        $this->patchJson("/api/v1/trips/{$this->tripId}", ['end_date' => '2026-10-05'])->assertOk();

        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05'], $this->datumy());
        $this->assertSame(['Den 1', 'Den 2', 'Den 3', 'Den 4', 'Den 5'], DB::table('trip_days')->where('trip_id', $this->tripId)->orderBy('sort_order')->pluck('title')->all());
        $this->assertSame('2026-10-02', $this->datumDneAktivity(), 'Posun samotného konce nechává dny na místě.');
    }

    public function test_jidlo_na_ceste_se_posune_se_dnem(): void
    {
        $recept = DB::table('recipes')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'title' => 'Guláš', 'created_at' => now(), 'updated_at' => now()]);
        $jidlo = DB::table('planned_meals')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'recipe_id' => $recept, 'trip_id' => $this->tripId,
            'trip_day_id' => $this->dny[1], 'created_by' => $this->owner->id, 'planned_for' => '2026-10-02 18:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->patchJson("/api/v1/trips/{$this->tripId}", ['start_date' => '2026-10-08', 'end_date' => '2026-10-10'])->assertOk();

        $this->assertSame('2026-10-09 18:00:00', substr((string) DB::table('planned_meals')->where('id', $jidlo)->value('planned_for'), 0, 19));
    }

    public function test_posun_hlavni_karty_v_kalendari_posune_dny_i_ostatni_udalosti(): void
    {
        $vlak = $this->udalost('Vlak domů', 'reservation', '2026-10-03 18:00:00', '2026-10-03 21:00:00');
        $uuid = DB::table('calendar_events')->where('id', $this->tripEventId)->value('uuid');

        $this->patchJson("/api/v1/calendar/events/{$uuid}", ['starts_at' => '2026-10-08 09:00:00', 'ends_at' => '2026-10-10 20:00:00'])->assertOk();

        $trip = DB::table('trips')->find($this->tripId);
        $this->assertSame(['2026-10-08', '2026-10-10'], [substr((string) $trip->start_date, 0, 10), substr((string) $trip->end_date, 0, 10)]);
        $this->assertSame(['2026-10-08', '2026-10-09', '2026-10-10'], $this->datumy());
        $this->assertSame('2026-10-09', $this->datumDneAktivity());
        $this->assertSame('2026-10-10 18:00:00', substr((string) DB::table('calendar_events')->where('id', $vlak)->value('starts_at'), 0, 19));
        $this->assertSame('2026-10-08 09:00:00', substr((string) DB::table('calendar_events')->where('id', $this->tripEventId)->value('starts_at'), 0, 19));
    }

    /**
     * Rezervace navázaná na cestu termín cesty neřídí.
     *
     * Úprava času vlaku v kalendáři dřív přepsala termín celé cesty na den
     * vlaku; s posunem dnů by se k tomu posunul celý itinerář.
     */
    public function test_uprava_rezervace_v_kalendari_termin_cesty_nemeni(): void
    {
        $vlak = $this->udalost('Vlak domů', 'reservation', '2026-10-03 18:00:00', '2026-10-03 21:00:00');
        $uuid = DB::table('calendar_events')->where('id', $vlak)->value('uuid');

        $this->patchJson("/api/v1/calendar/events/{$uuid}", ['starts_at' => '2026-10-03 18:30:00', 'ends_at' => '2026-10-03 21:30:00'])->assertOk();

        $trip = DB::table('trips')->find($this->tripId);
        $this->assertSame(['2026-10-01', '2026-10-03'], [substr((string) $trip->start_date, 0, 10), substr((string) $trip->end_date, 0, 10)]);
        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03'], $this->datumy());
    }

    /**
     * Galerie (prototyp) nepřidává dny vedle zastaralých.
     *
     * Cesta posunutá dřív, než se dny posouvaly: řádky dnů zůstaly na
     * 1.–3. 10., cesta je 8.–10. 10. Bod programu na první den dřív založil
     * 8. 10. s pořadím 0 vedle starého 1. 10. s pořadím 0.
     */
    public function test_program_z_galerie_srovna_zastarale_dny(): void
    {
        DB::table('trips')->where('id', $this->tripId)->update(['start_date' => '2026-10-08', 'end_date' => '2026-10-10']);

        $this->postJson("/api/cesty/{$this->tripId}/program", ['den' => 0, 'nazev' => 'Snídaně', 'cas' => '8:00'])->assertCreated();

        // Prázdné zastaralé dny zmizí, den s programem zůstane; pořadí jde po datech bez duplicit.
        $this->assertSame(['2026-10-02', '2026-10-08', '2026-10-09', '2026-10-10'], $this->datumy());
        $this->assertSame([0, 1, 2, 3], DB::table('trip_days')->where('trip_id', $this->tripId)->orderBy('date')->pluck('sort_order')->map('intval')->all());
        $snidane = DB::table('trip_activities')->where('title', 'Snídaně')->value('trip_day_id');
        $this->assertSame('2026-10-08', substr((string) DB::table('trip_days')->where('id', $snidane)->value('date'), 0, 10));
    }

    /** Plán cesty ve starém rozhraní dny srovná stejně, ne jen když žádné nejsou. */
    public function test_plan_cesty_doplni_dny_i_kdyz_nejake_existuji(): void
    {
        DB::table('trips')->where('id', $this->tripId)->update(['end_date' => '2026-10-04']);

        $this->getJson("/api/v1/trips/{$this->tripId}/plan")->assertOk();

        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04'], $this->datumy());
    }

    /** @return list<string> */
    private function datumy(): array
    {
        return DB::table('trip_days')->where('trip_id', $this->tripId)->orderBy('date')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all();
    }

    private function datumDneAktivity(): string
    {
        $den = DB::table('trip_activities')->where('id', $this->aktivita)->value('trip_day_id');

        return substr((string) DB::table('trip_days')->where('id', $den)->value('date'), 0, 10);
    }

    private function udalost(string $nazev, string $typ, string $od, string $do): int
    {
        return DB::table('calendar_events')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'trip_id' => $this->tripId, 'title' => $nazev, 'type' => $typ, 'status' => 'planned',
            'starts_at' => $od, 'ends_at' => $do, 'timezone' => 'Europe/Prague', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
