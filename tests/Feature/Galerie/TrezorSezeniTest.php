<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Auth\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Odemčený trezor patří člověku, ne prohlížeči.
 *
 * Odemčení bylo jen číslo `vault_unlocked_until` v sezení a přihlášení sezení
 * nečistí (`regenerate()` data nechává, token a otisk na sezení nesahají
 * vůbec). Kdo se přihlásil do prohlížeče, kde si druhý před chvílí trezor
 * odemkl, měl ho odemčený taky — až patnáct minut, a klidně z jiného páru.
 *
 * A soubor z trezoru šel s `max-age` na den: po zamčení ho prohlížeč
 * ukázal z mezipaměti, aniž by se serveru na cokoli zeptal.
 */
class TrezorSezeniTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->adri = User::factory()->create(['name' => 'Adrian', 'role' => 'owner', 'password' => Hash::make('adrianovo-heslo')]);
        $this->maki = User::factory()->create(['name' => 'Makinka', 'role' => 'partner', 'password' => Hash::make('makino-heslo')]);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);
    }

    /** Adrianovo odemčení Makince trezor neotevře — ani seznam, ani soubor. */
    public function test_odemceni_druheho_trezor_neotevre(): void
    {
        $this->fotka(['trashed_at' => now()->subDay(), 'original_filename' => 'IMG_1.jpg']);
        $this->fotka(['trashed_at' => now()->subDay(), 'is_hidden' => true, 'original_filename' => 'pas-v-kosi.jpg']);
        $skryta = $this->fotka(['is_hidden' => true, 'original_filename' => 'pas.jpg']);
        Storage::disk('public')->put('media/'.$skryta->uuid.'/original.jpg', 'jpeg');

        $this->withSession($this->odemcenyTrezor($this->adri));
        Sanctum::actingAs($this->maki);

        $this->getJson('/api/trezor')->assertOk()->assertJson(['odemceno' => false, 'zbyva' => 0]);

        $data = $this->getJson('/api/data/system')->assertOk()->json('data');
        $this->assertSame(['IMG_1.jpg'], array_column($data['TRASH'], 'name'));
        $this->assertSame([], $data['VAULT_ITEMS']);
        $this->assertFalse($data['TREZOR']['odemceno']);

        $this->get('/files/media/'.$skryta->uuid.'/original?ext=jpg')->assertNotFound();

        // Adrian ho má odemčený dál — patří jemu.
        Sanctum::actingAs($this->adri);
        $this->getJson('/api/trezor')->assertOk()->assertJson(['odemceno' => true]);
        $this->get('/files/media/'.$skryta->uuid.'/original?ext=jpg')->assertOk();
    }

    /** Člověk z jiného páru, který se přihlásí do téhož prohlížeče, trezor odemčený nemá. */
    public function test_cizi_par_nedostane_odemceny_trezor(): void
    {
        $cizi = User::factory()->create(['name' => 'Cizí', 'role' => 'owner']);
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $ciziProstor->members()->syncWithoutDetaching([$cizi->id => ['role' => 'owner']]);
        $this->fotka(['gallery_space_id' => $ciziProstor->id, 'owner_user_id' => $cizi->id, 'uploaded_by' => $cizi->id, 'is_hidden' => true]);

        $this->withSession($this->odemcenyTrezor($this->adri));
        Sanctum::actingAs($cizi);

        $this->getJson('/api/trezor')->assertOk()->assertJson(['odemceno' => false]);
        $this->assertSame([], $this->getJson('/api/data/system')->assertOk()->json('data.VAULT_ITEMS'));
    }

    /** Čas bez člověka (sezení z doby před opravou) trezor neotevře. */
    public function test_samotny_cas_trezor_neotevre(): void
    {
        $this->withSession(['vault_unlocked_until' => now()->addMinutes(5)->timestamp]);
        Sanctum::actingAs($this->adri);

        $this->getJson('/api/trezor')->assertOk()->assertJson(['odemceno' => false]);
    }

    /** Odemknutí si zapamatuje, kdo odemkl; zamknutí zapomene obojí. */
    public function test_odemknuti_si_pamatuje_cloveka(): void
    {
        Sanctum::actingAs($this->adri);

        $this->postJson('/api/trezor/odemknout', ['heslo' => 'adrianovo-heslo'])->assertOk()->assertJson(['odemceno' => true]);
        $this->assertSame($this->adri->id, (int) session('vault_unlocked_by'));

        $this->postJson('/api/trezor/zamknout')->assertOk()->assertJson(['odemceno' => false]);
        $this->assertFalse(session()->has('vault_unlocked_until'));
        $this->assertFalse(session()->has('vault_unlocked_by'));
    }

    /**
     * Pomalý požadavek zamčení nevrátí.
     *
     * Laravel na konci každého požadavku zapíše celé sezení. Požadavek, který
     * začal před „Zamknout" (nahrávání, dlouhý výpis) a skončil po něm, zapsal
     * zpátky `vault_unlocked_*` ze svého začátku — trezor se potichu otevřel
     * až na patnáct minut. Tady je to napodobené doslova: snímek sezení
     * z doby odemčení se po zamčení vrátí do sezení.
     */
    public function test_pozdni_zapis_sezeni_zamceny_trezor_neotevre(): void
    {
        Sanctum::actingAs($this->adri);

        $this->postJson('/api/trezor/odemknout', ['heslo' => 'adrianovo-heslo'])->assertOk()->assertJson(['odemceno' => true]);
        $snimek = session()->all();

        $this->postJson('/api/trezor/zamknout')->assertOk()->assertJson(['odemceno' => false]);
        session()->put($snimek);

        $this->getJson('/api/trezor')->assertOk()->assertJson(['odemceno' => false, 'zbyva' => 0]);

        // Nové odemčení po zamčení platí jako dřív.
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'adrianovo-heslo'])->assertOk()->assertJson(['odemceno' => true]);
        $this->getJson('/api/trezor')->assertOk()->assertJson(['odemceno' => true]);
    }

    /**
     * Zamčení zavře trezor na všech zařízeních toho, kdo zamkl.
     *
     * Je to krok pro soukromí („někdo se mi dívá přes rameno") — telefon
     * s odemčeným trezorem na stole nesmí zůstat otevřený jen proto, že se
     * zamykalo z počítače. Partnerovo odemčení zůstane, patří jemu.
     */
    public function test_zamceni_zavre_trezor_i_na_druhem_zarizeni(): void
    {
        Sanctum::actingAs($this->adri);

        // Zařízení B (telefon): vlastní sezení s vlastním odemčením.
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'adrianovo-heslo'])->assertOk()->assertJson(['odemceno' => true]);
        $telefon = session()->all();

        // Zařízení A (počítač): jiné sezení, odemkne a zamkne.
        session()->flush();
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'adrianovo-heslo'])->assertOk();
        $this->postJson('/api/trezor/zamknout')->assertOk()->assertJson(['odemceno' => false]);

        // Makinčino odemčení (v jejím sezení) zamčení Adriana nezavře.
        session()->flush();
        Sanctum::actingAs($this->maki);
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'makino-heslo'])->assertOk();
        $makinka = session()->all();

        session()->flush();
        session()->put($telefon);
        Sanctum::actingAs($this->adri);
        $this->getJson('/api/trezor')->assertOk()->assertJson(['odemceno' => false]);

        session()->flush();
        session()->put($makinka);
        Sanctum::actingAs($this->maki);
        $this->getJson('/api/trezor')->assertOk()->assertJson(['odemceno' => true]);
    }

    /** Přihlášení není zamčení — odemčený trezor na jiném zařízení nezavře. */
    public function test_prihlaseni_nezavre_trezor_jinde(): void
    {
        Sanctum::actingAs($this->adri);
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'adrianovo-heslo'])->assertOk();
        $telefon = session()->all();

        // Přihlášení heslem na počítači (`Sanctum::actingAs` přepnul výchozí strážce).
        session()->flush();
        $this->app['auth']->shouldUse('web');
        $this->post('/login', ['email' => $this->adri->email, 'password' => 'adrianovo-heslo'])->assertRedirect();
        $this->assertAuthenticatedAs($this->adri);

        session()->flush();
        session()->put($telefon);
        Sanctum::actingAs($this->adri);
        $this->getJson('/api/trezor')->assertOk()->assertJson(['odemceno' => true]);
    }

    public function test_prihlaseni_heslem_zapomene_odemceni(): void
    {
        $this->withSession($this->odemcenyTrezor($this->adri))
            ->post('/login', ['email' => $this->maki->email, 'password' => 'makino-heslo'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($this->maki);
        $this->assertOdemceniZapomenute();
    }

    public function test_prihlaseni_aplikace_zapomene_odemceni(): void
    {
        $this->withSession($this->odemcenyTrezor($this->adri))
            ->postJson('/sanctum/token', ['email' => $this->maki->email, 'password' => 'makino-heslo', 'device_name' => 'telefon'])
            ->assertOk();

        $this->assertOdemceniZapomenute();
    }

    public function test_druhy_faktor_zapomene_odemceni(): void
    {
        $totp = app(TotpService::class);
        $tajemstvi = $totp->generateSecret();
        $this->maki->forceFill(['two_factor_secret' => $tajemstvi, 'two_factor_confirmed_at' => now()])->save();

        $this->withSession($this->odemcenyTrezor($this->adri) + ['two_factor.user_id' => $this->maki->id])
            ->post('/login/overeni', ['code' => $totp->at($tajemstvi, intdiv(time(), 30))])
            ->assertRedirect();

        $this->assertAuthenticatedAs($this->maki);
        $this->assertOdemceniZapomenute();
    }

    /** Soubor z trezoru se neukládá do mezipaměti prohlížeče; běžná fotka ano. */
    public function test_soubor_z_trezoru_nejde_do_mezipameti(): void
    {
        $skryta = $this->fotka(['is_hidden' => true]);
        $bezna = $this->fotka();
        Storage::disk('public')->put('media/'.$skryta->uuid.'/original.jpg', 'jpeg');
        Storage::disk('public')->put('media/'.$bezna->uuid.'/original.jpg', 'jpeg');

        $this->actingAs($this->adri)->withSession($this->odemcenyTrezor($this->adri));

        $trezor = (string) $this->get('/files/media/'.$skryta->uuid.'/original?ext=jpg')->assertOk()->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $trezor);
        $this->assertStringContainsString('private', $trezor);
        $this->assertStringNotContainsString('max-age=86400', $trezor);

        $normalni = (string) $this->get('/files/media/'.$bezna->uuid.'/original?ext=jpg')->assertOk()->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=86400', $normalni);
    }

    private function assertOdemceniZapomenute(): void
    {
        $this->assertFalse(session()->has('vault_unlocked_until'), 'Odemčení předchozího člověka přežilo přihlášení.');
        $this->assertFalse(session()->has('vault_unlocked_by'));
    }

    private function fotka(array $navic = []): MediaItem
    {
        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG.jpg',
            'safe_filename' => 'img.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1000,
            'taken_at' => now()->subDays(2),
            'uploaded_at' => now()->subDays(2),
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }
}
