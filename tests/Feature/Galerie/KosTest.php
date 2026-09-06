<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Koš: vrátit, trvale odstranit, vyprázdnit.
 *
 * Obrazovka měla čtyři vymyšlené řádky a tlačítka, která jen přepsala stav
 * v prohlížeči. Dialog přitom sliboval, že se „odstraní i originály z Google
 * Drivu" — a nesmazalo se nic, ani v aplikaci, ani na Disku.
 */
class KosTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian', 'role' => 'owner']);
        $this->maki = User::factory()->create(['name' => 'Makinka', 'role' => 'member']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    /** Prázdný koš se neposílá — prototyp si nechá, co má. */
    public function test_prazdny_kos_se_neposila(): void
    {
        $data = $this->getJson('/api/data/system')->assertOk()->json('data');

        $this->assertArrayNotHasKey('TRASH', $data);
    }

    /** V koši je to, co v něm opravdu leží, a zbývající dny se počítají. */
    public function test_kos_ukazuje_skutecne_polozky(): void
    {
        $this->fotka(['trashed_at' => now()->subDays(3), 'purge_after' => now()->addDays(27)]);
        $this->fotka([], 2);

        $kos = $this->getJson('/api/data/system')->assertOk()->json('data.TRASH');

        $this->assertCount(1, $kos);
        $this->assertSame('IMG_1.jpg', $kos[0]['name']);
        $this->assertSame('Adrian', $kos[0]['by']);
        $this->assertSame('27 dní', $kos[0]['left']);
    }

    /** Vrácení z koše vrátí položku do knihovny. */
    public function test_vraceni_z_kose_vrati_polozku(): void
    {
        $fotka = $this->fotka(['trashed_at' => now(), 'purge_after' => now()->addDays(30)]);

        $this->postJson('/api/kos/vratit', ['id' => $fotka->uuid])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNull($fotka->fresh()->trashed_at);
    }

    /**
     * Poslední vrácená položka koš vyprázdní — a obrazovka se to musí dozvědět.
     *
     * Prázdné kolekce se neposílají, takže mlčení o `TRASH` by znamenalo
     * „nezměnilo se nic" a na obrazovce by zůstal řádek, který už neexistuje.
     */
    public function test_odpoved_rekne_ze_kos_zustal_prazdny(): void
    {
        $fotka = $this->fotka(['trashed_at' => now()]);

        $this->postJson('/api/kos/vratit', ['id' => $fotka->uuid])
            ->assertOk()
            ->assertJsonPath('prazdne', fn (array $klice) => in_array('TRASH', $klice, true));
    }

    /**
     * Trvalé odstranění doopravdy maže a zapisuje se do protokolu.
     *
     * Řádek v tabulce zůstává jako náhrobek (`deleted_at`) — soubory, náhledy
     * a kopie na Disku ne. Fotka je tím pádem opravdu nevratná, což je přesně
     * to, co dialog slibuje.
     */
    public function test_trvale_odstraneni_maze_a_zapisuje_se(): void
    {
        $fotka = $this->fotka(['trashed_at' => now()]);

        $this->postJson('/api/kos/odstranit', ['id' => $fotka->uuid])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNull(MediaItem::find($fotka->id));
        $this->assertNotNull(MediaItem::withTrashed()->find($fotka->id)->deleted_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'media.purge']);
    }

    /** Vyprázdnění smaže všechno v koši a nic mimo něj. */
    public function test_vyprazdneni_smaze_jen_kos(): void
    {
        $this->fotka(['trashed_at' => now()], 1);
        $this->fotka(['trashed_at' => now()], 2);
        $zustava = $this->fotka([], 3);

        $this->postJson('/api/kos/vyprazdnit')
            ->assertOk()
            ->assertJsonPath('zprava', 'Koš vyprázdněn — 2 položky trvale odstraněny');

        $this->assertNotNull(MediaItem::find($zustava->id));
        $this->assertSame(1, MediaItem::count());
    }

    /** Kdo nesmí mazat, dostane vysvětlení, ne ticho. */
    public function test_bez_opravneni_se_nemaze_a_rekne_se_to(): void
    {
        $fotka = $this->fotka(['trashed_at' => now()]);

        Sanctum::actingAs($this->maki);

        $this->postJson('/api/kos/odstranit', ['id' => $fotka->uuid])
            ->assertStatus(403)
            ->assertJsonPath('ok', false);

        $this->assertDatabaseHas('media_items', ['uuid' => $fotka->uuid]);
    }

    /** Cizí položka se z koše nedá odstranit. */
    public function test_cizi_polozka_neni_v_kosi(): void
    {
        $this->postJson('/api/kos/odstranit', ['id' => (string) Str::uuid()])->assertNotFound();
    }

    private function fotka(array $navic = [], int $poradi = 1): MediaItem
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
            'size_bytes' => 2_097_152,
            'taken_at' => now()->subDays($poradi),
            'uploaded_at' => now()->subDays($poradi),
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }
}
