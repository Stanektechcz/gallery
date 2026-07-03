<?php

namespace Tests\Unit;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlbumHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->space = GallerySpace::create([
            'uuid'     => \Str::uuid(),
            'name'     => 'Test Space',
            'slug'     => 'test-space',
            'owner_id' => $this->owner->id,
        ]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true]);
    }

    private function makeAlbum(string $title, ?int $parentId = null): Album
    {
        $album = Album::create([
            'uuid'             => \Str::uuid(),
            'gallery_space_id' => $this->space->id,
            'parent_id'        => $parentId,
            'title'            => $title,
            'slug'             => \Str::slug($title),
            'visibility'       => 'private',
            'sort_mode'        => 'date_taken',
            'sort_direction'   => 'asc',
            'created_by'       => $this->owner->id,
            'updated_by'       => $this->owner->id,
            'sync_status'      => 'pending',
            'inherit_permissions' => true,
        ]);
        $album->rebuildPaths();
        return $album;
    }

    /** @test */
    public function test_root_album_creates_self_closure(): void
    {
        $album = $this->makeAlbum('Root');

        $closureCount = \DB::table('album_closure')
            ->where('ancestor_id', $album->id)
            ->where('descendant_id', $album->id)
            ->where('depth', 0)
            ->count();

        $this->assertEquals(1, $closureCount);
    }

    /** @test */
    public function test_child_album_creates_correct_closure(): void
    {
        $root  = $this->makeAlbum('Root');
        $child = $this->makeAlbum('Child', $root->id);

        // Self closure for child
        $this->assertEquals(1, \DB::table('album_closure')
            ->where('ancestor_id', $child->id)
            ->where('descendant_id', $child->id)
            ->where('depth', 0)
            ->count());

        // Parent -> child closure
        $this->assertEquals(1, \DB::table('album_closure')
            ->where('ancestor_id', $root->id)
            ->where('descendant_id', $child->id)
            ->where('depth', 1)
            ->count());
    }

    /** @test */
    public function test_unlimited_nesting_depth(): void
    {
        $parent = $this->makeAlbum('Level 1');

        // Create 10 levels of nesting
        for ($i = 2; $i <= 10; $i++) {
            $parent = $this->makeAlbum("Level {$i}", $parent->id);
        }

        $this->assertEquals(9, $parent->depth);

        // Level 10 should have 10 closure rows (itself + 9 ancestors)
        $closureCount = \DB::table('album_closure')
            ->where('descendant_id', $parent->id)
            ->count();

        $this->assertEquals(10, $closureCount);
    }

    /** @test */
    public function test_prevents_cycle_on_move(): void
    {
        $parent = $this->makeAlbum('Parent');
        $child  = $this->makeAlbum('Child', $parent->id);

        $this->expectException(\InvalidArgumentException::class);

        // Try to move parent into child (would create cycle)
        $parent->moveTo($child->id);
    }

    /** @test */
    public function test_prevents_self_move(): void
    {
        $album = $this->makeAlbum('Album');

        $this->expectException(\InvalidArgumentException::class);
        $album->moveTo($album->id);
    }

    /** @test */
    public function test_subtree_move_updates_all_paths(): void
    {
        $a = $this->makeAlbum('A');
        $b = $this->makeAlbum('B', $a->id);
        $c = $this->makeAlbum('C', $b->id);
        $d = $this->makeAlbum('D'); // New parent

        // Move A's subtree (B+C) to D
        $b->moveTo($d->id);
        $b->refresh();
        $c->refresh();

        $this->assertEquals($d->id, $b->parent_id);
        $this->assertEquals(1, $b->depth);
        $this->assertEquals(2, $c->depth);

        // C should now have D as ancestor
        $this->assertEquals(1, \DB::table('album_closure')
            ->where('ancestor_id', $d->id)
            ->where('descendant_id', $c->id)
            ->count());
    }

    /** @test */
    public function test_get_ancestors(): void
    {
        $root   = $this->makeAlbum('Root');
        $child  = $this->makeAlbum('Child', $root->id);
        $grand  = $this->makeAlbum('Grand', $child->id);

        $ancestors = $grand->ancestors()->get();

        $this->assertCount(2, $ancestors);
        $this->assertTrue($ancestors->contains('id', $root->id));
        $this->assertTrue($ancestors->contains('id', $child->id));
    }

    /** @test */
    public function test_get_descendants(): void
    {
        $root   = $this->makeAlbum('Root');
        $child1 = $this->makeAlbum('Child1', $root->id);
        $child2 = $this->makeAlbum('Child2', $root->id);
        $grand  = $this->makeAlbum('Grand', $child1->id);

        $descendants = $root->descendants()->get();

        $this->assertCount(3, $descendants);
        $this->assertTrue($descendants->contains('id', $child1->id));
        $this->assertTrue($descendants->contains('id', $child2->id));
        $this->assertTrue($descendants->contains('id', $grand->id));
    }

    /** @test */
    public function test_materialized_path_rebuilds_correctly(): void
    {
        $a = $this->makeAlbum('A');
        $b = $this->makeAlbum('B', $a->id);
        $c = $this->makeAlbum('C', $b->id);

        $c->refresh();

        $this->assertStringContainsString((string) $a->id, $c->materialized_path);
        $this->assertStringContainsString((string) $b->id, $c->materialized_path);
        $this->assertStringContainsString((string) $c->id, $c->materialized_path);
        $this->assertStringContainsString('A / B / C', $c->full_display_path);
    }

    /** @test */
    public function test_breadcrumb_returns_correct_path(): void
    {
        $root  = $this->makeAlbum('Root');
        $child = $this->makeAlbum('Child', $root->id);

        $breadcrumb = $child->breadcrumb;

        $this->assertCount(2, $breadcrumb);
        $this->assertEquals('Root', $breadcrumb[0]['title']);
        $this->assertEquals('Child', $breadcrumb[1]['title']);
    }
}
