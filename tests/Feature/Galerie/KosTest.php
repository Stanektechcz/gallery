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

    /**
     * Vyhozená fotka z trezoru se v koši se zamčeným trezorem neukáže.
     *
     * Do koše jde poslat i fotku z trezoru (smazání se na `is_hidden` neptá)
     * a seznam koše ji pak vypsal i s názvem souboru komukoli u odemčené
     * aplikace — trezor přitom zamčený. S odemčeným trezorem se ukáže, aby šla
     * vrátit dřív, než ji úklid po třiceti dnech smaže.
     */
    public function test_fotka_z_trezoru_v_kosi_jen_s_odemcenym_trezorem(): void
    {
        $this->fotka(['trashed_at' => now()->subDay()]);
        $this->fotka(['trashed_at' => now()->subDay(), 'is_hidden' => true, 'original_filename' => 'pas-a-obcanka.jpg'], 2);

        $zamceno = $this->getJson('/api/data/system')->assertOk()->json('data.TRASH');
        $this->assertSame(['IMG_1.jpg'], array_column($zamceno, 'name'));

        $odemceno = $this->withSession(['vault_unlocked_until' => now()->addMinutes(5)->timestamp])
            ->getJson('/api/data/system')->assertOk()->json('data.TRASH');
        $this->assertEqualsCanonicalizing(['IMG_1.jpg', 'pas-a-obcanka.jpg'], array_column($odemceno, 'name'));
    }

    /**
     * Odznak koše v postranním panelu počítá totéž, co seznam koše ukáže.
     *
     * Seznam fotku z trezoru se zamčeným trezorem vynechá, odznak ji počítal:
     * u Koše stálo „2", otevřel se s jednou — a rozdíl řekl, že v koši leží
     * něco z trezoru.
     */
    public function test_odznak_kose_nepocita_trezor_se_zamcenym_trezorem(): void
    {
        $this->fotka(['trashed_at' => now()->subDay()]);
        $this->fotka(['trashed_at' => now()->subDay(), 'is_hidden' => true], 2);

        $zamceno = $this->getJson('/api/data/knihovna')->assertOk()->json('data.NAVCNT.trash');
        $this->assertSame('1', $zamceno);

        $odemceno = $this->withSession(['vault_unlocked_until' => now()->addMinutes(5)->timestamp])
            ->getJson('/api/data/knihovna')->assertOk()->json('data.NAVCNT.trash');
        $this->assertSame('2', $odemceno);
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

    /** „Zpět" po hromadném přesunu vrátí celou dávku jedním požadavkem. */
    public function test_vraceni_davky_z_kose(): void
    {
        $prvni = $this->fotka(['trashed_at' => now(), 'purge_after' => now()->addDays(30)]);
        $druha = $this->fotka(['trashed_at' => now(), 'purge_after' => now()->addDays(30)], 2);
        $zustane = $this->fotka(['trashed_at' => now()], 3);

        $odpoved = $this->postJson('/api/kos/vratit', ['ids' => [$prvni->uuid, $druha->uuid, 'neexistuje']])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertEqualsCanonicalizing([$prvni->uuid, $druha->uuid], $odpoved->json('ids'));
        $this->assertNull($prvni->fresh()->trashed_at);
        $this->assertNull($druha->fresh()->purge_after);
        $this->assertNotNull($zustane->fresh()->trashed_at);

        $this->postJson('/api/kos/vratit', ['ids' => ['neexistuje']])->assertNotFound();
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
     * Dřív tu stálo, že řádek zůstává jako náhrobek (`deleted_at`). To byla
     * chyba, ne záměr: takový řádek koš nevidí ani ho neuklidí noční
     * `gallery:purge-trash`, takže držel místo v součtu navždy. Nevratnost
     * zajišťuje smazání souborů a kopie na Disku — záznam o tom zůstává
     * v protokolu, ne v `media_items`.
     */
    public function test_trvale_odstraneni_maze_a_zapisuje_se(): void
    {
        $fotka = $this->fotka(['trashed_at' => now()]);

        $this->postJson('/api/kos/odstranit', ['id' => $fotka->uuid])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNull(MediaItem::find($fotka->id));
        $this->assertNull(MediaItem::withTrashed()->find($fotka->id));
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

    /**
     * Se zamčeným trezorem se z koše nemaže, co z trezoru přišlo.
     *
     * Seznam koše fotky z trezoru se zamčeným trezorem neukazuje — a „Vyprázdnit
     * koš" je přesto nadobro smazal: člověk potvrdil počet, který viděl, a přišel
     * i o to, co neviděl. Stejně šla trvale smazat jednotlivě podle uuid.
     */
    public function test_se_zamcenym_trezorem_se_z_kose_nemaze_trezor(): void
    {
        $bezna = $this->fotka(['trashed_at' => now()->subDay()], 1);
        $trezor = $this->fotka(['trashed_at' => now()->subDay(), 'is_hidden' => true], 2);

        $this->postJson('/api/kos/odstranit', ['id' => $trezor->uuid])->assertNotFound();

        $this->postJson('/api/kos/vyprazdnit')
            ->assertOk()
            ->assertJsonPath('zprava', 'Koš vyprázdněn — 1 položka trvale odstraněna');

        $this->assertNull(MediaItem::withTrashed()->find($bezna->id));
        $this->assertNotNull(MediaItem::find($trezor->id), 'Fotka z trezoru, kterou koš neukázal, zůstává.');

        $this->withSession(['vault_unlocked_until' => now()->addMinutes(5)->timestamp])
            ->postJson('/api/kos/odstranit', ['id' => $trezor->uuid])
            ->assertOk();

        $this->assertNull(MediaItem::withTrashed()->find($trezor->id));
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

    /**
     * „Trvale odstraněno" musí řádek opravdu smazat.
     *
     * `MediaItem` má `SoftDeletes`, takže `->delete()` jen nastavilo
     * `deleted_at`. Soubory zmizely, řádek zůstal — a noční úklid ho nevidí,
     * protože měkké mazání ho z dotazu vyřadí. Vznikl tím sirotek ukazující
     * na bajty, které už nejsou.
     */
    public function test_trvale_odstraneni_smaze_radek_doopravdy(): void
    {
        $foto = $this->fotka(['trashed_at' => now()->subDay()]);

        $this->postJson('/api/kos/odstranit', ['id' => $foto->uuid])->assertOk();

        $this->assertSame(0, MediaItem::withTrashed()->where('uuid', $foto->uuid)->count(),
            'Po „trvale odstraněno" nesmí zůstat ani měkce smazaný řádek.');
    }

    /** Totéž pro vysypání celého koše. */
    public function test_vyprazdneni_kose_smaze_radky_doopravdy(): void
    {
        $this->fotka(['trashed_at' => now()->subDay()], 1);
        $this->fotka(['trashed_at' => now()->subDay()], 2);

        $this->postJson('/api/kos/vyprazdnit')->assertOk();

        $this->assertSame(0, MediaItem::withTrashed()->whereNotNull('trashed_at')->count());
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
