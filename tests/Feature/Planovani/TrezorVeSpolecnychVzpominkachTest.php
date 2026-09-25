<?php

namespace Tests\Feature\Planovani;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fotka z trezoru nepatří do nic ze sdíleného — do společné vzpomínky,
 * milníku, alba z večera se vzpomínkami ani do akce „vrátit se na místo".
 * Stejně jako fotokniha: sdílené zůstane čitelné i po zamčení trezoru.
 */
class TrezorVeSpolecnychVzpominkachTest extends TestCase
{
    use DvojiceSHostem;
    use RefreshDatabase;

    public function test_vecer_se_vzpominkami_nevezme_fotku_presunutou_do_trezoru(): void
    {
        Queue::fake();
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $tajna = $this->fotka($prostor, $vlastnik, 'tajna.jpg', ['taken_at' => now()->subYears(2)->setTime(10, 0)]);
        $bezna = $this->fotka($prostor, $vlastnik, 'bezna.jpg', ['taken_at' => now()->subYears(2)->setTime(12, 0)]);

        $vecer = $this->actingAs($vlastnik)->postJson('/api/v1/memory-evenings', [
            'gallery_space_id' => $prostor->id, 'fingerprint' => hash('sha256', 'trezor'), 'source_type' => 'on_this_day',
            'title' => 'Před dvěma lety', 'scheduled_for' => now()->addWeek()->toIso8601String(),
            'media_uuids' => [$tajna->uuid, $bezna->uuid],
        ])->assertCreated()->json();

        // Mezi naplánováním a dokončením fotku někdo uklidil do trezoru.
        $tajna->update(['is_hidden' => true]);

        $hotovo = $this->postJson('/api/v1/memory-evenings/'.$vecer['uuid'].'/complete')->assertOk()->json();
        $albumId = (int) DB::table('albums')->where('uuid', $hotovo['album']['uuid'])->value('id');

        $this->assertDatabaseMissing('album_media', ['album_id' => $albumId, 'media_item_id' => $tajna->id]);
        $this->assertDatabaseHas('album_media', ['album_id' => $albumId, 'media_item_id' => $bezna->id]);
        $this->assertNotSame($tajna->id, (int) DB::table('albums')->where('id', $albumId)->value('cover_media_id'));
        $this->assertDatabaseMissing('event_attachments', ['media_item_id' => $tajna->id]);
        $ids = json_decode((string) DB::table('shared_memory_moments')->where('uuid', $hotovo['shared_memory']['uuid'])->value('media_item_ids'), true);
        $this->assertNotContains($tajna->id, $ids);
    }

    public function test_spolecna_vzpominka_neprijme_fotku_z_trezoru(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $tajna = $this->fotka($prostor, $vlastnik, 'tajna.jpg', ['is_hidden' => true]);

        $this->sOdemcenymTrezorem($vlastnik)->postJson('/api/v1/shared-memory-moments', [
            'gallery_space_id' => $prostor->id, 'title' => 'Náš den', 'media_item_ids' => [$tajna->id],
        ])->assertStatus(422);

        $this->assertDatabaseCount('shared_memory_moments', 0);
    }

    public function test_spolecna_vzpominka_nevypise_fotku_ktera_je_v_trezoru(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $tajna = $this->fotka($prostor, $vlastnik, 'tajna.jpg', ['is_hidden' => true]);
        $bezna = $this->fotka($prostor, $vlastnik, 'bezna.jpg');
        DB::table('shared_memory_moments')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $prostor->id, 'created_by' => $vlastnik->id,
            'title' => 'Starší vzpomínka', 'media_item_ids' => json_encode([$tajna->id, $bezna->id]),
            'is_favorite' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $odpoved = $this->actingAs($partner)->getJson('/api/v1/shared-memory-moments')->assertOk();

        $this->assertSame([$bezna->uuid], collect($odpoved->json('0.media'))->pluck('uuid')->all());
        $this->assertStringNotContainsString($tajna->uuid, (string) $odpoved->getContent());
    }

    public function test_milnik_neprijme_ani_neukaze_fotku_z_trezoru(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();
        $tajna = $this->fotka($prostor, $vlastnik, 'tajna.jpg', ['is_hidden' => true]);

        $this->sOdemcenymTrezorem($vlastnik)->postJson('/api/v1/relationship-milestones', [
            'gallery_space_id' => $prostor->id, 'title' => 'První byt', 'occurred_on' => '2025-05-01', 'media_item_id' => $tajna->id,
        ])->assertNotFound();

        DB::table('relationship_milestones')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $prostor->id, 'created_by' => $vlastnik->id,
            'title' => 'Starší milník', 'occurred_on' => '2025-05-01', 'visibility' => 'shared', 'remind_annually' => true,
            'media_item_id' => $tajna->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $odpoved = $this->actingAs($partner)->getJson('/api/v1/relationship-milestones')->assertOk();
        $this->assertNull($odpoved->json('0.media'));
        $this->assertStringNotContainsString($tajna->uuid, (string) $odpoved->getContent());

        $uuid = $odpoved->json('0.uuid');
        $this->postJson('/api/v1/relationship-milestones/'.$uuid.'/celebration', ['starts_at' => now()->addMonth()->toIso8601String()])->assertCreated();
        $this->assertDatabaseMissing('event_attachments', ['media_item_id' => $tajna->id]);
    }

    public function test_navrat_na_misto_nenabidne_ani_nenaplanuje_fotku_z_trezoru(): void
    {
        [$vlastnik, , , $prostor] = $this->dvojiceSHostem();
        $zdroj = $this->fotka($prostor, $vlastnik, 'most.jpg', ['latitude' => 50.0865, 'longitude' => 14.4114]);
        $tajna = $this->fotka($prostor, $vlastnik, 'tajna.jpg', ['latitude' => 50.0866, 'longitude' => 14.4115, 'is_hidden' => true]);

        $odpoved = $this->actingAs($vlastnik)->getJson('/api/v1/media/'.$zdroj->uuid.'/revisit-suggestions')->assertOk();
        $this->assertStringNotContainsString($tajna->uuid, (string) $odpoved->getContent());

        $this->sOdemcenymTrezorem($vlastnik)
            ->postJson('/api/v1/media/'.$tajna->uuid.'/revisit-suggestions', ['starts_at' => now()->addMonth()->toIso8601String()])
            ->assertStatus(422);
        $this->assertDatabaseMissing('event_attachments', ['media_item_id' => $tajna->id]);
    }
}
