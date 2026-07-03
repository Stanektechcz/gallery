<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\SharedLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function test_login_page_is_accessible(): void
    {
        $this->get('/login')->assertOk();
    }

    /** @test */
    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'email'     => 'test@gallery.local',
            'password'  => \Hash::make('securepassword123'),
            'is_active' => true,
        ]);

        $response = $this->post('/login', [
            'email'    => 'test@gallery.local',
            'password' => 'securepassword123',
        ]);

        $response->assertRedirect('/timeline');
        $this->assertAuthenticatedAs($user);
    }

    /** @test */
    public function test_wrong_password_fails_login(): void
    {
        User::factory()->create(['email' => 'test@gallery.local', 'password' => \Hash::make('correct')]);

        $this->post('/login', ['email' => 'test@gallery.local', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
    }

    /** @test */
    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'email'     => 'inactive@gallery.local',
            'password'  => \Hash::make('password'),
            'is_active' => false,
        ]);

        $this->post('/login', ['email' => 'inactive@gallery.local', 'password' => 'password'])
            ->assertSessionHasErrors('email');
    }

    /** @test */
    public function test_user_can_logout(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    /** @test */
    public function test_timeline_requires_auth(): void
    {
        $this->get('/timeline')->assertRedirect('/login');
    }

    /** @test */
    public function test_health_endpoints_are_public(): void
    {
        $this->get('/health/live')->assertOk();
        $this->get('/health/ready')->assertOk(); // May return 503 if DB is not ready, but still accessible
    }
}
