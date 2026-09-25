<?php

namespace Tests\Feature\Mazani;

use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Media\MazaniFotek;
use App\Services\Planning\AutomationRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Režim mazání mění dvojice jen společně.
 *
 * Povolit mazání každému zvlášť musí potvrdit druhý z dvojice (a prokázat se
 * kódem zámku nebo heslem). Zpřísnit zpátky na společné schvalování smí
 * kdokoli z nich hned.
 */
class RezimMazaniTest extends TestCase
{
    use DvojiceSHostem, OcekavaChybu, RefreshDatabase;

    private MazaniFotek $mazani;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mazani = app(MazaniFotek::class);
    }

    public function test_navrh_kazdy_zatim_nic_nemeni(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();

        $this->assertSame('navrzeno', $this->mazani->navrhniRezim($prostor, $vlastnik, 'kazdy', null, null));

        $prostor->refresh();
        $this->assertSame('spolecne', $prostor->media_delete_mode);
        $this->assertSame('kazdy', $prostor->media_delete_mode_requested);
        $this->assertSame($vlastnik->id, (int) $prostor->media_delete_mode_requested_by);
        $this->assertNotNull($prostor->media_delete_mode_requested_at);
        $this->assertSame(1, AuditLog::where('action', 'gallery.delete_mode_proposed')->count());

        // Druhé kliknutí nic nepřidá.
        $this->assertSame('beze_zmeny', $this->mazani->navrhniRezim($prostor, $vlastnik, 'kazdy', null, null));
        $this->assertSame(1, AuditLog::where('action', 'gallery.delete_mode_proposed')->count());
    }

    public function test_navrhujici_vlastni_navrh_potvrdit_nemuze(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $this->mazani->navrhniRezim($prostor, $vlastnik, 'kazdy', null, null);

        $this->ocekavejChybu(403, fn () => $this->mazani->potvrdRezim($prostor, $vlastnik, null, 'password'));

        $this->assertSame('spolecne', $prostor->fresh()->media_delete_mode);
    }

    public function test_partner_potvrdi_spravnym_heslem(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $this->mazani->navrhniRezim($prostor, $vlastnik, 'kazdy', null, null);

        $this->assertSame('potvrzeno', $this->mazani->potvrdRezim($prostor, $partner, null, 'password'));

        $prostor->refresh();
        $this->assertSame('kazdy', $prostor->media_delete_mode);
        $this->assertNull($prostor->media_delete_mode_requested);
        $this->assertNull($prostor->media_delete_mode_requested_by);
        $this->assertNull($prostor->media_delete_mode_requested_at);
        $this->assertTrue($this->mazani->muzeSam($prostor, $vlastnik));
        $zaznam = AuditLog::where('action', 'gallery.delete_mode_confirmed')->sole();
        $this->assertSame($vlastnik->id, (int) $zaznam->payload['navrhl']);
        $this->assertSame($partner->id, (int) $zaznam->payload['potvrdil']);

        // Teď už každý maže sám.
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg');
        $this->assertSame([$fotka->uuid], $this->mazani->doKose($prostor, $vlastnik, [$fotka->uuid], 'knihovna', false)->presunuto);
    }

    public function test_spatne_heslo_rezim_nezmeni(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $this->mazani->navrhniRezim($prostor, $vlastnik, 'kazdy', null, null);

        $this->ocekavejChybu(422, fn () => $this->mazani->potvrdRezim($prostor, $partner, null, 'spatne'));

        $prostor->refresh();
        $this->assertSame('spolecne', $prostor->media_delete_mode);
        $this->assertSame('kazdy', $prostor->media_delete_mode_requested);
        $this->assertSame(1, AuditLog::where('action', 'gallery.delete_mode_confirm_failed')->count());
    }

    public function test_navrh_druheho_na_cekajici_navrh_je_potvrzeni(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $this->mazani->navrhniRezim($prostor, $vlastnik, 'kazdy', null, null);

        $this->ocekavejChybu(422, fn () => $this->mazani->navrhniRezim($prostor, $partner, 'kazdy', null, 'spatne'));
        $this->assertSame('spolecne', $prostor->fresh()->media_delete_mode);

        $this->assertSame('potvrzeno', $this->mazani->navrhniRezim($prostor, $partner, 'kazdy', null, 'password'));
        $this->assertSame('kazdy', $prostor->fresh()->media_delete_mode);
    }

    public function test_zprisneni_plati_hned_a_rusi_cekajici_navrh(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $this->mazani->navrhniRezim($prostor, $vlastnik, 'kazdy', null, null);
        $this->mazani->potvrdRezim($prostor, $partner, null, 'password');

        $this->assertSame('zprisneno', $this->mazani->navrhniRezim($prostor, $partner, 'spolecne', null, null));
        $this->assertSame('spolecne', $prostor->fresh()->media_delete_mode);
        $this->assertSame(1, AuditLog::where('action', 'gallery.delete_mode_tightened')->count());

        // Čekající návrh „každý sám" zruší volba „společně" od kohokoli.
        $this->mazani->navrhniRezim($prostor, $vlastnik, 'kazdy', null, null);
        $this->assertSame('zruseno', $this->mazani->navrhniRezim($prostor, $partner, 'spolecne', null, null));
        $prostor->refresh();
        $this->assertSame('spolecne', $prostor->media_delete_mode);
        $this->assertNull($prostor->media_delete_mode_requested);
    }

    public function test_navrh_zrusi_kterykoli_z_dvojice(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();

        $this->mazani->navrhniRezim($prostor, $vlastnik, 'kazdy', null, null);
        $this->assertTrue($this->mazani->zrusNavrhRezimu($prostor, $partner));
        $this->assertNull($prostor->fresh()->media_delete_mode_requested);

        $this->mazani->navrhniRezim($prostor, $vlastnik, 'kazdy', null, null);
        $this->assertTrue($this->mazani->zrusNavrhRezimu($prostor, $vlastnik));
        $this->assertFalse($this->mazani->zrusNavrhRezimu($prostor, $vlastnik));
        $this->assertSame(2, AuditLog::where('action', 'gallery.delete_mode_cancelled')->count());
    }

    public function test_samotny_clen_nema_s_kym_se_dohodnout(): void
    {
        $sam = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $prostor = GallerySpace::create(['name' => 'Jen já', 'owner_id' => $sam->id]);
        $prostor->members()->attach($sam->id, ['role' => 'owner']);

        $this->ocekavejChybu(409, fn () => $this->mazani->navrhniRezim($prostor, $sam, 'kazdy', null, null));
        $this->assertNull($prostor->fresh()->media_delete_mode_requested);
    }

    public function test_host_rezim_menit_nesmi(): void
    {
        [$vlastnik, , $host, $prostor] = $this->dvojiceSHostem();

        $this->ocekavejChybu(403, fn () => $this->mazani->navrhniRezim($prostor, $host, 'kazdy', null, null));
        $this->mazani->navrhniRezim($prostor, $vlastnik, 'kazdy', null, null);
        $this->ocekavejChybu(403, fn () => $this->mazani->potvrdRezim($prostor, $host, null, 'password'));
        $this->ocekavejChybu(403, fn () => $this->mazani->zrusNavrhRezimu($prostor, $host));
        $this->assertSame('kazdy', $prostor->fresh()->media_delete_mode_requested);
    }

    public function test_neznamy_rezim_je_chyba(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();

        $this->ocekavejChybu(422, fn () => $this->mazani->navrhniRezim($prostor, $vlastnik, 'nikdo', null, null));
    }

    public function test_zastaraly_zapis_automatizaci_rezim_nevrati(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $zastaraly = GallerySpace::find($prostor->id);

        $this->mazani->navrhniRezim($prostor, $vlastnik, 'kazdy', null, null);
        $this->mazani->potvrdRezim($prostor, $partner, null, 'password');
        app(AutomationRegistryService::class)->setEnabled($zastaraly, AutomationRegistryService::AUTO_COMPLETE_ELAPSED, false);

        $this->assertSame('kazdy', $prostor->fresh()->media_delete_mode);
    }

    public function test_cekajici_navrhy_fotek_zustanou_po_zmene_rezimu(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg');
        $this->mazani->doKose($prostor, $vlastnik, [$fotka->uuid], 'knihovna', false);

        $this->mazani->navrhniRezim($prostor, $vlastnik, 'kazdy', null, null);
        $this->mazani->potvrdRezim($prostor, $partner, null, 'password');
        $this->assertNotNull($fotka->fresh()->trash_requested_at);

        // Kdo návrh podal, ho teď dotáhne sám.
        $vysledek = $this->mazani->doKose($prostor, $vlastnik, [$fotka->uuid], 'knihovna', false);
        $this->assertSame([$fotka->uuid], $vysledek->presunuto);
        $this->assertNull($fotka->fresh()->trash_requested_at);
        $this->assertNotNull($fotka->fresh()->trashed_at);
    }
}
