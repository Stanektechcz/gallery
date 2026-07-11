<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_job_is_owned_by_its_creator_and_cannot_be_inspected_by_another_user(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $jobId = $this->actingAs($owner)->postJson('/api/v1/exports', ['type' => 'selection', 'media_ids' => []])->assertAccepted()->json('job_id');

        $this->assertSame($owner->id, Cache::get("export_owner_{$jobId}"));
        $this->actingAs($owner)->getJson("/api/v1/exports/{$jobId}")->assertOk()->assertJsonPath('job_id', $jobId);
        $this->actingAs($other)->getJson("/api/v1/exports/{$jobId}")->assertNotFound();
    }
}
