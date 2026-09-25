<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\User;
use App\Support\SpaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Host v cizí galerii nesmí s jejími alby hýbat.
 *
 * `AlbumPolicy::update()` počítala s `users.role` (`isAdmin()`), a to je
 * `owner` u každého založeného účtu — nemá nic společného s tím, jakou roli
 * má člověk v konkrétním prostoru. Vlastník svého vlastního prostoru, který
 * je jinde jen host (`viewer`), tak směl přejmenovat, přeskládat i přemístit
 * album v galerii, kam byl jen pozvaný na prohlížení. `move()` navíc přijímal
 * jako nového rodiče album z úplně jiné galerie.
 */
class AlbumPolicyHostTest extends TestCase
{
    use RefreshDatabase;

    private User $vlastnik;

    private GallerySpace $vlastniProstor;

    private GallerySpace $cizi;

    private Album $ciziAlbum;

    protected function setUp(): void
    {
        parent::setUp();

        // Viz `ArchivVsTrezorTest` — statická mezipaměť prostorů se sama
        // nezneplatní mezi testy stejného procesu.
        SpaceContext::forget();

        $this->vlastnik = User::factory()->create(['role' => 'owner']);
        $this->vlastniProstor = GallerySpace::create(['name' => 'Moje galerie', 'owner_id' => $this->vlastnik->id]);
        $this->vlastniProstor->members()->syncWithoutDetaching([$this->vlastnik->id => ['role' => 'owner']]);

        $sousedka = User::factory()->create(['role' => 'owner']);
        $this->cizi = GallerySpace::create(['name' => 'Cizí galerie', 'owner_id' => $sousedka->id]);
        $this->cizi->members()->syncWithoutDetaching([
            $sousedka->id => ['role' => 'owner'],
            $this->vlastnik->id => ['role' => 'viewer'],
        ]);

        $this->ciziAlbum = Album::create([
            'gallery_space_id' => $this->cizi->id,
            'title' => 'Jejich léto',
            'slug' => 'jejich-leto',
            'visibility' => 'shared',
            'created_by' => $sousedka->id,
            'updated_by' => $sousedka->id,
        ]);
    }

    public function test_host_nesmi_prejmenovat_cizi_album(): void
    {
        $this->actingAs($this->vlastnik)
            ->patchJson('/albums/'.$this->ciziAlbum->uuid, ['title' => 'Přepsáno hostem'])
            ->assertForbidden();

        $this->assertSame('Jejich léto', $this->ciziAlbum->fresh()->title);
    }

    public function test_host_nesmi_ani_otevrit_cizi_album(): void
    {
        $this->actingAs($this->vlastnik)
            ->get('/albums/'.$this->ciziAlbum->uuid)
            ->assertForbidden();
    }

    public function test_move_odmitne_rodice_z_jine_galerie(): void
    {
        $vlastniAlbum = Album::create([
            'gallery_space_id' => $this->vlastniProstor->id,
            'title' => 'Moje album',
            'slug' => 'moje-album-'.Str::random(6),
            'visibility' => 'shared',
            'created_by' => $this->vlastnik->id,
            'updated_by' => $this->vlastnik->id,
        ]);

        $odpoved = $this->actingAs($this->vlastnik)
            ->postJson('/albums/'.$vlastniAlbum->uuid.'/move', ['parent_id' => $this->ciziAlbum->id]);

        $this->assertContains($odpoved->getStatusCode(), [403, 404, 422]);
        $this->assertNull($vlastniAlbum->fresh()->parent_id);
    }

    /**
     * I komu `update` na obou albech vyjde (editor v obou prostorech), rodič
     * musí zůstat ve stejné galerii jako přesouvané album.
     */
    public function test_move_odmitne_rodice_z_jine_galerie_i_pro_editora_obou_prostoru(): void
    {
        $this->cizi->members()->syncWithoutDetaching([$this->vlastnik->id => ['role' => 'editor']]);
        SpaceContext::forget();

        $vlastniAlbum = Album::create([
            'gallery_space_id' => $this->vlastniProstor->id,
            'title' => 'Moje album',
            'slug' => 'moje-album-'.Str::random(6),
            'visibility' => 'shared',
            'created_by' => $this->vlastnik->id,
            'updated_by' => $this->vlastnik->id,
        ]);

        $odpoved = $this->actingAs($this->vlastnik)
            ->postJson('/albums/'.$vlastniAlbum->uuid.'/move', ['parent_id' => $this->ciziAlbum->id]);

        $this->assertContains($odpoved->getStatusCode(), [404, 422]);
        $this->assertNull($vlastniAlbum->fresh()->parent_id);
    }
}
