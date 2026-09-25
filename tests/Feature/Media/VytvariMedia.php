<?php

namespace Tests\Feature\Media;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Dvojice, prostor a fotky pro testy zpracování médií. */
trait VytvariMedia
{
    protected User $adri;

    protected GallerySpace $prostor;

    protected function zalozProstor(): void
    {
        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Naše vzpomínky',
            'slug' => 'nase-vzpominky-'.Str::random(6),
            'owner_id' => $this->adri->id,
        ]);
        $this->prostor->members()->attach($this->adri->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
    }

    protected function media(array $atributy = [], ?GallerySpace $prostor = null): MediaItem
    {
        $prostor ??= $this->prostor;

        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'owner_user_id' => $prostor->owner_id,
            'uploaded_by' => $prostor->owner_id,
            'original_filename' => 'foto.jpg',
            'safe_filename' => 'foto.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1,
            'status' => 'ready',
            'storage_status' => 'ready',
            'is_hidden' => false,
            'uploaded_at' => now(),
        ], $atributy));
    }

    protected function varianta(MediaItem $media, string $typ, string $cesta, string $disk = 'public'): void
    {
        DB::table('media_variants')->insert([
            'media_item_id' => $media->id, 'type' => $typ, 'disk' => $disk, 'path' => $cesta,
            'size_bytes' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Skutečný JPEG z GD — pro dekodér, který musí projít. */
    protected function jpeg(int $sirka, int $vyska): string
    {
        $obraz = imagecreatetruecolor($sirka, $vyska);
        ob_start();
        imagejpeg($obraz, null, 85);
        imagedestroy($obraz);

        return (string) ob_get_clean();
    }

    /**
     * PNG, které v hlavičce tvrdí 30 000 × 30 000 px, ale má pár bajtů.
     *
     * Dekodér podle hlavičky alokuje plátno (~3,6 GB) dřív, než zjistí, že data
     * chybí — přesně „pixelová bomba", která shodí worker na každém pokusu.
     */
    protected function pixelovaBomba(int $sirka = 30000, int $vyska = 30000): string
    {
        $blok = fn (string $typ, string $data) => pack('N', strlen($data)).$typ.$data.pack('N', crc32($typ.$data));

        return "\x89PNG\r\n\x1a\n"
            .$blok('IHDR', pack('NNCCCCC', $sirka, $vyska, 8, 2, 0, 0, 0))
            .$blok('IDAT', (string) gzcompress("\0\0\0\0"))
            .$blok('IEND', '');
    }
}
