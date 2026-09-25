<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\MediaVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Soubor z trezoru ze starého rozhraní (`/media/{uuid}/full|download|stream`)
 * nejde do mezipaměti prohlížeče.
 *
 * Každá odpověď tu šla s `private, max-age=86400`: po zamčení trezoru ukázal
 * prohlížeč originál, náhled i video z paměti, aniž by se serveru zeptal —
 * zámek by platil jen pro soubory, které ještě nikdo neotevřel. Stejné
 * pravidlo jako `/files` (`MediaFileController`).
 */
class MediaTrezorMezipametTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->adri = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'slug' => 'nase', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->attach($this->adri->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true]);
    }

    public function test_plne_rozliseni_z_trezoru_se_neuklada(): void
    {
        $skryta = $this->polozka(['is_hidden' => true], ['original' => 'image/jpeg']);
        $bezna = $this->polozka([], ['original' => 'image/jpeg']);

        $this->assertBezMezipameti($this->odemceno()->get("/media/{$skryta->uuid}/full"));

        // Běžná fotka se den pamatuje — ale jen v prohlížeči. `response()->file()`
        // dělá odpověď veřejnou a `private` z hlaviček tiše přepsal na `public`,
        // takže soukromé fotky mohla uložit i sdílená mezipaměť (proxy, CDN).
        $bezne = $this->hlavicka($this->odemceno()->get("/media/{$bezna->uuid}/full")->assertOk());
        $this->assertStringContainsString('max-age=86400', $bezne);
        $this->assertStringContainsString('private', $bezne);
        $this->assertStringNotContainsString('public', $bezne);
    }

    public function test_bezne_stazeni_neni_verejne(): void
    {
        $bezna = $this->polozka([], ['original' => 'image/jpeg']);

        $this->assertStringNotContainsString('public', $this->hlavicka($this->odemceno()->get("/media/{$bezna->uuid}/download")->assertOk()));
    }

    /** HEIC jde přes náhled pro prohlížeč — i ten je z trezoru. */
    public function test_nahled_heic_z_trezoru_se_neuklada(): void
    {
        $vetsi = $this->polozka(['is_hidden' => true, 'extension' => 'heic', 'mime_type' => 'image/heic'], ['browser_full' => 'image/webp']);
        $mensi = $this->polozka(['is_hidden' => true, 'extension' => 'heic', 'mime_type' => 'image/heic'], ['large' => 'image/webp']);

        $this->assertBezMezipameti($this->odemceno()->get("/media/{$vetsi->uuid}/full")->assertHeader('X-Gallery-Viewer-Variant', 'browser_full'));
        $this->assertBezMezipameti($this->odemceno()->get("/media/{$mensi->uuid}/full")->assertHeader('X-Gallery-Viewer-Variant', 'large'));
    }

    /** Záložní náhled (originál chybí) taky. */
    public function test_zalozni_nahled_z_trezoru_se_neuklada(): void
    {
        $skryta = $this->polozka(['is_hidden' => true], ['medium' => 'image/webp']);

        $this->assertBezMezipameti($this->odemceno()->get("/media/{$skryta->uuid}/full"));
    }

    public function test_stazeni_z_trezoru_se_neuklada(): void
    {
        $skryta = $this->polozka(['is_hidden' => true], ['original' => 'image/jpeg']);

        $this->assertBezMezipameti($this->odemceno()->get("/media/{$skryta->uuid}/download"));
    }

    public function test_video_z_trezoru_se_neuklada(): void
    {
        $kompat = $this->polozka(['is_hidden' => true, 'media_type' => 'video', 'extension' => 'mp4', 'mime_type' => 'video/mp4'], ['video_compat' => 'video/mp4']);
        $original = $this->polozka(['is_hidden' => true, 'media_type' => 'video', 'extension' => 'mp4', 'mime_type' => 'video/mp4'], ['original' => 'video/mp4']);

        $this->assertBezMezipameti($this->odemceno()->get("/media/{$kompat->uuid}/stream"));
        $this->assertBezMezipameti($this->odemceno()->get("/media/{$original->uuid}/stream"));
    }

    /** Zámek na těchhle cestách hlídá `ProtectVaultMedia` — a odemčení patří člověku. */
    public function test_cizi_odemceni_soubor_z_trezoru_nevyda(): void
    {
        $skryta = $this->polozka(['is_hidden' => true], ['original' => 'image/jpeg']);
        $maki = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $this->prostor->members()->attach($maki->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true]);

        $this->actingAs($maki)->withSession($this->odemcenyTrezor($this->adri))
            ->get("/media/{$skryta->uuid}/full")
            ->assertRedirect(route('vault.index'));
    }

    private function odemceno(): static
    {
        return $this->actingAs($this->adri)->withSession($this->odemcenyTrezor($this->adri));
    }

    private function assertBezMezipameti(TestResponse $odpoved): void
    {
        $odpoved->assertOk();
        $hlavicka = $this->hlavicka($odpoved);

        $this->assertStringContainsString('no-store', $hlavicka);
        $this->assertStringContainsString('private', $hlavicka);
        $this->assertStringNotContainsString('max-age=86400', $hlavicka);
    }

    private function hlavicka(TestResponse $odpoved): string
    {
        return (string) $odpoved->headers->get('Cache-Control');
    }

    /** @param  array<string, string>  $varianty  typ => mime */
    private function polozka(array $navic, array $varianty): MediaItem
    {
        $media = MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG.jpg',
            'safe_filename' => 'img.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 100,
        ], $navic));

        foreach ($varianty as $typ => $mime) {
            $cesta = 'media/'.$media->uuid.'/'.$typ.'.bin';
            Storage::disk('public')->put($cesta, 'bajty');
            MediaVariant::create(['media_item_id' => $media->id, 'type' => $typ, 'disk' => 'public', 'path' => $cesta, 'mime_type' => $mime]);
        }

        return $media;
    }
}
