<?php

namespace Tests\Feature\Mazani;

use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Policies\MediaPolicy;
use App\Services\Media\MazaniFotek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Fotky se mažou jen po společném schválení.
 *
 * „Do koše" od jednoho z dvojice fotku jen navrhne ke smazání; do koše ji
 * přesune až souhlas druhého. Sám smí mazat jen ten, kdo v prostoru žádného
 * partnera nemá — deaktivovaný partner se počítá, jinak by stačilo mu
 * přístup odebrat a smazat všechno.
 */
class MazaniFotekServiceTest extends TestCase
{
    use DvojiceSHostem, OcekavaChybu, RefreshDatabase;

    private MazaniFotek $mazani;

    private Carbon $ted;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ted = Carbon::parse('2026-09-25 10:00:00');
        $this->travelTo($this->ted);
        config(['gallery.trash_retention_days' => 30]);
        $this->mazani = app(MazaniFotek::class);
    }

    public function test_vychozi_rezim_je_spolecne_i_u_radku_zalozeneho_bez_nej(): void
    {
        [, , , $prostor] = $this->dvojiceSHostem();

        $this->assertSame('spolecne', $prostor->fresh()->media_delete_mode);
        $this->assertSame('spolecne', $this->mazani->rezim($prostor));

        // Řádek vložený mimo model (jako ty z doby před migrací) dostane výchozí hodnotu z databáze.
        $id = DB::table('gallery_spaces')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Starý', 'slug' => 'stary-'.Str::random(5),
            'owner_id' => $prostor->owner_id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('spolecne', DB::table('gallery_spaces')->where('id', $id)->value('media_delete_mode'));
        $this->assertNull(DB::table('gallery_spaces')->where('id', $id)->value('media_delete_mode_requested'));
    }

    public function test_do_kose_ve_dvojici_fotku_jen_navrhne(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg');

        $this->assertFalse($this->mazani->muzeSam($prostor, $vlastnik));
        $vysledek = $this->mazani->doKose($prostor, $vlastnik, [$fotka->uuid], 'knihovna', false);

        $this->assertSame([$fotka->uuid], $vysledek->navrzeno);
        $this->assertSame([], $vysledek->presunuto);
        $fotka->refresh();
        $this->assertNull($fotka->trashed_at);
        $this->assertSame($vlastnik->id, (int) $fotka->trash_requested_by);
        $this->assertTrue($fotka->trash_requested_at->equalTo($this->ted));
        $this->assertSame(1, AuditLog::where('action', 'media.trash_proposed')->count());
        $this->assertSame(1, MediaItem::cekaNaSmazani()->count());
    }

    public function test_schvaleni_partnerem_presune_do_kose_na_tricet_dni(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg');
        $this->mazani->doKose($prostor, $vlastnik, [$fotka->uuid], 'knihovna', false);

        $vysledek = $this->mazani->schval($prostor, $partner, [$fotka->uuid], false);

        $this->assertSame([$fotka->uuid], $vysledek->schvaleno);
        $fotka->refresh();
        $this->assertTrue($fotka->trashed_at->equalTo($this->ted));
        $this->assertTrue($fotka->purge_after->equalTo($this->ted->copy()->addDays(30)));
        $this->assertSame($partner->id, (int) $fotka->trashed_by);
        $this->assertNull($fotka->trash_requested_by);
        $this->assertNull($fotka->trash_requested_at);

        $zaznam = AuditLog::where('action', 'media.trash_approved')->sole();
        $this->assertSame($vlastnik->id, (int) $zaznam->payload['navrhl']);
        $this->assertSame($partner->id, (int) $zaznam->payload['schvalil']);
        $this->assertSame(0, MediaItem::cekaNaSmazani()->count());
    }

    public function test_vlastni_navrh_schvalit_nejde(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg');
        $this->mazani->doKose($prostor, $vlastnik, [$fotka->uuid], 'knihovna', false);

        $this->ocekavejChybu(403, fn () => $this->mazani->schval($prostor, $vlastnik, [$fotka->uuid], false));

        $this->assertNull($fotka->fresh()->trashed_at);
        $this->assertSame(0, AuditLog::where('action', 'media.trash_approved')->count());
    }

    public function test_ponechat_partnerem_odmitne_a_navrhujicim_stahne(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $prvni = $this->fotka($prostor, $vlastnik, 'a.jpg');
        $druha = $this->fotka($prostor, $vlastnik, 'b.jpg');
        $this->mazani->doKose($prostor, $vlastnik, [$prvni->uuid, $druha->uuid], 'knihovna', false);

        $odmitnuto = $this->mazani->ponechat($prostor, $partner, [$prvni->uuid], false);
        $stazeno = $this->mazani->ponechat($prostor, $vlastnik, [$druha->uuid], false);

        $this->assertSame([$prvni->uuid], $odmitnuto->ponechano);
        $this->assertSame([$druha->uuid], $stazeno->ponechano);
        foreach ([$prvni, $druha] as $fotka) {
            $fotka->refresh();
            $this->assertNull($fotka->trash_requested_by);
            $this->assertNull($fotka->trash_requested_at);
            $this->assertNull($fotka->trashed_at);
        }
        $this->assertSame(1, AuditLog::where('action', 'media.trash_rejected')->count());
        $this->assertSame(1, AuditLog::where('action', 'media.trash_withdrawn')->count());
    }

    public function test_do_kose_druheho_na_navrzenou_fotku_je_souhlas(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg');
        $this->mazani->doKose($prostor, $vlastnik, [$fotka->uuid], 'knihovna', false);

        $vysledek = $this->mazani->doKose($prostor, $partner, [$fotka->uuid], 'knihovna', false);

        $this->assertSame([$fotka->uuid], $vysledek->schvaleno);
        $this->assertSame([$fotka->uuid], $vysledek->vKosi());
        $this->assertNotNull($fotka->fresh()->trashed_at);
        $this->assertSame($partner->id, (int) $fotka->fresh()->trashed_by);
        $this->assertSame(1, AuditLog::where('action', 'media.trash_approved')->count());
    }

    public function test_dvojklik_navrhu_vytvori_jediny_zaznam(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg');

        $this->mazani->doKose($prostor, $vlastnik, [$fotka->uuid], 'knihovna', false);
        $druhy = $this->mazani->doKose($prostor, $vlastnik, [$fotka->uuid], 'knihovna', false);

        $this->assertSame([$fotka->uuid], $druhy->uzNavrzeno);
        $this->assertSame([], $druhy->navrzeno);
        $this->assertSame(1, AuditLog::where('action', 'media.trash_proposed')->count());
    }

    public function test_druhe_schvaleni_nic_neudela(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg');
        $this->mazani->doKose($prostor, $vlastnik, [$fotka->uuid], 'knihovna', false);
        $this->mazani->schval($prostor, $partner, [$fotka->uuid], false);
        $prvniKos = $fotka->fresh()->trashed_at;

        $this->travel(5)->minutes();
        $druhe = $this->mazani->schval($prostor, $partner, [$fotka->uuid], false);
        $opet = $this->mazani->doKose($prostor, $vlastnik, [$fotka->uuid], 'knihovna', false);

        $this->assertTrue($druhe->jePrazdny());
        $this->assertTrue($opet->jePrazdny());
        $this->assertTrue($fotka->fresh()->trashed_at->equalTo($prvniKos));
        $this->assertSame(1, AuditLog::where('action', 'media.trash_approved')->count());
    }

    public function test_host_nesmi_navrhnout_schvalit_ani_ponechat(): void
    {
        [$vlastnik, , $host, $prostor] = $this->dvojiceSHostem();
        $host->forceFill(['role' => 'owner'])->save(); // users.role oprávnění nedává
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg');
        $this->mazani->doKose($prostor, $vlastnik, [$fotka->uuid], 'knihovna', false);

        $this->ocekavejChybu(403, fn () => $this->mazani->doKose($prostor, $host, [$fotka->uuid], 'knihovna', false));
        $this->ocekavejChybu(403, fn () => $this->mazani->schval($prostor, $host, [$fotka->uuid], false));
        $this->ocekavejChybu(403, fn () => $this->mazani->ponechat($prostor, $host, [$fotka->uuid], false));

        $this->assertNull($fotka->fresh()->trashed_at);
        $this->assertSame($vlastnik->id, (int) $fotka->fresh()->trash_requested_by);
    }

    public function test_rezim_jen_pro_cteni_je_odmitnut(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg');
        $this->mazani->doKose($prostor, $vlastnik, [$fotka->uuid], 'knihovna', false);
        $partner->forceFill(['read_only_mode' => true])->save();

        $this->ocekavejChybu(403, fn () => $this->mazani->schval($prostor, $partner, [$fotka->uuid], false));
        $this->ocekavejChybu(403, fn () => $this->mazani->doKose($prostor, $partner, [$fotka->uuid], 'knihovna', false));

        $this->assertNull($fotka->fresh()->trashed_at);
    }

    public function test_deaktivovany_partner_se_pocita_a_schvalit_nemuze(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $partner->forceFill(['is_active' => false])->save();
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg');

        $this->assertSame(2, $this->mazani->pocetDvojice($prostor));
        $this->assertFalse($this->mazani->muzeSam($prostor, $vlastnik));
        $vysledek = $this->mazani->doKose($prostor, $vlastnik, [$fotka->uuid], 'knihovna', false);

        $this->assertSame([$fotka->uuid], $vysledek->navrzeno);
        $this->assertNull($fotka->fresh()->trashed_at);
        $this->ocekavejChybu(403, fn () => $this->mazani->schval($prostor, $partner, [$fotka->uuid], false));
        $this->assertNull($fotka->fresh()->trashed_at);
    }

    public function test_jediny_clen_dvojice_maze_hned(): void
    {
        $sam = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $host = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $prostor = GallerySpace::create(['name' => 'Jen já', 'owner_id' => $sam->id]);
        $prostor->members()->attach($sam->id, ['role' => 'owner']);
        $prostor->members()->attach($host->id, ['role' => 'viewer']);
        $fotka = $this->fotka($prostor, $sam, 'sam.jpg');

        $this->assertSame(1, $this->mazani->pocetDvojice($prostor));
        $this->assertTrue($this->mazani->muzeSam($prostor, $sam));
        $vysledek = $this->mazani->doKose($prostor, $sam, [$fotka->uuid], 'knihovna', false);

        $this->assertSame([$fotka->uuid], $vysledek->presunuto);
        $fotka->refresh();
        $this->assertTrue($fotka->trashed_at->equalTo($this->ted));
        $this->assertTrue($fotka->purge_after->equalTo($this->ted->copy()->addDays(30)));
        $this->assertSame($sam->id, (int) $fotka->trashed_by);
        $zaznam = AuditLog::where('action', 'media.trash')->sole();
        $this->assertSame('spolecne', $zaznam->payload['rezim']);
        $this->assertSame('knihovna', $zaznam->payload['odkud']);
    }

    public function test_pet_set_fotek_jednim_volanim(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $radky = [];
        for ($i = 0; $i < 500; $i++) {
            $radky[] = [
                'uuid' => (string) Str::uuid(), 'gallery_space_id' => $prostor->id, 'owner_user_id' => $vlastnik->id,
                'uploaded_by' => $vlastnik->id, 'original_filename' => "f{$i}.jpg", 'safe_filename' => "f{$i}.jpg",
                'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 10,
                'status' => 'ready', 'storage_status' => 'local_only', 'is_hidden' => false,
                'uploaded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($radky, 100) as $davka) {
            MediaItem::insert($davka);
        }
        $uuids = array_column($radky, 'uuid');

        $navrh = $this->mazani->doKose($prostor, $vlastnik, $uuids, 'knihovna', false);
        $this->assertCount(500, $navrh->navrzeno);

        $schvaleni = $this->mazani->schval($prostor, $partner, $uuids, false);
        $this->assertCount(500, $schvaleni->schvaleno);
        $this->assertSame(500, MediaItem::whereNotNull('trashed_at')->count());
    }

    public function test_trezor_zamceny_preskoci_odemceny_navrhne(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $skryta = $this->fotka($prostor, $vlastnik, 'tajne-jmeno.jpg', ['is_hidden' => true]);

        $zamceno = $this->mazani->doKose($prostor, $vlastnik, [$skryta->uuid], 'trezor', false);
        $this->assertSame([$skryta->uuid], $zamceno->preskoceno);
        $this->assertNull($skryta->fresh()->trash_requested_at);

        $odemceno = $this->mazani->doKose($prostor, $vlastnik, [$skryta->uuid], 'trezor', true);
        $this->assertSame([$skryta->uuid], $odemceno->navrzeno);

        // Schválení i ponechání se zamčeným trezorem skrytou fotku minou.
        $this->assertSame([$skryta->uuid], $this->mazani->schval($prostor, $partner, [$skryta->uuid], false)->preskoceno);
        $this->assertSame([$skryta->uuid], $this->mazani->ponechat($prostor, $partner, [$skryta->uuid], false)->preskoceno);
        $this->assertNull($skryta->fresh()->trashed_at);
        $this->assertNotNull($skryta->fresh()->trash_requested_at);

        $this->assertSame([$skryta->uuid], $this->mazani->schval($prostor, $partner, [$skryta->uuid], true)->schvaleno);
        $this->assertNotNull($skryta->fresh()->trashed_at);

        // Jméno souboru z trezoru se do protokolu nedostane (úvodní přehled ho vypisuje).
        foreach (AuditLog::whereIn('action', ['media.trash_proposed', 'media.trash_approved'])->get() as $zaznam) {
            $this->assertArrayNotHasKey('filename', (array) $zaznam->payload);
        }
    }

    public function test_cizi_prostor_se_nedotkne(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $ciziFotka = $this->fotka($ciziProstor, $cizi, 'cizi.jpg');

        $vysledek = $this->mazani->doKose($prostor, $vlastnik, [$ciziFotka->uuid], 'knihovna', false);

        $this->assertTrue($vysledek->jePrazdny());
        $this->assertNull($ciziFotka->fresh()->trash_requested_at);
    }

    public function test_zruseni_navrhu_uzivatele_se_tyka_jen_jeho(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $moje = $this->fotka($prostor, $vlastnik, 'a.jpg');
        $jeho = $this->fotka($prostor, $partner, 'b.jpg');
        $this->mazani->doKose($prostor, $vlastnik, [$moje->uuid], 'knihovna', false);
        $this->mazani->doKose($prostor, $partner, [$jeho->uuid], 'knihovna', false);

        $zruseno = $this->mazani->zrusNavrhyUzivatele($vlastnik);

        $this->assertSame(1, $zruseno);
        $this->assertNull($moje->fresh()->trash_requested_at);
        $this->assertNull($moje->fresh()->trash_requested_by);
        $this->assertSame($partner->id, (int) $jeho->fresh()->trash_requested_by);
    }

    public function test_pravidlo_smazani_pusti_jen_clena_dvojice(): void
    {
        [$vlastnik, $partner, $host, $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg');
        $pravidlo = app(MediaPolicy::class);

        // `can_delete` v členství už oprávnění není — rozhoduje dvojice.
        $prostor->members()->updateExistingPivot($partner->id, ['can_delete' => false]);

        $this->assertTrue($pravidlo->delete($vlastnik, $fotka));
        $this->assertTrue($pravidlo->delete($partner->fresh(), $fotka));
        $this->assertTrue($pravidlo->restore($partner->fresh(), $fotka));
        $this->assertFalse($pravidlo->delete($host, $fotka));
        $this->assertFalse($pravidlo->restore($host, $fotka));

        $host->forceFill(['role' => 'owner'])->save();
        $this->assertFalse($pravidlo->delete($host->fresh(), $fotka));

        $partner->forceFill(['read_only_mode' => true])->save();
        $this->assertFalse($pravidlo->delete($partner->fresh(), $fotka));
    }
}
