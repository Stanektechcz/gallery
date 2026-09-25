<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Auth\PristupDoGalerie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Prostory, kde účet patří do dvojice — ne galerie, kam je jen pozvaný jako host.
 *
 * Brána posuzuje jen první prostor účtu. Řadiče, které braly „kterýkoli
 * prostor, kde je členem", tak účtu s druhým členstvím otevíraly cesty,
 * dokumenty i výdaje cizí galerie.
 */
class ProstoryDvojiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_vraci_vlastni_prostor_a_prostor_s_roli_dvojice_ne_hostovsky(): void
    {
        $ja = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $cizi = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $vlastni = $this->prostor($ja, 'Náš', true);
        $jakoEditor = $this->prostor($cizi, 'Rodinný', false);
        $jakoHost = $this->prostor($cizi, 'Cizí', false);

        $vlastni->members()->attach($ja->id, ['role' => 'owner', 'joined_at' => now()]);
        $jakoEditor->members()->attach($ja->id, ['role' => 'editor', 'joined_at' => now()]);
        $jakoHost->members()->attach($ja->id, ['role' => 'viewer', 'joined_at' => now()]);

        $pristup = app(PristupDoGalerie::class);

        $this->assertEqualsCanonicalizing([$vlastni->id, $jakoEditor->id], $pristup->idProstoruDvojice($ja));
        $this->assertNotContains($jakoHost->id, $pristup->prostoryDvojice($ja)->pluck('id')->all());
    }

    public function test_vlastnik_prostoru_patri_do_dvojice_i_s_vychozi_roli_clenstvi(): void
    {
        $ja = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $prostor = $this->prostor($ja, 'Náš', true);
        $prostor->members()->attach($ja->id, ['role' => 'viewer', 'joined_at' => now()]);

        $this->assertSame([$prostor->id], app(PristupDoGalerie::class)->idProstoruDvojice($ja));
    }

    private function prostor(User $vlastnik, string $nazev, bool $vychozi): GallerySpace
    {
        return GallerySpace::create([
            'uuid' => (string) Str::uuid(),
            'name' => $nazev,
            'slug' => Str::slug($nazev).'-'.Str::random(4),
            'owner_id' => $vlastnik->id,
            'is_default' => $vychozi,
        ]);
    }
}
