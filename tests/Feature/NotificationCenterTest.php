<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use App\Notifications\GalleryNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $partner;
    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->partner = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $this->space = GallerySpace::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Pro nás dva', 'slug' => 'pro-nas-dva',
            'owner_id' => $this->owner->id, 'is_default' => true,
        ]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->space->members()->attach($this->partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
    }

    public function test_partner_notification_center_prioritizes_and_manages_notifications_safely(): void
    {
        $this->owner->notify(new GalleryNotification(
            'calendar.task.assigned',
            'Markétka ti přiřadila společný úkol.',
            '/calendar/events/event-1',
            '✅',
            ['event_uuid' => 'event-1', 'actor_user_id' => $this->partner->id]
        ));
        $this->owner->notify(new GalleryNotification('upload.complete', 'Fotografie jsou připravené.', '/timeline'));

        $response = $this->actingAs($this->owner)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.unread', 2)
            ->assertJsonPath('meta.important', 1)
            ->assertJsonPath('data.0.category', 'planning')
            ->assertJsonPath('data.0.priority', 'high')
            ->assertJsonPath('data.0.context_key', 'event:event-1');
        $importantId = $response->json('data.0.id');
        $this->getJson('/api/v1/notifications?category=memories')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.priority', 'low');

        $this->actingAs($this->partner)->postJson("/api/v1/notifications/{$importantId}/archive")->assertNotFound();
        $this->actingAs($this->owner)->postJson("/api/v1/notifications/{$importantId}/snooze", ['minutes' => 60])
            ->assertOk()->assertJsonPath('status', 'snoozed');
        $this->getJson('/api/v1/notifications?focus=important')->assertOk()->assertJsonCount(0, 'data');

        $this->travel(61)->minutes();
        $this->getJson('/api/v1/notifications?focus=important')->assertOk()->assertJsonPath('data.0.id', $importantId);
        $this->postJson("/api/v1/notifications/{$importantId}/archive")->assertOk();
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonMissing(['id' => $importantId]);

        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('count', 1);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.unread', 0);
    }

    public function test_personal_preferences_filter_categories_but_never_hide_critical_security_events(): void
    {
        $this->travelTo(now()->startOfDay()->setTime(23, 0));
        $preferences = $this->actingAs($this->owner)->patchJson('/api/v1/notifications/preferences', [
            'categories' => ['planning' => false, 'memories' => false, 'system' => false],
            'quiet' => ['enabled' => true, 'from' => '22:00', 'to' => '07:00'],
            'browser_notifications' => true,
        ])->assertOk()
            ->assertJsonPath('preferences.categories.planning', false)
            ->assertJsonPath('preferences.categories.memories', false)
            ->assertJsonPath('preferences.categories.system', true)
            ->assertJsonPath('quiet_now', true)
            ->json('preferences');
        $this->assertTrue($preferences['browser_notifications']);
        $this->assertSame(['from' => '22:00', 'to' => '07:00'], $this->owner->fresh()->preferences['quiet_hours']);

        $this->owner->notify(new GalleryNotification('calendar.task.assigned', 'Skrytý běžný plán.'));
        $this->owner->notify(new GalleryNotification('media.added', 'Skrytá nová fotografie.'));
        $this->owner->notify(new GalleryNotification('security.failed', 'Byl zaznamenán kritický problém zabezpečení.'));

        $this->assertSame(1, $this->owner->notifications()->count());
        $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category', 'system')
            ->assertJsonPath('data.0.priority', 'critical')
            ->assertJsonPath('meta.critical', 1)
            ->assertJsonPath('meta.quiet_now', true);
    }

    public function test_notification_preferences_are_personal_to_each_partner(): void
    {
        $this->actingAs($this->partner)->patchJson('/api/v1/notifications/preferences', [
            'categories' => ['finance' => false],
            'priority_floor' => 'high',
        ])->assertOk();

        $this->actingAs($this->owner)->getJson('/api/v1/notifications/preferences')->assertOk()
            ->assertJsonPath('preferences.categories.finance', true)
            ->assertJsonPath('preferences.priority_floor', 'low');
        $this->actingAs($this->partner)->getJson('/api/v1/notifications/preferences')->assertOk()
            ->assertJsonPath('preferences.categories.finance', false)
            ->assertJsonPath('preferences.priority_floor', 'high');
    }

    public function test_shared_space_notification_reaches_only_the_other_partner_with_source_context(): void
    {
        GalleryNotification::notifySpace(
            $this->space,
            $this->owner->id,
            'finance.imported',
            'Adrian doplnil společný finanční přehled.',
            '/finances',
            ['import_uuid' => 'import-123', 'rows_imported' => 36]
        );

        $this->assertSame(0, $this->owner->notifications()->count());
        $this->actingAs($this->partner)->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category', 'finance')
            ->assertJsonPath('data.0.context_key', 'finance-import:import-123')
            ->assertJsonPath('data.0.data.extra.actor_user_id', $this->owner->id)
            ->assertJsonPath('data.0.data.extra.gallery_space_id', $this->space->id);
    }
}
