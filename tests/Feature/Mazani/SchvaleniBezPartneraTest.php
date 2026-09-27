<?php

namespace Tests\Feature\Mazani;

use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Media\MazaniFotek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Přebití vlastníkem se záznamem (rozhodnutí 27. 9. 2026).
 *
 * „Když partner nemá přístup, vlastník může návrh schválit sám. Aplikace si
 * vyžádá potvrzení a zapíše to do protokolu."
 *
 * Partner s odebraným přístupem (`users.is_active = false`) se do dvojice
 * počítá dál — jinak by stačilo mu přístup odebrat a mazat bez dohody. Schválit
 * ale nemůže, takže vlastníkovy návrhy by čekaly navždy. Cesta ven je jen
 * pro vlastníka prostoru, jen s výslovným potvrzením a vždy s řádkem
 * v protokolu. Do koše jde fotka stejně jako při běžném schválení.
 */
class SchvaleniBezPartneraTest extends TestCase
{
    use DvojiceSHostem, RefreshDatabase;

    private const CESTA = '/api/kos/schvalit-sam';

    private User $vlastnik;

    private User $partner;

    private User $host;

    private GallerySpace $prostor;

    private Carbon $ted;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ted = Carbon::parse('2026-09-27 10:00:00');
        $this->travelTo($this->ted);
        config(['gallery.trash_retention_days' => 30]);

