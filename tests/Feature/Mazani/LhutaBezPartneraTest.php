<?php

namespace Tests\Feature\Mazani;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Media\MazaniFotek;
use App\Services\Provoz\AdministraceZasahy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Přebití vlastníkem nesmí být zkratka kolem společného schválení.
 *
 * Vlastník sám partnerovi přístup odebírá (a tím ruší i jeho upozornění),
 * takže bez pojistek by šlo: odebrat přístup, schválit vlastní návrhy,
 * trvale smazat z koše a přístup vrátit — partner by se nedozvěděl nic.
 * Proto dvě pojistky:
 *
 *  - schválit sám jde až po 14 dnech bez přístupu (`access_revoked_at`);
 *  - co vlastník schválil sám, jde trvale smazat až po celé lhůtě koše
 *    (`trash_approved_alone_at`) — ručně ani „vysypáním" dřív ne; noční
 *    úklid po lhůtě běží dál.
 */
class LhutaBezPartneraTest extends TestCase
{
    use DvojiceSHostem, RefreshDatabase;

    private User $vlastnik;

    private User $partner;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-27 10:00:00'));
        config(['gallery.trash_retention_days' => 30]);
        Storage::fake('public');

        [$this->vlastnik, $this->partner, , $this->prostor] = $this->dvojiceSHostem();
    }

    public function test_odebrani_a_obnoveni_pristupu_zapise_a_smaze_datum(): void
    {
        $this->actingAs($this->vlastnik);
        $zasahy = app(AdministraceZasahy::class);

        $this->assertTrue($zasahy->nastavPristup($this->prostor, $this->vlastnik, $this->partner->id, false));
        $this->assertNotNull($this->partner->fresh()->access_revoked_at);
        $this->assertTrue(Carbon::parse($this->partner->fresh()->access_revoked_at)->equalTo(now()));

        // Druhé „odebrat" hodiny nepřetočí — lhůta běží od prvního.
        $this->travel(3)->days();
        $zasahy->nastavPristup($this->prostor, $this->vlastnik, $this->partner->id, false);
        $this->assertTrue(Carbon::parse($this->partner->fresh()->access_revoked_at)->equalTo(now()->subDays(3)));

        $zasahy->nastavPristup($this->prostor, $this->vlastnik, $this->partner->id, true);
        $this->assertNull($this->partner->fresh()->access_revoked_at);
        $this->assertTrue((bool) $this->partner->fresh()->is_active);
    }

    public function test_schvalit_sam_jde_az_po_14_dnech_bez_pristupu(): void
    {
        $fotka = $this->navrzena('more.jpg');
        $this->actingAs($this->vlastnik);
        app(AdministraceZasahy::class)->nastavPristup($this->prostor, $this->vlastnik, $this->partner->id, false);
        Sanctum::actingAs($this->vlastnik);

        $hned = $this->schvalSam($fotka)->assertForbidden();
        $this->assertStringContainsString('14 dnech', (string) $hned->json('message'));
        $mazani = $this->getJson('/api/data/system')->assertOk()->json('data.MAZANI');
        $this->assertFalse($mazani['partnerBezPristupu']);
        $this->assertTrue($mazani['partnerBezPristupuLhuta'], 'Obrazovka má říct, proč tlačítko chybí.');

        $this->travel(14)->days();
        $this->travel(-1)->minutes();
        $this->schvalSam($fotka)->assertForbidden();
        $this->assertNull($fotka->fresh()->trashed_at);

        $this->travel(2)->minutes();
        $this->schvalSam($fotka)->assertOk()->assertJsonPath('ids', [$fotka->uuid]);
        $mazani = $this->getJson('/api/data/system')->assertOk()->json('data.MAZANI');
        $this->assertTrue($mazani['partnerBezPristupu']);
        $this->assertFalse($mazani['partnerBezPristupuLhuta']);
    }

    public function test_obnoveny_a_znovu_odebrany_pristup_zacina_lhutu_znovu(): void
    {
        $fotka = $this->navrzena('more.jpg');
        $this->actingAs($this->vlastnik);
        $zasahy = app(AdministraceZasahy::class);
        $zasahy->nastavPristup($this->prostor, $this->vlastnik, $this->partner->id, false);
        $this->travel(20)->days();
        $zasahy->nastavPristup($this->prostor, $this->vlastnik, $this->partner->id, true);
        $zasahy->nastavPristup($this->prostor, $this->vlastnik, $this->partner->id, false);

        Sanctum::actingAs($this->vlastnik);
        $this->schvalSam($fotka)->assertForbidden();
    }

    public function test_odebrany_pristup_bez_data_se_nepocita(): void
    {
        // Stará data: přístup odebraný, kdy — nevíme. Radši nic, než pustit zkratku.
        $fotka = $this->navrzena('more.jpg');
        $this->partner->forceFill(['is_active' => false, 'access_revoked_at' => null])->save();
        Sanctum::actingAs($this->vlastnik);

        $this->schvalSam($fotka)->assertForbidden();
        $this->assertFalse(app(MazaniFotek::class)->partnerBezPristupu($this->prostor));
        $this->assertTrue($this->getJson('/api/data/system')->assertOk()->json('data.MAZANI.partnerBezPristupuLhuta'));
    }

    public function test_schvalene_bez_partnera_trvale_smazat_az_po_lhute_kose(): void
    {
        $bezna = $this->fotka($this->prostor, $this->partner, 'bezna.jpg');
        Sanctum::actingAs($this->partner);
        $this->deleteJson('/api/media/'.$bezna->uuid)->assertOk();
        $sama = $this->schvalenaSama('sama.jpg');
        // Partner se vrátil; jeho návrh vlastník schválí běžně.
        $this->partner->forceFill(['is_active' => true, 'access_revoked_at' => null])->save();
        Sanctum::actingAs($this->vlastnik);
        $this->postJson('/api/kos/schvalit', ['ids' => [$bezna->uuid]])->assertOk()->assertJsonPath('ids', [$bezna->uuid]);
        $this->assertNotNull(MediaItem::withoutGlobalScopes()->find($sama->id)->trash_approved_alone_at);
        $this->assertNull(MediaItem::withoutGlobalScopes()->find($bezna->id)->trash_approved_alone_at);

        // Ručně jedna položka: odmítnuto s vysvětlením.
        $odmitnuti = $this->postJson('/api/kos/odstranit', ['id' => $sama->uuid])->assertStatus(422);
        $this->assertStringContainsString('bez partnera', (string) $odmitnuti->json('zprava'));
        $this->assertNotNull($this->radek($sama->id));

        // Vyprázdnit koš: běžná zmizí, schválená bez partnera zůstává.
        $this->postJson('/api/kos/vyprazdnit')->assertOk();
        $this->assertNull($this->radek($bezna->id));
        $this->assertNotNull($this->radek($sama->id));

        // Po lhůtě koše už ano.
        $this->travel(30)->days();
        $this->postJson('/api/kos/odstranit', ['id' => $sama->uuid])->assertOk();
        $this->assertNull($this->radek($sama->id));
    }

    public function test_stare_api_trvale_nesmaze_pred_lhutou(): void
    {
        $sama = $this->schvalenaSama('sama.jpg');
        Sanctum::actingAs($this->vlastnik);

        $this->deleteJson('/api/v1/trash/'.$sama->uuid.'/purge')->assertStatus(422);
        $this->deleteJson('/api/v1/trash/empty')->assertOk()->assertJson(['count' => 0]);
        $this->assertNotNull($this->radek($sama->id));
    }

    public function test_vysypani_z_panelu_a_kratsi_uklid_ji_minou_nocni_uklid_po_lhute_ne(): void
    {
        $sama = $this->schvalenaSama('sama.jpg');
        Sanctum::actingAs($this->vlastnik);

        $this->postJson('/api/admin/risks/r2/fix')->assertOk();
        $this->artisan('gallery:purge-trash', ['--no-interaction' => true])->assertSuccessful();
        $this->artisan('gallery:purge-trash', ['--dny' => 1, '--no-interaction' => true])->assertSuccessful();
        $this->assertNotNull($this->radek($sama->id));

        $this->travel(29)->days();
        $this->artisan('gallery:purge-trash', ['--no-interaction' => true])->assertSuccessful();
        $this->assertNotNull($this->radek($sama->id));

        $this->travel(1)->days();
        $this->travel(1)->minutes();
        $this->artisan('gallery:purge-trash', ['--no-interaction' => true])->assertSuccessful();
        $this->assertNull($this->radek($sama->id));
    }

    public function test_bezne_schvaleni_jde_trvale_smazat_hned_jako_dosud(): void
    {
        $fotka = $this->navrzena('more.jpg');
        Sanctum::actingAs($this->partner);
        $this->postJson('/api/kos/schvalit', ['ids' => [$fotka->uuid]])->assertOk();

        Sanctum::actingAs($this->vlastnik);
        $this->postJson('/api/kos/odstranit', ['id' => $fotka->uuid])->assertOk();
        $this->assertNull($this->radek($fotka->id));
    }

    // ——— pomocné ———

    private function navrzena(string $nazev): MediaItem
    {
        $fotka = $this->fotka($this->prostor, $this->vlastnik, $nazev);
        Sanctum::actingAs($this->vlastnik);
        $this->deleteJson('/api/media/'.$fotka->uuid)->assertOk()->assertJsonPath('status', 'proposed');

        return $fotka;
    }

    /** Návrh vlastníka, partner patnáct dní bez přístupu, vlastník schválil sám. */
    private function schvalenaSama(string $nazev): MediaItem
    {
        $fotka = $this->navrzena($nazev);
        $this->partner->forceFill(['is_active' => false, 'access_revoked_at' => now()->subDays(15)])->save();
        Sanctum::actingAs($this->vlastnik);
        $this->schvalSam($fotka)->assertOk()->assertJsonPath('ids', [$fotka->uuid]);

        return $fotka;
    }

    private function schvalSam(MediaItem $fotka): TestResponse
    {
        return $this->postJson('/api/kos/schvalit-sam', ['ids' => [$fotka->uuid], 'potvrzuji_bez_partnera' => true]);
    }

    private function radek(int $id): ?MediaItem
    {
        return MediaItem::withoutGlobalScopes()->whereKey($id)->first();
    }
}
