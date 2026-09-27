<?php

namespace Tests\Feature\Hledani;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Hledani\ObnovaHledani;
use Illuminate\Support\Str;

/** Dvojice s prostorem a fotky k hledání. */
trait VytvariFotky
{
    private User $adri;

    private GallerySpace $prostor;

    private function zalozDvojici(): void
    {
        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = $this->novyProstor($this->adri);
    }

    private function novyProstor(User $kdo): GallerySpace
    {
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky '.Str::random(4), 'owner_id' => $kdo->id]);
        $prostor->members()->syncWithoutDetaching([$kdo->id => ['role' => 'owner']]);

        return $prostor;
    }

    /** @param  array<string, mixed>  $navic */
    private function fotka(array $navic = []): MediaItem
    {
        static $poradi = 0;
        $poradi++;

        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG_'.$poradi.'.jpg',
            'safe_filename' => 'img-'.$poradi.'.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'taken_at' => '2024-05-01 10:00:00',
            'uploaded_at' => '2024-05-01 11:00:00',
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }

    /** Fotka s hotovým hledaným textem. */
    private function hledatelna(array $navic = []): MediaItem
    {
        $m = $this->fotka($navic);
        app(ObnovaHledani::class)->obnov([$m->id]);

        return $m->refresh();
    }
}
