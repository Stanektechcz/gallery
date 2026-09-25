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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Moderace vzkazů od hostů.
 *
 * „Vzkaz skryt — host ho už nevidí" platilo jen v prohlížeči: sdílená stránka
 * četla databázi a skrytý vzkaz dál ukazovala každému, kdo měl odkaz.
 */
class ModeraceVzkazuTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    private SharedLink $odkaz;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        // Skutečné album: odkaz na album, které neexistuje, už neplatí.
        $album = Album::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Beskydy',
            'slug' => 'beskydy',
        ]);

        $this->odkaz = SharedLink::create([
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'token' => 'tok'.Str::random(8),
            'name' => 'Beskydy',
            'target_type' => 'album',
            'target_id' => $album->id,
            'allow_comments' => true,
        ]);

        Sanctum::actingAs($this->adri);
    }

    /** Skrytý vzkaz zmizí i ze sdílené stránky, zveřejněný se vrátí. */
    public function test_skryty_vzkaz_host_neuvidi(): void
    {
        $uuid = $this->vzkaz('Tohle tam být nemá.');

        $this->patchJson('/api/vzkazy-hostu/'.$uuid, ['skryty' => true])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.GV_C.0.hidden', true);

        $this->get('/s/'.$this->odkaz->token)
            ->assertInertia(fn ($stranka) => $stranka->where('comments', []));

        $this->patchJson('/api/vzkazy-hostu/'.$uuid, ['skryty' => false])->assertOk();

        $this->get('/s/'.$this->odkaz->token)
            ->assertInertia(fn ($stranka) => $stranka->where('comments.0.text', 'Tohle tam být nemá.'));
    }

    /** Smazaný vzkaz je pryč i s nahrávkou — a seznam po akci přijde prázdný. */
    public function test_smazani_vezme_i_nahravku(): void
    {
        Storage::disk('public')->put('hlasovky/'.$this->prostor->id.'/vzkaz.webm', 'zvuk');
        $uuid = $this->vzkaz(null, ['kind' => 'voice', 'audio_path' => 'hlasovky/'.$this->prostor->id.'/vzkaz.webm']);

        $odpoved = $this->deleteJson('/api/vzkazy-hostu/'.$uuid)->assertOk();

        // Prázdný seznam musí odpověď říct — mlčení by na obrazovce nechalo smazaný řádek.
        $this->assertTrue(
            in_array('GV_C', $odpoved->json('prazdne'), true) || $odpoved->json('data.GV_C') === [],
            'Po smazání posledního vzkazu musí přijít prázdný seznam.'
        );

        $this->assertDatabaseMissing('guest_comments', ['uuid' => $uuid]);
        Storage::disk('public')->assertMissing('hlasovky/'.$this->prostor->id.'/vzkaz.webm');
    }

    /** Přilepený vzkaz je popisek fotky; odlepení smaže jen ten popisek, který tam dal. */
    public function test_prilepeni_je_popisek_fotky(): void
    {
        $fotka = $this->fotka();
        $uuid = $this->vzkaz('Tady jsme byli poprvé.', ['media_item_id' => $fotka->id]);

        $this->postJson('/api/vzkazy-hostu/'.$uuid.'/prilepit')->assertOk();

        $this->assertSame('Tady jsme byli poprvé.', $fotka->fresh()->caption);
        $this->assertTrue((bool) DB::table('guest_comments')->where('uuid', $uuid)->value('is_pinned'));

        $this->postJson('/api/vzkazy-hostu/'.$uuid.'/prilepit', ['prilepit' => false])->assertOk();
        $this->assertNull($fotka->fresh()->caption);

        // Popisek, který mezitím někdo přepsal, odlepení nechá být.
        $this->postJson('/api/vzkazy-hostu/'.$uuid.'/prilepit')->assertOk();
        $fotka->forceFill(['caption' => 'Vlastní popisek'])->save();
        $this->postJson('/api/vzkazy-hostu/'.$uuid.'/prilepit', ['prilepit' => false])->assertOk();
        $this->assertSame('Vlastní popisek', $fotka->fresh()->caption);
    }

    /** Hlasovka bez přepisu ani vzkaz bez fotky prázdný popisek nevyrobí. */
    public function test_bez_textu_nebo_fotky_se_neprilepi(): void
    {
        $fotka = $this->fotka();
        $hlas = $this->vzkaz(null, ['kind' => 'voice', 'media_item_id' => $fotka->id]);
        $bezFotky = $this->vzkaz('Pěkné!');

        $this->postJson('/api/vzkazy-hostu/'.$hlas.'/prilepit')->assertStatus(422);
        $this->postJson('/api/vzkazy-hostu/'.$bezFotky.'/prilepit')->assertStatus(422);

        $this->assertNull($fotka->fresh()->caption);
    }

    /**
     * Fotka z trezoru se v přehledu vzkazů se zamčeným trezorem nejmenuje.
     *
     * `GV_C` čte `Pribeh::komentareHostu` — dřív bral `original_filename`
     * bez ohledu na `is_hidden`, takže fotka poslaná do trezoru dál ukazovala
     * svůj název na obrazovce vzkazů hostů, i s trezorem zamčeným.
     */
    public function test_fotka_z_trezoru_se_ve_vzkazech_nejmenuje_bez_odemceni(): void
    {
        $fotka = $this->fotka();
        $this->vzkaz('Tady jsme byli poprvé.', ['media_item_id' => $fotka->id]);
        $fotka->forceFill(['is_hidden' => true])->save();

        $zamceno = $this->getJson('/api/data/pribeh')->assertOk()->json('data.GV_C');
        $this->assertArrayNotHasKey('photo', $zamceno[0]);

        $odemceno = $this->withSession($this->odemcenyTrezor($this->adri))
            ->getJson('/api/data/pribeh')->assertOk()->json('data.GV_C');
        $this->assertSame('IMG_1.jpg', $odemceno[0]['photo']);
    }

    /** Smazaná (v koši) fotka se ve vzkazech taky nejmenuje — je pryč, ne skrytá. */
    public function test_fotka_v_kosi_se_ve_vzkazech_nejmenuje(): void
    {
        $fotka = $this->fotka();
        $this->vzkaz('Tady jsme byli poprvé.', ['media_item_id' => $fotka->id]);
        $fotka->forceFill(['trashed_at' => now()])->save();

        $odpoved = $this->getJson('/api/data/pribeh')->assertOk()->json('data.GV_C');
        $this->assertArrayNotHasKey('photo', $odpoved[0]);
    }

    /** Cizí dvojice na vzkaz nesáhne. */
    public function test_cizi_vzkaz_nejde_upravit(): void
    {
        $uuid = $this->vzkaz('Náš vzkaz');

        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $ciziProstor->members()->syncWithoutDetaching([$cizi->id => ['role' => 'owner']]);
        Sanctum::actingAs($cizi);

        $this->patchJson('/api/vzkazy-hostu/'.$uuid, ['skryty' => true])->assertNotFound();
        $this->deleteJson('/api/vzkazy-hostu/'.$uuid)->assertNotFound();

        $this->assertFalse((bool) DB::table('guest_comments')->where('uuid', $uuid)->value('is_hidden'));
    }

    private function vzkaz(?string $text, array $navic = []): string
    {
        $uuid = (string) Str::uuid();

        DB::table('guest_comments')->insert(array_merge([
            'uuid' => $uuid,
            'gallery_space_id' => $this->prostor->id,
            'shared_link_id' => $this->odkaz->id,
            'guest_name' => 'Babička',
            'body' => $text,
            'kind' => 'text',
            'is_hidden' => false,
            'is_pinned' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));

        return $uuid;
    }

    private function fotka(): MediaItem
    {
        return MediaItem::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG_1.jpg',
            'safe_filename' => 'img-1.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'uploaded_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
        ]);
    }
}
