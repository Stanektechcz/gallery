<?php

namespace Database\Seeders;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\Person;
use App\Models\SystemSetting;
use App\Models\Tag;
use App\Models\User;
use App\Services\AlbumService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GallerySpaceSeeder extends Seeder
{
    public function run(): void
    {
        // Create owner: Adrian
        $adrian = User::firstOrCreate(
            ['email' => config('gallery.owner_email') ?: 'adrian@gallery.local'],
            [
                'uuid'       => (string) Str::uuid(),
                'name'       => config('gallery.owner_name', 'Adrian'),
                'password'   => Hash::make('change-me-' . Str::random(16)),
                'role'       => 'owner',
                'is_active'  => true,
            ]
        );

        // Create partner: Makinka
        $makinka = User::firstOrCreate(
            ['email' => config('gallery.partner_email') ?: 'makinka@gallery.local'],
            [
                'uuid'       => (string) Str::uuid(),
                'name'       => config('gallery.partner_name', 'Makinka'),
                'password'   => Hash::make('change-me-' . Str::random(16)),
                'role'       => 'partner',
                'is_active'  => true,
            ]
        );

        // Create default gallery space
        $spaceName = config('gallery.default_space_name', 'Naše galerie');

        $space = GallerySpace::firstOrCreate(
            ['slug' => 'nase-galerie'],
            [
                'uuid'       => (string) Str::uuid(),
                'name'       => $spaceName,
                'owner_id'   => $adrian->id,
                'is_default' => true,
            ]
        );

        // Add both users to the space
        $space->members()->syncWithoutDetaching([
            $adrian->id  => ['role' => 'owner',  'can_delete' => true,  'can_share' => true,  'joined_at' => now()],
            $makinka->id => ['role' => 'editor',  'can_delete' => false, 'can_share' => true,  'joined_at' => now()],
        ]);

        // Create example album hierarchy
        // Česká republika → Praha → Akce → Muzeum 2026-06-21
        $albumService = new AlbumService();

        $cr = Album::firstOrCreate(
            ['gallery_space_id' => $space->id, 'slug' => 'ceska-republika', 'parent_id' => null],
            array_merge([
                'uuid'             => (string) Str::uuid(),
                'gallery_space_id' => $space->id,
                'title'            => 'Česká republika',
                'slug'             => 'ceska-republika',
                'created_by'       => $adrian->id,
                'updated_by'       => $adrian->id,
                'sync_status'      => 'pending',
                'sort_mode'        => 'date_taken',
                'sort_direction'   => 'asc',
                'visibility'       => 'private',
                'inherit_permissions' => true,
            ])
        );
        if ($cr->wasRecentlyCreated) {
            $cr->insertClosureRows();
            $cr->rebuildPaths();
        }

        $praha = Album::firstOrCreate(
            ['gallery_space_id' => $space->id, 'slug' => 'praha', 'parent_id' => $cr->id],
            [
                'uuid'             => (string) Str::uuid(),
                'gallery_space_id' => $space->id,
                'parent_id'        => $cr->id,
                'title'            => 'Praha',
                'slug'             => 'praha',
                'created_by'       => $adrian->id,
                'updated_by'       => $adrian->id,
                'sync_status'      => 'pending',
                'sort_mode'        => 'date_taken',
                'sort_direction'   => 'asc',
                'visibility'       => 'private',
                'inherit_permissions' => true,
            ]
        );
        if ($praha->wasRecentlyCreated) {
            $praha->insertClosureRows();
            $praha->rebuildPaths();
        }

        $akce = Album::firstOrCreate(
            ['gallery_space_id' => $space->id, 'slug' => 'akce', 'parent_id' => $praha->id],
            [
                'uuid'             => (string) Str::uuid(),
                'gallery_space_id' => $space->id,
                'parent_id'        => $praha->id,
                'title'            => 'Akce',
                'slug'             => 'akce',
                'created_by'       => $adrian->id,
                'updated_by'       => $adrian->id,
                'sync_status'      => 'pending',
                'sort_mode'        => 'date_taken',
                'sort_direction'   => 'asc',
                'visibility'       => 'private',
                'inherit_permissions' => true,
            ]
        );
        if ($akce->wasRecentlyCreated) {
            $akce->insertClosureRows();
            $akce->rebuildPaths();
        }

        $muzeum = Album::firstOrCreate(
            ['gallery_space_id' => $space->id, 'slug' => 'muzeum-2026-06-21', 'parent_id' => $akce->id],
            [
                'uuid'             => (string) Str::uuid(),
                'gallery_space_id' => $space->id,
                'parent_id'        => $akce->id,
                'title'            => 'Muzeum 2026-06-21',
                'slug'             => 'muzeum-2026-06-21',
                'description'      => 'Návštěva muzea',
                'event_date_start' => '2026-06-21',
                'event_date_end'   => '2026-06-21',
                'created_by'       => $adrian->id,
                'updated_by'       => $adrian->id,
                'sync_status'      => 'pending',
                'sort_mode'        => 'date_taken',
                'sort_direction'   => 'asc',
                'visibility'       => 'private',
                'inherit_permissions' => true,
            ]
        );
        if ($muzeum->wasRecentlyCreated) {
            $muzeum->insertClosureRows();
            $muzeum->rebuildPaths();
        }

        // Example people
        Person::firstOrCreate(
            ['gallery_space_id' => $space->id, 'name' => 'Adrian'],
            ['gallery_space_id' => $space->id, 'name' => 'Adrian', 'created_by' => $adrian->id]
        );
        Person::firstOrCreate(
            ['gallery_space_id' => $space->id, 'name' => 'Makinka'],
            ['gallery_space_id' => $space->id, 'name' => 'Makinka', 'created_by' => $adrian->id]
        );

        // Example tags
        $cestovaniTag = Tag::firstOrCreate(
            ['gallery_space_id' => $space->id, 'slug' => 'cestovani'],
            [
                'gallery_space_id'   => $space->id,
                'name'               => 'Cestování',
                'slug'               => 'cestovani',
                'depth'              => 0,
                'materialized_path'  => '',
                'created_by'         => $adrian->id,
            ]
        );

        // System settings
        SystemSetting::set('app_initialized', '1', 'boolean', 'system');

        $this->command?->info('Seeded: Adrian, Makinka, Naše galerie, and example album tree.');
    }
}
