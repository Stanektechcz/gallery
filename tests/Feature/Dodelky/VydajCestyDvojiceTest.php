<?php

namespace Tests\Feature\Dodelky;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Výdaj z cesty se dělí jen mezi dvojici — host prostoru (viewer) není plátce
 * ani člen automatického rovného podílu.
 */
class VydajCestyDvojiceTest extends TestCase
{
    use DvojiceSHostem, RefreshDatabase;

    public function test_equal_split_excludes_guest_and_covers_only_couple(): void
    {
        [$vlastnik, $partner, $host, $prostor] = $this->dvojiceSHostem();
        $tripId = DB::table('trips')->insertGetId([
            'gallery_space_id' => $prostor->id, 'created_by' => $vlastnik->id, 'name' => 'Výlet', 'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(), 'status' => 'planned', 'timezone' => 'Europe/Prague', 'currency' => 'CZK',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($vlastnik)->postJson("/api/v1/trips/{$tripId}/expenses", [
            'title' => 'Večeře', 'amount' => 900, 'payment_source' => 'personal', 'paid_by_user_id' => $vlastnik->id,
        ])->assertCreated();
        $split = json_decode($response->json('split'), true);
        $this->assertCount(2, $split);
        foreach ($split as $share) {
            $this->assertSame(450.0, (float) $share['amount']);
            $this->assertNotSame($host->id, (int) $share['user_id']);
        }
    }

    public function test_guest_cannot_be_set_as_payer(): void
    {
        [$vlastnik, $partner, $host, $prostor] = $this->dvojiceSHostem();
        $tripId = DB::table('trips')->insertGetId([
            'gallery_space_id' => $prostor->id, 'created_by' => $vlastnik->id, 'name' => 'Výlet', 'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(), 'status' => 'planned', 'timezone' => 'Europe/Prague', 'currency' => 'CZK',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($vlastnik)->postJson("/api/v1/trips/{$tripId}/expenses", [
            'title' => 'Večeře', 'amount' => 900, 'payment_source' => 'personal', 'paid_by_user_id' => $host->id,
        ])->assertUnprocessable();
    }
}
