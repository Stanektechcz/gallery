<?php

namespace Tests\Feature\StareApi;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nález 1: osoby nesmí vydat fotky z trezoru ani z koše.
 */
class PersonVaultTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$this->prostor->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    public function test_show_vraci_jen_viditelne_fotky_a_pocet(): void
    {
        $osoba = Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Klára', 'created_by' => $this->adri->id]);
        $viditelna = $this->fotka();
        $vTrezoru = $this->fotka(['is_hidden' => true]);
        $vKosi = $this->fotka(['trashed_at' => now()]);
        foreach ([$viditelna, $vTrezoru, $vKosi] as $m) {
            $this->pripoj($m, $osoba);
        }

        $odpoved = $this->getJson('/api/v1/people/'.$osoba->id)->assertOk();
        $odpoved->assertJsonCount(1, 'media');
        $this->assertSame($viditelna->id, $odpoved->json('media.0.id'));
        $this->assertSame(1, $odpoved->json('person.media_count'));
    }

    public function test_index_pocita_jen_viditelne_fotky(): void
    {
        $osoba = Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Bára', 'created_by' => $this->adri->id]);
        $this->pripoj($this->fotka(), $osoba);
        $this->pripoj($this->fotka(['is_hidden' => true]), $osoba);
        $this->pripoj($this->fotka(['trashed_at' => now()]), $osoba);

        $odpoved = $this->getJson('/api/v1/people')->assertOk();
        $data = collect($odpoved->json())->firstWhere('id', $osoba->id);
        $this->assertSame(1, $data['media_count']);
    }

    public function test_titulni_fotka_z_trezoru_se_nevraci(): void
    {
        $skryta = $this->fotka(['is_hidden' => true]);
        $osoba = Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Maki', 'created_by' => $this->adri->id, 'cover_media_id' => $skryta->id]);
        $this->pripoj($skryta, $osoba);

        $odpoved = $this->getJson('/api/v1/people')->assertOk();
        $data = collect($odpoved->json())->firstWhere('id', $osoba->id);
        $this->assertNull($data['latest_thumb']);
    }

    private function fotka(array $navic = []): MediaItem
    {
        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG_'.Str::random(4).'.jpg',
            'safe_filename' => 'img.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'uploaded_at' => now(),
            'taken_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }

    private function pripoj(MediaItem $m, Person $osoba): void
    {
        DB::table('media_person')->insert(['media_item_id' => $m->id, 'person_id' => $osoba->id, 'created_at' => now()]);
    }
}
