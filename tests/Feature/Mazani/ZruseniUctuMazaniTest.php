<?php

namespace Tests\Feature\Mazani;

use App\Services\Auth\ZruseniUctu;
use App\Services\Media\MazaniFotek;
use App\Support\SpaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Zrušený účet po sobě nenechá návrh ke smazání.
 *
 * Řádek účtu se jen anonymizuje (drží na něj odkazy protokol i fotky), takže
 * cizí klíč `trash_requested_by` by na něj ukazoval dál. Návrh by pak nešel
 * stáhnout (navrhující už není), a kdo zbyl, by ho „schválil" jen tím, že
 * fotku sám pošle do koše.
 */
class ZruseniUctuMazaniTest extends TestCase
{
    use DvojiceSHostem, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SpaceContext::forget();
        Storage::fake('local');
    }

    public function test_zruseni_uctu_zrusi_jeho_navrhy_fotek_i_rezimu(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $jehoNavrh = $this->fotka($prostor, $vlastnik, 'jeho.jpg');
        $mujNavrh = $this->fotka($prostor, $vlastnik, 'muj.jpg');
        $mazani = app(MazaniFotek::class);
        $mazani->doKose($prostor, $partner, [$mujNavrh->uuid], 'knihovna', false);
        $mazani->doKose($prostor, $vlastnik, [$jehoNavrh->uuid], 'knihovna', false);
        $this->assertSame('navrzeno', $mazani->navrhniRezim($prostor, $partner, MazaniFotek::KAZDY, null, null));

        app(ZruseniUctu::class)->proved($partner);

        $mujNavrh->refresh();
        $this->assertNull($mujNavrh->trash_requested_by);
        $this->assertNull($mujNavrh->trash_requested_at);
        $this->assertNull($mujNavrh->trashed_at);
        $this->assertNull($prostor->fresh()->media_delete_mode_requested);
        $this->assertNull($prostor->fresh()->media_delete_mode_requested_by);
        // Návrh toho, kdo zůstává, se nemění.
        $this->assertSame($vlastnik->id, (int) $jehoNavrh->fresh()->trash_requested_by);
    }

    public function test_planovane_zruseni_po_lhute_navrhy_taky_zrusi(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg');
        app(MazaniFotek::class)->doKose($prostor, $partner, [$fotka->uuid], 'knihovna', false);
        $partner->forceFill(['preferences' => ['delete_requested_at' => now()->subDays(15)->toIso8601String()]])->save();

        $this->artisan('gallery:zrus-ucty')->assertSuccessful();

        $this->assertFalse((bool) $partner->fresh()->is_active);
        $this->assertNull($fotka->fresh()->trash_requested_by);
    }
}
