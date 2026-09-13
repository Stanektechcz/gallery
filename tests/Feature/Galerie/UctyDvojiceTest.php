<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Oprava jmen a adres dvojice, která dojede s nasazením.
 *
 * Migrace běží na produkci jednou a nad skutečnými účty — proto se tu
 * zkouší hlavně to, na co nesmí sáhnout: cizí adresu, třetího člena,
 * heslo a vlastníkovo jméno.
 */
class UctyDvojiceTest extends TestCase
{
    use RefreshDatabase;

    private function spust(): void
    {
        (require database_path('migrations/2026_09_13_120000_sjednotit_ucty_dvojice.php'))->up();
    }

    private function dvojice(): array
    {
        $adrian = User::factory()->create(['name' => 'Adrian', 'email' => 'adrian@gallery.local']);
        $makinka = User::factory()->create(['name' => 'Markéta Hrnčířová', 'email' => 'makinka@gallery.local']);
        $prostor = GallerySpace::create(['name' => 'Naše galerie', 'owner_id' => $adrian->id, 'is_default' => true]);
        $prostor->members()->syncWithoutDetaching([
            $adrian->id => ['role' => 'owner'],
            $makinka->id => ['role' => 'editor'],
        ]);

        return [$adrian, $makinka, $prostor];
    }

    public function test_opravi_jmeno_a_adresy_dvojice(): void
    {
        [$adrian, $makinka] = $this->dvojice();
        $heslo = $makinka->password;

        $this->spust();

        $this->assertSame('info@stanektech.cz', $adrian->fresh()->email);
        $this->assertSame('Adrian', $adrian->fresh()->name, 'Jméno vlastníka se neměnilo.');
        $this->assertSame('Makinka Kubíčková', $makinka->fresh()->name);
        $this->assertSame('marketa@stanektech.cz', $makinka->fresh()->email);
        $this->assertSame($heslo, $makinka->fresh()->password, 'Heslo zůstává.');
    }

    /** Adresu, kterou už má jiný účet, nepřepíše — unikátní sloupec by shodil nasazení. */
    public function test_obsazenou_adresu_neprepise(): void
    {
        [$adrian] = $this->dvojice();
        User::factory()->create(['email' => 'info@stanektech.cz']);

        $this->spust();

        $this->assertSame('adrian@gallery.local', $adrian->fresh()->email);
    }

    /** Se třetím členem se nepozná, kdo je Makinka — nic se nepřejmenuje. */
    public function test_se_tretim_clenem_jmena_nemeni(): void
    {
        [, $makinka, $prostor] = $this->dvojice();
        $host = User::factory()->create(['name' => 'Klára']);
        $prostor->members()->syncWithoutDetaching([$host->id => ['role' => 'viewer']]);

        $this->spust();

        $this->assertSame('Markéta Hrnčířová', $makinka->fresh()->name);
        $this->assertSame('Klára', $host->fresh()->name);
    }

    public function test_dvakrat_spustena_je_totez(): void
    {
        [, $makinka] = $this->dvojice();

        $this->spust();
        $this->spust();

        $this->assertSame('marketa@stanektech.cz', $makinka->fresh()->email);
    }
}
