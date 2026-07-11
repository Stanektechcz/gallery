<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvitationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_invitation_is_mailed_and_tracks_the_real_inviter(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->post('/admin/users/invite', [
            'name' => 'Partnerka', 'email' => 'partnerka@example.test', 'role' => 'partner',
        ])->assertRedirect();

        $invitee = User::where('email', 'partnerka@example.test')->firstOrFail();
        $this->assertSame($admin->id, $invitee->invited_by_user_id);
        $this->assertNotEmpty($invitee->invitation_token);
        Notification::assertSentTo($invitee, InvitationNotification::class);
    }
}
