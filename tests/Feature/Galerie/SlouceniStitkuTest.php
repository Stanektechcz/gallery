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
 * „Sloučit" u štítků psaných dvakrát opravdu slučuje v databázi.
 */
class SlouceniStitkuTest extends TestCase
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

    public function test_fotky_prejdou_pod_stitek_s_vice_fotkami(): void
    {
        $velky = $this->stitek('Chorvatsko', 'chorvatsko');
        $maly = $this->stitek('chorvatsko', 'chorvatsko-2');
        [$a, $b, $c] = [$this->fotka(1), $this->fotka(2), $this->fotka(3)];

        $this->oznac($a, $velky);
        $this->oznac($b, $velky);
        $this->oznac($b, $maly);   // tatáž fotka pod oběma — po sloučení jednou
        $this->oznac($c, $maly);

        $this->assertSame(1, count($this->getJson('/api/data/knihovna')->assertOk()->json('data.AL.tagMerge')));

        $this->postJson('/api/stitky/sloucit', ['stitky' => ['#Chorvatsko', '#chorvatsko']])
            ->assertOk()
            ->assertJsonPath('zprava', 'Sloučeno pod #Chorvatsko · 1 štítek zrušen');

        $this->assertFalse(DB::table('tags')->where('id', $maly)->exists());
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id], DB::table('media_tag')->where('tag_id', $velky)->pluck('media_item_id')->all());
        $this->assertSame(3, DB::table('media_tag')->count());
        $this->assertSame([], $this->getJson('/api/data/knihovna')->assertOk()->json('data.AL.tagMerge') ?? []);
    }

    public function test_ruzne_stitky_se_neslouci(): void
    {
        $this->stitek('hory', 'hory');
        $this->stitek('more', 'more');

        $this->postJson('/api/stitky/sloucit', ['stitky' => ['#hory', '#more']])->assertStatus(422);
        $this->assertSame(2, DB::table('tags')->count());
    }

    public function test_stitek_s_podstitky_se_neslouci(): void
    {
        $rodic = $this->stitek('Léto', 'leto');
        $this->stitek('leto', 'leto-2');
        DB::table('tags')->insert([
            'gallery_space_id' => $this->prostor->id, 'parent_id' => $rodic, 'name' => 'Koupání', 'slug' => 'koupani',
            'depth' => 1, 'materialized_path' => 'leto/koupani', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/stitky/sloucit', ['stitky' => ['#Léto', '#leto']])->assertStatus(422);
        $this->assertSame(3, DB::table('tags')->count());
    }

    public function test_cizi_stitky_nejsou_videt(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $this->stitek('Praha', 'praha', $ciziProstor->id);
        $this->stitek('praha', 'praha-2', $ciziProstor->id);

        $this->postJson('/api/stitky/sloucit', ['stitky' => ['#Praha', '#praha']])->assertStatus(422);
        $this->assertSame(2, DB::table('tags')->count());
    }

    private function stitek(string $nazev, string $slug, ?int $prostor = null): int
    {
        return DB::table('tags')->insertGetId([
            'gallery_space_id' => $prostor ?? $this->prostor->id,
            'name' => $nazev, 'slug' => $slug, 'depth' => 0, 'materialized_path' => $slug,
            'created_by' => $this->adri->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function oznac(MediaItem $fotka, int $stitek): void
    {
        DB::table('media_tag')->insert(['media_item_id' => $fotka->id, 'tag_id' => $stitek, 'tagged_by' => $this->adri->id, 'created_at' => now()]);
    }

    private function fotka(int $poradi): MediaItem
    {
        return MediaItem::create([
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
        ]);
    }
}
