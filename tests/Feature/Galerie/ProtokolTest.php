<?php

namespace Tests\Feature\Galerie;

use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Co se stalo, má dvojice vidět.
 *
 * `AuditLog::record()` bere galerii z předmětu akce. Jenže polovina záznamů
 * žádný předmět nemá (`app_lock.*`, `vault.*`, `auth.login*`) nebo je jejich
 * předmětem `User`, který sloupec `gallery_space_id` nemá — takže se uložil
 * `null`. Panel Aktivita přitom čte `where('gallery_space_id', …)`, takže se
 * odemykání zámku, otevření trezoru ani přihlášení nikdy neobjevilo.
 *
 * Obrazovka zámku i trezoru přitom protokol výslovně slibují.
 */
class ProtokolTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    /** Záznam bez předmětu patří galerii toho, kdo ho vyvolal. */
    public function test_zaznam_bez_predmetu_zna_galerii(): void
    {
        AuditLog::record('app_lock.unlocked');

        $this->assertSame($this->prostor->id, (int) AuditLog::sole()->gallery_space_id,
            'Bez galerie se záznam v Aktivitě nikdy neukáže.');
    }

    /** A záznam o účtu taky — `User` sloupec `gallery_space_id` nemá. */
    public function test_zaznam_o_uctu_zna_galerii(): void
    {
        AuditLog::record('auth.login', $this->adri);

        $this->assertSame($this->prostor->id, (int) AuditLog::sole()->gallery_space_id);
    }

    /**
     * Předmět z jiné galerie si svou galerii ponechá.
     *
     * Jinak by se záznam o cizí věci objevil v Aktivitě téhle dvojice.
     */
    public function test_predmet_z_jine_galerie_si_svou_galerii_ponecha(): void
    {
        $cizi = User::factory()->create(['name' => 'Cizí']);
        $ciziProstor = GallerySpace::create(['name' => 'Jiná', 'owner_id' => $cizi->id]);

        AuditLog::record('album.created', $ciziProstor);

        $this->assertSame($ciziProstor->id, (int) AuditLog::sole()->gallery_space_id);
    }
}
