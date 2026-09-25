<?php

namespace Tests\Feature\Media;

use App\Models\Album;
use App\Services\Media\AlbumCurationAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Společný výběr alba neukazuje fotky z trezoru ani smazané.
 */
class KuratorskaNastenkaTest extends TestCase
{
    use RefreshDatabase;
    use VytvariMedia;

    public function test_nastenka_vynecha_trezor_a_smazane(): void
    {
        $this->zalozProstor();
        $album = Album::create([
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Vídeň',
            'slug' => 'viden',
            'visibility' => 'shared',
            'created_by' => $this->adri->id,
            'updated_by' => $this->adri->id,
        ]);
        $nastenka = DB::table('curation_boards')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Společný výběr',
            'album_id' => $album->id,
            'purpose' => 'album_selection',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $viditelna = $this->media();
        $trezor = $this->media(['is_hidden' => true]);
        $smazana = $this->media();
        $smazana->delete();

        foreach ([$viditelna, $trezor, $smazana] as $poradi => $m) {
            DB::table('curation_board_items')->insert([
                'curation_board_id' => $nastenka, 'media_item_id' => $m->id, 'sort_order' => $poradi,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $payload = app(AlbumCurationAssistantService::class)->boardPayload($album, $this->adri->id);

        $this->assertSame([$viditelna->uuid], collect($payload['items'])->pluck('media_uuid')->all());
    }
}
