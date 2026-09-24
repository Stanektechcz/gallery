<?php

namespace Tests\Feature;

use App\Jobs\GenerateExportJob;
use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Vývoz fotek: čí je úloha a co do ní smí spadnout.
 *
 * Úloha běží ve frontě, kde není přihlášený uživatel — a `SpaceContext` tam
 * proto ustupuje. Dokud dotaz nefiltroval podle `gallery_space_id`, stačilo
 * poslat cizí identifikátory a vývoz vrátil ZIP s fotkami jiné dvojice.
 */
class ExportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_job_is_owned_by_its_creator_and_cannot_be_inspected_by_another_user(): void
    {
        Queue::fake();
        $owner = $this->uzivatelSProstorem('Adrian');
        $other = $this->uzivatelSProstorem('Cizí');

        $jobId = $this->actingAs($owner)
            ->postJson('/api/v1/exports', ['type' => 'selection', 'media_ids' => []])
            ->assertAccepted()->json('job_id');

        $this->assertSame($owner->id, Cache::get("export_owner_{$jobId}"));
        $this->actingAs($owner)->getJson("/api/v1/exports/{$jobId}")->assertOk()->assertJsonPath('job_id', $jobId);
        $this->actingAs($other)->getJson("/api/v1/exports/{$jobId}")->assertNotFound();
    }

    /** Cizí identifikátory se do vývozu nedostanou už při zadání. */
    public function test_vyvoz_odmitne_cizi_fotky(): void
    {
        Queue::fake();
        $adri = $this->uzivatelSProstorem('Adrian');
        $cizi = $this->uzivatelSProstorem('Cizí');
        $ciziFotka = $this->fotka($cizi, 1);

        $this->actingAs($adri)
            ->postJson('/api/v1/exports', ['type' => 'selection', 'media_ids' => [$ciziFotka->id]])
            ->assertStatus(403);

        Queue::assertNothingPushed();
    }

    /** Cizí album taky ne. */
    public function test_vyvoz_odmitne_cizi_album(): void
    {
        Queue::fake();
        $adri = $this->uzivatelSProstorem('Adrian');
        $cizi = $this->uzivatelSProstorem('Cizí');

        $album = Album::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $cizi->gallerySpaces()->sole()->id,
            'title' => 'Cizí album',
            'slug' => 'cizi-album-'.Str::random(6),
            'created_by' => $cizi->id,
        ]);

        $this->actingAs($adri)
            ->postJson('/api/v1/exports', ['type' => 'album', 'target_id' => $album->id])
            ->assertStatus(403);

        Queue::assertNothingPushed();
    }

    /**
     * A i kdyby se úloha do fronty dostala jinak, filtruje sama.
     *
     * Tohle je ta podstatná pojistka: ve frontě není přihlášený uživatel,
     * takže globální rozsah `SpaceContext` neplatí a jediné, co fotky drží
     * u sebe, je `where` v úloze.
     */
    public function test_uloha_vybere_jen_fotky_vlastniho_prostoru(): void
    {
        $adri = $this->uzivatelSProstorem('Adrian');
        $cizi = $this->uzivatelSProstorem('Cizí');

        $moje = $this->fotka($adri, 1);
        $ciziFotka = $this->fotka($cizi, 2);

        $vybrane = GenerateExportJob::vybraneFotky(
            ['type' => 'selection', 'media_ids' => [$moje->id, $ciziFotka->id]],
            (int) $adri->gallerySpaces()->sole()->id,
        );

        $this->assertSame([$moje->id], $vybrane->pluck('id')->all(),
            'Do vývozu smí jen fotky z prostoru, který o něj požádal.');
    }

    private function uzivatelSProstorem(string $jmeno): User
    {
        $uzivatel = User::factory()->create(['name' => $jmeno]);
        $prostor = GallerySpace::create(['name' => 'Galerie '.$jmeno, 'owner_id' => $uzivatel->id]);
        $prostor->members()->syncWithoutDetaching([$uzivatel->id => ['role' => 'owner']]);

        return $uzivatel;
    }

    private function fotka(User $kdo, int $poradi): MediaItem
    {
        return MediaItem::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $kdo->gallerySpaces()->sole()->id,
            'owner_user_id' => $kdo->id,
            'uploaded_by' => $kdo->id,
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
        ]);
    }
}
