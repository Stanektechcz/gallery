<?php

namespace Tests\Feature;

use App\Models\ScheduledTaskRun;
use App\Services\Provoz\PlanovaneUlohy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Protokol běhů úloh se uklízí — ale nikdy tak, aby administrace oněměla.
 *
 * `scheduled_task_runs` se jen plnila. Sám tep plánovače je běh každou minutu,
 * tedy přes půl milionu řádků ročně; se zbytkem úloh je to zhruba 1,8 milionu.
 * A čte se z ní při každém otevření administrace.
 *
 * Úklid má jednu tvrdou výjimku: **poslední běh každé úlohy zůstává vždycky**,
 * ať je jakkoli starý. Administrace z něj bere sloupec „naposledy"; kdyby ho
 * úklid smazal, úloha, která běží jednou za rok, by o sobě tvrdila „nikdy".
 */
class UklidProtokoluTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gallery.task_log_retention_days' => 90]);
    }

    public function test_stare_behy_zmizi_a_cerstve_zustanou(): void
    {
        $stary = $this->beh('trash-purge', dni: 200);
        $dalsiStary = $this->beh('trash-purge', dni: 150);
        $cerstvy = $this->beh('trash-purge', dni: 3);

        $this->artisan('gallery:uklid-protokol')->assertExitCode(0);

        $this->assertNull(ScheduledTaskRun::find($stary->id));
        $this->assertNull(ScheduledTaskRun::find($dalsiStary->id));
        $this->assertNotNull(ScheduledTaskRun::find($cerstvy->id));
    }

    public function test_posledni_beh_ulohy_zustava_i_kdyz_je_letity(): void
    {
        $jediny = $this->beh('galerie-notify', dni: 400);

        $this->artisan('gallery:uklid-protokol')->assertExitCode(0);

        $this->assertNotNull(ScheduledTaskRun::find($jediny->id),
            'Bez posledního běhu by administrace u úlohy psala „nikdy".');
        $this->assertTrue(ScheduledTaskRun::posledni()->has('galerie-notify'));
    }

    public function test_z_kazde_ulohy_zbyde_aspon_jeden_radek(): void
    {
        foreach (['trash-purge', 'temp-cleanup', 'scheduler-heartbeat'] as $uloha) {
            $this->beh($uloha, dni: 500);
            $this->beh($uloha, dni: 300);
        }

        $this->artisan('gallery:uklid-protokol')->assertExitCode(0);

        $zbyle = ScheduledTaskRun::query()->get()->groupBy('task');
        $this->assertCount(3, $zbyle, 'Každá úloha má mít pořád svůj poslední běh.');
        foreach ($zbyle as $uloha => $behy) {
            $this->assertCount(1, $behy, "Z úlohy {$uloha} měl zbýt přesně poslední běh.");
        }
    }

    public function test_nasucho_jen_spocita(): void
    {
        $stary = $this->beh('trash-purge', dni: 200);
        $this->beh('trash-purge', dni: 3);

        $this->artisan('gallery:uklid-protokol --nasucho')->assertExitCode(0);

        $this->assertNotNull(ScheduledTaskRun::find($stary->id), 'Nasucho se nemaže.');
    }

    public function test_pocet_dni_jde_prebit_prepinacem(): void
    {
        $tyden = $this->beh('trash-purge', dni: 7);
        $this->beh('trash-purge', dni: 1);

        $this->artisan('gallery:uklid-protokol --dni=3')->assertExitCode(0);

        $this->assertNull(ScheduledTaskRun::find($tyden->id));
    }

    /** Hledání posledního běhu má v databázi oporu, ne průchod celou tabulkou. */
    public function test_tabulka_ma_rejstrik_pro_posledni_beh(): void
    {
        $sloupce = array_map(
            fn (array $rejstrik) => $rejstrik['columns'],
            Schema::getIndexes('scheduled_task_runs'),
        );

        $this->assertContains(['task', 'id'], $sloupce,
            '`ScheduledTaskRun::posledni()` počítá MAX(id) na úlohu; bez rejstříku (task, id) '
            .'to je průchod celou tabulkou při každém otevření administrace.');
    }

    /** A úklid sám musí být v administraci vidět česky. */
    public function test_uklid_je_videt_v_administraci(): void
    {
        $this->beh('protokol-uloh', dni: 1);

        $radek = (new PlanovaneUlohy)->seznam()->firstWhere('id', 'protokol-uloh');

        $this->assertNotNull($radek, 'Úklid protokolu není v plánu.');
        $this->assertSame('Úklid protokolu úloh', $radek['name']);
    }

    private function beh(string $uloha, int $dni): ScheduledTaskRun
    {
        return ScheduledTaskRun::create([
            'task' => $uloha,
            'command' => 'artisan '.$uloha,
            'started_at' => now()->subDays($dni),
            'finished_at' => now()->subDays($dni)->addSeconds(2),
            'duration_ms' => 2000,
            'state' => ScheduledTaskRun::HOTOVO,
        ]);
    }
}
