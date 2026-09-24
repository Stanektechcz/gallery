<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Po trvalém smazání fotky nesmí nikde zbýt odkaz na ni.
 *
 * Devět sloupců míří na `media_items` bez cizího klíče — obálka alba, osoby,
 * cesty, fotoknihy, stohu, druhá půlka živé fotky, výsledek nahrávání, doklad
 * hosta a záznam o stažení. Dokud koš mazal měkce, řádek v `media_items`
 * zůstával a odkaz na něco ukazoval. Od chvíle, kdy se maže doopravdy, ukazuje
 * na prázdno: album si pak jako obálku vezme nic a obrazovka se ptá na fotku,
 * která neexistuje.
 *
 * Že jde o přehlédnutí a ne o záměr, dokazuje `recipes.cover_media_id` —
 * ten cizí klíč s `nullOnDelete()` má.
 */
class SirotciPoMazaniTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);
    }

    #[DataProvider('odkazyNaFotku')]
    public function test_odkaz_na_smazanou_fotku_nezustane(string $tabulka, string $sloupec): void
    {
        if (! Schema::hasTable($tabulka) || ! Schema::hasColumn($tabulka, $sloupec)) {
            $this->markTestSkipped("Tabulka {$tabulka} tu není.");
        }

        $foto = $this->fotka();
        $id = DB::table($tabulka)->insertGetId($this->radek($tabulka, $sloupec, $foto));

        $foto->forceDelete();

        $this->assertNull(DB::table($tabulka)->where('id', $id)->value($sloupec),
            "V {$tabulka}.{$sloupec} zůstal odkaz na fotku, která už neexistuje.");
    }

    /** @return array<string, array{string, string}> */
    public static function odkazyNaFotku(): array
    {
        return [
            'obálka alba' => ['albums', 'cover_media_id'],
            'obálka osoby' => ['people', 'cover_media_id'],
            'obálka cesty' => ['trips', 'cover_media_id'],
            'obálka fotoknihy' => ['photo_books', 'cover_media_id'],
            'obálka stohu' => ['media_stacks', 'cover_media_id'],
            'výsledek nahrávání' => ['upload_sessions', 'resulting_media_id'],
            'doklad hosta' => ['guest_uploads', 'media_item_id'],
            'záznam o stažení' => ['share_access_logs', 'media_item_id'],
        ];
    }

    /** Druhá půlka živé fotky je odkaz uvnitř téže tabulky. */
    public function test_ziva_fotka_neukazuje_na_smazanou_polovinu(): void
    {
        if (! Schema::hasColumn('media_items', 'live_photo_pair_id')) {
            $this->markTestSkipped('Sloupec tu není.');
        }

        $video = $this->fotka(2);
        $foto = $this->fotka(1, ['live_photo_pair_id' => $video->id]);

        $video->forceDelete();

        $this->assertNull($foto->fresh()->live_photo_pair_id);
    }

    /** @return array<string, mixed> */
    private function radek(string $tabulka, string $sloupec, MediaItem $foto): array
    {
        // `share_access_logs` má jen `created_at` — je to protokol, ne záznam,
        // který by se měnil.
        $zaklad = $tabulka === 'share_access_logs'
            ? [$sloupec => $foto->id, 'created_at' => now()]
            : [$sloupec => $foto->id, 'created_at' => now(), 'updated_at' => now()];

        return $zaklad + match ($tabulka) {
            'albums' => ['uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id,
                'title' => 'Léto', 'slug' => 'leto-'.Str::random(6), 'created_by' => $this->adri->id],
            'people' => ['gallery_space_id' => $this->prostor->id, 'name' => 'Klára'],
            'trips' => ['gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
                'name' => 'Chorvatsko', 'start_date' => now()->toDateString(),
                'end_date' => now()->addWeek()->toDateString()],
            'photo_books' => ['uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'name' => 'Rok'],
            'media_stacks' => ['uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id],
            'upload_sessions' => ['uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id,
                'user_id' => $this->adri->id, 'original_filename' => 'IMG.jpg', 'mime_type' => 'image/jpeg',
                'total_size' => 1024, 'total_chunks' => 1, 'expires_at' => now()->addDay()],
            'guest_uploads' => ['uuid' => (string) Str::uuid(), 'shared_link_id' => $this->odkaz(),
                'original_filename' => 'IMG.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024,
                'storage_path' => 'guest/img.jpg'],
            'share_access_logs' => ['shared_link_id' => $this->odkaz(), 'action' => 'view'],
            default => [],
        };
    }

    /** Sdílený odkaz, na kterém visí doklad hosta i záznam o stažení. */
    private function odkaz(): int
    {
        return DB::table('shared_links')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'token' => Str::random(40),
            'created_by' => $this->adri->id,
            'gallery_space_id' => $this->prostor->id,
            'target_type' => 'album',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function fotka(int $poradi = 1, array $navic = []): MediaItem
    {
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
            'taken_at' => now()->subDays($poradi),
            'uploaded_at' => now()->subDays($poradi),
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }
}
