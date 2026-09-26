<?php

namespace Tests\Feature\Galerie;

use App\Models\CoupleState;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SelhavajiciTabulka;
use Tests\TestCase;

/**
 * Dluh zápisu (`__dluh`) nesmí přebít novější úpravu ani cizí srdíčka.
 *
 * Dluh je v **sdíleném** stavu dvojice a zopakuje se při každém zápisu.
 * Dvě věci se tu pokazily tak, že to vypadalo funkčně:
 *
 *  - sloučení `+=` drželo **nejstarší** hodnotu: trvale padající dluh `edits`
 *    se přehrával při každém PATCHi a vracel staré popisky,
 *  - `favs` jsou srdíčka jednoho člověka, jenže dluh se přehrál pod tím, kdo
 *    zrovna zapisoval — její nepovedená srdíčka se stala jeho.
 */
class StavDluhTest extends TestCase
{
    use RefreshDatabase;
    use SelhavajiciTabulka;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->maki = User::factory()->create(['name' => 'Makinka']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);
    }

    /**
     * Úspěšně zapsaný klíč se z dluhu vyškrtne — i když starý dluh padá dál.
     *
     * Starý dluh nese popisek z doby před úpravou a u jiné fotky štítek, na
     * kterém zápis spadne pokaždé. S `+=` v něm zůstal navždy a při dalším
     * zápisu čehokoli vrátil fotce starý popisek.
     */
    public function test_novy_zapis_uprav_nahradi_stary_dluh(): void
    {
        $fotka = $this->fotka();
        $vadna = $this->fotka();

        $this->dluh(['edits' => [
            $fotka->uuid => ['caption' => 'Stará verze'],
            // Pole v poli: `(string)` na něm vyhodí chybu — dluh, který nikdy neprojde.
            $vadna->uuid => ['tags' => [['nejde']]],
        ]]);

        $this->actingAs($this->adri)
            ->patchJson('/api/state', ['data' => ['edits' => [$fotka->uuid => ['caption' => 'Nová verze']]]])
            ->assertOk();

        $this->assertSame('Nová verze', $fotka->fresh()->caption);
        $this->assertSame([], $this->stav()->dluh(), 'Úspěšně zapsaný klíč nemá v dluhu co dělat.');

        // A další zápis čehokoli jiného popisek nevrátí.
        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['grid' => 'big']])->assertOk();

        $this->assertSame('Nová verze', $fotka->fresh()->caption);
    }

    /** Když i nový zápis selže, dluh nese **novější** hodnotu, ne tu nejstarší. */
    public function test_selhany_novy_zapis_prepise_dluh_novejsi_hodnotou(): void
    {
        $vadna = $this->fotka();

        $this->dluh(['edits' => [$vadna->uuid => ['caption' => 'Stará', 'tags' => [['nejde']]]]]);

        $this->actingAs($this->adri)
            ->patchJson('/api/state', ['data' => ['edits' => [$vadna->uuid => ['caption' => 'Nová', 'tags' => [['nejde']]]]]])
            ->assertOk();

        $this->assertSame('Nová', $this->stav()->dluh()['edits'][$vadna->uuid]['caption']);
    }

    /**
     * Srdíčka ze starého dluhu bez autora se nepřehrají pod partnerem.
     *
     * Dřív šel dluh `favs` bez údaje, čí je, a zaplatil ho ten, kdo zapisoval
     * jako další — jejích srdíček se tak stala jeho.
     */
    public function test_cizi_srdicka_z_dluhu_se_partnerovi_nezapisou(): void
    {
        $foto = $this->fotka();
        $this->dluh(['favs' => [$foto->uuid => true]]);

        $this->actingAs($this->maki)->patchJson('/api/state', ['data' => ['grid' => 'big']])->assertOk();

        $this->assertFalse(DB::table('user_favorites')->where('user_id', $this->maki->id)->exists(),
            'Srdíčka z dluhu bez autora se nesmí zapsat tomu, kdo zrovna zapisuje.');
    }

    /** Partner nesmí odebrat srdíčko, které má v databázi, kvůli cizímu dluhu s `false`. */
    public function test_cizi_dluh_s_false_partnerovi_srdicko_neodebere(): void
    {
        $foto = $this->fotka();
        DB::table('user_favorites')->insert(['user_id' => $this->maki->id, 'media_item_id' => $foto->id, 'created_at' => now()]);
        $this->dluh(['favs' => [$foto->uuid => false]]);

        $this->actingAs($this->maki)->patchJson('/api/state', ['data' => ['grid' => 'big']])->assertOk();

        $this->assertTrue(DB::table('user_favorites')->where('user_id', $this->maki->id)->where('media_item_id', $foto->id)->exists());
    }

    /**
     * Srdíčka v dluhu nesou autora a zaplatí je jen on.
     *
     * Její nepovedené srdíčko počká, až bude zapisovat ona — partnerův zápis
     * ho nepřehraje, ale ani nezahodí.
     */
    public function test_srdicka_v_dluhu_zaplati_jen_jejich_autor(): void
    {
        $foto = $this->fotka();

        // Napodobená chyba, ne zahozená tabulka — viz `SelhavajiciTabulka`.
        $this->rozbijTabulku('user_favorites');
        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['favs' => [$foto->uuid => true]]])->assertOk();
        $this->opravTabulku('user_favorites');

        $this->actingAs($this->maki)->patchJson('/api/state', ['data' => ['grid' => 'big']])->assertOk();

        $this->assertFalse(DB::table('user_favorites')->where('user_id', $this->maki->id)->exists());
        $this->assertNotSame([], $this->stav()->dluh(), 'Cizí dluh se partnerovým zápisem nesmí zahodit.');

        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['grid' => 'med']])->assertOk();

        $this->assertTrue(DB::table('user_favorites')->where('user_id', $this->adri->id)->where('media_item_id', $foto->id)->exists());
        $this->assertSame([], $this->stav()->dluh());
    }

    // ——— pomůcky ———

    private function dluh(array $dluh): void
    {
        CoupleState::forCouple($this->prostor->id)->forceFill(['data' => ['__dluh' => $dluh]])->save();
    }

    private function stav(): CoupleState
    {
        return CoupleState::where('couple_id', $this->prostor->id)->sole();
    }

    private function fotka(): MediaItem
    {
        static $poradi = 0;
        $poradi++;

        return MediaItem::create([
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
            'taken_at' => '2026-09-01 10:00:00',
            'uploaded_at' => '2026-09-01 11:00:00',
            'status' => 'ready',
            'storage_status' => 'local',
        ]);
    }
}
