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
 * Šest obrazovek, které dosud neměly kam psát.
 *
 * Náš příběh, tisk, nouzový přístup, tierlisty, papírová záloha a hosté.
 */
class ObsahPribehTest extends TestCase
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

    /** Prázdný příběh nechává ukázku — vyprávění o ničem není vyprávění. */
    public function test_bez_kapitol_se_pribeh_neposila(): void
    {
        $this->assertSame([], $this->getJson('/api/data/pribeh')->assertOk()->json('data'));
    }

    /**
     * Ke kapitole se dopočítá jen to, co aplikace ví: fotky a zápisy z jejího
     * roku. Text píše dvojice.
     */
    public function test_kapitola_zna_svoje_fotky_a_zapisy(): void
    {
        $this->kapitola('První byt', '2018', 'done', 'Třicet čtyři metrů.');

        $this->fotka(['taken_at' => '2018-11-11 12:00:00'], 1);
        $this->fotka(['taken_at' => '2018-12-01 12:00:00'], 2);
        $this->fotka(['taken_at' => '2021-05-01 12:00:00'], 3);

        DB::table('journal_entries')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Stěhování',
            'body' => 'Radiátor hučel.',
            'entry_date' => '2018-11-12',
            'visibility' => 'shared',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $k = $this->getJson('/api/data/pribeh')->assertOk()->json('data.STORY.0');

        $this->assertSame('První byt', $k[1]);
        $this->assertSame('2018', $k[2]);
        $this->assertSame('hotovo', $k[3]);
        $this->assertSame(2, $k[4]);
        $this->assertSame(1, $k[5]);
        $this->assertSame('Třicet čtyři metrů.', $k[6]);
    }

    /** Milník ví, ke které kapitole patří. */
    public function test_milnik_zna_svoji_kapitolu(): void
    {
        $kapitola = $this->kapitola('Jak jsme se potkali', '2016');

        DB::table('couple_story_milestones')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'chapter_id' => $kapitola,
            'happened_on' => '2016-05-14',
            'title' => 'Poprvé jsme se potkali',
            'note' => 'Svatba u Kláry.',
            'icon' => 'ph-sparkle',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $m = $this->getJson('/api/data/pribeh')->assertOk()->json('data.STORYMS.0');

        $this->assertSame('2016', $m[1]);
        $this->assertSame('14. května 2016', $m[2]);
        $this->assertSame('Poprvé jsme se potkali', $m[3]);
        $this->assertSame(
            DB::table('couple_story_chapters')->where('id', $kapitola)->value('uuid'),
            $m[6],
        );
    }

    /**
     * Odhadnutý termín se pozná od potvrzeného.
     *
     * „Odhad 4. 9." a „4. 9." jsou dvě různá tvrzení a dvojice se podle nich
     * rozhoduje, jestli má čekat.
     */
    public function test_objednavka_rozlisi_odhad_od_terminu(): void
    {
        $this->objednavka('Fotokniha Náš rok', 'kniha', 3, '2026-01-08', false);
        $this->objednavka('Rámeček Pustevny', 'ramecek', 1, '2026-09-04', true);

        $o = collect($this->getJson('/api/data/pribeh')->assertOk()->json('data.PORDERS'))->keyBy(1);

        $this->assertSame('8. 1. 2026', $o['Fotokniha Náš rok'][5]);
        $this->assertSame('odhad 4. 9. 2026', $o['Rámeček Pustevny'][5]);
        $this->assertSame(3, $o['Fotokniha Náš rok'][4]);
    }

    /** Bez termínu se nic nepředstírá. */
    public function test_objednavka_bez_terminu(): void
    {
        $this->objednavka('Plakát', 'plakat', 0, null, true);

        $this->assertSame(
            'termín neznámý',
            $this->getJson('/api/data/pribeh')->assertOk()->json('data.PORDERS.0.5'),
        );
    }

    /** Nouzový přístup ví, co se odemkne a co ne. */
    public function test_nouzovy_pristup_zna_sve_polozky(): void
    {
        DB::table('emergency_access_items')->insert([
            [
                'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id,
                'label' => 'Pojistky a smlouvy', 'note' => 'Povinné ručení',
                'is_shared' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id,
                'label' => 'Soukromé zápisy v deníku', 'note' => 'To, co jsme si nechali pro sebe',
                'is_shared' => false, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $p = $this->getJson('/api/data/pribeh')->assertOk()->json('data.EM_ITEMS');

        $this->assertTrue($p[0]['on']);
        $this->assertFalse($p[1]['on']);
        $this->assertSame('Soukromé zápisy v deníku', $p[1]['label']);
    }

    /** Tierlist se porovnává s vlastním seznamem, ne s cizím žebříčkem. */
    public function test_tierlist_porovnava_s_vlastnim_seznamem(): void
    {
        foreach ([['Dune 2', 'S'], ['Shogun', 'S'], ['Ripley', 'A']] as [$nazev, $pasmo]) {
            DB::table('watch_titles')->insert([
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $this->prostor->id,
                'created_by' => $this->adri->id,
                'title' => $nazev,
                'kind' => 'film',
                'status' => 'seen',
                'tier' => $pasmo,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $t = collect($this->getJson('/api/data/pribeh')->assertOk()->json('data.ABARS.tier'))->keyBy(0);

        $this->assertSame('Dune 2, Shogun', $t['S · nezapomenutelné'][1]);
        $this->assertSame(100, $t['S · nezapomenutelné'][2]);
        $this->assertSame(50, $t['A · velmi dobré'][2]);
    }

    /** Host nemá uživatele — má jméno, které si napsal, a odkaz, kterým přišel. */
    public function test_komentar_hosta_nese_jmeno_i_odkaz(): void
    {
        $odkaz = DB::table('shared_links')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'token' => Str::random(20),
            'name' => 'Vánoce pro mamku',
            'target_type' => 'album',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('guest_comments')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'shared_link_id' => $odkaz,
            'guest_name' => 'Babička',
            'body' => 'Ten stromek je letos nádherný.',
            'kind' => 'text',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $k = $this->getJson('/api/data/pribeh')->assertOk()->json('data.GV_C.0');

        $this->assertSame('Babička', $k['who']);
        $this->assertSame('Vánoce pro mamku', $k['share']);
        $this->assertFalse($k['hidden']);
        $this->assertArrayNotHasKey('kind', $k);
    }

    /** Příběh druhého páru se do odpovědi nedostane. */
    public function test_pribeh_jineho_paru_se_neposila(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $this->kapitola('Naše', '2016');
        DB::table('couple_story_chapters')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $ciziProstor->id,
            'title' => 'Cizí',
            'year' => '2016',
            'status' => 'draft',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $k = $this->getJson('/api/data/pribeh')->assertOk()->json('data.STORY');

        $this->assertCount(1, $k);
        $this->assertSame('Naše', $k[0][1]);
    }

    // ——— pomůcky ———

    private function kapitola(string $nazev, string $rok, string $stav = 'draft', string $text = ''): int
    {
        return DB::table('couple_story_chapters')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => $nazev,
            'year' => $rok,
            'status' => $stav,
            'body' => $text,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function objednavka(string $nazev, string $druh, int $krok, ?string $termin, bool $odhad): void
    {
        DB::table('print_orders')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'title' => $nazev,
            'kind' => $druh,
            'price' => 1190,
            'step' => $krok,
            'due_on' => $termin,
            'due_estimated' => $odhad,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
            'uploaded_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }
}
