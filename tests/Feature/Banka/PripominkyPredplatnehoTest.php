<?php

namespace Tests\Feature\Banka;

use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\SpaceSubscription;
use App\Models\User;
use App\Notifications\GalleryNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Připomínka konce předplatného jde vlastníkovi prostoru.
 *
 * `users.role` není oprávnění — `owner` má každý zaregistrovaný účet. Dřív
 * tak upozornění mohl dostat host, který galerii jen prohlíží.
 */
class PripominkyPredplatnehoTest extends TestCase
{
    use RefreshDatabase;

    public function test_pripominka_jde_jen_vlastnikovi_prostoru(): void
    {
        Notification::fake();
        $vlastnik = User::factory()->create(['role' => 'admin']);
        $host = User::factory()->create(['role' => 'owner']);
        $prostor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Galerie', 'slug' => 'galerie-predplatne', 'owner_id' => $vlastnik->id]);
        $prostor->members()->attach($host->id, ['role' => 'viewer', 'joined_at' => now()->subDays(10)]);
        $prostor->members()->attach($vlastnik->id, ['role' => 'owner', 'joined_at' => now()->subDays(5)]);
        $plan = BillingPlan::create(['code' => 'test-pripominky', 'name' => 'Rodinný', 'price_monthly' => 199, 'storage_limit_mb' => 50000]);
        SpaceSubscription::create(['gallery_space_id' => $prostor->id, 'billing_plan_id' => $plan->id, 'status' => 'active',
            'started_at' => now()->subMonth(), 'ends_at' => now()->addDays(3)]);

        $this->artisan('gallery:billing-reminders')->assertSuccessful();

        Notification::assertSentTo($vlastnik, GalleryNotification::class);
        Notification::assertNotSentTo($host, GalleryNotification::class);
    }
}
