<?php

namespace Tests\Feature\Planovani;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * „Dnes" je den dvojice v Praze, ne serveru v UTC.
 *
 * 25. 9. ve 22:30 UTC je v Praze už 26. 9. 0:30 — pravidlo `before_or_equal:today`
 * by dnešní zápis odmítlo s 422 a výchozí datum by patřilo ke včerejšku.
 */
class DnesDvojiceTest extends TestCase
{
    use DvojiceSHostem;
    use RefreshDatabase;

    private const PRAZSKA_PULNOC_PRYC = '2026-09-25 22:30:00';

    private const DNES_V_PRAZE = '2026-09-26';

    public function test_denik_prijme_dnesni_datum_a_dnes_je_vychozi(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $this->travelTo(self::PRAZSKA_PULNOC_PRYC);

        $this->actingAs($vlastnik)->postJson('/api/v1/journal', ['gallery_space_id' => $prostor->id, 'body' => 'Po půlnoci.', 'entry_date' => self::DNES_V_PRAZE])
            ->assertCreated()->assertJsonPath('entry_date', self::DNES_V_PRAZE);
        $this->postJson('/api/v1/journal', ['gallery_space_id' => $prostor->id, 'body' => 'Bez data.'])
            ->assertCreated()->assertJsonPath('entry_date', self::DNES_V_PRAZE);
    }

    public function test_zacatek_vztahu_i_historie_cyklu_prijmou_dnesek(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $this->travelTo(self::PRAZSKA_PULNOC_PRYC);
        $this->actingAs($vlastnik);

        $this->putJson('/api/v1/relationship-milestones/relationship-anniversary', [
            'gallery_space_id' => $prostor->id, 'started_on' => self::DNES_V_PRAZE,
        ])->assertOk()->assertJsonPath('started_on', self::DNES_V_PRAZE);

        $this->postJson('/api/v1/cyklus/historie', ['starts' => [self::DNES_V_PRAZE]])->assertCreated();
    }

    public function test_check_in_bez_data_patri_k_dnesku_dvojice(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $this->travelTo(self::PRAZSKA_PULNOC_PRYC);

        $this->actingAs($vlastnik)->putJson('/api/v1/coordination/check-in', ['gallery_space_id' => $prostor->id, 'capacity' => 'normal'])
            ->assertOk()->assertJsonPath('my_check_in.check_in_on', self::DNES_V_PRAZE);
        $this->assertDatabaseHas('partner_check_ins', ['user_id' => $vlastnik->id, 'check_in_on' => self::DNES_V_PRAZE]);
    }

    public function test_vyroci_milniku_je_dnes_ne_zitra(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        DB::table('relationship_milestones')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $prostor->id, 'created_by' => $vlastnik->id,
            'title' => 'Seznámení', 'occurred_on' => '2020-09-26', 'visibility' => 'shared', 'remind_annually' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->travelTo(self::PRAZSKA_PULNOC_PRYC);

        $this->actingAs($vlastnik)->getJson('/api/v1/relationship-milestones/upcoming')->assertOk()
            ->assertJsonPath('0.next_anniversary', self::DNES_V_PRAZE)
            ->assertJsonPath('0.days_until', 0);
    }
}
