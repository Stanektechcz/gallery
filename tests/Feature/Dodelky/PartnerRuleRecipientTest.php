<?php

namespace Tests\Feature\Dodelky;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Pravidlo sdílení s partnerem smí mít jako příjemce jen člena dvojice, ne hosta prostoru.
 */
class PartnerRuleRecipientTest extends TestCase
{
    use DvojiceSHostem, RefreshDatabase;

    public function test_guest_recipient_is_rejected(): void
    {
        [$vlastnik, $partner, $host, $prostor] = $this->dvojiceSHostem();

        $this->actingAs($vlastnik)->postJson('/api/v1/calendar/partner-rules', [
            'gallery_space_id' => $prostor->id, 'recipient_user_id' => $host->id, 'name' => 'Fotky pro hosta',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('partner_share_rules', 0);
    }

    public function test_couple_member_recipient_is_accepted(): void
    {
        [$vlastnik, $partner, $host, $prostor] = $this->dvojiceSHostem();

        $this->actingAs($vlastnik)->postJson('/api/v1/calendar/partner-rules', [
            'gallery_space_id' => $prostor->id, 'recipient_user_id' => $partner->id, 'name' => 'Fotky pro partnera',
        ])->assertCreated();
    }
}
