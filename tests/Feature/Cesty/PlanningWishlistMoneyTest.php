<?php

namespace Tests\Feature\Cesty;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cestovní přání — `travel_wishlist_items.estimated_cost` je `decimal(12,2)`
 * a z MySQL by dorazilo jako řetězec. Testy tu na SQLite projdou i bez
 * převodu; MySQL v CI teprve prokáže, že kontroler přetypoval.
 */
class PlanningWishlistMoneyTest extends CestyTestCase
{
    public function test_ulozena_polozka_prani_vraci_odhad_jako_cislo(): void
    {
        $uuid = (string) Str::uuid();
        DB::table('travel_wishlists')->insert([
            'uuid' => $uuid, 'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'title' => 'Rakousko', 'is_shared' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $polozka = $this->postJson("/api/v1/calendar/wishlists/{$uuid}/items", [
            'title' => 'Vídeňský hrad', 'estimated_cost' => 450,
        ])->assertCreated()->json();

        $this->assertIsNotString($polozka['estimated_cost']);
        $this->assertEquals(450, $polozka['estimated_cost']);
    }

    public function test_seznam_prani_vraci_odhady_jako_cisla(): void
    {
        $uuid = (string) Str::uuid();
        $listId = DB::table('travel_wishlists')->insertGetId([
            'uuid' => $uuid, 'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'title' => 'Rakousko', 'is_shared' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('travel_wishlist_items')->insert([
            'wishlist_id' => $listId, 'created_by' => $this->owner->id, 'title' => 'Muzeum',
            'category' => 'place', 'priority' => 3, 'estimated_cost' => 220, 'currency' => 'CZK',
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->getJson('/api/v1/calendar/wishlists')->assertOk()->json();

        $this->assertIsNotString($data[0]['items'][0]['estimated_cost']);
        $this->assertEquals(220, $data[0]['items'][0]['estimated_cost']);
    }
}
