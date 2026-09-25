<?php

namespace Tests\Feature\Cesty;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Cesta patří dvojici: host prostoru ani účet, který je v cizí galerii jen
 * pozvaný, nesmí k její rezervaci, do výdajů, do připomínek ani do alba.
 */
class CestyJenProDvojiciTest extends CestyTestCase
{
    public function test_host_cizi_galerie_neotevre_jeji_cestu_ani_rezervaci(): void
    {
        // U vlastní svou (výchozí, nižší id) galerii — brána `dvojice:klic` ho pustí.
        $u = User::factory()->create(['role' => 'owner']);
        $vlastni = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Moje', 'slug' => 'moje-'.Str::random(4), 'owner_id' => $u->id]);
        $vlastni->members()->attach($u->id, ['role' => 'owner', 'joined_at' => now()]);
        // …a v galerii `space` je jen host.
        $cizi = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Cizí', 'slug' => 'cizi-'.Str::random(4), 'owner_id' => $this->owner->id]);
        $cizi->members()->attach($this->owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $cizi->members()->attach($u->id, ['role' => 'viewer', 'joined_at' => now()]);
        $this->assertLessThan($cizi->id, $vlastni->id);

        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $cizi->id, 'created_by' => $this->owner->id, 'name' => 'Cizí cesta', 'start_date' => '2026-11-01', 'end_date' => '2026-11-02', 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $uuid = (string) Str::uuid();
        Storage::disk('local')->put("trip-reservations/{$tripId}/{$uuid}.pdf", 'PDF');
        DB::table('trip_reservation_imports')->insert([
            'uuid' => $uuid, 'trip_id' => $tripId, 'uploaded_by' => $this->owner->id, 'original_name' => 'letenka.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 3, 'storage_path' => "trip-reservations/{$tripId}/{$uuid}.pdf",
            'sha256' => hash('sha256', 'PDF'), 'status' => 'confirmed', 'extraction_method' => 'manual',
            'extracted_data' => '{}', 'confirmed_data' => json_encode(['reference' => 'TAJNE1']), 'confirmed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($u);
        $this->getJson("/api/v1/trips/{$tripId}/offline-package")->assertNotFound();
        $this->get("/api/v1/trips/{$tripId}/reservation-imports/{$uuid}/download")->assertNotFound();
        $this->getJson("/api/v1/trips/{$tripId}/reservation-imports")->assertNotFound();
        $this->getJson("/api/v1/trips/{$tripId}/finance-summary")->assertNotFound();
        $this->getJson("/api/v1/trips/{$tripId}/plan")->assertNotFound();
        $this->getJson("/api/v1/trips/{$tripId}/travel-choices")->assertNotFound();
    }

    public function test_cestovni_album_nedava_hostovi_prava_editora(): void
    {
        $tripId = $this->cesta('2026-08-01', '2026-08-02');
        $foto = $this->media(['taken_at' => '2026-08-01 10:00:00']);
        DB::table('trip_media')->insert(['trip_id' => $tripId, 'media_item_id' => $foto, 'added_at' => now()]);

        $album = $this->postJson("/api/v1/trips/{$tripId}/recap-album", ['media_item_ids' => [$foto]])->assertCreated()->json();

        $albumId = DB::table('albums')->where('uuid', $album['uuid'])->value('id');
        $this->assertDatabaseHas('album_user_permissions', ['album_id' => $albumId, 'user_id' => $this->partner->id, 'role' => 'editor']);
        $this->assertDatabaseMissing('album_user_permissions', ['album_id' => $albumId, 'user_id' => $this->guest->id]);
    }

    public function test_vydaje_cesty_se_deli_jen_mezi_dvojici(): void
    {
        $tripId = $this->cesta('2026-08-01', '2026-08-02');
        DB::table('trip_expenses')->insert(['trip_id' => $tripId, 'created_by' => $this->owner->id, 'title' => 'Hotel', 'category' => 'accommodation', 'amount' => 900, 'currency' => 'CZK', 'paid_by_user_id' => $this->owner->id, 'state' => 'actual', 'split' => null, 'created_at' => now(), 'updated_at' => now()]);

        $summary = $this->getJson("/api/v1/trips/{$tripId}/finance-summary")->assertOk()->json();
        $this->assertCount(1, $summary['proposals']);
        $this->assertSame($this->partner->id, $summary['proposals'][0]['from_user_id']);
        $this->assertSame($this->owner->id, $summary['proposals'][0]['to_user_id']);
        $this->assertEquals(450, $summary['proposals'][0]['amount']);
        $this->assertNotContains($this->guest->id, array_column($summary['members'], 'user_id'));

        // Výdaj z cestovního deníku se dělí stejně — jen mezi dvojici.
        $this->postJson("/api/v1/trips/{$tripId}/journal", ['type' => 'expense', 'content' => 'Večeře', 'amount' => 600])->assertCreated();
        $split = json_decode(DB::table('trip_expenses')->where('trip_id', $tripId)->where('title', 'Večeře')->value('split'), true);
        $this->assertEqualsCanonicalizing([$this->owner->id, $this->partner->id], array_column($split, 'user_id'));
        $this->assertEquals(300, $split[0]['amount']);

        // Host není „člen" ani pro vyrovnání, balení nebo sdílení polohy.
        $this->postJson("/api/v1/trips/{$tripId}/location-consent", ['recipient_user_id' => $this->guest->id, 'expires_at' => now()->addDay()->toDateTimeString()])->assertStatus(422);
        $this->postJson("/api/v1/trips/{$tripId}/packing-items", ['title' => 'Mapa', 'assigned_to' => $this->guest->id])->assertStatus(422);
        $members = $this->getJson("/api/v1/trips/{$tripId}/packing-members")->assertOk()->json();
        $this->assertNotContains($this->guest->id, array_column($members, 'id'));
    }

    public function test_host_nedostane_pripominky_rezervace_ani_pripravy(): void
    {
        $this->travelTo('2026-09-01 08:00:00');
        $tripId = $this->cesta('2026-10-10', '2026-10-12');
        $import = $this->postJson("/api/v1/trips/{$tripId}/reservation-imports", ['source_text' => 'RegioJet rezervace ABCD1234'])->assertCreated()->json('import');

        $this->putJson("/api/v1/trips/{$tripId}/reservation-imports/{$import['uuid']}/confirm", [
            'type' => 'ticket', 'title' => 'Vlak', 'starts_at' => '2026-10-10 07:30:00', 'reminder_hours' => [24, 2],
        ])->assertOk();

        $eventId = DB::table('calendar_events')->where('trip_id', $tripId)->where('type', 'reservation')->value('id');
        $this->assertDatabaseHas('event_participants', ['event_id' => $eventId, 'user_id' => $this->partner->id]);
        $this->assertDatabaseMissing('event_participants', ['event_id' => $eventId, 'user_id' => $this->guest->id]);
        $this->assertGreaterThan(0, DB::table('event_reminders')->where('user_id', $this->partner->id)->count());
        $this->assertSame(0, DB::table('event_reminders')->where('user_id', $this->guest->id)->count());
    }
}
