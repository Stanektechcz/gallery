<?php

namespace Tests\Feature\Dodelky;

use App\Models\MemoryEvening;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Večer se vzpomínkami musí brát prostor dvojice, ne kterékoli členství —
 * a jeho výpis nesmí ukázat trezor ani koš.
 */
class MemoryEveningSpaceTest extends TestCase
{
    use DvojiceSHostem, RefreshDatabase;

    public function test_explicit_guest_space_id_is_rejected(): void
    {
        [$ucet, $vlastni, $cizi, $ciziProstor] = $this->hostCiziGalerie();
        // Účet je hostem cizí galerie — nesmí si vynutit její prostor přes parametr.
        $this->actingAs($ucet)->getJson('/api/v1/memory-evenings?gallery_space_id='.$ciziProstor->id)->assertNotFound();
    }

    public function test_payload_excludes_vault_and_trashed_media(): void
    {
        [$vlastnik, $partner, $host, $prostor] = $this->dvojiceSHostem();
        $viditelna = $this->fotka($prostor, $vlastnik, 'viditelna.jpg');
        $trezorova = $this->fotka($prostor, $vlastnik, 'trezor.jpg', ['is_hidden' => true]);
        $kosova = $this->fotka($prostor, $vlastnik, 'kos.jpg', ['trashed_at' => now()]);

        $evening = MemoryEvening::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $prostor->id, 'created_by' => $vlastnik->id,
            'fingerprint' => hash('sha256', 'test'), 'dedupe_key' => hash('sha256', 'test-dedupe'),
            'source_type' => 'on_this_day', 'title' => 'Vzpomínka',
            'status' => 'planned', 'scheduled_for' => now()->addWeek(),
        ]);
        $boardId = DB::table('curation_boards')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $prostor->id, 'created_by' => $vlastnik->id,
            'title' => 'Vzpomínka', 'visibility' => 'shared', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $evening->update(['curation_board_id' => $boardId]);
        foreach ([$viditelna, $trezorova, $kosova] as $index => $media) {
            DB::table('curation_board_items')->insert([
                'curation_board_id' => $boardId, 'media_item_id' => $media->id, 'added_by' => $vlastnik->id,
                'sort_order' => $index, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $response = $this->actingAs($vlastnik)->getJson('/api/v1/memory-evenings/'.$evening->uuid)->assertOk();
        $uuids = collect($response->json('items'))->pluck('uuid');
        $this->assertTrue($uuids->contains($viditelna->uuid));
        $this->assertFalse($uuids->contains($trezorova->uuid));
        $this->assertFalse($uuids->contains($kosova->uuid));
    }
}
