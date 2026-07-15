<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SharedTodoSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_todos_support_assignment_recurrence_subtasks_comments_calendar_and_partner_pulse(): void
    {
        [$owner, $partner, $space] = $this->couple();
        $due = now()->addDays(2)->setTime(18, 0);
        $todo = $this->actingAs($owner)->postJson('/api/v1/todos', [
            'gallery_space_id' => $space->id, 'title' => 'Naplánovat víkend', 'description' => 'Vybereme trasu a ubytování.',
            'assigned_to' => $partner->id, 'priority' => 'high', 'due_at' => $due->toIso8601String(),
            'remind_at' => $due->copy()->subDay()->toIso8601String(), 'recurrence' => ['frequency' => 'weekly', 'interval' => 1],
            'create_calendar_event' => true,
        ])->assertCreated()->assertJsonPath('title', 'Naplánovat víkend')->assertJsonPath('assignee.id', $partner->id)->json();

        $this->assertDatabaseHas('calendar_events', ['type' => 'todo', 'gallery_space_id' => $space->id]);
        $this->assertDatabaseCount('event_participants', 2);
        $this->actingAs($partner)->postJson('/api/v1/todos/' . $todo['uuid'] . '/comments', ['body' => 'Zařídím dopravu.'])
            ->assertCreated()->assertJsonPath('body', 'Zařídím dopravu.');
        $this->postJson('/api/v1/todos', [
            'gallery_space_id' => $space->id, 'parent_uuid' => $todo['uuid'], 'list_uuid' => $todo['list']['uuid'], 'title' => 'Porovnat vlak a autobus',
        ])->assertCreated();

        $index = $this->getJson('/api/v1/todos?gallery_space_id=' . $space->id)->assertOk()
            ->assertJsonPath('summary.active', 2)->assertJsonPath('tasks.0.children.0.title', 'Porovnat vlak a autobus');
        $pulse = $this->getJson('/api/v1/coordination/pulse?gallery_space_id=' . $space->id)->assertOk();
        $this->assertTrue(collect($pulse->json('actions'))->contains(fn ($action) => $action['type'] === 'shared_todo' && $action['source_key'] === $todo['uuid']));

        $this->patchJson('/api/v1/coordination/actions/shared_todo/' . $todo['uuid'], [
            'gallery_space_id' => $space->id, 'completed' => true,
        ])->assertOk();
        $this->assertDatabaseHas('shared_todos', ['uuid' => $todo['uuid'], 'status' => 'completed']);
        $this->assertSame(2, DB::table('shared_todos')->where('series_uuid', $todo['series_uuid'])->count());
        $this->assertDatabaseHas('shared_todos', ['series_uuid' => $todo['series_uuid'], 'status' => 'open']);
        $this->assertNotNull($index->json('tasks.0.href'));
    }

    public function test_outsiders_and_read_only_members_cannot_change_todos(): void
    {
        [$owner, $partner, $space] = $this->couple(); $outsider = User::factory()->create();
        $this->actingAs($outsider)->postJson('/api/v1/todos', ['gallery_space_id' => $space->id, 'title' => 'Cizí'])->assertNotFound();
        $partner->update(['read_only_mode' => true]);
        $this->actingAs($partner)->postJson('/api/v1/todos', ['gallery_space_id' => $space->id, 'title' => 'Zakázaný'])->assertForbidden();
    }

    private function couple(): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]); $partner = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $space = GallerySpace::create(['name' => 'Náš prostor', 'slug' => 'todo-couple', 'owner_id' => $owner->id, 'is_default' => true]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $space->members()->attach($partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        return [$owner, $partner, $space];
    }
}
