<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rozhodnutí z úklidu knihovny se provedou, ne jen zobrazí.
 *
 * „Pustit" a „Sloučit" dřív měnily jen prohlížeč toho, kdo klikl. Originál
 * ležel dál na disku a druhý z dvojice viděl karanténu nedotčenou.
 */
class UklidVeStavuTest extends TestCase
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

    /** „Ponechat" vrátí fotku do knihovny a smaže lhůtu. */
    public function test_ponechat_vraci_fotku_z_karanteny(): void
    {
        $f = $this->fotka(['is_archived' => true, 'purge_after' => now()->addMonths(4)]);

        $this->stav(['quarGone' => [$f->uuid => 'keep']])->assertOk();

        $f->refresh();
        $this->assertFalse((bool) $f->is_archived);
        $this->assertNull($f->purge_after);
        $this->assertNull($f->trashed_at);
    }

    /**
     * „Pustit" pošle fotku do koše — nemaže ji, a karanténu tím ukončí.
     *
     * Kdyby si `is_archived` nechala, vrácení z koše by ji vrátilo do fronty
     * otázek, kterou už dvojice zodpověděla.
     */
    public function test_pustit_posila_fotku_do_kose(): void
    {
        $f = $this->fotka(['is_archived' => true, 'purge_after' => now()->addMonths(4)]);

        $this->stav(['quarGone' => [$f->uuid => 'drop']])->assertOk();

        $f->refresh();
        $this->assertNotNull($f->trashed_at);
        $this->assertNull($f->deleted_at);
        $this->assertFalse((bool) $f->is_archived);
        $this->assertNull($f->purge_after);
    }

    /** Rozhodnutí se pošle znovu s dalším patchem — a podruhé už nemá co změnit. */
    public function test_druhy_zapis_nic_nemeni(): void
    {
        $f = $this->fotka(['is_archived' => true]);

        $this->stav(['quarGone' => [$f->uuid => 'drop']])->assertOk();
        $prvni = $f->refresh()->trashed_at;

        $this->travel(2)->minutes();
        $this->stav(['quarGone' => [$f->uuid => 'drop'], 'quarAsked' => 2])->assertOk();

        $this->assertEquals($prvni, $f->refresh()->trashed_at);
    }

    /** Karanténa druhého páru se z cizího stavu ovlivnit nedá. */
    public function test_cizi_fotku_pustit_nejde(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $f = $this->fotka([
            'is_archived' => true,
            'gallery_space_id' => $ciziProstor->id,
            'owner_user_id' => $cizi->id,
            'uploaded_by' => $cizi->id,
        ]);

        $this->stav(['quarGone' => [$f->uuid => 'drop']])->assertOk();

        $this->assertNull($f->refresh()->trashed_at);
    }

    /** Sloučení nechá vybranou kopii, ostatní pošle do koše a nález uzavře. */
    public function test_slouceni_nechava_vybranou_kopii(): void
    {
        $velka = $this->fotka(['size_bytes' => 8_388_608], 1);
        $mala = $this->fotka(['size_bytes' => 3_145_728], 2);
        [$skupina, $uuid] = $this->nalez([$velka, $mala]);

        // Vítěz je pořadí v seznamu ze serveru — ten řadí od největšího souboru.
        $odpoved = $this->stav(['dupDone' => [$uuid], 'dupKeep' => [$uuid => 0]])->assertOk();

        $this->assertNull($velka->refresh()->trashed_at);
        $this->assertNotNull($mala->refresh()->trashed_at);
        $this->assertSame('merged', DB::table('duplicate_groups')->where('id', $skupina)->value('resolution'));
        // 3 MB, které opravdu zmizely — ne odhad z obrazovky.
        $this->assertEqualsWithDelta(3.0, $odpoved->json('data.clnFreed'), 0.05);
    }

    /** Bez výběru zůstane největší kopie. */
    public function test_bez_vyberu_zustava_nejvetsi_kopie(): void
    {
        $velka = $this->fotka(['size_bytes' => 8_388_608], 1);
        $mala = $this->fotka(['size_bytes' => 3_145_728], 2);
        [, $uuid] = $this->nalez([$velka, $mala]);

        $this->stav(['dupDone' => [$uuid]])->assertOk();

        $this->assertNull($velka->refresh()->trashed_at);
        $this->assertNotNull($mala->refresh()->trashed_at);
    }

    /** Druhá kopie mezitím zmizela — pak není co slučovat. */
    public function test_nalez_o_jedne_polozce_se_nesluci(): void
    {
        $zbyla = $this->fotka(['size_bytes' => 8_388_608], 1);
        $prazdna = $this->fotka(['size_bytes' => 3_145_728, 'trashed_at' => now()], 2);
        [$skupina, $uuid] = $this->nalez([$zbyla, $prazdna]);

        $this->stav(['dupDone' => [$uuid]])->assertOk();

        $this->assertNull($zbyla->refresh()->trashed_at);
        $this->assertNull(DB::table('duplicate_groups')->where('id', $skupina)->value('resolved_at'));
    }

    /** Uvolněné místo se po načtení stránky nepočítá znovu od nuly. */
    public function test_uvolnene_misto_prezije_nacteni(): void
    {
        $velka = $this->fotka(['size_bytes' => 8_388_608], 1);
        $mala = $this->fotka(['size_bytes' => 3_145_728], 2);
        [, $uuid] = $this->nalez([$velka, $mala]);

        $this->stav(['dupDone' => [$uuid]])->assertOk();

        // Nová relace pošle tentýž seznam; nález je už uzavřený.
        $odpoved = $this->stav(['dupDone' => [$uuid]])->assertOk();

        $this->assertEqualsWithDelta(3.0, $odpoved->json('data.clnFreed'), 0.05);
    }

    // ——— pomůcky ———

    private function stav(array $patch)
    {
        return $this->patchJson('/api/state', ['data' => $patch]);
    }

    /** @return array{0: int, 1: string} id a uuid nálezu */
    private function nalez(array $fotky): array
    {
        $uuid = (string) Str::uuid();

        $id = DB::table('duplicate_groups')->insertGetId([
            'uuid' => $uuid,
            'gallery_space_id' => $this->prostor->id,
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

    private function fotka(array $navic = [], int $poradi = 1): MediaItem
    {
        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG_'.$poradi.'.jpg',
            'safe_filename' => 'img-'.$poradi.'.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 2_097_152,
            'taken_at' => now(),
            'uploaded_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }
}
