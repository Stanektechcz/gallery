<?php

namespace Tests\Feature\Cesty;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Fotka z trezoru (`is_hidden`) nepatří do sdílených seznamů cesty, deníku
 * ani itineráře — ani jako obálka, počet nebo návrh k přiřazení.
 */
class CestaATrezorTest extends CestyTestCase
{
    private int $viditelna;

    private int $trezorova;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cesta('2026-08-01', '2026-08-03');
        $gps = ['latitude' => 48.2082, 'longitude' => 16.3738];
        $this->trezorova = $this->media($gps + ['taken_at' => '2026-08-01 08:00:00', 'is_hidden' => true]);
        $this->viditelna = $this->media($gps + ['taken_at' => '2026-08-01 12:00:00']);
        foreach ([$this->trezorova => 'trezor', $this->viditelna => 'verejna'] as $id => $nazev) {
            DB::table('media_variants')->insert(['media_item_id' => $id, 'type' => 'thumbnail', 'disk' => 'local', 'path' => "nahledy/{$nazev}.webp", 'size_bytes' => 10, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_seznamy_cesty_fotku_z_trezoru_neukazou(): void
    {
        DB::table('trip_media')->insert([
            ['trip_id' => $this->tripId, 'media_item_id' => $this->trezorova, 'added_at' => now()],
            ['trip_id' => $this->tripId, 'media_item_id' => $this->viditelna, 'added_at' => now()],
        ]);

        $media = $this->getJson("/api/v1/trips/{$this->tripId}/media")->assertOk()->json();
        $this->assertSame([$this->viditelna], array_column($media, 'id'));

        $cesta = $this->getJson("/api/v1/trips/{$this->tripId}")->assertOk()->json();
        $this->assertSame(1, $cesta['media_count']);
        $seznam = $this->getJson('/api/v1/trips')->assertOk()->json();
        $this->assertSame(1, $seznam[0]['media_count']);

        // Obálka nastavená na fotku z trezoru se nepoužije.
        $this->assertStringContainsString('verejna', (string) $cesta['cover_thumb']);
        DB::table('trips')->where('id', $this->tripId)->update(['cover_media_id' => $this->trezorova]);
        $this->assertStringContainsString('verejna', (string) $this->getJson("/api/v1/trips/{$this->tripId}")->json('cover_thumb'));

        // Sdílená vzpomínka ji nepřijme.
        $this->postJson("/api/v1/trips/{$this->tripId}/shared-memory", ['media_item_ids' => [$this->trezorova]])->assertStatus(422);
    }

    public function test_navrh_a_pridani_fotek_trezor_vynechaji(): void
    {
        $navrh = $this->getJson("/api/v1/trips/{$this->tripId}/suggest-media")->assertOk()->json();
        $this->assertSame([$this->viditelna], $navrh['all_ids']);

        $this->postJson("/api/v1/trips/{$this->tripId}/media", ['media_ids' => [$this->trezorova, $this->viditelna]])->assertOk()->assertJsonPath('added', 1);
        $this->assertDatabaseMissing('trip_media', ['trip_id' => $this->tripId, 'media_item_id' => $this->trezorova]);
    }

    public function test_denik_a_itinerar_fotku_z_trezoru_neukazou(): void
    {
        $udalost = DB::table('journey_events')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'title' => 'Vídeň', 'event_date' => '2026-08-01', 'latitude' => 48.2, 'longitude' => 16.37, 'created_at' => now(), 'updated_at' => now()]);
        $trezorUuid = DB::table('media_items')->where('id', $this->trezorova)->value('uuid');

        $fotky = $this->getJson("/api/v1/journey/{$udalost}/photos")->assertOk()->json();
        $this->assertNotContains($trezorUuid, array_column($fotky, 'uuid'));
        $this->assertCount(1, $fotky);
        $this->assertSame(1, $this->getJson('/api/v1/journey')->assertOk()->json('0.photo_count'));

        $misto = DB::table('itinerary_places')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Vídeň', 'latitude' => 48.2, 'longitude' => 16.37, 'visited' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertNotContains($trezorUuid, array_column($this->getJson("/api/v1/itinerary/{$misto}/photos")->assertOk()->json(), 'uuid'));
        $this->assertSame(1, (int) $this->getJson('/api/v1/itinerary')->assertOk()->json('visited_areas.0.photo_count'));

        // Místo, kde je jen fotka z trezoru, se jako navštívené neoznačí ani nenavrhne.
        DB::table('media_items')->where('id', $this->viditelna)->delete();
        DB::table('journey_events')->where('id', $udalost)->delete();
        Cache::put('rgc_48.2_16.4', 'Vídeň', 60);
        $this->postJson('/api/v1/itinerary/check-visited')->assertOk()->assertJsonPath('auto_detected', 0);
        $this->getJson('/api/v1/journey/auto-suggest')->assertOk()->assertExactJson([]);
    }
}
