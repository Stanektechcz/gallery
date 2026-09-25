<?php

namespace Tests\Feature\Mazani;

use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Media\MazaniFotek;
use App\Services\Provoz\UklidVeStavu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Úklid knihovny ze stavu maže jen po společném schválení.
 *
 * „Pustit" v karanténě a „Sloučit" u duplicit posílaly fotky rovnou do koše
 * — bez záznamu v protokolu, bez lhůty koše a i skryté fotky při zamčeném
 * trezoru. A „Necháváme obě" (jen `dupDone`, bez vítěze) vyhodilo všechny
 * kopie kromě největší. Teď jde všechno přes `MazaniFotek::doKose()`: ve
 * dvojici je to návrh, jediný z dvojice maže sám, host nic.
 */
class UklidSpolecneTest extends TestCase
{
    use DvojiceSHostem, RefreshDatabase;

    private Carbon $ted;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ted = Carbon::parse('2026-09-25 10:00:00');
        $this->travelTo($this->ted);
        // Schválně jiná než výchozích 30 dní — ať je vidět, že se lhůta bere z nastavení.
        config(['gallery.trash_retention_days' => 14]);
    }

    public function test_pustit_z_karanteny_ve_dvojici_jen_navrhne(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $f = $this->fotka($prostor, $vlastnik, 'stara.jpg', ['is_archived' => true, 'purge_after' => now()->addMonths(4)]);

        Sanctum::actingAs($vlastnik);
        $this->stav(['quarGone' => [$f->uuid => 'drop']])->assertOk();

        $f->refresh();
        $this->assertNull($f->trashed_at, 'Ve dvojici „Pustit" fotku jen navrhne.');
        $this->assertSame($vlastnik->id, (int) $f->trash_requested_by);
        // Rozhodnutí karanténu ukončilo — návrh čeká v knihovně, ne ve frontě otázek.
        $this->assertFalse((bool) $f->is_archived);
        $this->assertNull($f->purge_after, 'Lhůta karantény s rozhodnutím končí.');

        $zaznam = AuditLog::where('action', 'media.trash_proposed')->sole();
        $this->assertSame('uklid-karantena', $zaznam->payload['odkud']);
    }

    public function test_znovu_poslany_patch_po_odmitnuti_nenavrhne_znovu(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $f = $this->fotka($prostor, $vlastnik, 'stara.jpg', ['is_archived' => true]);

        Sanctum::actingAs($vlastnik);
        $this->stav(['quarGone' => [$f->uuid => 'drop']])->assertOk();

        app(MazaniFotek::class)->ponechat($prostor, $partner, [$f->uuid], false);
        $this->assertNull($f->refresh()->trash_requested_by);

        // Klíč zůstává ve stavu, takže tentýž seznam přijde i s dalším zápisem.
        $this->travel(5)->minutes();
        $this->stav(['quarGone' => [$f->uuid => 'drop'], 'quarAsked' => 3])->assertOk();

        $f->refresh();
        $this->assertNull($f->trash_requested_by, 'Odmítnutý návrh se starým stavem neobnoví.');
        $this->assertNull($f->trashed_at);
        $this->assertSame(1, AuditLog::where('action', 'media.trash_proposed')->count());
    }

    public function test_jediny_z_dvojice_pusti_rovnou_do_kose_se_lhutou_a_zaznamem(): void
    {
        [$sam, $prostor] = $this->samotny();
        $f = $this->fotka($prostor, $sam, 'stara.jpg', ['is_archived' => true, 'purge_after' => now()->addMonths(4)]);

        Sanctum::actingAs($sam);
        $this->stav(['quarGone' => [$f->uuid => 'drop']])->assertOk();

        $f->refresh();
        $this->assertTrue($f->trashed_at->equalTo($this->ted));
        $this->assertTrue($f->purge_after->equalTo($this->ted->copy()->addDays(14)), 'Lhůta koše z nastavení, ne lhůta karantény ani nic.');
        $this->assertSame($sam->id, (int) $f->trashed_by);
        $this->assertFalse((bool) $f->is_archived);

        $zaznam = AuditLog::where('action', 'media.trash')->sole();
        $this->assertSame('uklid-karantena', $zaznam->payload['odkud']);
    }

    public function test_skryta_fotka_v_karantene_se_zamcenym_trezorem_zustane(): void
    {
        [$sam, $prostor] = $this->samotny();
        $f = $this->fotka($prostor, $sam, 'tajna.jpg', ['is_archived' => true, 'is_hidden' => true]);

        Sanctum::actingAs($sam);
        $this->stav(['quarGone' => [$f->uuid => 'drop']])->assertOk();

        $f->refresh();
        $this->assertNull($f->trashed_at, 'Zamčený trezor skrytou fotku do koše pustit nesmí.');
        // Rozhodnutí se neprovedlo, takže karanténa trvá — po odemčení se dá dokončit.
        $this->assertTrue((bool) $f->is_archived);
    }

    public function test_slouceni_ve_dvojici_ostatni_kopie_navrhne(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $velka = $this->fotka($prostor, $vlastnik, 'velka.jpg', ['size_bytes' => 8_388_608]);
        $mala = $this->fotka($prostor, $vlastnik, 'mala.jpg', ['size_bytes' => 3_145_728]);
        [$skupina, $uuid] = $this->nalez($prostor, [$velka, $mala]);

        Sanctum::actingAs($vlastnik);
        $odpoved = $this->stav(['dupDone' => [$uuid], 'dupKeep' => [$uuid => $velka->uuid]])->assertOk();

        $this->assertNull($velka->refresh()->trashed_at);
        $mala->refresh();
        $this->assertNull($mala->trashed_at, 'Ve dvojici jde druhá kopie jen k návrhu.');
        $this->assertSame($vlastnik->id, (int) $mala->trash_requested_by);

        $nalez = DB::table('duplicate_groups')->where('id', $skupina)->first();
        $this->assertSame('merged', $nalez->resolution);
        $this->assertNotNull($nalez->resolved_at);
        // Návrh nic neuvolnil — to udělá až souhlas partnera.
        $this->assertEqualsWithDelta(0.0, $odpoved->json('data.clnFreed'), 0.001);

        $zaznam = AuditLog::where('action', 'media.trash_proposed')->sole();
        $this->assertSame('uklid-duplicity', $zaznam->payload['odkud']);
    }

    public function test_slouceni_souhlasem_s_navrhem_partnera_pocita_uvolnene_misto(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $velka = $this->fotka($prostor, $vlastnik, 'velka.jpg', ['size_bytes' => 8_388_608]);
        $mala = $this->fotka($prostor, $vlastnik, 'mala.jpg', ['size_bytes' => 3_145_728]);
        [, $uuid] = $this->nalez($prostor, [$velka, $mala]);

        // Partner menší kopii už navrhl ke smazání — sloučení je souhlas.
        app(MazaniFotek::class)->doKose($prostor, $partner, [$mala->uuid], 'knihovna', false);

        Sanctum::actingAs($vlastnik);
        $odpoved = $this->stav(['dupDone' => [$uuid], 'dupKeep' => [$uuid => $velka->uuid]])->assertOk();

        $this->assertNotNull($mala->refresh()->trashed_at);
        $this->assertEqualsWithDelta(3.0, $odpoved->json('data.clnFreed'), 0.05);
        $this->assertSame(1, AuditLog::where('action', 'media.trash_approved')->count());
    }

    public function test_nechat_obe_hvezdickou_nic_nevyhodi(): void
    {
        [$sam, $prostor] = $this->samotny();
        $velka = $this->fotka($prostor, $sam, 'velka.jpg', ['size_bytes' => 8_388_608]);
        $mala = $this->fotka($prostor, $sam, 'mala.jpg', ['size_bytes' => 3_145_728]);
        [$skupina, $uuid] = $this->nalez($prostor, [$velka, $mala]);

        Sanctum::actingAs($sam);
        $odpoved = $this->stav(['dupDone' => [$uuid], 'dupKeep' => [$uuid => '*']])->assertOk();

        foreach ([$velka, $mala] as $f) {
            $f->refresh();
            $this->assertNull($f->trashed_at, 'Necháváme obě znamená obě.');
            $this->assertNull($f->trash_requested_by);
        }

        $nalez = DB::table('duplicate_groups')->where('id', $skupina)->first();
        $this->assertSame('kept_all', $nalez->resolution);
        $this->assertNotNull($nalez->resolved_at, 'Nález se už nepřipomene.');
        $this->assertSame(2, DB::table('duplicate_group_items')->where('duplicate_group_id', $skupina)->where('is_kept', true)->count());
        $this->assertEqualsWithDelta(0.0, $odpoved->json('data.clnFreed'), 0.001);
    }

    public function test_hotovy_nalez_bez_viteze_nic_nevyhodi(): void
    {
        [$sam, $prostor] = $this->samotny();
        $velka = $this->fotka($prostor, $sam, 'velka.jpg', ['size_bytes' => 8_388_608]);
        $mala = $this->fotka($prostor, $sam, 'mala.jpg', ['size_bytes' => 3_145_728]);
        [$skupina, $uuid] = $this->nalez($prostor, [$velka, $mala]);

        Sanctum::actingAs($sam);
        // Desktopové „Necháváme obě" posílalo jen `dupDone`.
        $this->stav(['dupDone' => [$uuid]])->assertOk();
        $this->stav(['dupDone' => [$uuid], 'dupKeep' => (object) []])->assertOk();

        $this->assertNull($velka->refresh()->trashed_at);
        $this->assertNull($mala->refresh()->trashed_at, 'Bez výslovného vítěze se nevyhazuje nic.');
        $this->assertNull(DB::table('duplicate_groups')->where('id', $skupina)->value('resolved_at'));
        $this->assertSame(0, AuditLog::whereIn('action', ['media.trash', 'media.trash_proposed'])->count());
    }

    public function test_skryta_kopie_se_zamcenym_trezorem_zustane(): void
    {
        [$sam, $prostor] = $this->samotny();
        $velka = $this->fotka($prostor, $sam, 'velka.jpg', ['size_bytes' => 8_388_608]);
        $stredni = $this->fotka($prostor, $sam, 'stredni.jpg', ['size_bytes' => 5_242_880]);
        $skryta = $this->fotka($prostor, $sam, 'skryta.jpg', ['size_bytes' => 3_145_728, 'is_hidden' => true]);
        [, $uuid] = $this->nalez($prostor, [$velka, $stredni, $skryta]);

        Sanctum::actingAs($sam);
        $this->stav(['dupDone' => [$uuid], 'dupKeep' => [$uuid => $velka->uuid]])->assertOk();

        $this->assertNull($velka->refresh()->trashed_at);
        $this->assertNotNull($stredni->refresh()->trashed_at);
        $this->assertNull($skryta->refresh()->trashed_at, 'Skrytou kopii zamčený trezor chrání.');
        $this->assertNull($skryta->trash_requested_by);
    }

    /**
     * Host se ke stavu přes bránu `dvojice` nedostane (403); převodník ale
     * nesmí spoléhat jen na ni — volá se i odjinud.
     */
    public function test_host_nic_nevyhodi_ani_nenavrhne(): void
    {
        [$vlastnik, , $host, $prostor] = $this->dvojiceSHostem();
        $stara = $this->fotka($prostor, $vlastnik, 'stara.jpg', ['is_archived' => true]);
        $velka = $this->fotka($prostor, $vlastnik, 'velka.jpg', ['size_bytes' => 8_388_608]);
        $mala = $this->fotka($prostor, $vlastnik, 'mala.jpg', ['size_bytes' => 3_145_728]);
        [$skupina, $uuid] = $this->nalez($prostor, [$velka, $mala]);

        $patch = app(UklidVeStavu::class)->zpracuj([
            'quarGone' => [$stara->uuid => 'drop'],
            'dupDone' => [$uuid],
            'dupKeep' => [$uuid => $velka->uuid],
        ], $prostor, $host, false);

        $this->assertEqualsWithDelta(0.0, $patch['clnFreed'], 0.001);
        $this->assertNull($stara->refresh()->trashed_at);
        $this->assertNull($stara->trash_requested_by);
        $this->assertTrue((bool) $stara->is_archived, 'Host karanténu dvojice neukončí.');
        $this->assertNull($mala->refresh()->trashed_at);
        $this->assertNull($mala->trash_requested_by);
        $this->assertNull(DB::table('duplicate_groups')->where('id', $skupina)->value('resolved_at'));

        Sanctum::actingAs($host);
        $this->stav(['quarGone' => [$stara->uuid => 'drop']])->assertForbidden();
        $this->assertNull($stara->refresh()->trashed_at);
    }

    /** Účet jen pro čtení bránou projde — zbytek stavu se uloží, mazání ne (a žádná 500). */
    public function test_ucet_jen_pro_cteni_ulozi_stav_bez_mazani(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $partner->forceFill(['read_only_mode' => true])->save();
        $stara = $this->fotka($prostor, $vlastnik, 'stara.jpg', ['is_archived' => true]);
        $velka = $this->fotka($prostor, $vlastnik, 'velka.jpg', ['size_bytes' => 8_388_608]);
        $mala = $this->fotka($prostor, $vlastnik, 'mala.jpg', ['size_bytes' => 3_145_728]);
        [, $uuid] = $this->nalez($prostor, [$velka, $mala]);

        Sanctum::actingAs($partner);
        $this->stav([
            'quarGone' => [$stara->uuid => 'drop'],
            'dupDone' => [$uuid],
            'dupKeep' => [$uuid => $velka->uuid],
        ])->assertSuccessful();

        $this->assertNull($stara->refresh()->trashed_at);
        $this->assertNull($stara->trash_requested_by);
        $this->assertNull($mala->refresh()->trashed_at);
        $this->assertNull($mala->trash_requested_by);
        $this->assertSame(0, MediaItem::cekaNaSmazani()->count());
    }

    // ——— pomůcky ———

    private function stav(array $patch)
    {
        return $this->patchJson('/api/state', ['data' => $patch]);
    }

    /** @return array{0: User, 1: GallerySpace} prostor, ve kterém je z dvojice jen jeden */
    private function samotny(): array
    {
        $sam = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $prostor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Jen já', 'slug' => 'jen-ja-'.Str::random(6), 'owner_id' => $sam->id, 'is_default' => true]);
        $prostor->members()->attach($sam->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);

        return [$sam, $prostor];
    }

    /**
     * @param  list<MediaItem>  $fotky
     * @return array{0: int, 1: string} id a uuid nálezu
     */
    private function nalez(GallerySpace $prostor, array $fotky): array
    {
        $uuid = (string) Str::uuid();

        $id = DB::table('duplicate_groups')->insertGetId([
            'uuid' => $uuid,
            'gallery_space_id' => $prostor->id,
            'match_type' => 'exact',
            'resolution' => 'unresolved',
            'detected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($fotky as $f) {
            DB::table('duplicate_group_items')->insert([
                'duplicate_group_id' => $id,
                'media_item_id' => $f->id,
                'is_kept' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$id, $uuid];
    }
}
