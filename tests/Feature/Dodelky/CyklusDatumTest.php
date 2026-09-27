<?php

namespace Tests\Feature\Dodelky;

use App\Models\CycleSetting;
use App\Services\Health\CycleService;
use App\Support\Cas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Služba cyklu bere i neměnné datum.
 *
 * Ranní připomínky předávaly `Cas::dnes()` (`CarbonImmutable`), služba čekala
 * `Carbon` a na produkci `gallery:cycle-reminders` padal každý den.
 */
class CyklusDatumTest extends TestCase
{
    use DvojiceSHostem, RefreshDatabase;

    public function test_prehled_prijme_nemenne_datum(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();

        $prehled = app(CycleService::class)->overview($prostor, $vlastnik, Cas::dnes());

        $this->assertIsArray($prehled);
    }

    public function test_ranni_pripominky_dobehnou(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        CycleSetting::create([
            'gallery_space_id' => $prostor->id, 'user_id' => $vlastnik->id,
            'share_level' => 'full', 'remind_upcoming' => true, 'remind_days_before' => 2,
        ]);

        $this->artisan('gallery:cycle-reminders', ['--force' => true])->assertSuccessful();
    }
}
