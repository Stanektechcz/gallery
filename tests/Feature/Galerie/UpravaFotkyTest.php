<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Otočení a výřez z prohlížeče fotky se uloží na serveru.
 *
 * Dřív šel úhel jen do stavu prohlížeče a fotka se natáčela přes CSS: mřížka,
 * stažený soubor i sdílený odkaz ji ukazovaly neotočenou.
 */
class UpravaFotkyTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    public function test_otoceni_a_vyrez_vyrobi_upravenou_verzi_a_zpet_ji_smaze(): void
    {
        $this->potrebujeGd();

        $fotka = $this->fotka(1200, 800);

        $this->postJson('/api/media/'.$fotka->uuid.'/uprava', ['otoceni' => 90, 'vyrez' => true])
            ->assertOk()->assertJsonPath('upraveno', true);

        $varianty = $fotka->variants()->get()->keyBy('type');
        $this->assertTrue($varianty->has('edited_preview'));
        $this->assertTrue($varianty->has('edited_thumbnail'));
        // 1200×800 otočené o 90° je 800×1200; výřez 76 % × 82 % → 608×984.
        $this->assertSame(608, (int) $varianty['edited_preview']->width);
        $this->assertSame(984, (int) $varianty['edited_preview']->height);
        $this->assertSame(320, (int) $varianty['edited_thumbnail']->width);
        // Originál zůstal, jak byl.
        Storage::disk('public')->assertExists('media/'.$fotka->uuid.'/original.jpg');
        $this->assertSame(1, DB::table('media_edits')->where('media_item_id', $fotka->id)->where('is_current', true)->count());

        // Knihovna posílá adresu, která se po úpravě změnila, a velký obrázek.
        $radek = collect($this->getJson('/api/data/knihovna')->json('data.PHOTOS'))->firstWhere('id', $fotka->uuid);
        $this->assertTrue($radek['upraveno']);
        $this->assertStringContainsString('velikost=velky', $radek['full']);
        $this->assertStringContainsString('v=', $radek['bg']);

        // Zpět na originál.
        $this->postJson('/api/media/'.$fotka->uuid.'/uprava', ['otoceni' => 0, 'vyrez' => false])->assertOk();
        $this->assertFalse($fotka->variants()->whereIn('type', ['edited_preview', 'edited_thumbnail'])->exists());
        Storage::disk('public')->assertMissing('media/'.$fotka->uuid.'/edited_preview.webp');
        $this->assertSame(0, DB::table('media_edits')->where('media_item_id', $fotka->id)->where('is_current', true)->count());
    }

    /** Prohlížeč dostane velký obrázek, mřížka náhled — a upravená verze má přednost. */
    public function test_nahled_a_velky_obrazek_podle_podpisu(): void
    {
        $fotka = $this->fotka(0, 0, ['thumbnail', 'large']);

        $nahled = $this->get(\URL::temporarySignedRoute('galerie.media.thumb', now()->addHour(), ['uuid' => $fotka->uuid]))->assertOk();
        $this->assertSame('thumbnail', $nahled->streamedContent());

        $velky = $this->get(\URL::temporarySignedRoute('galerie.media.thumb', now()->addHour(), ['uuid' => $fotka->uuid, 'velikost' => 'velky']))->assertOk();
        $this->assertSame('large', $velky->streamedContent());

        $this->varianta($fotka, 'edited_preview');
        $upravena = $this->get(\URL::temporarySignedRoute('galerie.media.thumb', now()->addHour(), ['uuid' => $fotka->uuid, 'velikost' => 'velky']))->assertOk();
        $this->assertSame('edited_preview', $upravena->streamedContent());
    }

    public function test_cizi_fotku_upravit_nejde(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $fotka = $this->fotka(0, 0, ['thumbnail']);
        $fotka->forceFill(['gallery_space_id' => $ciziProstor->id])->save();

        $this->postJson('/api/media/'.$fotka->uuid.'/uprava', ['otoceni' => 90, 'vyrez' => false])->assertNotFound();
    }

    private function potrebujeGd(): void
    {
        if (! extension_loaded('gd') && ! extension_loaded('imagick')) {
            $this->markTestSkipped('Bez GD i Imagick se fotka upravit nedá (na serveru je GD).');
        }
    }

    private function fotka(int $sirka, int $vyska, array $varianty = []): MediaItem
    {
        $m = MediaItem::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'vylet.jpg',
            'safe_filename' => 'vylet.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1000,
            'width' => $sirka ?: null,
            'height' => $vyska ?: null,
        ]);

        if ($sirka > 0) {
            $obraz = imagecreatetruecolor($sirka, $vyska);
            ob_start();
            imagejpeg($obraz, null, 85);
            Storage::disk('public')->put('media/'.$m->uuid.'/original.jpg', (string) ob_get_clean());
            DB::table('media_variants')->insert([
                'media_item_id' => $m->id, 'type' => 'original', 'disk' => 'public', 'path' => 'media/'.$m->uuid.'/original.jpg',
                'size_bytes' => 1000, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ($varianty as $typ) {
            $this->varianta($m, $typ);
        }

        return $m;
    }

    private function varianta(MediaItem $m, string $typ): void
    {
        $cesta = 'media/'.$m->uuid.'/'.$typ.'.webp';
        Storage::disk('public')->put($cesta, $typ);
        DB::table('media_variants')->insert([
            'media_item_id' => $m->id, 'type' => $typ, 'disk' => 'public', 'path' => $cesta,
            'size_bytes' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
