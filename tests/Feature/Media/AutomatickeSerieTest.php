<?php

namespace Tests\Feature\Media;

use App\Services\Media\AutoStackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Návrhy sérií musí vidět i nové fotky.
 *
 * Služba brala nejstarších N nesložených fotek (`orderBy('taken_at')`).
 * Jakmile knihovna měla víc samostatných fotek než limit, nová série se
 * do výběru nikdy nedostala.
 */
class AutomatickeSerieTest extends TestCase
{
    use RefreshDatabase;
    use VytvariMedia;

    public function test_nova_serie_se_najde_i_za_limitem_starych_fotek(): void
    {
        $this->zalozProstor();
        foreach (range(1, 3) as $den) {
            $this->media(['taken_at' => "2024-01-0{$den} 10:00:00", 'camera_model' => 'iPhone 15', 'original_filename' => "stara{$den}.jpg"]);
        }
        $prvni = $this->media(['taken_at' => '2026-09-20 18:00:00', 'camera_model' => 'iPhone 15', 'original_filename' => 'IMG_1.jpg']);
        $druha = $this->media(['taken_at' => '2026-09-20 18:00:02', 'camera_model' => 'iPhone 15', 'original_filename' => 'IMG_2.jpg']);

        $serie = app(AutoStackService::class)->candidates($this->prostor->id, 3);

        $this->assertCount(1, $serie);
        // Uvnitř série zůstává chronologické pořadí.
        $this->assertSame([$prvni->id, $druha->id], collect($serie[0]['items'])->pluck('id')->all());
    }

    public function test_fotka_uz_ve_stohu_se_znovu_nenabidne(): void
    {
        $this->zalozProstor();
        $a = $this->media(['taken_at' => '2026-09-20 18:00:00', 'camera_model' => 'iPhone 15', 'original_filename' => 'IMG_1.jpg']);
        $this->media(['taken_at' => '2026-09-20 18:00:02', 'camera_model' => 'iPhone 15', 'original_filename' => 'IMG_2.jpg']);
        $stoh = DB::table('media_stacks')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('media_stack_items')->insert(['media_stack_id' => $stoh, 'media_item_id' => $a->id]);

        $this->assertCount(0, app(AutoStackService::class)->candidates($this->prostor->id));
    }
}
