<?php

namespace Database\Seeders;

use App\Models\GallerySpace;
use App\Models\SystemSetting;
use App\Models\User;
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
            $makinka->id => ['role' => 'editor',  'can_delete' => true, 'can_share' => true,  'joined_at' => now()],
        ]);

        // System settings
        SystemSetting::set('app_initialized', '1', 'boolean', 'system');

        $this->command?->info('Seeded: Adrian + Makinka users and gallery space. No sample albums created.');
    }
}
