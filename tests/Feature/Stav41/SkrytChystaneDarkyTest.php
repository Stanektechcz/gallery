<?php

namespace Tests\Feature\Stav41;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Starší chystané dárky z prototypu se zpětně schovají i pro kalendář.
 *
 * Převodník psal jen `private_to_user_id`, `visibility` zůstalo „shared" —
 * a podle toho čte kalendář dárků, rozpočty i koordinace dvojice.
 */
class SkrytChystaneDarkyTest extends TestCase
{
    use RefreshDatabase;

    public function test_nakup_s_vlastnikem_soukromi_se_schova(): void
    {
        $adri = User::factory()->create(['name' => 'Adrian']);
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $adri->id]);

        $schovany = $this->darek($prostor, $adri, ['private_to_user_id' => $adri->id, 'visibility' => 'shared', 'status' => 'reserved']);
        $verejny = $this->darek($prostor, $adri, ['private_to_user_id' => null, 'visibility' => 'shared', 'status' => 'wish']);

        (require database_path('migrations/2026_09_27_110000_skryt_chystane_darky.php'))->up();

        $this->assertSame('private', DB::table('gift_ideas')->where('uuid', $schovany)->value('visibility'));
        $this->assertSame('shared', DB::table('gift_ideas')->where('uuid', $verejny)->value('visibility'));
    }

    private function darek(GallerySpace $prostor, User $kdo, array $navic): string
    {
        $uuid = (string) Str::uuid();

        DB::table('gift_ideas')->insert(array_merge([
            'uuid' => $uuid,
            'gallery_space_id' => $prostor->id,
            'created_by' => $kdo->id,
            'title' => 'Dárek',
            'budget' => 1000,
            'currency' => 'CZK',
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));

        return $uuid;
    }
}
