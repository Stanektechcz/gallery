<?php

namespace Tests\Feature\Nahravani;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Models\VoiceNote;
use App\Support\AudioUploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Hlasovka se nepodává jako stránka a cizí galerie hosta zůstává cizí.
 *
 * Typ souboru se ukládal podle toho, co ohlásil prohlížeč, a vracel se jako
 * Content-Type: soubor s hlavičkou MP3 a HTML uvnitř, ohlášený jako
 * text/html, se v prohlížeči spustil jako stránka galerie.
 *
 * Hlasovky, náhledy a soukromé poznámky braly „kterýkoli prostor, kde je
 * členem" — včetně galerie, kam je účet pozvaný jen jako host.
 */
class HlasovkyAPristupDvojiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private GallerySpace $space;

    private GallerySpace $cizi;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->user = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->space = GallerySpace::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Naše', 'slug' => 'nase', 'owner_id' => $this->user->id, 'is_default' => true,
        ]);
        $this->space->members()->attach($this->user->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);

        $jiny = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->cizi = GallerySpace::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Cizí', 'slug' => 'cizi', 'owner_id' => $jiny->id,
        ]);
        $this->cizi->members()->attach($jiny->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        // Host cizí galerie — členství zapsané přímo, jako by přijal pozvánku.
        DB::table('gallery_space_user')->insert([
            'gallery_space_id' => $this->cizi->id, 'user_id' => $this->user->id, 'role' => 'viewer',
            'can_delete' => false, 'can_share' => false, 'joined_at' => now(),
        ]);
    }

    public function test_hlasovka_s_html_se_neulozi_ani_nepoda_jako_html(): void
    {
        $cesta = tempnam(sys_get_temp_dir(), 'hlas');
        // Hlavička ID3 a první rámec MP3 — finfo to pozná jako audio/mpeg — a za nimi stránka.
        file_put_contents($cesta, "ID3\x03\x00\x00\x00\x00\x00\x0A".str_repeat("\x00", 10)."\xFF\xFB\x90\x64".str_repeat("\x00", 200)
            .'<html><script>alert(document.cookie)</script></html>');
        $soubor = new UploadedFile($cesta, 'vzkaz.html', 'text/html', null, true);

        $poznamka = $this->actingAs($this->user)->post('/api/v1/voice-notes', ['audio' => $soubor], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json();

        $ulozeny = VoiceNote::where('uuid', $poznamka['uuid'])->value('mime_type');
        $this->assertContains($ulozeny, AudioUploads::MIME_TYPES);

        $odpoved = $this->get("/api/v1/voice-notes/{$poznamka['uuid']}/stream")->assertOk();
        $this->assertStringNotContainsString('html', (string) $odpoved->headers->get('Content-Type'));
        $this->assertStringContainsString('sandbox', (string) $odpoved->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $odpoved->headers->get('X-Content-Type-Options'));
    }

    public function test_starsi_hlasovka_s_nebezpecnym_typem_jde_jako_binarni_data(): void
    {
        $uuid = $this->hlasovka($this->space, 'text/html');

        $odpoved = $this->actingAs($this->user)->get("/api/v1/voice-notes/{$uuid}/stream")->assertOk();

        $this->assertSame('application/octet-stream', $odpoved->headers->get('Content-Type'));
    }

    public function test_hlasovky_hostovske_galerie_nejsou_pristupne(): void
    {
        $uuid = $this->hlasovka($this->cizi, 'audio/webm');

        $this->actingAs($this->user)->getJson('/api/v1/voice-notes?gallery_space_id='.$this->cizi->id)->assertNotFound();
        $this->get("/api/v1/voice-notes/{$uuid}/stream")->assertNotFound();
        $this->postJson("/api/v1/voice-notes/{$uuid}/listened")->assertNotFound();

        // Vlastní galerie funguje dál.
        $this->getJson('/api/v1/voice-notes')->assertOk()->assertJsonPath('space_id', $this->space->id);
    }

    public function test_nahled_a_soukroma_poznamka_hostovske_galerie_nejsou_pristupne(): void
    {
        $media = MediaItem::create([
            'gallery_space_id' => $this->cizi->id, 'owner_user_id' => $this->cizi->owner_id, 'uploaded_by' => $this->cizi->owner_id,
            'original_filename' => 'x.heic', 'safe_filename' => 'x.heic', 'extension' => 'heic',
            'mime_type' => 'image/heic', 'media_type' => 'photo', 'size_bytes' => 1,
            'status' => 'ready', 'storage_status' => 'local_only', 'uploaded_at' => now(),
        ]);

        $this->actingAs($this->user)->post("/api/v1/media/{$media->uuid}/thumbnail", [
            'thumbnail' => UploadedFile::fake()->image('nahled.jpg', 40, 40),
        ], ['Accept' => 'application/json'])->assertNotFound();
        $this->assertSame(0, $media->variants()->count());

        $this->getJson("/api/v1/media/{$media->uuid}/private-note")->assertNotFound();
        $this->putJson("/api/v1/media/{$media->uuid}/private-note", ['content' => 'Poznámka'])->assertNotFound();
    }

    private function hlasovka(GallerySpace $prostor, string $typ): string
    {
        $cesta = "voice-notes/{$prostor->id}/".Str::uuid().'.webm';
        Storage::disk('local')->put($cesta, '<html><script>alert(1)</script></html>');
        $uuid = (string) Str::uuid();
        DB::table('voice_notes')->insert([
            'uuid' => $uuid, 'gallery_space_id' => $prostor->id, 'created_by' => $prostor->owner_id,
            'title' => 'Hlas', 'path' => $cesta, 'mime_type' => $typ, 'size_bytes' => 10,
            'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $uuid;
    }
}
