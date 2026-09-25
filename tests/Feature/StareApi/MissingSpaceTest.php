<?php

namespace Tests\Feature\StareApi;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nález 7: účet bez prostoru dostane čistou 403 JSON, ne pád na `null->id`.
 */
class MissingSpaceTest extends TestCase
{
    use RefreshDatabase;

    private User $bezProstoru;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bezProstoru = User::factory()->create();
        Sanctum::actingAs($this->bezProstoru);
    }

    public function test_hledani_vraci_403_bez_prostoru(): void
    {
        $this->getJson('/api/v1/search')->assertStatus(403)->assertJsonStructure(['message']);
    }

    public function test_casova_osa_vraci_403_bez_prostoru(): void
    {
        $this->getJson('/api/v1/timeline')->assertStatus(403);
    }

    public function test_ulozena_hledani_vraci_403_bez_prostoru(): void
    {
        $this->postJson('/api/v1/saved-searches', ['name' => 'Test', 'filters_json' => ['q' => 'test']])->assertStatus(403);
    }
}
