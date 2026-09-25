<?php

namespace Tests\Feature\Propojeni;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Pozvánku do galerie posílá jen ten, kdo galerii spravuje.
 *
 * Brána `can:admin` pouštěla podle `users.role` (owner/admin) — a tu má každý
 * zaregistrovaný účet. Účet bez vlastní galerie tak prošel i bránou dvojice
 * (bez prostoru se přihlásit smí) a zakládal účty dalším lidem, i když je
 * registrace zavřená. O oprávnění rozhoduje role v prostoru, ne v `users`.
 */
class PozvankaSpravceTest extends TestCase
{
    use RefreshDatabase;

    private const CESTA = '/admin/users/invite';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['gallery.registration_open' => false]);
    }

    public function test_spravce_bez_galerie_pozvat_nesmi(): void
    {
        $bezGalerie = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($bezGalerie)->post(self::CESTA, $this->pozvanka())->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'novy@example.test']);
    }

    public function test_vlastnik_podle_users_role_bez_galerie_pozvat_nesmi(): void
    {
        $bezGalerie = User::factory()->create(['role' => 'owner', 'is_active' => true]);

        $this->actingAs($bezGalerie)->post(self::CESTA, $this->pozvanka())->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'novy@example.test']);
    }

    /** Partner (editor) v galerii správcem není, ať má v `users.role` cokoli. */
    public function test_editor_galerie_pozvat_nesmi(): void
    {
        $vlastnik = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $prostor = $this->prostor($vlastnik);
        $editor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $prostor->members()->syncWithoutDetaching([$editor->id => ['role' => 'editor']]);

        $this->actingAs($editor)->post(self::CESTA, $this->pozvanka())->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'novy@example.test']);
    }

    public function test_vlastnik_galerie_pozvat_smi(): void
    {
        $vlastnik = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->prostor($vlastnik);

        $this->actingAs($vlastnik)->post(self::CESTA, $this->pozvanka())->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'novy@example.test']);
    }

    /** Vlastník je vlastník i s výchozí rolí v členství (viz PristupDoGalerie). */
    public function test_vlastnik_s_vychozi_roli_v_clenstvi_pozvat_smi(): void
    {
        $vlastnik = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $this->prostor($vlastnik, 'editor');

        $this->actingAs($vlastnik)->post(self::CESTA, $this->pozvanka())->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'novy@example.test']);
    }

    public function test_spravce_galerie_pozvat_smi(): void
    {
        $vlastnik = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $prostor = $this->prostor($vlastnik);
        $spravce = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $prostor->members()->syncWithoutDetaching([$spravce->id => ['role' => 'admin']]);

        $this->actingAs($spravce)->post(self::CESTA, $this->pozvanka())->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'novy@example.test']);
    }

    private function prostor(User $vlastnik, string $role = 'owner'): GallerySpace
    {
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $vlastnik->id, 'is_default' => true]);
        $prostor->members()->syncWithoutDetaching([$vlastnik->id => ['role' => $role]]);

        return $prostor;
    }

    /** @return array<string, string> */
    private function pozvanka(): array
    {
        return ['name' => 'Nový', 'email' => 'novy@example.test', 'role' => 'viewer'];
    }
}
