<?php

namespace Database\Seeders;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\SystemSetting;
use App\Models\Tag;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            GallerySpaceSeeder::class,
        ]);
    }
}
