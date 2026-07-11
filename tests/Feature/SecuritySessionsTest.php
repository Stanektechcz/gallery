<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecuritySessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_revoke_only_their_other_sessions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 'own-other-session', 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Mozilla Android', 'payload' => '', 'last_activity' => now()->timestamp],
            ['id' => 'foreign-session', 'user_id' => $other->id, 'ip_address' => '127.0.0.2', 'user_agent' => 'Mozilla Windows', 'payload' => '', 'last_activity' => now()->timestamp],
        ]);
        $this->actingAs($user)->deleteJson('/settings/security/sessions/own-other-session')->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'own-other-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'foreign-session']);
    }
}
