<?php

namespace Tests\Feature\Nahravani;

use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Nahrávání po blocích: kvóta, disk, souběh a metadata, která jdou do MySQL.
 *
 * Kvóta se hlídala jen podle deklarované velikosti — bloky samotné mohly být
 * libovolně velké a počet bloků neomezený. Dvojí „dokončit" založilo dvě
 * média a chyba sestavení vracela text výjimky i s cestou na serveru.
 */
class RelaceNahravaniTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private GallerySpace $space;

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
    }

    public function test_blok_vetsi_nez_ohlasena_velikost_se_odmitne_a_nezustane_na_disku(): void
    {
        $uuid = $this->zahaj(['filename' => 'a.jpg', 'total_size' => 10, 'total_chunks' => 3]);

        $this->actingAs($this->user)->putJson("/api/v1/uploads/{$uuid}/chunks/0", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk_0', str_repeat('A', 2048)),
        ])->assertStatus(422);

        Storage::disk('local')->assertMissing("upload_chunks/{$uuid}/chunk_0");
        $this->assertSame(0, (int) UploadSession::where('uuid', $uuid)->value('uploaded_bytes'));
    }

    public function test_bloky_dohromady_nepresahnou_ohlasenou_velikost(): void
    {
        $uuid = $this->zahaj(['filename' => 'a.jpg', 'total_size' => 1500, 'total_chunks' => 2]);

        $this->actingAs($this->user)->putJson("/api/v1/uploads/{$uuid}/chunks/0", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk_0', str_repeat('A', 1000)),
        ])->assertOk();

        $this->putJson("/api/v1/uploads/{$uuid}/chunks/1", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk_1', str_repeat('B', 1000)),
        ])->assertStatus(422);

        Storage::disk('local')->assertMissing("upload_chunks/{$uuid}/chunk_1");
    }

    public function test_prilis_velky_blok_se_odmitne(): void
    {
        $uuid = $this->zahaj(['filename' => 'film.mp4', 'mime_type' => 'video/mp4', 'total_size' => 100 * 1024 * 1024, 'total_chunks' => 100]);

        $this->actingAs($this->user)->putJson("/api/v1/uploads/{$uuid}/chunks/0", [
            'chunk' => UploadedFile::fake()->create('chunk_0', 20 * 1024),
        ])->assertStatus(422);

        Storage::disk('local')->assertMissing("upload_chunks/{$uuid}/chunk_0");
    }

    public function test_pocet_bloku_ma_strop(): void
    {
        // Sloupec total_chunks je unsignedInteger — nad 4294967295 by MySQL spadl na 500.
        $this->actingAs($this->user)->postJson('/api/v1/uploads', [
            'filename' => 'a.jpg', 'mime_type' => 'image/jpeg', 'total_size' => 10, 'total_chunks' => 5000000000,
        ])->assertStatus(422);

        // Víc bloků než bajtů nedává smysl a jen otevírá cestu k tisícům souborů na disku.
        $this->postJson('/api/v1/uploads', [
            'filename' => 'a.jpg', 'mime_type' => 'image/jpeg', 'total_size' => 10, 'total_chunks' => 5000,
        ])->assertStatus(422);

        $this->assertSame(0, UploadSession::count());
    }

    public function test_dokonceni_znovu_overi_kvotu(): void
    {
        $plan = BillingPlan::create(['code' => 'maly', 'name' => 'Malý', 'price_monthly' => 0, 'storage_limit_mb' => 1, 'member_limit' => 2]);
        app(EntitlementService::class)->assignPlan($this->space, $plan);

        $obsah = 'obsah-fotky';
        $uuid = $this->zahaj(['filename' => 'a.jpg', 'total_size' => strlen($obsah), 'total_chunks' => 1]);
        $this->actingAs($this->user)->putJson("/api/v1/uploads/{$uuid}/chunks/0", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk_0', $obsah),
        ])->assertOk();

        // Mezitím se místo zaplnilo jiným nahráním.
        $this->media(['size_bytes' => 1024 * 1024, 'sha256' => str_repeat('f', 64)]);

        $this->postJson("/api/v1/uploads/{$uuid}/complete")->assertStatus(402);
        $this->assertSame(1, MediaItem::count());
    }

    public function test_nazev_s_teckou_uprostred_neulozi_nesmyslnou_priponu(): void
    {
        $obsah = 'png-obsah';
        $uuid = $this->zahaj(['filename' => 'Snímek 2024.05 dovolená u moře', 'mime_type' => 'image/png', 'total_size' => strlen($obsah), 'total_chunks' => 1]);
        $this->actingAs($this->user)->putJson("/api/v1/uploads/{$uuid}/chunks/0", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk_0', $obsah),
        ])->assertOk();

        $mediaId = $this->postJson("/api/v1/uploads/{$uuid}/complete")->assertOk()->json('media_id');
        $media = MediaItem::findOrFail($mediaId);

        $this->assertMatchesRegularExpression('/^[a-z0-9]{0,10}$/', (string) $media->extension);
        $this->assertSame('png', $media->extension);
        $this->assertSame('Snímek 2024.05 dovolená u moře', $media->original_filename);
        Storage::disk('public')->assertExists("media/{$media->uuid}/original.png");
    }

    public function test_chyba_sestaveni_nevrati_text_vyjimky(): void
    {
        $obsah = 'obsah';
        $uuid = $this->zahaj(['filename' => 'a.jpg', 'total_size' => strlen($obsah), 'total_chunks' => 1]);
        $this->actingAs($this->user)->putJson("/api/v1/uploads/{$uuid}/chunks/0", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk_0', $obsah),
        ])->assertOk();

        // `created`, ne `creating`: tam posluchač modelu vrací uuid a `until()`
        // by další posluchače už nezavolal.
        MediaItem::created(function () {
            throw new \RuntimeException('SQLSTATE: /var/www/tajna-cesta/databaze.sqlite');
        });

        $odpoved = $this->postJson("/api/v1/uploads/{$uuid}/complete")->assertStatus(500);

        $odpoved->assertDontSee('tajna-cesta');
        $odpoved->assertDontSee('SQLSTATE');
        $odpoved->assertDontSee('Assembly failed');
        $this->assertNotEmpty($odpoved->json('error'));
        $this->assertSame(0, MediaItem::count(), 'Po chybě nesmí zůstat rozpracované médium.');
    }

    public function test_relace_ve_skladani_se_nedokonci_podruhe(): void
    {
        [$uuid] = $this->pripravenaRelace();
        UploadSession::where('uuid', $uuid)->update(['status' => 'assembling']);

        $this->actingAs($this->user)->postJson("/api/v1/uploads/{$uuid}/complete")->assertStatus(409);
        $this->assertSame(0, MediaItem::count());
    }

    public function test_soubezne_dokonceni_zalozi_jen_jedno_medium(): void
    {
        [$uuid] = $this->pripravenaRelace();

        // Druhý požadavek relaci převezme mezi načtením a sestavením.
        UploadSession::retrieved(function (UploadSession $relace) {
            DB::table('upload_sessions')->where('id', $relace->id)->update(['status' => 'assembling']);
        });

        $this->actingAs($this->user)->postJson("/api/v1/uploads/{$uuid}/complete")->assertStatus(409);
        $this->assertSame(0, MediaItem::count());
    }

    /** Klientovi vypadla odpověď a dokončení zopakoval — fotka je nahraná, ne chyba. */
    public function test_opakovane_dokonceni_hotove_relace_vrati_totez_medium(): void
    {
        $obraz = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($obraz);
        $obsah = (string) ob_get_clean();

        $uuid = $this->zahaj(['filename' => 'obrazek.png', 'mime_type' => 'image/png', 'total_size' => strlen($obsah), 'total_chunks' => 1]);
        $this->actingAs($this->user)->putJson("/api/v1/uploads/{$uuid}/chunks/0", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk_0', $obsah),
        ])->assertOk();

        $prvni = $this->postJson("/api/v1/uploads/{$uuid}/complete")->assertOk()->json('media_uuid');
        $druhe = $this->postJson("/api/v1/uploads/{$uuid}/complete")->assertOk();

        $this->assertSame($prvni, $druhe->json('media_uuid'));
        $this->assertSame('completed', $druhe->json('status'));
        $this->assertSame(1, MediaItem::count());
    }

    public function test_cas_zmeny_souboru_se_ulozi_podle_prazskych_hodin(): void
    {
        $obraz = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($obraz);
        $obsah = (string) ob_get_clean();

        $uuid = $this->zahaj([
            'filename' => 'obrazek.png', 'mime_type' => 'image/png',
            'total_size' => strlen($obsah), 'total_chunks' => 1,
            'client_modified_at' => '2025-12-31T23:30:00.000Z',
        ]);
        $this->actingAs($this->user)->putJson("/api/v1/uploads/{$uuid}/chunks/0", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk_0', $obsah),
        ])->assertOk();

        $mediaId = $this->postJson("/api/v1/uploads/{$uuid}/complete")->assertOk()->json('media_id');

        $this->assertSame('2026-01-01 00:30:00', MediaItem::findOrFail($mediaId)->taken_at?->format('Y-m-d H:i:s'));
    }

    /** @return array{0: string} */
    private function pripravenaRelace(): array
    {
        $obsah = 'obsah';
        $uuid = $this->zahaj(['filename' => 'a.jpg', 'total_size' => strlen($obsah), 'total_chunks' => 1]);
        $this->actingAs($this->user)->putJson("/api/v1/uploads/{$uuid}/chunks/0", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk_0', $obsah),
        ])->assertOk();

        return [$uuid];
    }

    private function zahaj(array $data): string
    {
        return (string) $this->actingAs($this->user)->postJson('/api/v1/uploads', $data + ['mime_type' => 'image/jpeg'])
            ->assertCreated()
            ->json('uuid');
    }

    private function media(array $atributy = []): MediaItem
    {
        return MediaItem::create($atributy + [
            'gallery_space_id' => $this->space->id, 'owner_user_id' => $this->user->id, 'uploaded_by' => $this->user->id,
            'original_filename' => 'x.jpg', 'safe_filename' => 'x.jpg', 'extension' => 'jpg',
            'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1,
            'status' => 'ready', 'storage_status' => 'local_only', 'uploaded_at' => now(),
        ]);
    }
}
