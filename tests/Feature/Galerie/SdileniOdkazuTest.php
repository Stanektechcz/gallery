<?php

namespace Tests\Feature\Galerie;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\SharedLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sdílený odkaz opravdu vznikne — a dá se otevřít.
 *
 * Obrazovka slibovala odkaz, který někomu pošlete, a celý ho držela ve stavu
 * prohlížeče: adresa byla náhodná čtyři písmena, token se nikde nezaložil
 * a po odhlášení odkaz zmizel.
 */
class SdileniOdkazuTest extends TestCase
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

        Sanctum::actingAs($this->adri);
    }

    /** Odkaz na album vznikne v tabulce a jde ho otevřít. */
    public function test_odkaz_na_album_vznikne(): void
    {
        $album = $this->album();

        $odpoved = $this->postJson('/api/sdileni', [
            'name' => 'Beskydy s Makinkou',
            'expirace' => '7',
            'album' => $album->uuid,
            'stahovani' => true,
            'metadata' => true,
            'komentare' => true,
            'hoste' => false,
        ])->assertOk();

        $odkaz = SharedLink::sole();

        $this->assertSame('Beskydy s Makinkou', $odkaz->name);
        $this->assertSame('album', $odkaz->target_type);
        $this->assertSame($album->id, $odkaz->target_id);
        $this->assertTrue($odkaz->allow_comments, 'Přepínač vzkazů se dosud nedal z prototypu zapnout.');
        $this->assertNotNull($odkaz->expires_at);
        $this->assertNull($odkaz->password_hash);

        // Adresa musí být ta skutečná — zkrácená z tabulky se nedá poslat.
        $this->assertSame(route('share.show', $odkaz->token), $odpoved->json('odkaz'));
        $this->assertSame('Beskydy s Makinkou', $odpoved->json('data.SHARES.0.name'));
        $this->assertSame($odkaz->id, $odpoved->json('data.SHARES.0.id'));
    }

    /** Vybrané položky se k odkazu připojí. */
    public function test_odkaz_na_vyber_polozek(): void
    {
        $prvni = $this->fotka('IMG_1.jpg');
        $druha = $this->fotka('IMG_2.jpg');

        $this->postJson('/api/sdileni', [
            'name' => '2 vybrané fotky',
            'expirace' => 'nikdy',
            'polozky' => [$prvni->uuid, $druha->uuid],
        ])->assertOk();

        $odkaz = SharedLink::sole();

        $this->assertSame('selection', $odkaz->target_type);
        $this->assertNull($odkaz->expires_at, 'Bez expirace znamená bez expirace.');
        $this->assertEqualsCanonicalizing([$prvni->id, $druha->id], $odkaz->mediaItems()->pluck('media_items.id')->all());
    }

    /** Odkaz bez obsahu se nezakládá. */
    public function test_odkaz_bez_obsahu_neprojde(): void
    {
        $this->postJson('/api/sdileni', ['name' => 'Nic', 'expirace' => '7'])->assertStatus(422);

        $this->assertSame(0, SharedLink::count());
    }

    /** Položka z trezoru se sdílet nedá. */
    public function test_schovana_polozka_se_nesdili(): void
    {
        $schovana = $this->fotka('tajne.jpg', ['is_hidden' => true]);

        $this->postJson('/api/sdileni', [
            'name' => 'Pokus', 'expirace' => '7', 'polozky' => [$schovana->uuid],
        ])->assertStatus(422);

        $this->assertSame(0, SharedLink::count());
    }

    /** Krátké heslo neprojde — u fotek, které dvojice poslala, není ochranou. */
    public function test_kratke_heslo_neprojde(): void
    {
        $this->postJson('/api/sdileni', [
            'name' => 'Pokus', 'expirace' => '7', 'album' => $this->album()->uuid, 'heslo' => 'abc',
        ])->assertStatus(422);
    }

    /** Heslo se ukládá zahašované, ne v čitelné podobě. */
    public function test_heslo_se_ulozi_zahasovane(): void
    {
        $this->postJson('/api/sdileni', [
            'name' => 'Zadar — výběr', 'expirace' => '30', 'album' => $this->album()->uuid, 'heslo' => 'letnizadar',
        ])->assertOk();

        $odkaz = SharedLink::sole();

        $this->assertNotSame('letnizadar', $odkaz->password_hash);
        $this->assertTrue(Hash::check('letnizadar', $odkaz->password_hash));
        $this->assertSame('Heslo', $this->getJson('/api/data/sdileni')->assertOk()->json('data.SHARES.0.protection'));
    }

    /**
     * Úprava bez hesla heslo nesmaže.
     *
     * Dialog heslo nezná — server ho v čitelné podobě nemá a mít nebude.
     * Kdyby se přebíralo prázdné pole, otevřelo by uložení jiné změny odkaz
     * všem, kdo znají adresu.
     */
    public function test_uprava_bez_hesla_heslo_zachova(): void
    {
        $odkaz = $this->odkaz(['password_hash' => Hash::make('letnizadar')]);

        $this->patchJson('/api/sdileni/'.$odkaz->id, [
            'name' => 'Jiný název', 'expirace' => '7', 'komentare' => true,
        ])->assertOk();

        $odkaz->refresh();

        $this->assertSame('Jiný název', $odkaz->name);
        $this->assertTrue($odkaz->allow_comments);
        $this->assertTrue(Hash::check('letnizadar', $odkaz->password_hash));
    }

    /** A výslovné vypnutí hesla ho smaže. */
    public function test_vypnute_heslo_se_smaze(): void
    {
        $odkaz = $this->odkaz(['password_hash' => Hash::make('letnizadar')]);

        $this->patchJson('/api/sdileni/'.$odkaz->id, [
            'name' => 'Bez hesla', 'expirace' => '7', 'bez_hesla' => true,
        ])->assertOk();

        $this->assertNull($odkaz->refresh()->password_hash);
    }

    /** Zneplatnění odkaz odstraní — a z obrazovky zmizí hned. */
    public function test_zneplatneni_odkaz_odstrani(): void
    {
        $odkaz = $this->odkaz();

        $odpoved = $this->deleteJson('/api/sdileni/'.$odkaz->id)->assertOk();

        $this->assertSame(0, SharedLink::count());
        $this->assertContains('SHARES', $odpoved->json('prazdne'));
    }

    /** Cizí odkaz nejde ani upravit, ani zneplatnit. */
    public function test_cizi_odkaz_je_404(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $odkaz = SharedLink::create([
            'created_by' => $cizi->id, 'gallery_space_id' => $ciziProstor->id,
            'target_type' => 'album', 'target_id' => 1, 'name' => 'Cizí odkaz', 'is_active' => true,
        ]);

        $this->deleteJson('/api/sdileni/'.$odkaz->id)->assertNotFound();
        $this->patchJson('/api/sdileni/'.$odkaz->id, ['name' => 'Můj', 'expirace' => '7'])->assertNotFound();

        $this->assertSame(1, SharedLink::count());
    }

    /** Bez přihlášení se odkaz nezaloží. */
    public function test_bez_prihlaseni_neprojde(): void
    {
        $this->app['auth']->forgetGuards();
        auth()->guard('sanctum')->forgetUser();

        $this->postJson('/api/sdileni', ['name' => 'Pokus', 'expirace' => '7'])->assertUnauthorized();
    }

    // ——— pomůcky ———

    private function album(): Album
    {
        return Album::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Beskydy → Pustevny',
            'slug' => 'beskydy-pustevny',
            'type' => 'manual',
        ]);
    }

    private function fotka(string $jmeno, array $navic = []): MediaItem
    {
        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => $jmeno,
            'safe_filename' => $jmeno,
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1000,
            'uploaded_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }

    private function odkaz(array $navic = []): SharedLink
    {
        return SharedLink::create(array_merge([
            'created_by' => $this->adri->id,
            'gallery_space_id' => $this->prostor->id,
            'target_type' => 'album',
            'target_id' => $this->album()->id,
            'name' => 'Beskydy s Makinkou',
            'is_active' => true,
        ], $navic));
    }
}
