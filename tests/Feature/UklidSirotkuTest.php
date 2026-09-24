<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `gallery:exif --clean-orphans` maže natvrdo — a mazal moc.
 *
 * Bral každou položku bez `drive_file_id`, jejíž originál nenašel na místním
 * disku, a rovnou ji odstranil `forceDelete()`. Do téhle množiny ale patří
 * i fotky zrcadlené do Dropboxu nebo OneDrivu (ty `drive_file_id` nemají
 * z principu) a položky, které se právě nahrávají a variantu originálu ještě
 * nemají — u nich vycházelo „soubor tu není" prostě proto, že tam ještě není.
 *
 * Bez zkoušky nanečisto, bez potvrzení a bez zápisu do protokolu. Příkaz
 * nikdo nespouští, což je jediný důvod, proč se to ještě nestalo.
 */
class UklidSirotkuTest extends TestCase
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
    }

    /** Bez výslovného potvrzení se nemaže nic — jen se vypíše, co by odešlo. */
    public function test_bez_potvrzeni_se_nemaze(): void
    {
        $sirotek = $this->fotka(1, ['storage_status' => 'local_only']);
        $this->varianta($sirotek, 'public', 'media/chybi.jpg');

        $this->artisan('gallery:exif --clean-orphans')->assertSuccessful();

        $this->assertNotNull($sirotek->fresh(), 'Nanečisto znamená nanečisto.');
    }

    /** Fotka zrcadlená do jiného cloudu není sirotek. */
    public function test_zrcadlena_do_jineho_cloudu_zustane(): void
    {
        $zrcadlena = $this->fotka(2, ['storage_status' => 'synced']);
        $this->varianta($zrcadlena, 'dropbox', 'galerie/IMG_2.jpg');

        $this->artisan('gallery:exif --clean-orphans --opravdu')->assertSuccessful();

        $this->assertNotNull($zrcadlena->fresh(),
            'Originál leží v Dropboxu — `drive_file_id` nemá a mít nemá.');
    }

    /** Položka, která se právě nahrává, variantu originálu ještě nemá. */
    public function test_rozdelana_polozka_zustane(): void
    {
        $rozdelana = $this->fotka(3, ['storage_status' => 'uploading']);

        $this->artisan('gallery:exif --clean-orphans --opravdu')->assertSuccessful();

        $this->assertNotNull($rozdelana->fresh(),
            '„Soubor tu není" u rozdělaného nahrávání znamená „ještě tam není".');
    }

    /** Skutečný sirotek s potvrzením odejde — a zůstane po něm záznam. */
    public function test_skutecny_sirotek_s_potvrzenim_odejde(): void
    {
        $sirotek = $this->fotka(4, ['storage_status' => 'local_only']);
        $this->varianta($sirotek, 'public', 'media/chybi.jpg');

        $this->artisan('gallery:exif --clean-orphans --opravdu')->assertSuccessful();

        $this->assertNull(MediaItem::withTrashed()->find($sirotek->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'media.orphan.purged']);
    }

    private function varianta(MediaItem $media, string $disk, string $cesta): void
    {
        DB::table('media_variants')->insert([
            'media_item_id' => $media->id,
            'type' => 'original',
            'disk' => $disk,
            'path' => $cesta,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function fotka(int $poradi, array $navic = []): MediaItem
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
            'size_bytes' => 1024,
            'taken_at' => now()->subDays($poradi),
            'uploaded_at' => now()->subDays($poradi),
            'status' => 'ready',
        ], $navic));
    }
}
