<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\User;
use App\Notifications\ZmenaEmailuNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Změna e-mailu dá vědět na adresu, ze které účet odchází.
 *
 * E-mail je klíč k účtu: na něj chodí odkaz na nové heslo. Kdo zná heslo
 * (nebo drží odemčený telefon a heslo odkouká), přepsal adresu na svou —
 * a majitel se to nedozvěděl, dokud se nepokusil heslo obnovit. Teď dostane
 * zprávu na starou adresu a v protokolu je stopa.
 */
class ZmenaEmailuTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->adri = User::factory()->create([
            'name' => 'Adrian',
            'email' => 'adrian@vzpominky.test',
            'password' => Hash::make('zadar-2026-heslo'),
        ]);
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$prostor->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    public function test_zmena_emailu_upozorni_starou_adresu(): void
    {
        $this->patchJson('/api/v1/profil', [
            'name' => 'Adrian',
            'email' => 'novy@vzpominky.test',
            'current_password' => 'zadar-2026-heslo',
        ])->assertOk();

        $this->assertSame('novy@vzpominky.test', $this->adri->fresh()->email);

        Notification::assertSentOnDemand(
            ZmenaEmailuNotification::class,
            fn ($zprava, array $kanaly, AnonymousNotifiable $komu) => ($komu->routes['mail'] ?? null) === 'adrian@vzpominky.test'
                && $zprava->novy === 'novy@vzpominky.test',
        );
        $this->assertTrue(
            AuditLog::where('action', 'profile.email_changed')->where('user_id', $this->adri->id)->exists(),
            'Změna adresy nenechala stopu v protokolu.',
        );
    }

    public function test_zmena_jmena_nic_neposila(): void
    {
        $this->patchJson('/api/v1/profil', [
            'name' => 'Adrián',
            'email' => 'adrian@vzpominky.test',
        ])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_spatne_heslo_adresu_nezmeni_ani_neposila(): void
    {
        $this->patchJson('/api/v1/profil', [
            'name' => 'Adrian',
            'email' => 'novy@vzpominky.test',
            'current_password' => 'spatne-heslo',
        ])->assertStatus(422);

        $this->assertSame('adrian@vzpominky.test', $this->adri->fresh()->email);
        Notification::assertNothingSent();
    }
}
