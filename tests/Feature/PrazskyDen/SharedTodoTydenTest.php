<?php

namespace Tests\Feature\PrazskyDen;

use App\Models\GallerySpace;
use App\Models\SharedTodo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `completed_at` je skutečný okamžik v UTC, ale „tento týden" se dřív počítal
 * podle týdne UTC serveru — těsně po pražské půlnoci v pondělí tak ještě
 * zahrnoval celý předchozí (pražský) týden navíc.
 */
class SharedTodoTydenTest extends TestCase
{
    use RefreshDatabase;

    public function test_dokonceno_tento_tyden_pocita_podle_prazskeho_tydne(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Naše', 'slug' => 'nase-'.Str::random(6), 'owner_id' => $owner->id, 'is_default' => true]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->actingAs($owner);

        // Reálný okamžik: pondělí 0:30 v Praze, ale ještě neděle 22:30 v UTC.
        $this->travelTo(CarbonImmutable::parse('2026-09-28 00:30', 'Europe/Prague'));

        // Dokončeno minulý pátek (pražsky i podle UTC) — nepatří do tohoto týdne.
        SharedTodo::create([
            'gallery_space_id' => $space->id, 'created_by' => $owner->id, 'title' => 'Staré',
            'status' => 'completed', 'completed_at' => '2026-09-25 12:00:00',
        ]);

        $summary = $this->getJson('/api/v1/todos')->assertOk()->json('summary');

        $this->assertSame(0, $summary['completed_this_week'], 'Úkol z minulého (pražského) týdne se počítá do tohoto týdne.');
    }
}
