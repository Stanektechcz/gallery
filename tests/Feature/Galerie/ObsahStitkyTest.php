<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Štítky a lidé v záložkách.
 *
 * Prototyp měl napsané, že knihovna má 4 812 fotek s tagem „léto" — a dalo se
 * na něj kliknout. Hledání pak nenašlo nic.
 */
class ObsahStitkyTest extends TestCase
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

    /** Bez knihovny zůstává ukázka — prázdná obrazovka vypadá jako rozbitá. */
    public function test_bez_knihovny_se_stitky_neposilaji(): void
    {
        $data = $this->getJson('/api/data/knihovna')->assertOk()->json('data');

        $this->assertArrayNotHasKey('ATAGS', $data);
    }

    /** Štítek nese počet fotek, kterých se opravdu týká. */
    public function test_stitek_nese_skutecny_pocet(): void
    {
        $hory = $this->stitek('hory');
        $jidlo = $this->stitek('jídlo');

        foreach (range(1, 3) as $i) {
            $this->oznac($this->fotka([], $i), $hory);
        }
        $this->oznac($this->fotka([], 4), $jidlo);

        $t = $this->getJson('/api/data/knihovna')->assertOk()->json('data.ATAGS');

        $this->assertSame([['hory', '3'], ['jídlo', '1']], $t['all']);
    }

    /** Fotka v koši ani v trezoru se do počtu nepočítá. */
    public function test_kos_a_trezor_se_do_poctu_nepocitaji(): void
    {
        $hory = $this->stitek('hory');

        $this->oznac($this->fotka([], 1), $hory);
        $this->oznac($this->fotka(['trashed_at' => now()], 2), $hory);
        $this->oznac($this->fotka(['is_hidden' => true], 3), $hory);

        $t = $this->getJson('/api/data/knihovna')->assertOk()->json('data.ATAGS');

        $this->assertSame([['hory', '1']], $t['all']);
    }

    /** Nepoužitý štítek se pošle s nulou — dvojice si ho založila, existuje. */
    public function test_nepouzity_stitek_ma_nulu(): void
    {
        $this->fotka();
        $this->stitek('dron');

        $t = $this->getJson('/api/data/knihovna')->assertOk()->json('data.ATAGS');

        $this->assertSame([['dron', '0']], $t['all']);
    }

    /**
     * Návrhy štítků zůstávají prázdné.
     *
     * Aplikace nemá rozpoznávání obsahu, které by je vyrábělo. Prázdná záložka
     * je odpověď; vymyšlené návrhy jsou práce navíc pro dvojici.
     */
    public function test_navrhy_stitku_jsou_prazdne(): void
    {
        $this->oznac($this->fotka(), $this->stitek('hory'));

        $t = $this->getJson('/api/data/knihovna')->assertOk()->json('data.ATAGS');

        $this->assertSame([], $t['sug']);
    }

    /** Štítky druhého páru se do odpovědi nedostanou. */
    public function test_stitky_jineho_paru_se_neposilaji(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $this->oznac($this->fotka(), $this->stitek('naše'));
        $this->stitek('cizí', $ciziProstor->id);

        $t = $this->getJson('/api/data/knihovna')->assertOk()->json('data.ATAGS');

        $this->assertSame([['naše', '1']], $t['all']);
    }

    /** Úzké rozvržení kreslí tytéž lidi, jen v jiném tvaru. */
    public function test_lide_v_zalozkach_odpovidaji_lidem_v_knihovne(): void
    {
        $f = $this->fotka();
        $klara = $this->clovek('Klára');
        $skryta = $this->clovek('Kolemjdoucí', ['is_hidden' => true]);

        DB::table('media_person')->insert([
            ['media_item_id' => $f->id, 'person_id' => $klara->id, 'tagged_by' => $this->adri->id, 'created_at' => now()],
            ['media_item_id' => $f->id, 'person_id' => $skryta->id, 'tagged_by' => $this->adri->id, 'created_at' => now()],
        ]);

        $data = $this->getJson('/api/data/knihovna')->assertOk()->json('data');

        $this->assertSame('Klára', $data['APEOPLE']['ok'][0][0]);
        $this->assertSame($data['PERSONS']['Klára']['meta'], $data['APEOPLE']['ok'][0][1]);
        $this->assertSame('Kolemjdoucí', $data['APEOPLE']['hidden'][0][0]);
        // Návrhy nemá kdo vyrobit — rozpoznávání tváří aplikace nemá.
        $this->assertSame([], $data['APEOPLE']['sug']);
    }

    // ——— pomůcky ———

    private function stitek(string $nazev, ?int $prostor = null): int
    {
        return DB::table('tags')->insertGetId([
            'gallery_space_id' => $prostor ?? $this->prostor->id,
            'name' => $nazev,
            'slug' => Str::slug($nazev) ?: 'stitek',
            'depth' => 0,
            'materialized_path' => Str::slug($nazev) ?: 'stitek',
            'created_by' => $this->adri->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function oznac(MediaItem $fotka, int $stitek): void
    {
        DB::table('media_tag')->insert([
            'media_item_id' => $fotka->id,
            'tag_id' => $stitek,
            'tagged_by' => $this->adri->id,
            'created_at' => now(),
        ]);
    }

    private function clovek(string $jmeno, array $navic = []): Person
    {
        return Person::create(array_merge([
            'gallery_space_id' => $this->prostor->id,
            'name' => $jmeno,
            'created_by' => $this->adri->id,
        ], $navic));
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
            'taken_at' => now()->subDays($poradi),
            'uploaded_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }
}
