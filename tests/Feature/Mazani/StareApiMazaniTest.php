<?php

namespace Tests\Feature\Mazani;

use App\Models\AuditLog;
use App\Models\MediaItem;
use App\Support\SpaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Staré rozhraní a `/api/v1` mažou stejně jako prototyp — po společném schválení.
 *
 * „Do koše" ve starém webu, hromadná akce i úklid duplicit přesouvaly fotku
 * rovnou do koše, bez souhlasu druhého a bez záznamu v protokolu. Trvalé
 * smazání navíc šlo na **jakoukoli** fotku (i mimo koš) a o správci
 * rozhodovalo `users.role`, které má `owner` každý účet.
 */
class StareApiMazaniTest extends TestCase
{
    use DvojiceSHostem, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Statická mezipaměť prostorů přežívá mezi testy téhož procesu.
        SpaceContext::forget();
        Storage::fake('public');
        $this->travelTo(Carbon::parse('2026-09-25 10:00:00'));
        config(['gallery.trash_retention_days' => 30]);
    }

    // ── Do koše ve starém webu ─────────────────────────────────────────

    public function test_web_do_kose_ve_dvojici_fotku_jen_navrhne(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg');

        $this->actingAs($vlastnik)->deleteJson('/media/'.$fotka->uuid)
            ->assertStatus(202)
            ->assertJson(['status' => 'pending_approval', 'message' => 'Čeká na schválení druhým z vás']);

        $fotka->refresh();
        $this->assertNull($fotka->trashed_at);
        $this->assertSame($vlastnik->id, (int) $fotka->trash_requested_by);
        $this->assertSame('stare-rozhrani', AuditLog::where('action', 'media.trash_proposed')->sole()->payload['odkud']);
    }

    public function test_web_do_kose_druheho_na_navrzenou_fotku_ji_presune(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg');
        $this->actingAs($vlastnik)->deleteJson('/media/'.$fotka->uuid)->assertStatus(202);

        $this->actingAs($partner)->deleteJson('/media/'.$fotka->uuid)
            ->assertOk()
            ->assertJson(['status' => 'trashed']);

        $this->assertNotNull($fotka->fresh()->trashed_at);
        $this->assertSame($partner->id, (int) $fotka->fresh()->trashed_by);
    }

    public function test_web_do_kose_v_prostoru_jednoho_presune_rovnou(): void
    {
        [$ucet, $vlastni] = $this->hostCiziGalerie();
        $fotka = $this->fotka($vlastni, $ucet, 'sam.jpg');

        $this->actingAs($ucet)->deleteJson('/media/'.$fotka->uuid)
            ->assertOk()
            ->assertJson(['status' => 'trashed']);

        $fotka->refresh();
        $this->assertTrue($fotka->trashed_at->equalTo(now()));
        $this->assertTrue($fotka->purge_after->equalTo(now()->addDays(30)));
        $this->assertSame($ucet->id, (int) $fotka->trashed_by);
        $this->assertSame(1, AuditLog::where('action', 'media.trash')->count());
    }

    public function test_host_cizi_galerie_jeji_fotku_pres_web_do_kose_neda(): void
    {
        [$ucet, , $cizi, $ciziProstor] = $this->hostCiziGalerie();
        $fotka = $this->fotka($ciziProstor, $cizi, 'cizi.jpg');

        $this->actingAs($ucet)->deleteJson('/media/'.$fotka->uuid)->assertForbidden();

        $fotka->refresh();
        $this->assertNull($fotka->trashed_at);
        $this->assertNull($fotka->trash_requested_by);
    }

    // ── Hromadná akce v1 ───────────────────────────────────────────────

    public function test_hromadne_do_kose_ve_dvojici_hlasi_cekajici(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $prvni = $this->fotka($prostor, $vlastnik, 'a.jpg');
        $druha = $this->fotka($prostor, $vlastnik, 'b.jpg');

        Sanctum::actingAs($vlastnik);
        $this->postJson('/api/v1/media/bulk', ['action' => 'trash', 'uuids' => [$prvni->uuid, $druha->uuid]])
            ->assertOk()
            ->assertJson(['processed' => 2, 'trashed' => 0, 'pending' => 2]);

        $this->assertNull($prvni->fresh()->trashed_at);
        $this->assertNull($druha->fresh()->trashed_at);
        $this->assertSame(2, MediaItem::cekaNaSmazani()->count());
        // Jedna dávka, ale záznam za každou fotku — ne za každý požadavek.
        $this->assertSame(2, AuditLog::where('action', 'media.trash_proposed')->count());
    }

    public function test_hromadne_do_kose_host_cizi_galerie_jeji_fotky_nesmaze(): void
    {
        [$ucet, $vlastni, $cizi, $ciziProstor] = $this->hostCiziGalerie();
        $moje = $this->fotka($vlastni, $ucet, 'moje.jpg');
        $ciziFotka = $this->fotka($ciziProstor, $cizi, 'cizi.jpg');

        Sanctum::actingAs($ucet);
        $this->postJson('/api/v1/media/bulk', ['action' => 'trash', 'uuids' => [$ciziFotka->uuid]])->assertForbidden();

        // Smíšený výběr: vlastní prostor (je v něm sám) ano, cizí ne.
        $this->postJson('/api/v1/media/bulk', ['action' => 'trash', 'uuids' => [$ciziFotka->uuid, $moje->uuid]])
            ->assertOk()
            ->assertJson(['processed' => 1, 'trashed' => 1, 'pending' => 0]);

        $this->assertNotNull($moje->fresh()->trashed_at);
        $ciziFotka->refresh();
        $this->assertNull($ciziFotka->trashed_at);
        $this->assertNull($ciziFotka->trash_requested_by);
    }

    public function test_hromadne_do_kose_se_zamcenym_trezorem_skrytou_preskoci(): void
    {
        [$ucet, $vlastni] = $this->hostCiziGalerie();
        $bezna = $this->fotka($vlastni, $ucet, 'bezna.jpg');
        $skryta = $this->fotka($vlastni, $ucet, 'tajna.jpg', ['is_hidden' => true]);

        // Jen token: sezení (a tedy odemčený trezor) nemá.
        Sanctum::actingAs($ucet);
        $this->postJson('/api/v1/media/bulk', ['action' => 'trash', 'uuids' => [$bezna->uuid, $skryta->uuid]])
            ->assertOk()
            ->assertJson(['processed' => 1, 'trashed' => 1, 'pending' => 0]);

        $this->assertNotNull($bezna->fresh()->trashed_at);
        $this->assertNull($skryta->fresh()->trashed_at);
    }

    public function test_hromadne_vraceni_z_kose_smi_jen_dvojice(): void
    {
        [$ucet, , $cizi, $ciziProstor] = $this->hostCiziGalerie();
        $ciziFotka = $this->fotka($ciziProstor, $cizi, 'cizi.jpg', ['trashed_at' => now(), 'purge_after' => now()->addDays(30)]);

        Sanctum::actingAs($ucet);
        $this->postJson('/api/v1/media/bulk', ['action' => 'restore', 'uuids' => [$ciziFotka->uuid]])
            ->assertOk()
            ->assertJson(['processed' => 0]);
        $this->assertNotNull($ciziFotka->fresh()->trashed_at);

        SpaceContext::forget();
        Sanctum::actingAs($cizi);
        $this->postJson('/api/v1/media/bulk', ['action' => 'restore', 'uuids' => [$ciziFotka->uuid]])
            ->assertOk()
            ->assertJson(['processed' => 1]);
        $this->assertNull($ciziFotka->fresh()->trashed_at);
    }

    public function test_web_vraceni_z_kose_smi_dvojice_a_host_ne(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg', ['trashed_at' => now(), 'purge_after' => now()->addDays(30)]);

        $this->actingAs($partner)->postJson('/media/'.$fotka->uuid.'/restore')
            ->assertOk()
            ->assertJson(['status' => 'restored']);
        $this->assertNull($fotka->fresh()->trashed_at);

        [$ucet, , $cizi, $ciziProstor] = $this->hostCiziGalerie();
        $ciziFotka = $this->fotka($ciziProstor, $cizi, 'cizi.jpg', ['trashed_at' => now()]);

        $this->actingAs($ucet)->postJson('/media/'.$ciziFotka->uuid.'/restore')->assertForbidden();
        $this->assertNotNull($ciziFotka->fresh()->trashed_at);
    }

    // ── Úklid duplicit ─────────────────────────────────────────────────

    public function test_uklid_duplicit_ve_dvojici_jen_navrhne(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $prvni = $this->fotka($prostor, $vlastnik, 'kopie1.jpg');
        $druha = $this->fotka($prostor, $vlastnik, 'kopie2.jpg');

        Sanctum::actingAs($vlastnik);
        $this->deleteJson('/api/v1/recovery/duplicates/trash', ['media_ids' => [$prvni->id, $druha->id]])
            ->assertOk()
            ->assertJson(['trashed' => 0, 'pending' => 2]);

        $this->assertNull($prvni->fresh()->trashed_at);
        $this->assertSame(2, MediaItem::cekaNaSmazani()->count());
        $this->assertSame('obnova-duplicity', AuditLog::where('action', 'media.trash_proposed')->first()->payload['odkud']);
    }

    public function test_uklid_duplicit_sam_presune_podle_nastavene_lhuty_a_zapise_protokol(): void
    {
        config(['gallery.trash_retention_days' => 7]);
        [$ucet, $vlastni] = $this->hostCiziGalerie();
        $kopie = $this->fotka($vlastni, $ucet, 'kopie.jpg');

        Sanctum::actingAs($ucet);
        $this->deleteJson('/api/v1/recovery/duplicates/trash', ['media_ids' => [$kopie->id]])
            ->assertOk()
            ->assertJson(['trashed' => 1, 'pending' => 0]);

        $this->assertTrue($kopie->fresh()->purge_after->equalTo(now()->addDays(7)));
        $this->assertSame('obnova-duplicity', AuditLog::where('action', 'media.trash')->sole()->payload['odkud']);
    }

    // ── Trvalé smazání ve starém webu ─────────────────────────────────

    public function test_trvale_smazani_pres_web_nesmaze_fotku_mimo_kos(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg');

        $this->actingAs($vlastnik)->deleteJson('/media/'.$fotka->uuid.'/purge')->assertNotFound();

        $this->assertNotNull($this->radek($fotka->id));
        $this->assertSame(0, AuditLog::where('action', 'media.purge')->count());
    }

    public function test_trvale_smazani_pres_web_z_kose_smi_jen_spravce(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        // Jako skutečné účty: `users.role = owner` má každý, oprávnění to není.
        $partner->forceFill(['role' => 'owner'])->save();
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg', ['trashed_at' => now()]);

        $this->actingAs($partner)->deleteJson('/media/'.$fotka->uuid.'/purge')->assertForbidden();
        $this->assertNotNull($this->radek($fotka->id));

        $this->actingAs($vlastnik)->deleteJson('/media/'.$fotka->uuid.'/purge')
            ->assertOk()
            ->assertJson(['status' => 'purged']);

        $this->assertNull($this->radek($fotka->id), 'Řádek má zmizet úplně, ne měkce.');
        $this->assertSame(1, AuditLog::where('action', 'media.purge')->count());
    }

    public function test_trvale_smazani_pres_web_skryte_se_zamcenym_trezorem_neprojde(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'tajna.jpg', ['is_hidden' => true, 'trashed_at' => now()]);

        // Zamčený trezor zastaví už `ProtectVaultMedia` (423).
        $this->actingAs($vlastnik)->deleteJson('/media/'.$fotka->uuid.'/purge')->assertStatus(423);

        $this->assertNotNull($this->radek($fotka->id));
    }

    // ── Koš v1 ─────────────────────────────────────────────────────────

    public function test_trvale_smazani_v1_editor_nesmi(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $partner->forceFill(['role' => 'owner'])->save();
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg', ['trashed_at' => now()]);

        Sanctum::actingAs($partner);
        $this->deleteJson('/api/v1/trash/'.$fotka->uuid.'/purge')->assertForbidden();

        $this->assertNotNull($this->radek($fotka->id));
    }

    public function test_trvale_smazani_v1_jen_z_kose(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'more.jpg');

        Sanctum::actingAs($vlastnik);
        $this->deleteJson('/api/v1/trash/'.$fotka->uuid.'/purge')->assertNotFound();

        $this->assertNotNull($this->radek($fotka->id));
    }

    public function test_trvale_smazani_v1_skryte_jen_s_odemcenym_trezorem(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $fotka = $this->fotka($prostor, $vlastnik, 'tajna.jpg', ['is_hidden' => true, 'trashed_at' => now()]);

        Sanctum::actingAs($vlastnik);
        $this->deleteJson('/api/v1/trash/'.$fotka->uuid.'/purge')->assertNotFound();
        $this->assertNotNull($this->radek($fotka->id));

        $this->sOdemcenymTrezorem($vlastnik)->deleteJson('/api/v1/trash/'.$fotka->uuid.'/purge')
            ->assertOk()
            ->assertJson(['status' => 'purged']);
        $this->assertNull($this->radek($fotka->id));
    }

    /** Ani starý web, ani koš v1 nepíšou jméno souboru z trezoru do protokolu. */
    public function test_trvale_smazani_skryte_nezapise_jmeno_do_protokolu(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $pres1 = $this->fotka($prostor, $vlastnik, 'tajna-v1.jpg', ['is_hidden' => true, 'trashed_at' => now()]);
        $presWeb = $this->fotka($prostor, $vlastnik, 'tajna-web.jpg', ['is_hidden' => true, 'trashed_at' => now()]);

        $this->sOdemcenymTrezorem($vlastnik)->deleteJson('/api/v1/trash/'.$pres1->uuid.'/purge')->assertOk();
        $this->sOdemcenymTrezorem($vlastnik)->deleteJson('/media/'.$presWeb->uuid.'/purge')->assertOk();

        $zaznamy = AuditLog::where('action', 'media.purge')->get();
        $this->assertEqualsCanonicalizing([$pres1->id, $presWeb->id], $zaznamy->pluck('subject_id')->all());

        foreach ($zaznamy as $zaznam) {
            $this->assertArrayNotHasKey('filename', (array) $zaznam->payload);
        }
    }

    public function test_vysypani_kose_se_zamcenym_trezorem_necha_skryte_i_nesmazane(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $vKosi = $this->fotka($prostor, $vlastnik, 'kos.jpg', ['trashed_at' => now()]);
        $skryta = $this->fotka($prostor, $vlastnik, 'tajna.jpg', ['is_hidden' => true, 'trashed_at' => now()]);
        $zije = $this->fotka($prostor, $vlastnik, 'zije.jpg');

        Sanctum::actingAs($vlastnik);
        $this->deleteJson('/api/v1/trash/empty')->assertOk()->assertJson(['count' => 1]);

        $this->assertNull($this->radek($vKosi->id));
        $this->assertNotNull($this->radek($skryta->id));
        $this->assertNotNull($this->radek($zije->id));
    }

    public function test_vysypani_kose_editor_nesmi_a_cizi_prostor_nezasahne(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $partner->forceFill(['role' => 'owner'])->save();
        $vKosi = $this->fotka($prostor, $vlastnik, 'kos.jpg', ['trashed_at' => now()]);
        [, , $cizi, $ciziProstor] = $this->hostCiziGalerie();
        $ciziVKosi = $this->fotka($ciziProstor, $cizi, 'cizi.jpg', ['trashed_at' => now()]);

        Sanctum::actingAs($partner);
        $this->deleteJson('/api/v1/trash/empty')->assertForbidden();
        $this->assertNotNull($this->radek($vKosi->id));

        SpaceContext::forget();
        Sanctum::actingAs($vlastnik);
        $this->deleteJson('/api/v1/trash/empty')->assertOk()->assertJson(['count' => 1]);
        $this->assertNull($this->radek($vKosi->id));
        $this->assertNotNull($this->radek($ciziVKosi->id));
    }

    public function test_obrazovka_kose_nabidne_trvale_smazani_jen_spravci(): void
    {
        [$vlastnik, $partner] = $this->dvojiceSHostem();
        $partner->forceFill(['role' => 'owner'])->save();

        $this->actingAs($partner)->get('/trash')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $stranka) => $stranka->where('can_purge', false));

        $this->actingAs($vlastnik)->get('/trash')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $stranka) => $stranka->where('can_purge', true));
    }

    /**
     * Řádek i s měkce smazanými a bez rozsahu prostoru — po `actingAs` by
     * globální rozsah skryl fotku cizí galerie i v samotném testu.
     */
    private function radek(int $id): ?MediaItem
    {
        return MediaItem::withoutGlobalScopes()->whereKey($id)->first();
    }
}
