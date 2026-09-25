<?php

namespace Tests\Feature;

use App\Models\DailyMoment;
use App\Models\GallerySpace;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Výzva Zároveň počítá den a okno v pásmu dvojice a chodí jen dvojici.
 *
 * `DailyMomentCommand` bral `Carbon::now()` (UTC) a `DailyMomentService` z něj
 * počítala i den i okno 9-21 h přímo — v létě tak vycházelo na 11-23 h
 * pražského času a mezi půlnocí a druhou ráno patřil moment ještě ke
 * včerejšku. Výzva navíc chodila i hostům a odebraným účtům, protože se
 * četlo rovnou `$space->members`.
 */
class DailyMomentCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private User $host;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['is_active' => true]);
        $this->maki = User::factory()->create(['is_active' => true]);
        $this->host = User::factory()->create(['is_active' => true]);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->attach($this->adri->id, ['role' => 'owner', 'joined_at' => now()]);
        $this->prostor->members()->attach($this->maki->id, ['role' => 'editor', 'joined_at' => now()]);
        $this->prostor->members()->attach($this->host->id, ['role' => 'viewer', 'joined_at' => now()]);
    }

    /**
     * V létě je 20:30 UTC teprve 22:30 v Praze — moment musí patřit k tomu
     * samému dni a čas výzvy nesmí přesáhnout 21:00 pražského času.
     */
    public function test_v_lete_zustava_okno_v_prazskem_case(): void
    {
        // 15:00 UTC je v létě 17:00 v Praze — uvnitř okna 9-21 h, ne 11-23 h,
        // jaké by vyšlo z holého UTC.
        $this->travelTo(Carbon::parse('2026-07-01 15:00:00', 'UTC'));

        $this->artisan('gallery:daily-moment')->assertSuccessful();

        $moment = DailyMoment::sole();
        $this->assertSame('2026-07-01', $moment->moment_date->toDateString());
        $this->assertLessThanOrEqual('21:00:00', $moment->notify_at->copy()->setTimezone('Europe/Prague')->format('H:i:s'));
    }

    /** 22:30 UTC v létě je 00:30 v Praze druhého dne — moment patří k tomu dni. */
    public function test_po_pulnoci_v_praze_patri_moment_k_novemu_dni(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 22:30:00', 'UTC'));

        $this->artisan('gallery:daily-moment')->assertSuccessful();

        $moment = DailyMoment::sole();
        $this->assertSame('2026-07-02', $moment->moment_date->toDateString());
    }

    /** Host (role viewer) a odebraný účet výzvu nedostanou, jen dvojice. */
    public function test_vyzva_chodi_jen_dvojici(): void
    {
        $this->travelTo(Carbon::parse('2026-07-01 10:00:00', 'UTC'));

        $tretiClen = User::factory()->create(['is_active' => false]);
        $this->prostor->members()->attach($tretiClen->id, ['role' => 'editor', 'joined_at' => now()]);

        $this->artisan('gallery:daily-moment --force')->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->adri->id, 'notifiable_type' => User::class]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->maki->id, 'notifiable_type' => User::class]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $this->host->id, 'notifiable_type' => User::class]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $tretiClen->id, 'notifiable_type' => User::class]);
        $this->assertSame(2, DB::table('notifications')->count());
    }
}
