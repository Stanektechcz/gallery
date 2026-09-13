<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * „Odhlásit ostatní" ve starém rozhraní odhlásí i aplikaci.
 *
 * Rušila se jen sezení prohlížeče a obrazovka hlásila „Všechna ostatní
 * zařízení byla odhlášena" — telefon s klíčem aplikace zůstal přihlášený.
 */
class OdhlaseniOstatnichTest extends TestCase
{
    use RefreshDatabase;

    public function test_odhlasi_sezeni_i_klice_aplikace(): void
    {
        $clovek = User::factory()->create(['role' => 'owner']);
        GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Společně', 'slug' => 'spolecne', 'owner_id' => $clovek->id])
            ->members()->attach($clovek->id, ['role' => 'owner']);

        $clovek->createToken('telefon');
        DB::table('sessions')->insert(['id' => 'jine-zarizeni', 'user_id' => $clovek->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($clovek)
            ->postJson('/settings/security/sessions/revoke-others')
            ->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'jine-zarizeni']);
        $this->assertSame(0, $clovek->tokens()->count(), 'Telefon s aplikací musí přestat být přihlášený.');
        $this->assertAuthenticatedAs($clovek);
    }
}
