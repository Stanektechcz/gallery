<?php

namespace Tests\Feature\Media;

use App\Services\Media\MemoryDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Vzpomínky z `MemoryDiscoveryService` — bez trezoru a podle pražského dne.
 */
class MemoryDiscoveryTest extends TestCase
{
    use RefreshDatabase;
    use VytvariMedia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->zalozProstor();
    }

    private function vsechnyUuid(Collection $karty): array
    {
        return $karty->flatMap(fn (array $karta) => collect($karta['items'])->pluck('uuid'))->all();
    }

    public function test_fotka_z_trezoru_se_neukaze_ve_vyroci_cesty(): void
    {
        $this->travelTo('2026-09-25 12:00:00');

        $viditelna = $this->media(['taken_at' => '2025-09-20 10:00:00']);
        $trezor = $this->media(['taken_at' => '2025-09-20 11:00:00', 'is_hidden' => true]);
        $smazana = $this->media(['taken_at' => '2025-09-20 12:00:00', 'status' => 'pending']);

        $cesta = DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'name' => 'Vídeň',
            'start_date' => '2025-09-20',
            'end_date' => '2025-09-22',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([$viditelna, $trezor, $smazana] as $m) {
            DB::table('trip_media')->insert(['trip_id' => $cesta, 'media_item_id' => $m->id]);
        }

        $karty = app(MemoryDiscoveryService::class)->discover($this->adri);

        $vyroci = $karty->firstWhere('type', 'trip_anniversary');
        $this->assertNotNull($vyroci, 'Výročí cesty s viditelnou fotkou se má ukázat.');
        $this->assertSame([$viditelna->uuid], collect($vyroci['items'])->pluck('uuid')->all());
        $this->assertNotContains($trezor->uuid, $this->vsechnyUuid($karty), 'Fotka z trezoru nesmí být v žádné vzpomínce.');
    }

    public function test_misto_s_fotkami_jen_v_trezoru_nevytlaci_misto_s_viditelnymi(): void
    {
        $this->travelTo('2026-09-25 12:00:00');

        $schovane = DB::table('places')->insertGetId(['name' => 'Tajné', 'gallery_space_id' => $this->prostor->id, 'created_at' => now(), 'updated_at' => now()]);
        $viditelne = DB::table('places')->insertGetId(['name' => 'Brno', 'gallery_space_id' => $this->prostor->id, 'created_at' => now(), 'updated_at' => now()]);

        foreach (range(1, 3) as $i) {
            $m = $this->media(['taken_at' => '2025-01-0'.$i.' 10:00:00', 'is_hidden' => true]);
            DB::table('media_place')->insert(['media_item_id' => $m->id, 'place_id' => $schovane]);
        }
        $vyhozena = $this->media(['taken_at' => '2025-01-05 10:00:00']);
        $vyhozena->delete();
        DB::table('media_place')->insert(['media_item_id' => $vyhozena->id, 'place_id' => $schovane]);

        $brno = $this->media(['taken_at' => '2025-02-01 10:00:00']);
        DB::table('media_place')->insert(['media_item_id' => $brno->id, 'place_id' => $viditelne]);

        $karta = app(MemoryDiscoveryService::class)->discover($this->adri)->firstWhere('type', 'place_flashback');

        $this->assertNotNull($karta, 'Místo s viditelnou fotkou má dostat kartu.');
        $this->assertSame('Zpátky na místě Brno', $karta['title']);
        $this->assertSame('1 zachycených okamžiků', $karta['subtitle']);
    }

    public function test_tento_den_plati_podle_prahy_ne_utc(): void
    {
        // 23:30 UTC 1. července je v Praze už 2. července 1:30.
        $this->travelTo('2026-07-01 23:30:00');

        $fotka = $this->media(['taken_at' => '2025-07-02 09:00:00']);

        $karta = app(MemoryDiscoveryService::class)->discover($this->adri)->firstWhere('type', 'on_this_day');

        $this->assertNotNull($karta, 'Fotka z 2. 7. 2025 je „před rokem" už po pražské půlnoci.');
        $this->assertSame([$fotka->uuid], collect($karta['items'])->pluck('uuid')->all());
    }
}
