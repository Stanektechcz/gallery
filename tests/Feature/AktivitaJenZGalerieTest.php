<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Přehled aktivity ukazuje jen tuhle galerii.
 *
 * Stránka `/activity` filtrovala protokol jen podle toho, **kdo** akci
 * udělal — členové galerie. Kdo je ve dvou galeriích, tomu partner v té první
 * viděl, co nahrál do druhé, i se jménem souboru. Sloupec `gallery_space_id`
 * v protokolu vznikl právě kvůli tomuhle a přehled „Dnes" ho používá; tahle
 * stránka zůstala pozadu.
 */
class AktivitaJenZGalerieTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_nevidi_co_druhy_dela_v_jine_galerii(): void
    {
        $adri = User::factory()->create(['name' => 'Adrian']);
        $maki = User::factory()->create(['name' => 'Makinka']);
        $nase = GallerySpace::create(['name' => 'Naše', 'owner_id' => $adri->id, 'is_default' => true]);
        $nase->members()->syncWithoutDetaching([$adri->id => ['role' => 'owner'], $maki->id => ['role' => 'editor']]);
        $ciziGalerie = GallerySpace::create(['name' => 'Rodinná', 'owner_id' => $adri->id]);
        $ciziGalerie->members()->syncWithoutDetaching([$adri->id => ['role' => 'owner']]);

        $this->zaznam($adri, $nase, 'spolecny-vylet.jpg');
        $this->zaznam($adri, $ciziGalerie, 'z-jine-galerie.jpg');

        $this->actingAs($maki)->get('/activity')
            ->assertOk()
            ->assertSee('spolecny-vylet.jpg')
            ->assertDontSee('z-jine-galerie.jpg');
    }

    private function zaznam(User $kdo, GallerySpace $galerie, string $soubor): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $kdo->id,
            'action' => 'media.upload',
            'payload' => json_encode(['filename' => $soubor]),
            'gallery_space_id' => $galerie->id,
            'created_at' => now(),
        ]);
    }
}
