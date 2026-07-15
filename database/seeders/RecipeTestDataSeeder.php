<?php

namespace Database\Seeders;

use App\Models\GallerySpace;
use App\Services\Recipes\TestRecipeInstaller;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class RecipeTestDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('recipes') || ! Schema::hasTable('recipe_ingredients') || ! Schema::hasTable('recipe_steps')) {
            $this->command?->warn('Recipe tables are not available; test recipes were skipped.');

            return;
        }

        $space = GallerySpace::where('is_default', true)->orderBy('id')->first() ?? GallerySpace::orderBy('id')->first();
        if (! $space) {
            $this->command?->warn('No gallery space is available; test recipes were skipped.');

            return;
        }

        $recipes = app(TestRecipeInstaller::class)->install($space);
        $this->command?->info("Seeded {$recipes->count()} complete test recipes into {$space->name}.");
    }
}
