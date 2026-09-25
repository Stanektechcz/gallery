<?php

namespace Tests\Feature\Galerie;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\SharedLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Co host uvidí na sdílené stránce alba.
 *
 * Stránka posílala celý model fotky (identifikátory na Disku, otisky, id
 * vlastníka, cesty na disku), ukazovala fotky z koše a u alba jen fotky
 * s `primary_album_id` — album složené v galerii se hostovi otevřelo prázdné.
 */
class SdilenaStrankaTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    private Album $album;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        $this->album = Album::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Beskydy',
            'slug' => 'beskydy-'.Str::random(4),
            'created_by' => $this->adri->id,
        ]);
    }

    public function test_host_vidi_jen_viditelne_fotky_a_nic_navic(): void
    {
        $vAlbu = $this->fotka('VIDITELNA.jpg');
        $this->doAlba($vAlbu);
        $vKosi = $this->fotka('KOS.jpg', ['trashed_at' => now()]);
        $this->doAlba($vKosi);
        $vTrezoru = $this->fotka('TREZOR.jpg', ['is_hidden' => true]);
        $this->doAlba($vTrezoru);

        $odkaz = SharedLink::create([
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'token' => 'tok'.Str::random(20),
            'name' => 'Beskydy',
            'target_type' => 'album',
            'target_id' => $this->album->id,
            'allow_download' => true,
        ]);

        $odpoved = $this->get('/s/'.$odkaz->token)->assertOk();
        $media = $odpoved->viewData('page')['props']['media'];

        $this->assertSame([$vAlbu->uuid], collect($media)->pluck('uuid')->all());

        $html = json_encode($media);
        foreach (['drive_file_id', 'sha256', 'owner_user_id', 'path', 'location_name', 'original_filename'] as $pole) {
            $this->assertStringNotContainsString('"'.$pole.'"', $html, 'Host nemá dostat '.$pole.'.');
        }

        // Adresa náhledu je podepsaná a otevře se bez přihlášení.
        $url = $media[0]['variants'][0]['url'];
        $this->assertStringContainsString('signature=', $url);
        $this->get($url)->assertOk();

        // Stáhnout jde viditelnou, fotku z koše ne.
        $this->get('/s/'.$odkaz->token.'/media/'.$vAlbu->uuid.'/download')->assertOk();
        $this->get('/s/'.$odkaz->token.'/media/'.$vKosi->uuid.'/download')->assertNotFound();
        $this->get('/s/'.$odkaz->token.'/media/'.$vTrezoru->uuid.'/download')->assertNotFound();
    }

    /**
     * Host s vlastním účtem v jiné galerii vidí a stáhne totéž co host bez účtu.
     *
     * Rozsah prostoru přihlášeného člověka mu fotky z cizího odkazu tiše
     * odfiltroval — stránka prázdná, stažení 404.
     */
    public function test_prihlaseny_host_z_jine_galerie_odkaz_vidi(): void
    {
        $foto = $this->fotka('VIDITELNA.jpg');
        $this->doAlba($foto);

        $odkaz = SharedLink::create([
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'token' => 'tok'.Str::random(20),
            'name' => 'Beskydy',
            'target_type' => 'album',
            'target_id' => $this->album->id,
            'allow_download' => true,
        ]);

        $babicka = User::factory()->create();
        $jejiProstor = GallerySpace::create(['name' => 'Babiččina galerie', 'owner_id' => $babicka->id]);
        $jejiProstor->members()->syncWithoutDetaching([$babicka->id => ['role' => 'owner']]);

        $this->actingAs($babicka);

        $media = $this->get('/s/'.$odkaz->token)->assertOk()->viewData('page')['props']['media'];
        $this->assertSame([$foto->uuid], collect($media)->pluck('uuid')->all());

        $this->get('/s/'.$odkaz->token.'/media/'.$foto->uuid.'/download')->assertOk();
    }

    /**
     * Smazané album přes starý odkaz nic neukáže — ani fotky zařazené jen přes `primary_album_id`.
     *
     * Větev s `primary_album_id` nekontrolovala, jestli album ještě existuje
     * (spojovací tabulka ano, přes měkké mazání). Dvojice album smazala
     * a host z odkazu dál viděl a stahoval jeho fotky.
     */
    public function test_smazane_album_pres_odkaz_nic_neukaze(): void
    {
        $hlavni = $this->fotka('HLAVNI.jpg', ['primary_album_id' => $this->album->id]);
        $vazba = $this->fotka('VAZBA.jpg');
        $this->doAlba($vazba);

        $odkaz = SharedLink::create([
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'token' => 'tok'.Str::random(20),
            'name' => 'Beskydy',
            'target_type' => 'album',
            'target_id' => $this->album->id,
            'allow_download' => true,
        ]);

        Sanctum::actingAs($this->adri);
        $this->deleteJson('/api/alba/'.$this->album->uuid)->assertOk();
        $this->app['auth']->forgetGuards();

        // Jako prošlý odkaz: stránka o konci platnosti, žádné fotky.
        $this->get('/s/'.$odkaz->token)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Shares/Expired')->missing('media'));

        $this->get('/s/'.$odkaz->token.'/media/'.$hlavni->uuid.'/download')->assertForbidden();
        $this->get('/s/'.$odkaz->token.'/media/'.$vazba->uuid.'/download')->assertForbidden();
    }

    private function fotka(string $jmeno, array $navic = []): MediaItem
    {
        $m = MediaItem::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => $jmeno,
            'safe_filename' => Str::slug($jmeno),
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'uploaded_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
            'drive_file_id' => 'drive-'.Str::random(6),
            'sha256' => hash('sha256', $jmeno),
            'location_name' => 'Pustevny',
        ]);
        $m->forceFill($navic)->save();

        foreach (['original', 'thumbnail'] as $typ) {
            $cesta = 'media/'.$m->uuid.'/'.$typ.'.jpg';
            Storage::disk('public')->put($cesta, 'obsah '.$jmeno);
            DB::table('media_variants')->insert([
                'media_item_id' => $m->id, 'type' => $typ, 'disk' => 'public', 'path' => $cesta,
                'size_bytes' => 10, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $m;
    }

    private function doAlba(MediaItem $m): void
    {
        DB::table('album_media')->insert(['album_id' => $this->album->id, 'media_item_id' => $m->id, 'sort_order' => $m->id, 'added_at' => now()]);
    }
}