        [$this->vlastnik, $this->partner, $this->host, $this->prostor] = $this->dvojiceSHostem();
        $this->vlastnik->update(['name' => 'Bára']);
        $this->partner->update(['name' => 'Ctibor']);
    }

    public function test_vlastnik_schvali_sam_kdyz_partner_nema_pristup(): void
    {
        $fotka = $this->navrzenaVlastnikem('more.jpg');
        $this->odeberPartnerovi();
        Sanctum::actingAs($this->vlastnik);

        $odpoved = $this->postJson(self::CESTA, ['ids' => [$fotka->uuid], 'potvrzuji_bez_partnera' => true])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ids', [$fotka->uuid])
            ->assertJsonPath('rezim', 'spolecne');

        $this->assertStringContainsString('30 dní na vrácení', (string) $odpoved->json('zprava'));

        // Týmiž hodnotami jako běžné schválení.
        $fotka->refresh();
        $this->assertTrue($fotka->trashed_at->equalTo($this->ted));
        $this->assertTrue($fotka->purge_after->equalTo($this->ted->copy()->addDays(30)));
        $this->assertSame($this->vlastnik->id, (int) $fotka->trashed_by);
        $this->assertNull($fotka->trash_requested_by);
        $this->assertNull($fotka->trash_requested_at);

        $zaznam = AuditLog::where('action', 'media.trash_approved_alone')->sole();
        $this->assertSame($this->vlastnik->id, (int) $zaznam->user_id);
        $this->assertSame($fotka->id, (int) $zaznam->subject_id);
        $this->assertSame((int) $this->prostor->id, (int) $zaznam->gallery_space_id);
        $this->assertSame($this->vlastnik->id, $zaznam->payload['navrhl']);
        $this->assertSame($this->vlastnik->id, $zaznam->payload['schvalil']);
        $this->assertSame('partner_bez_pristupu', $zaznam->payload['duvod']);
        $this->assertSame([$this->partner->id], $zaznam->payload['partner']);
        $this->assertSame('more.jpg', $zaznam->payload['filename']);
        $this->assertSame(0, AuditLog::where('action', 'media.trash_approved')->count(), 'Přebití se v protokolu nesmí tvářit jako souhlas partnera.');

        // Odpověď nese obsah obrazovky jako běžné schválení.
        $this->assertSame([], $odpoved->json('data.KE_SCHVALENI'));
        $this->assertSame($fotka->uuid, $odpoved->json('data.TRASH.0.id'));
        $this->assertSame(0, $odpoved->json('data.MAZANI.cekaNaPartnera'));
    }

    public function test_bez_potvrzeni_je_422_a_nic_se_nestane(): void
    {
        $fotka = $this->navrzenaVlastnikem('more.jpg');
        $this->odeberPartnerovi();
        Sanctum::actingAs($this->vlastnik);

        foreach ([[], ['potvrzuji_bez_partnera' => false], ['potvrzuji_bez_partnera' => 'ano']] as $navic) {
            $odpoved = $this->postJson(self::CESTA, ['ids' => [$fotka->uuid]] + $navic)->assertStatus(422);
            $this->assertStringContainsString('nemá přístup', (string) $odpoved->json('message'));
        }

        $this->assertNull($fotka->fresh()->trashed_at);
        $this->assertSame($this->vlastnik->id, (int) $fotka->fresh()->trash_requested_by);
        $this->assertSame(0, AuditLog::where('action', 'media.trash_approved_alone')->count());
    }

    public function test_partner_s_pristupem_schvaluje_sam(): void
    {
        $fotka = $this->navrzenaVlastnikem('more.jpg');
        Sanctum::actingAs($this->vlastnik);

        $this->postJson(self::CESTA, ['ids' => [$fotka->uuid], 'potvrzuji_bez_partnera' => true])->assertForbidden();

        $this->assertNull($fotka->fresh()->trashed_at);
        $this->assertSame(0, AuditLog::where('action', 'media.trash_approved_alone')->count());

        // Běžná cesta zůstává, jak byla: vlastní návrh schválit nejde.
        $this->postJson('/api/kos/schvalit', ['ids' => [$fotka->uuid]])->assertForbidden();
    }

    public function test_partner_jen_pro_cteni_pristup_ma(): void
    {
        $fotka = $this->navrzenaVlastnikem('more.jpg');
        $this->partner->update(['read_only_mode' => true]);
        Sanctum::actingAs($this->vlastnik);

        $this->postJson(self::CESTA, ['ids' => [$fotka->uuid], 'potvrzuji_bez_partnera' => true])->assertForbidden();

        $this->assertNull($fotka->fresh()->trashed_at);
    }

    public function test_bez_partnera_v_galerii_neni_co_prebijet(): void
    {
        $this->assertFalse(app(MazaniFotek::class)->partnerBezPristupu($this->prostorBezPartnera()));
    }

    public function test_spravce_ani_host_to_nesmi(): void
    {
        // Správce z dvojice (ne vlastník) navrhne; druhý z dvojice je vlastník, ten přístup má vždycky.
        DB::table('gallery_space_user')->where('gallery_space_id', $this->prostor->id)->where('user_id', $this->partner->id)->update(['role' => 'admin']);
        $fotka = $this->fotka($this->prostor, $this->partner, 'hory.jpg');
        Sanctum::actingAs($this->partner);
        $this->deleteJson('/api/media/'.$fotka->uuid)->assertOk()->assertJsonPath('status', 'proposed');

        $this->postJson(self::CESTA, ['ids' => [$fotka->uuid], 'potvrzuji_bez_partnera' => true])->assertForbidden();

        Sanctum::actingAs($this->host);
        $this->postJson(self::CESTA, ['ids' => [$fotka->uuid], 'potvrzuji_bez_partnera' => true])->assertForbidden();

        $this->assertNull($fotka->fresh()->trashed_at);
        $this->assertSame(0, AuditLog::where('action', 'media.trash_approved_alone')->count());
    }

    public function test_ne_vlastnik_nesmi_ani_kdyz_druhemu_chybi_pristup(): void
    {
        // Třetí člen dvojice ze starých dat: správce, ne vlastník. Ani odebraný přístup partnera mu přebití nedá.
        $spravce = User::factory()->create(['is_active' => true]);
        $this->prostor->members()->attach($spravce->id, ['role' => 'admin', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $fotka = $this->navrzenaVlastnikem('more.jpg');
        $this->odeberPartnerovi();
        Sanctum::actingAs($spravce);

        $this->postJson(self::CESTA, ['ids' => [$fotka->uuid], 'potvrzuji_bez_partnera' => true])->assertForbidden();
        $this->assertNull($fotka->fresh()->trashed_at);
    }

    public function test_hromadne_i_s_navrhem_partnera(): void
    {
        $moje = $this->navrzenaVlastnikem('a.jpg');
        $druha = $this->navrzenaVlastnikem('b.jpg');
        // Partner navrhl dřív, než přišel o přístup — to je obyčejný souhlas.
        $jeho = $this->fotka($this->prostor, $this->partner, 'c.jpg');
        Sanctum::actingAs($this->partner);
        $this->deleteJson('/api/media/'.$jeho->uuid)->assertOk();
        $this->odeberPartnerovi();
        Sanctum::actingAs($this->vlastnik);

        $odpoved = $this->postJson(self::CESTA, [
            'ids' => [$moje->uuid, $druha->uuid, $jeho->uuid, 'neexistuje'],
            'potvrzuji_bez_partnera' => true,
        ])->assertOk();

        $this->assertEqualsCanonicalizing([$moje->uuid, $druha->uuid, $jeho->uuid], $odpoved->json('ids'));
        $this->assertSame(0, MediaItem::whereNull('trashed_at')->whereKey([$moje->id, $druha->id, $jeho->id])->count());
        $this->assertSame(2, AuditLog::where('action', 'media.trash_approved_alone')->count());
        $this->assertSame(1, AuditLog::where('action', 'media.trash_approved')->count());

        // Druhé kliknutí už nic nenajde a druhý záznam nevznikne.
        $this->postJson(self::CESTA, ['ids' => [$moje->uuid], 'potvrzuji_bez_partnera' => true])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('ids', []);
        $this->assertSame(2, AuditLog::where('action', 'media.trash_approved_alone')->count());
    }

    public function test_skryta_fotka_bez_jmena_v_protokolu_a_zamceny_trezor_ji_mine(): void
    {
        // Návrh rovnou v datech: odemčený trezor (sezení a `Referer`) by v testu platil i pro další požadavky.
        $skryta = $this->fotka($this->prostor, $this->vlastnik, 'pas.jpg', ['is_hidden' => true]);
        $skryta->forceFill(['trash_requested_by' => $this->vlastnik->id, 'trash_requested_at' => now()])->save();
        $this->odeberPartnerovi();

        // Zamčený trezor: jako by tu nebyla.
        Sanctum::actingAs($this->vlastnik);
        $zamceno = $this->postJson(self::CESTA, ['ids' => [$skryta->uuid], 'potvrzuji_bez_partnera' => true])
            ->assertOk()
            ->assertJsonPath('ids', []);
        $this->assertStringNotContainsString($skryta->uuid, $zamceno->getContent());
        $this->assertNull($skryta->fresh()->trashed_at);

        $this->sOdemcenymTrezorem($this->vlastnik)
            ->postJson(self::CESTA, ['ids' => [$skryta->uuid], 'potvrzuji_bez_partnera' => true])
            ->assertOk()
            ->assertJsonPath('ids', [$skryta->uuid]);

        $this->assertNotNull($skryta->fresh()->trashed_at);
        $zaznam = AuditLog::where('action', 'media.trash_approved_alone')->sole();
        $this->assertArrayNotHasKey('filename', $zaznam->payload);
        $this->assertStringNotContainsString('pas.jpg', json_encode($zaznam->payload));
    }

    public function test_obrazovka_vi_ze_partner_nema_pristup(): void
    {
        Sanctum::actingAs($this->vlastnik);
        $this->assertFalse($this->getJson('/api/data/system')->assertOk()->json('data.MAZANI.partnerBezPristupu'));

        Sanctum::actingAs($this->partner);
        $this->assertFalse($this->getJson('/api/data/system')->assertOk()->json('data.MAZANI.partnerBezPristupu'));

        $this->odeberPartnerovi();
        Sanctum::actingAs($this->vlastnik);
        $this->assertTrue($this->getJson('/api/data/system')->assertOk()->json('data.MAZANI.partnerBezPristupu'));

        // Obnovený přístup: tlačítko zase zmizí.
        $this->partner->forceFill(['is_active' => true, 'access_revoked_at' => null])->save();
        $this->assertFalse($this->getJson('/api/data/system')->assertOk()->json('data.MAZANI.partnerBezPristupu'));
    }

    public function test_prehled_dnes_prebiti_pojmenuje(): void
    {
        $fotka = $this->navrzenaVlastnikem('more.jpg');
        $this->odeberPartnerovi();
        $this->travel(2)->hours();
        $this->postJson(self::CESTA, ['ids' => [$fotka->uuid], 'potvrzuji_bez_partnera' => true])->assertOk();

        $radky = array_column($this->getJson('/api/data/dnes')->assertOk()->json('data.DNES.aktivita'), 1);

        $this->assertContains('Bára · smazání bez partnera (neměl přístup) more.jpg', $radky);
    }

    public function test_jediny_v_galerii_partnera_bez_pristupu_nema(): void
    {
        $prostor = $this->prostorBezPartnera();
        Sanctum::actingAs(User::query()->findOrFail($prostor->owner_id));

        $this->assertFalse($this->getJson('/api/data/system')->assertOk()->json('data.MAZANI.partnerBezPristupu'));
    }

    // ——— pomocné ———

    private function navrzenaVlastnikem(string $nazev): MediaItem
    {
        $fotka = $this->fotka($this->prostor, $this->vlastnik, $nazev);
        Sanctum::actingAs($this->vlastnik);
        $this->deleteJson('/api/media/'.$fotka->uuid)->assertOk()->assertJsonPath('status', 'proposed');

        return $fotka;
    }

    /**
     * „Odebrat přístup" v administraci — a to už před patnácti dny, takže
     * čekací lhůta (`MazaniFotek::LHUTA_BEZ_PRISTUPU_DNI`) uběhla. Samotnou
     * lhůtu zkouší `LhutaBezPartneraTest`.
     */
    private function odeberPartnerovi(): void
    {
        $this->partner->forceFill(['is_active' => false, 'access_revoked_at' => now()->subDays(15)])->save();
    }

    private function prostorBezPartnera(): GallerySpace
    {
        $sam = User::factory()->create(['is_active' => true]);
        $prostor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Jen já', 'slug' => 'jen-ja-'.Str::random(5), 'owner_id' => $sam->id, 'is_default' => true]);
        $prostor->members()->attach($sam->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);

        return $prostor;
    }
}
