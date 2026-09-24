<?php

namespace Tests\Feature;

use App\Jobs\Media\UploadDriveChunkJob;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Nahrávání na Disk po částech.
 *
 * `UploadDriveChunkJob` měl vlastní `dispatch()`, které slibovalo
 * `PendingDispatch` a vracelo samotnou úlohu — tedy `TypeError` při každém
 * volání a nic ve frontě. Volající to měl uvnitř `try`, takže se z toho stal
 * `release()`: každý z pěti pokusů znovu založil na Googlu novou relaci
 * nahrávání a pak to vzdal. Položka zůstala ve stavu `uploading` a velký
 * soubor se na Disk nedostal nikdy.
 */
class NahravaniNaDiskTest extends TestCase
{
    use RefreshDatabase;

    public function test_uloha_pro_cast_souboru_se_dostane_do_fronty(): void
    {
        Queue::fake();

        $media = $this->fotka();

        UploadDriveChunkJob::dispatch($media->id, null, 'https://upload.example/relace', 0, 1024)
            ->onQueue('drive');

        Queue::assertPushedOn('drive', UploadDriveChunkJob::class);
    }

    private function fotka(): MediaItem
    {
        $kdo = User::factory()->create(['name' => 'Adrian']);
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $kdo->id]);
        $prostor->members()->syncWithoutDetaching([$kdo->id => ['role' => 'owner']]);

        return MediaItem::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'owner_user_id' => $kdo->id,
            'uploaded_by' => $kdo->id,
            'original_filename' => 'IMG_1.jpg',
            'safe_filename' => 'img-1.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'taken_at' => now()->subDay(),
            'uploaded_at' => now()->subDay(),
            'status' => 'ready',
            'storage_status' => 'local',
        ]);
    }
}
