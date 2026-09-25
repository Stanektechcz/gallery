<?php

namespace Tests\Feature\Obsah41;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Vzpomínka nese fotky z okamžiku, kdy vznikla (`media_ids`) — ale ukázat
 * smí jen ty, které jsou pořád vidět.
 *
 * Fotka přesunutá do trezoru, do koše nebo smazaná zůstávala v dlaždicích
 * vzpomínky i v jejím počtu.
 */
class VzpominkyFotkyTest extends TestCase
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

    public function test_vzpominka_ukaze_jen_fotky_ktere_jsou_videt(): void
    {
        $videt = $this->fotka(1);
        $trezor = $this->fotka(2, ['is_hidden' => true]);
        $kos = $this->fotka(3, ['trashed_at' => now()]);
        $smazana = $this->fotka(4);
        $smazana->delete();
        $cizi = $this->fotka(5, ['gallery_space_id' => GallerySpace::create(['name' => 'Cizí', 'owner_id' => $this->adri->id])->id]);

        $this->vzpominka([$videt->uuid, $trezor->uuid, $kos->uuid, $smazana->uuid, $cizi->uuid, 'neexistuje']);

        Sanctum::actingAs($this->adri);
        $v = $this->getJson('/api/data/pravidla')->assertOk()->json('data.MEMS.0');

        $this->assertSame(1, $v[6]);
        $this->assertSame([$videt->uuid], $v[9]);
    }

    public function test_s_odemcenym_trezorem_se_ukaze_i_skryta_fotka(): void
    {
        $videt = $this->fotka(1);
        $trezor = $this->fotka(2, ['is_hidden' => true]);
        $this->vzpominka([$trezor->uuid, $videt->uuid]);

        $domena = (string) (config('sanctum.stateful')[0] ?? 'localhost');
        $v = $this->actingAs($this->adri)
            ->withSession($this->odemcenyTrezor($this->adri))
            ->withHeader('Referer', 'http://'.$domena.'/')
            ->getJson('/api/data/pravidla')->assertOk()->json('data.MEMS.0');

        $this->assertSame(2, $v[6]);
        // Pořadí z vzpomínky zůstává.
        $this->assertSame([$trezor->uuid, $videt->uuid], $v[9]);
    }

    /** @param  list<string>  $fotky */
    private function vzpominka(array $fotky): void
    {
        DB::table('generated_memories')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'kind' => 'anniversary',
            'title' => 'Den u vodopádů', 'subtitle' => 'Krka', 'occurs_on' => '2021-08-16', 'years_ago' => 5,
            'media_ids' => json_encode($fotky), 'score' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function fotka(int $poradi, array $navic = []): MediaItem
    {
        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id, 'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG_'.$poradi.'.jpg', 'safe_filename' => 'img-'.$poradi.'.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 2048,
            'uploaded_at' => now(), 'status' => 'ready', 'storage_status' => 'local', 'is_hidden' => false,
            'taken_at' => '2021-08-16 12:00:00',
        ], $navic));
    }
}
