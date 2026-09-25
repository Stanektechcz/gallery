<?php

namespace Tests\Feature\Dodelky;

use App\Models\CycleSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Menstruační kalendář ukazuje partnerovy záznamy jen dvojici — host prostoru
 * (viewer/contributor) není partner a jeho vlastní záznamy tam nepatří.
 */
class CyklusPartnerTest extends TestCase
{
    use DvojiceSHostem, RefreshDatabase;

    public function test_guest_never_appears_as_partner_in_cycle_overview(): void
    {
        [$vlastnik, $partner, $host, $prostor] = $this->dvojiceSHostem();
        CycleSetting::create(['gallery_space_id' => $prostor->id, 'user_id' => $partner->id, 'share_level' => 'full']);
        CycleSetting::create(['gallery_space_id' => $prostor->id, 'user_id' => $host->id, 'share_level' => 'full']);

        $response = $this->actingAs($vlastnik)->getJson('/api/v1/cyklus')->assertOk();
        $partnerIds = collect($response->json('partners'))->pluck('owner.id');

        $this->assertTrue($partnerIds->contains($partner->id));
        $this->assertFalse($partnerIds->contains($host->id));
    }
}
