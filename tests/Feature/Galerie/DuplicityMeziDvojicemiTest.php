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
 * Hledání duplicit nesmí míchat dvojice.
 *
 * Týdenní `gallery:scan-duplicates` běží bez `--space` a dřív bral všechny
 * fotky celé databáze naráz: dvě dvojice, které si nahrály tutéž fotku
 * (přeposlanou ze stejné skupiny), skončily v jednom nálezu. Nález viděla
 * první dvojice i s cizím názvem souboru a místem, a „Sloučit" poslalo
 * fotku druhé dvojice do koše — po třiceti dnech ji `gallery:purge-trash`
 * smazal nadobro.
 */
class DuplicityMeziDvojicemiTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACE = 'migrations/2026_09_26_100000_oddelit_duplicity_dvojic.php';

    private User $adri;

    private User $cizi;

    private GallerySpace $nase;

    private GallerySpace $jejich;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->nase = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->nase->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        $this->cizi = User::factory()->create(['name' => 'Cizí']);
        $this->jejich = GallerySpace::create(['name' => 'Jejich vzpomínky', 'owner_id' => $this->cizi->id]);
        $this->jejich->members()->syncWithoutDetaching([$this->cizi->id => ['role' => 'owner']]);
    }

    /** Stejný obsah ve dvou prostorech: každý prostor má vlastní nález, nikdy společný. */
    public function test_stejny_otisk_ve_dvou_prostorech_nevytvori_spolecny_nalez(): void
    {
        $otisk = str_repeat('a', 64);
        $nase1 = $this->fotka($this->nase, ['sha256' => $otisk]);
        $nase2 = $this->fotka($this->nase, ['sha256' => $otisk]);
        $jejich1 = $this->fotka($this->jejich, ['sha256' => $otisk]);
        $jejich2 = $this->fotka($this->jejich, ['sha256' => $otisk]);

        $this->artisan('gallery:scan-duplicates')->assertSuccessful();

        $this->assertSame([], $this->smiseneNalezy(), 'Žádný nález nesmí obsahovat fotky dvou prostorů.');
        $this->assertEqualsCanonicalizing([$nase1->id, $nase2->id], $this->clenove($this->nase));
        // Dřív hledání „už existujícího" nálezu bralo celou databázi: druhá
        // dvojice svůj nález nedostala, protože otisk už byl v nálezu té první.
        $this->assertEqualsCanonicalizing([$jejich1->id, $jejich2->id], $this->clenove($this->jejich));
    }

    /** Jedna kopie u nás a jedna u nich nejsou duplicita vůbec. */
    public function test_jedna_kopie_v_kazdem_prostoru_neni_nalez(): void
    {
        $otisk = str_repeat('b', 64);
        $this->fotka($this->nase, ['sha256' => $otisk]);
        $this->fotka($this->jejich, ['sha256' => $otisk]);

        $this->artisan('gallery:scan-duplicates')->assertSuccessful();

        $this->assertSame(0, DB::table('duplicate_groups')->count());
    }

    /** Fotka z trezoru se do nálezu nedostane — seznam duplicit ukazuje názvy i mimo trezor. */
    public function test_fotka_z_trezoru_se_do_nalezu_nedostane(): void
    {
        $otisk = str_repeat('c', 64);
        $this->fotka($this->nase, ['sha256' => $otisk]);
        $this->fotka($this->nase, ['sha256' => $otisk, 'is_hidden' => true]);

        $this->artisan('gallery:scan-duplicates')->assertSuccessful();

        $this->assertSame(0, DB::table('duplicate_groups')->count());
    }

    /** Podobné snímky se porovnávají jen uvnitř jednoho prostoru. */
    public function test_podobne_snimky_se_neporovnavaji_napric_prostory(): void
    {
        $vzhled = str_repeat('f0', 8);
        $this->fotka($this->nase, ['sha256' => str_repeat('1', 64), 'perceptual_hash' => $vzhled]);
        $this->fotka($this->jejich, ['sha256' => str_repeat('2', 64), 'perceptual_hash' => $vzhled]);
        $nase1 = $this->fotka($this->nase, ['sha256' => str_repeat('3', 64), 'perceptual_hash' => $vzhled]);

        $this->artisan('gallery:scan-duplicates')->assertSuccessful();

        $this->assertSame([], $this->smiseneNalezy());
        $this->assertSame(1, DB::table('duplicate_groups')->where('match_type', 'similar')->count());
        $this->assertContains($nase1->id, $this->clenove($this->nase));
    }

    /**
     * I kdyby smíšený nález v databázi zůstal, cizí fotku nikdo neuvidí ani nevyhodí.
     *
     * Obrana pro nálezy ze staré verze hledání: seznam a sloučení čtou nález
     * podle jeho id, a id nález nese jen jeden prostor.
     */
    public function test_slouceni_nevyhodi_fotku_jine_dvojice(): void
    {
        $otisk = str_repeat('d', 64);
        $nase1 = $this->fotka($this->nase, ['sha256' => $otisk, 'size_bytes' => 5_000_000]);
        $nase2 = $this->fotka($this->nase, ['sha256' => $otisk, 'size_bytes' => 4_000_000]);
        $jejich = $this->fotka($this->jejich, [
            'sha256' => $otisk, 'size_bytes' => 3_000_000,
            'original_filename' => 'jejich-tajny-vylet.jpg', 'location_name' => 'Jejich chata',
        ]);
        [, $uuid] = $this->nalez($this->nase, [$nase1, $nase2, $jejich]);

        Sanctum::actingAs($this->adri);

        $seznam = json_encode($this->getJson('/api/data/knihovna')->assertOk()->json('data.DUP_GROUPS'));
        $this->assertStringNotContainsString('jejich-tajny-vylet.jpg', $seznam);
        $this->assertStringNotContainsString('Jejich chata', $seznam);

        $this->patchJson('/api/state', ['data' => ['dupDone' => [$uuid]]])->assertOk();

        $this->assertNull($nase1->refresh()->trashed_at);
        $this->assertNotNull($nase2->refresh()->trashed_at, 'Vlastní kopie jde do koše jako dřív.');
        $this->assertNull($jejich->refresh()->trashed_at, 'Fotka jiné dvojice do koše nesmí.');
    }

    /**
     * Oprava dat: smíšený nález se rozpojí a cizí fotka vyhozená sloučením se vrátí.
     */
    public function test_migrace_opravi_smiseny_nalez(): void
    {
        $otisk = str_repeat('e', 64);
        $sloucenoV = now()->subDays(3)->startOfSecond();

        $nase1 = $this->fotka($this->nase, ['sha256' => $otisk]);
        $nase2 = $this->fotka($this->nase, ['sha256' => $otisk, 'trashed_at' => $sloucenoV]);
        $jejich = $this->fotka($this->jejich, ['sha256' => $otisk, 'trashed_at' => $sloucenoV]);
        // Fotku, kterou si druhá dvojice vyhodila sama dávno předtím, oprava nevrací.
        $jejichStara = $this->fotka($this->jejich, ['sha256' => $otisk, 'trashed_at' => $sloucenoV->copy()->subDays(10)]);
        [$skupina] = $this->nalez($this->nase, [$nase1, $nase2, $jejich, $jejichStara], $nase1, $sloucenoV);

        // Druhý nález: jen cizí fotka a jedna naše — po odpojení z něj nic nezbude.
        $nase3 = $this->fotka($this->nase, ['sha256' => str_repeat('9', 64)]);
        $jejich3 = $this->fotka($this->jejich, ['sha256' => str_repeat('9', 64)]);
        [$prazdna] = $this->nalez($this->nase, [$nase3, $jejich3]);

        $migrace = require database_path(self::MIGRACE);
        $migrace->up();
        // Opakované spuštění nic dalšího nezmění.
        $migrace->up();

        $this->assertNull($jejich->refresh()->trashed_at, 'Cizí fotka vyhozená sloučením se vrátí.');
        $this->assertNull($jejich->purge_after);
        $this->assertNotNull($jejichStara->refresh()->trashed_at, 'Co si dvojice vyhodila sama, zůstane v koši.');
        $this->assertNotNull($nase2->refresh()->trashed_at, 'Vlastní kopie zůstává, jak ji dvojice sloučila.');

        $this->assertSame([], $this->smiseneNalezy());
        $this->assertEqualsCanonicalizing(
            [$nase1->id, $nase2->id],
            DB::table('duplicate_group_items')->where('duplicate_group_id', $skupina)->pluck('media_item_id')->map(fn ($i) => (int) $i)->all(),
        );
        $this->assertFalse(DB::table('duplicate_groups')->where('id', $prazdna)->exists(), 'Nález o jedné položce není nález.');
        $this->assertSame(6, MediaItem::withTrashed()->count(), 'Oprava žádnou fotku nesmaže.');
    }

    /**
     * Vítězem sloučení byla cizí fotka: dvojice přišla o všechny vlastní kopie.
     *
     * Ty se vrátí taky a nález se otevře, ať si dvojice vybere znovu — už jen
     * ze svých fotek.
     */
    public function test_migrace_vrati_vlastni_kopie_kdyz_vitezem_byla_cizi(): void
    {
        $otisk = str_repeat('7', 64);
        $sloucenoV = now()->subDay()->startOfSecond();

        $nase1 = $this->fotka($this->nase, ['sha256' => $otisk, 'trashed_at' => $sloucenoV]);
        $nase2 = $this->fotka($this->nase, ['sha256' => $otisk, 'trashed_at' => $sloucenoV]);
        $jejich = $this->fotka($this->jejich, ['sha256' => $otisk]);
        [$skupina] = $this->nalez($this->nase, [$nase1, $nase2, $jejich], $jejich, $sloucenoV);

        (require database_path(self::MIGRACE))->up();

        $this->assertNull($nase1->refresh()->trashed_at);
        $this->assertNull($nase2->refresh()->trashed_at);
        $this->assertNull($jejich->refresh()->trashed_at);

        $nalez = DB::table('duplicate_groups')->where('id', $skupina)->first();
        $this->assertSame('unresolved', $nalez->resolution);
        $this->assertNull($nalez->resolved_at);
        $this->assertSame(0, DB::table('duplicate_group_items')->where('duplicate_group_id', $skupina)->where('is_kept', true)->count());
        $this->assertSame([], $this->smiseneNalezy());
    }

    // ——— pomůcky ———

    /** @return list<int> id nálezů, které obsahují fotku jiného prostoru */
    private function smiseneNalezy(): array
    {
        return DB::table('duplicate_group_items as p')
            ->join('duplicate_groups as g', 'g.id', '=', 'p.duplicate_group_id')
            ->join('media_items as m', 'm.id', '=', 'p.media_item_id')
            ->whereColumn('m.gallery_space_id', '!=', 'g.gallery_space_id')
            ->distinct()
            ->pluck('g.id')
            ->map(fn ($i) => (int) $i)
            ->all();
    }

    /** @return list<int> */
    private function clenove(GallerySpace $prostor): array
    {
        return DB::table('duplicate_group_items as p')
            ->join('duplicate_groups as g', 'g.id', '=', 'p.duplicate_group_id')
            ->where('g.gallery_space_id', $prostor->id)
            ->pluck('p.media_item_id')
            ->map(fn ($i) => (int) $i)
            ->all();
    }

    /** @return array{0: int, 1: string} */
    private function nalez(GallerySpace $prostor, array $fotky, ?MediaItem $vitez = null, $vyreseno = null): array
    {
        $uuid = (string) Str::uuid();

        $id = DB::table('duplicate_groups')->insertGetId([
            'uuid' => $uuid,
            'gallery_space_id' => $prostor->id,
            'match_type' => 'exact',
            'resolution' => $vyreseno ? 'merged' : 'unresolved',
            'detected_at' => now()->subWeek(),
            'resolved_at' => $vyreseno,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($fotky as $f) {
            DB::table('duplicate_group_items')->insert([
                'duplicate_group_id' => $id,
                'media_item_id' => $f->id,
                'is_kept' => $vitez !== null && $vitez->id === $f->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$id, $uuid];
    }

    private function fotka(GallerySpace $prostor, array $navic = []): MediaItem
    {
        static $poradi = 0;
        $poradi++;

        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'owner_user_id' => $prostor->owner_id,
            'uploaded_by' => $prostor->owner_id,
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
