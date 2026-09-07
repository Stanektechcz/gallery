<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sdílení a systém ve tvaru, ve kterém je kreslí prototyp.
 *
 * Odkazy, hosté i časové kapsle mají v aplikaci vlastní tabulky; prototyp z nich
 * neukazoval nic — pět napsaných odkazů na doménu, která nikam nevede, a dvě
 * fotky od babičky, které nikdo nenahrál.
 */
class ObsahSdileniTest extends TestCase
{
    use RefreshDatabase;

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

        Sanctum::actingAs($this->adri);
    }

    /** Bez odkazů, hostů a kapslí se nic neposílá. */
    public function test_bez_obsahu_se_skupina_neposila(): void
    {
        $this->assertSame([], $this->getJson('/api/data/sdileni')->assertOk()->json('data'));
    }

    /**
     * Odkaz nese platnost, ochranu i počet otevření.
     *
     * Prototyp podle toho obarvuje řádek: expirovaný odkaz se nemá tvářit jako
     * živý.
     */
    public function test_odkaz_nese_platnost_ochranu_i_otevreni(): void
    {
        $this->odkaz([
            'name' => 'Vánoce pro mamku',
            'password_hash' => 'x',
            'expires_at' => now()->addDays(6),
            'use_count' => 38,
        ]);

        $this->odkaz([
            'name' => 'Zadar — výběr',
            'expires_at' => now()->subDay(),
            'use_count' => 112,
        ]);

        $odkazy = collect($this->getJson('/api/data/sdileni')->assertOk()->json('data.SHARES'))
            ->keyBy('name');

        $this->assertSame('Heslo', $odkazy['Vánoce pro mamku']['protection']);
        $this->assertSame('Za 6 dní', $odkazy['Vánoce pro mamku']['expires']);
        $this->assertSame('tag-accent', $odkazy['Vánoce pro mamku']['expTag']);
        $this->assertSame('38', $odkazy['Vánoce pro mamku']['views']);

        $this->assertSame('Bez hesla', $odkazy['Zadar — výběr']['protection']);
        $this->assertSame('Expirovalo', $odkazy['Zadar — výběr']['expires']);
        $this->assertSame('tag-neutral', $odkazy['Zadar — výběr']['expTag']);
    }

    /** Odkaz bez expirace se nemá tvářit, že vypršel. */
    public function test_odkaz_bez_expirace(): void
    {
        $this->odkaz(['name' => 'Napořád', 'expires_at' => null]);

        $this->assertSame(
            'Bez expirace',
            $this->getJson('/api/data/sdileni')->assertOk()->json('data.SHARES.0.expires'),
        );
    }

    /**
     * Fronta od hostů se seskupuje po lidech.
     *
     * Prototyp píše „3 fotky od Kláry" — jeden řádek na člověka, ne tři na
     * jednu večeři.
     */
    public function test_fronta_hostu_se_seskupi_po_lidech(): void
    {
        $odkaz = $this->odkaz(['name' => 'Beskydy s Makinkou', 'allow_guest_upload' => true]);

        foreach (range(1, 3) as $i) {
            $this->nahravkaHosta($odkaz, 'Klára');
        }
        $this->nahravkaHosta($odkaz, 'Mamka');
        // Schválená fotka je v knihovně a ve frontě nemá co dělat.
        $this->nahravkaHosta($odkaz, 'Klára', 'approved');

        $fronta = collect($this->getJson('/api/data/sdileni')->assertOk()->json('data.GUEST_Q'))
            ->keyBy('who');

        $this->assertCount(2, $fronta);
        $this->assertSame('3 fotky', $fronta['Klára']['what']);
        $this->assertSame('Kláry', $fronta['Klára']['whom']);
        $this->assertSame('Beskydy s Makinkou', $fronta['Klára']['share']);
        $this->assertSame('1 fotka', $fronta['Mamka']['what']);
    }

    /**
     * Zapečetěná kapsle se posílá bez textu.
     *
     * Celý smysl je, že se otevře v den, na který se čeká — a obsah leží
     * v prohlížeči, kde se dá přečíst.
     */
    public function test_zapecetena_kapsle_neposila_text(): void
    {
        $this->kapsle([
            'title' => 'Dopis k pátému roku',
            'message' => 'Až tohle otevřeš, budeme mít za sebou pátý rok.',
            'deliver_at' => now()->addYear(),
            'status' => 'sealed',
        ]);

        $this->kapsle([
            'title' => 'Vzkaz z prvního nájmu',
            'message' => 'Pořád spíme na matraci na zemi.',
            'deliver_at' => now()->subMonth(),
            'status' => 'delivered',
        ]);

        $kapsle = collect($this->getJson('/api/data/sdileni')->assertOk()->json('data.KAPS'))
            ->keyBy('title');

        $this->assertSame('', $kapsle['Dopis k pátému roku']['body']);
        $this->assertSame('Pořád spíme na matraci na zemi.', $kapsle['Vzkaz z prvního nájmu']['body']);
        $this->assertSame('Adrian', $kapsle['Dopis k pátému roku']['from']);
    }

    /**
     * Trezor odsud nechodí vůbec.
     *
     * Tenhle poskytovatel ho posílal na každé načtení stránky bez ohledu na
     * zámek, takže obsah byl v prohlížeči dřív, než si obrazovka řekla o heslo.
     * Dodává ho `system`, a jen s odemčeným trezorem — viz `TrezorTest`.
     */
    public function test_trezor_odsud_nechodi(): void
    {
        $this->fotka(['is_hidden' => true]);
        $this->fotka(['is_hidden' => false], 2);

        $data = (array) $this->getJson('/api/data/sdileni')->assertOk()->json('data');

        $this->assertArrayNotHasKey('VAULT_ITEMS', $data);
    }

    /** Balíčky do offline se počítají z knihovny, ne z katalogu. */
    public function test_baliky_do_offline_se_pocitaji_z_knihovny(): void
    {
        $this->fotka(['is_favorite' => true, 'taken_at' => now()]);
        $this->fotka(['taken_at' => now()], 2);

        $baliky = collect($this->getJson('/api/data/sdileni')->assertOk()->json('data.OFFPACKS'))
            ->keyBy(0);

        $this->assertSame('2 položky v náhledové kvalitě', $baliky['letos'][2]);
        $this->assertSame('1 položka v plné kvalitě', $baliky['fav'][2]);
    }

    // ——— zpátky ze stavu do databáze ———

    /**
     * Vložení do trezoru fotku schová doopravdy.
     *
     * Jinak by ji druhý z dvojice dál viděl v mřížce — u trezoru dost podstatný
     * rozdíl.
     */
    public function test_vlozeni_do_trezoru_schova_fotku(): void
    {
        $foto = $this->fotka();

        $this->patchJson('/api/state', ['data' => ['vaultAdded' => [$foto->uuid]]])->assertOk();

        $this->assertTrue($foto->refresh()->is_hidden);
    }

    /** Vyjmutí z trezoru ji vrátí do knihovny. */
    public function test_vyjmuti_z_trezoru_fotku_vrati(): void
    {
        $foto = $this->fotka(['is_hidden' => true]);

        $this->patchJson('/api/state', ['data' => ['vaultAdded' => []]])->assertOk();

        $this->assertFalse($foto->refresh()->is_hidden);
    }

    /** Trezor se do stavu neukládá — jediná pravda je knihovna. */
    public function test_trezor_se_do_stavu_neuklada(): void
    {
        $foto = $this->fotka();

        $odpoved = $this->patchJson('/api/state', ['data' => ['vaultAdded' => [$foto->uuid]]])->assertOk();

        $this->assertArrayNotHasKey('vaultAdded', (array) $odpoved->json('data'));
    }

    /** Cizí fotka se do trezoru dostat nedá. */
    public function test_cizi_fotka_se_neschova(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $ciziFoto = $this->fotka([
            'gallery_space_id' => $ciziProstor->id,
            'owner_user_id' => $cizi->id,
            'uploaded_by' => $cizi->id,
        ], 9);

        $this->patchJson('/api/state', ['data' => ['vaultAdded' => [$ciziFoto->uuid]]])->assertOk();

        $this->assertFalse($ciziFoto->refresh()->is_hidden);
    }

    /** Odkazy jiného páru se do odpovědi nedostanou. */
    public function test_odkazy_jineho_paru_se_neposilaji(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $this->odkaz(['name' => 'Náš']);
        $this->odkaz(['name' => 'Cizí', 'gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id]);

        $odkazy = collect($this->getJson('/api/data/sdileni')->assertOk()->json('data.SHARES'))->pluck('name');

        $this->assertSame(['Náš'], $odkazy->all());
    }

    // ——— pomůcky ———

    private function odkaz(array $navic = []): int
    {
        return DB::table('shared_links')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'token' => Str::random(32),
            'created_by' => $this->adri->id,
            'gallery_space_id' => $this->prostor->id,
            'target_type' => 'album',
            'target_id' => null,
            'name' => 'Odkaz',
            'allow_download' => true,
            'allow_guest_upload' => false,
            'show_metadata' => true,
            'use_count' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }

    private function nahravkaHosta(int $odkaz, string $kdo, string $stav = 'pending'): void
    {
        DB::table('guest_uploads')->insert([
            'uuid' => (string) Str::uuid(),
            'shared_link_id' => $odkaz,
            'original_filename' => 'host.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'storage_path' => 'hoste/host.jpg',
            'contributor_name' => $kdo,
            'status' => $stav,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function kapsle(array $navic = []): void
    {
        DB::table('time_capsules')->insert(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Kapsle',
            'message' => null,
            'deliver_at' => now()->addYear(),
            'status' => 'sealed',
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
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
            'taken_at' => now(),
            'uploaded_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }
}
