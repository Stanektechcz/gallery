<?php

use App\Models\GallerySpace;
use App\Models\Recipe;
use App\Services\Recipes\TestRecipeInstaller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recipes') || ! Schema::hasTable('recipe_ingredients') || ! Schema::hasTable('recipe_steps')) {
            return;
        }

        $space = GallerySpace::where('is_default', true)->orderBy('id')->first() ?? GallerySpace::orderBy('id')->first();
        if ($space) {
            app(TestRecipeInstaller::class)->install($space);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('recipes')) {
            Recipe::withTrashed()->whereIn('uuid', TestRecipeInstaller::RECIPE_UUIDS)->forceDelete();
        }
    }
};
