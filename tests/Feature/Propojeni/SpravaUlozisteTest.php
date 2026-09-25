<?php

namespace Tests\Feature\Propojeni;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Storage\StorageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Úložiště prostoru připojuje a odpojuje jen vlastník galerie.
 *
 * `mayManage()` přehlížel `gallery_spaces.owner_id` a když nikdo neměl
 * v členství roli `owner`, sáhl po prvním členovi s `users.role = owner` —
 * tu má každý zaregistrovaný účet, tedy i host. Host tak mohl galerii dvojice
 * přesměrovat fotky na vlastní Dropbox, OneDrive nebo WebDAV.
 */
class SpravaUlozisteTest extends TestCase
{
    use RefreshDatabase;

    private User $vlastnik;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vlastnik = User::factory()->create(['role' => 'partner']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->vlastnik->id]);
        // Vlastníkovi zůstala v členství výchozí role — pořád je vlastník.
        $this->prostor->members()->syncWithoutDetaching([$this->vlastnik->id => ['role' => 'editor']]);
    }

    public function test_host_s_users_role_owner_uloziste_nespravuje(): void
    {
        $host = User::factory()->create(['role' => 'owner']);
        $this->prostor->members()->syncWithoutDetaching([$host->id => ['role' => 'viewer']]);

        $this->assertFalse($this->resolver()->mayManage($this->prostor, $host->id));
    }

    public function test_vlastnik_podle_owner_id_uloziste_spravuje(): void
    {
        $this->assertTrue($this->resolver()->mayManage($this->prostor, $this->vlastnik->id));
    }

    public function test_vlastnik_bez_clenstvi_uloziste_spravuje(): void
    {
        $this->prostor->members()->detach($this->vlastnik->id);

        $this->assertTrue($this->resolver()->mayManage($this->prostor, $this->vlastnik->id));
    }

    /** Spoluvlastník (role `owner` v členství) — obě poloviny dvojice. */
    public function test_spoluvlastnik_uloziste_spravuje(): void
    {
        $partner = User::factory()->create(['role' => 'partner']);
        $this->prostor->members()->syncWithoutDetaching([$partner->id => ['role' => 'owner']]);

        $this->assertTrue($this->resolver()->mayManage($this->prostor, $partner->id));
        $this->assertTrue($this->resolver()->mayManage($this->prostor, $this->vlastnik->id));
    }

    public function test_cizi_ucet_ani_nikdo_uloziste_nespravuje(): void
    {
        $cizi = User::factory()->create(['role' => 'owner']);

        $this->assertFalse($this->resolver()->mayManage($this->prostor, $cizi->id));
        $this->assertFalse($this->resolver()->mayManage($this->prostor, null));
    }

    private function resolver(): StorageResolver
    {
        return app(StorageResolver::class);
    }
}
