<?php

namespace Tests\Feature\Galerie;

use App\Models\CoupleState;
use App\Models\GallerySpace;
use App\Models\User;
use Database\Seeders\GalerieSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Startovní stav prototypu.
 *
 * Seeder má být nudný a spustitelný opakovaně — nasazení ho pouští po každé
 * migraci a druhý běh nesmí nic přepsat.
 */
class SeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_kazdy_prostor_dostane_prazdny_stav(): void
    {
        $adri = User::factory()->create();
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $adri->id]);

        $this->seed(GalerieSeeder::class);

        $stav = CoupleState::where('couple_id', $prostor->id)->sole();

        $this->assertSame([], $stav->data);
        $this->assertSame(0, $stav->rev);
    }

    /** Druhý běh nesmí přepsat, co dvojice mezitím napsala. */
    public function test_opakovany_beh_nic_neprepise(): void
    {
        $adri = User::factory()->create();
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $adri->id]);

        $this->seed(GalerieSeeder::class);

        CoupleState::where('couple_id', $prostor->id)->sole()->applyPatch(['joy' => ['výlet do Řezna']]);

        $this->seed(GalerieSeeder::class);

        $stav = CoupleState::where('couple_id', $prostor->id)->sole();

        $this->assertSame(['výlet do Řezna'], $stav->data['joy']);
        $this->assertSame(1, $stav->rev);
        $this->assertSame(1, CoupleState::count());
    }
}
