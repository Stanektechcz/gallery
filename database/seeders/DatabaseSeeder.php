<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            GallerySpaceSeeder::class,
            // Musí až za prostorem — stav se zakládá pro každý existující prostor.
            GalerieSeeder::class,
            RecipeTestDataSeeder::class,
        ]);
    }
}
