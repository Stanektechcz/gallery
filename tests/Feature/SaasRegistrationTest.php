<?php

namespace Tests\Feature;

use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Support\SpaceContext;
use Database\Seeders\BillingCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaasRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BillingCatalogSeeder::class);
        config()->set('gallery.registration_open', true);
    }

    public function test_registration_creates_an_account_a_space_and_the_default_plan(): void
    {
        $this->post('/registrace', [
            'name' => 'Jan Novák',
            'email' => 'jan@example.cz',
            'space_name' => 'Naše vzpomínky',
            'password' => 'tajneheslo1',
            'password_confirmation' => 'tajneheslo1',
        ])->assertRedirect('/');

        $user = User::where('email', 'jan@example.cz')->firstOrFail();
        $this->assertAuthenticatedAs($user);

        $space = GallerySpace::where('owner_id', $user->id)->firstOrFail();
        $this->assertSame('Naše vzpomínky', $space->name);
        $this->assertSame(1, $space->members()->count());

        // The default plan is attached automatically.
        $this->assertSame('duo', app(EntitlementService::class)->plan($space)?->code);
    }

    public function test_registration_is_refused_while_it_is_closed(): void
    {
        config()->set('gallery.registration_open', false);

        $this->get('/registrace')->assertRedirect('/login');
        $this->post('/registrace', [
            'name' => 'Kdokoliv', 'email' => 'kdo@example.cz', 'space_name' => 'X',
            'password' => 'tajneheslo1', 'password_confirmation' => 'tajneheslo1',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'kdo@example.cz']);
    }

    public function test_two_slugs_never_collide(): void
    {
        foreach (['a@example.cz', 'b@example.cz'] as $email) {
            $this->post('/registrace', [
                'name' => 'Kdokoliv', 'email' => $email, 'space_name' => 'Naše vzpomínky',
                'password' => 'tajneheslo1', 'password_confirmation' => 'tajneheslo1',
            ])->assertRedirect('/');
            $this->post('/logout');
        }

        $this->assertSame(2, GallerySpace::whereIn('slug', ['nase-vzpominky', 'nase-vzpominky-2'])->count());
    }

    public function test_a_fresh_customer_sees_none_of_an_existing_customers_data(): void
    {
        // An established space with a photo in it.
        $veteran = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $veteranSpace = GallerySpace::create(['name' => 'Starousedlíci', 'slug' => 'starousedlici', 'owner_id' => $veteran->id, 'is_default' => true]);
        $veteranSpace->members()->attach($veteran->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        MediaItem::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $veteranSpace->id,
            'owner_user_id' => $veteran->id, 'uploaded_by' => $veteran->id,
            'original_filename' => 'stara.jpg', 'safe_filename' => 'stara.jpg', 'extension' => 'jpg',
            'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 2048,
            'status' => 'ready', 'storage_status' => 'ready', 'taken_at' => now(),
        ]);

        $this->post('/registrace', [
            'name' => 'Nováček', 'email' => 'novacek@example.cz', 'space_name' => 'Začínáme',
            'password' => 'tajneheslo1', 'password_confirmation' => 'tajneheslo1',
        ])->assertRedirect('/');

        // The cached space ids from before the space existed must not leak through.
        SpaceContext::forget();
        $this->assertSame(0, MediaItem::count(), 'A new customer must start with an empty gallery.');
    }

    public function test_onboarding_reflects_real_state_and_can_be_dismissed(): void
    {
        $this->post('/registrace', [
            'name' => 'Nováček', 'email' => 'novacek@example.cz', 'space_name' => 'Začínáme',
            'password' => 'tajneheslo1', 'password_confirmation' => 'tajneheslo1',
        ])->assertRedirect('/');
        SpaceContext::forget();

        $user = User::where('email', 'novacek@example.cz')->firstOrFail();
        $space = GallerySpace::where('owner_id', $user->id)->firstOrFail();

        $checklist = $this->getJson('/api/v1/onboarding')->assertOk()->json();
        $this->assertTrue($checklist['visible']);
        // Naming the space is done by registering; the rest is not.
        $this->assertTrue(collect($checklist['steps'])->firstWhere('key', 'space')['done']);
        $this->assertFalse(collect($checklist['steps'])->firstWhere('key', 'media')['done']);
        $this->assertSame(3, $checklist['remaining']);

        // Uploading a photo elsewhere ticks the step off without any client-side flag.
        MediaItem::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $space->id,
            'owner_user_id' => $user->id, 'uploaded_by' => $user->id,
            'original_filename' => 'prvni.jpg', 'safe_filename' => 'prvni.jpg', 'extension' => 'jpg',
            'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1024,
            'status' => 'ready', 'storage_status' => 'ready', 'taken_at' => now(),
        ]);
        $this->assertTrue(collect($this->getJson('/api/v1/onboarding')->json('steps'))->firstWhere('key', 'media')['done']);

        $this->postJson('/api/v1/onboarding/dismiss')->assertOk();
        $this->assertFalse($this->getJson('/api/v1/onboarding')->json('visible'));
    }

    public function test_the_member_limit_of_the_plan_is_enforced_on_invitations(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $partner = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $space = GallerySpace::create(['name' => 'Dvojka', 'slug' => 'dvojka', 'owner_id' => $owner->id, 'is_default' => true]);
        foreach ([$owner, $partner] as $member) {
            $space->members()->attach($member->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        }
        app(EntitlementService::class)->assignPlan($space, BillingPlan::where('code', 'duo')->firstOrFail());

        // Duo allows two members and both seats are taken.
        $this->actingAs($owner)->post('/admin/users/invite', [
            'name' => 'Třetí', 'email' => 'treti@example.cz', 'role' => 'viewer',
        ])->assertStatus(402);

        $this->assertDatabaseMissing('users', ['email' => 'treti@example.cz']);

        // On a bigger plan the same invitation goes through.
        app(EntitlementService::class)->assignPlan($space, BillingPlan::where('code', 'rodina')->firstOrFail());
        $this->post('/admin/users/invite', [
            'name' => 'Třetí', 'email' => 'treti@example.cz', 'role' => 'viewer',
        ])->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'treti@example.cz']);
    }

    public function test_an_upload_beyond_the_plan_storage_is_refused_before_any_chunk(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $space = GallerySpace::create(['name' => 'Malý', 'slug' => 'maly', 'owner_id' => $owner->id, 'is_default' => true]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);

        // A one-megabyte plan makes the arithmetic obvious.
        $plan = BillingPlan::create(['code' => 'tiny', 'name' => 'Tiny', 'price_monthly' => 0, 'storage_limit_mb' => 1, 'member_limit' => 2]);
        app(EntitlementService::class)->assignPlan($space, $plan);

        $this->actingAs($owner)->postJson('/api/v1/uploads', [
            'filename' => 'velka.jpg', 'mime_type' => 'image/jpeg',
            'total_size' => 5 * 1024 * 1024, 'total_chunks' => 5,
        ])->assertStatus(402);

        // Something that fits is still accepted.
        $this->postJson('/api/v1/uploads', [
            'filename' => 'mala.jpg', 'mime_type' => 'image/jpeg',
            'total_size' => 100 * 1024, 'total_chunks' => 1,
        ])->assertSuccessful();
    }
}
