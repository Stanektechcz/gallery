<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\Place;
use App\Models\PlaceReview;
use App\Models\Recipe;
use App\Models\SharedLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SharedLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $adrian;

    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adrian = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->space = GallerySpace::create([
            'uuid' => \Str::uuid(),
            'name' => 'Test',
            'slug' => 'test',
            'owner_id' => $this->adrian->id,
        ]);
        $this->space->members()->attach($this->adrian->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true]);
    }

    /** @test */
    public function test_can_create_shared_link(): void
    {
        $response = $this->actingAs($this->adrian)
            ->postJson('/shares', [
                'target_type' => 'selection',
                'allow_download' => true,
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['token', 'url']);
        $this->assertDatabaseHas('shared_links', ['created_by' => $this->adrian->id, 'target_type' => 'selection']);
    }

    /** @test */
    public function test_shared_link_with_password(): void
    {
        $response = $this->actingAs($this->adrian)
            ->postJson('/shares', [
                'target_type' => 'selection',
                'password' => 'secret1234',
            ]);

        $token = $response->json('token');
        $this->assertDatabaseHas('shared_links', ['token' => $token]);

        $link = SharedLink::where('token', $token)->first();
        $this->assertNotNull($link->password_hash);
        $this->assertTrue(\Hash::check('secret1234', $link->password_hash));
    }

    /** @test */
    public function test_shared_link_with_expiry(): void
    {
        $expiry = now()->addDay()->format('Y-m-d H:i:s');

        $response = $this->actingAs($this->adrian)
            ->postJson('/shares', [
                'target_type' => 'selection',
                'expires_at' => $expiry,
            ]);

        $token = $response->json('token');
        $link = SharedLink::where('token', $token)->first();
        $this->assertNotNull($link->expires_at);
    }

    /** @test */
    public function test_can_delete_shared_link(): void
    {
        $response = $this->actingAs($this->adrian)
            ->postJson('/shares', ['target_type' => 'selection']);

        $token = $response->json('token');
        $link = SharedLink::where('token', $token)->first();

        $this->actingAs($this->adrian)
            ->deleteJson("/shares/{$link->id}")
            ->assertOk();

        $this->assertDatabaseMissing('shared_links', ['id' => $link->id]);
    }

    /** @test */
    public function test_recipe_can_be_shared_without_exposing_private_cooking_history(): void
    {
        $recipe = Recipe::create([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->adrian->id,
            'title' => 'Naše lasagne', 'summary' => 'Rodinný recept.', 'category' => 'main_course',
            'difficulty' => 'medium', 'status' => 'published', 'base_servings' => 2,
            'prep_minutes' => 15, 'cook_minutes' => 40, 'currency' => 'CZK',
        ]);
        $recipe->ingredients()->create(['section' => 'Omáčka', 'name' => 'Rajčata', 'quantity' => 400, 'unit' => 'g']);
        $recipe->steps()->create(['title' => 'Připravit', 'instruction' => 'Uvařte omáčku.', 'sort_order' => 0]);
        $recipe->cookingSessions()->create([
            'uuid' => (string) Str::uuid(), 'created_by' => $this->adrian->id, 'status' => 'completed',
            'servings' => 2, 'partner_feedback' => 'Toto je jen pro nás.', 'currency' => 'CZK',
        ]);

        $response = $this->actingAs($this->adrian)->postJson('/api/v1/shares', [
            'target_type' => 'recipe', 'target_uuid' => $recipe->uuid,
            'name' => 'Recept pro rodinu', 'allow_download' => true, 'allow_guest_upload' => true,
        ])->assertOk()->assertJsonStructure(['id', 'token', 'url']);

        $this->assertDatabaseHas('shared_links', [
            'target_type' => 'recipe', 'target_id' => $recipe->id, 'gallery_space_id' => $this->space->id,
            'allow_download' => false, 'allow_guest_upload' => false, 'hide_gps' => true, 'show_metadata' => false,
        ]);
        $this->get('/s/'.$response->json('token'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shares/Content')
            ->where('content.type', 'recipe')
            ->where('content.title', 'Naše lasagne')
            ->where('content.data.ingredients.0.name', 'Rajčata')
            ->where('content.data.steps.0.instruction', 'Uvařte omáčku.')
            ->missing('content.data.cooking_sessions')
            ->missing('content.data.partner_feedback'));
    }

    /**
     * Sdílený recept vidí i host přihlášený do jiné galerie — a fotka
     * z trezoru se na veřejnou stránku nedostane, ani jako titulní.
     */
    public function test_shared_recipe_for_foreign_signed_in_guest_hides_vault_photos(): void
    {
        $trezor = MediaItem::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id,
            'owner_user_id' => $this->adrian->id, 'uploaded_by' => $this->adrian->id,
            'original_filename' => 'TREZOR.jpg', 'safe_filename' => 'trezor.jpg', 'extension' => 'jpg',
            'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 10,
            'uploaded_at' => now(), 'status' => 'ready', 'storage_status' => 'local',
        ]);
        $trezor->forceFill(['is_hidden' => true])->save();

        $recipe = Recipe::create([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->adrian->id, 'cover_media_id' => $trezor->id,
            'title' => 'Naše lasagne', 'category' => 'main_course', 'difficulty' => 'medium',
            'status' => 'published', 'base_servings' => 2, 'currency' => 'CZK',
        ]);
        $recipe->media()->attach($trezor->id, ['role' => 'gallery', 'created_at' => now()]);

        $link = SharedLink::create([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->adrian->id,
            'token' => 'tok'.Str::random(20), 'name' => 'Recept', 'target_type' => 'recipe', 'target_id' => $recipe->id,
        ]);

        $babicka = User::factory()->create(['is_active' => true]);
        $jejiGalerie = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Babička', 'slug' => 'babicka', 'owner_id' => $babicka->id]);
        $jejiGalerie->members()->attach($babicka->id, ['role' => 'owner']);

        $this->actingAs($babicka)->get('/s/'.$link->token)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shares/Content')
            ->where('content.title', 'Naše lasagne')
            ->where('content.data.cover', null)
            ->where('content.data.media', []));
    }

    /** @test */
    public function test_published_own_place_review_can_be_shared_without_internal_follow_up_note_or_gps(): void
    {
        $place = Place::create([
            'gallery_space_id' => $this->space->id, 'name' => 'Bistro U parku', 'type' => 'restaurant',
            'city' => 'Brno', 'address' => 'Parková 1', 'latitude' => 49.2, 'longitude' => 16.6,
            'created_by' => $this->adrian->id,
        ]);
        $review = PlaceReview::create([
            'gallery_space_id' => $this->space->id, 'place_id' => $place->id,
            'author_user_id' => $this->adrian->id, 'status' => 'published', 'overall_rating' => 5,
            'service_rating' => 4, 'currency' => 'CZK', 'positives' => 'Milá obsluha.',
            'notes' => 'Výborná večeře.', 'next_time_note' => 'Soukromě: objednat stůl u okna.',
        ]);
        $review->items()->create(['category' => 'food', 'name' => 'Rizoto', 'overall_rating' => 5, 'currency' => 'CZK']);

        $response = $this->actingAs($this->adrian)->postJson('/api/v1/shares', [
            'target_type' => 'place_review', 'target_uuid' => $review->uuid,
        ])->assertOk();

        $this->get('/s/'.$response->json('token'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Shares/Content')
            ->where('content.type', 'place_review')
            ->where('content.data.place.name', 'Bistro U parku')
            ->where('content.data.ratings.overall', 5)
            ->where('content.data.items.0.name', 'Rizoto')
            ->missing('content.data.next_time_note')
            ->missing('content.data.place.latitude')
            ->missing('content.data.place.longitude'));
    }

    // ——— heslo k odkazu ———

    /**
     * Heslo k odkazu má limit na odkaz, ne jen na adresu.
     *
     * Limit na adresu obejde každý, kdo má víc adres (mobilní síť, proxy).
     * Odkaz sám přitom chrání fotky, které dvojice někomu poslala.
     */
    public function test_heslo_k_odkazu_ma_limit_na_odkaz(): void
    {
        $odkaz = $this->odkazSHeslem();

        for ($i = 1; $i <= 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->post("/s/{$odkaz->token}/verify", ['password' => 'spatne-'.$i])
                ->assertRedirect();
        }

        // Stav přímo: `assertStatus` na přesměrování padá uvnitř vendoru.
        $this->assertSame(429, $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
            ->post("/s/{$odkaz->token}/verify", ['password' => 'spatne-21'])->status());

        // Ani správné heslo po vyčerpání neprojde — jinak by odpověď prozradila,
        // že se trefil.
        $this->assertSame(429, $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.2'])
            ->post("/s/{$odkaz->token}/verify", ['password' => 'tajne-heslo'])->status());
        $this->assertNull(session("share_verified_{$odkaz->token}"));
    }

    /** Správné heslo počítadlo vynuluje — host s překlepy si nezamkne příště. */
    public function test_spravne_heslo_vynuluje_pocitadlo(): void
    {
        $odkaz = $this->odkazSHeslem();

        // Každý pokus z jiné adresy: limit na adresu je užší a tady nejde o něj.
        for ($i = 1; $i <= 19; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.1.{$i}"])
                ->post("/s/{$odkaz->token}/verify", ['password' => 'spatne'])
                ->assertRedirect();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.3.1'])
            ->post("/s/{$odkaz->token}/verify", ['password' => 'tajne-heslo'])
            ->assertRedirect(route('share.show', $odkaz->token));

        // Po úspěchu je k dispozici zase celých dvacet pokusů.
        for ($i = 1; $i <= 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.2.{$i}"])
                ->post("/s/{$odkaz->token}/verify", ['password' => 'spatne'])
                ->assertRedirect();
        }
    }

    public function test_heslo_k_odkazu_ma_aspon_sest_znaku(): void
    {
        $this->actingAs($this->adrian)
            ->postJson('/shares', ['target_type' => 'selection', 'password' => '1234'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    /**
     * Host, který nahrává fotky, si tím nezamkne heslo ani vzkaz.
     *
     * Limity bez předpony počítadla sdílí jeden klíč (adresa), bez ohledu
     * na cestu: deset nahrání vyčerpalo i heslo a vzkazy.
     */
    public function test_nahravani_neubira_z_limitu_hesla_a_vzkazu(): void
    {
        $odkaz = $this->odkazSHeslem(['allow_comments' => true]);

        for ($i = 1; $i <= 10; $i++) {
            $this->post("/s/{$odkaz->token}/upload", []);
        }

        $this->assertNotSame(429, $this->post("/s/{$odkaz->token}/verify", ['password' => 'spatne'])->status());
        $this->assertNotSame(429, $this->postJson("/s/{$odkaz->token}/vzkaz", ['jmeno' => 'Babička', 'text' => 'Ahoj'])->status());
    }

    // ——— počet použití ———

    /**
     * Odkaz „na jedno otevření" počítá návštěvu, ne každé načtení.
     *
     * `use_count` rostl s každým GET — obnovení stránky i náhled odkazu
     * v chatu (robot, který si stránku stáhne). Kdo stránku otevřel jako
     * poslední povolený, pak už nemohl stáhnout fotku, na kterou se díval.
     */
    public function test_jedno_pouziti_pusti_navstevnika_ke_stazeni(): void
    {
        Storage::fake('public');
        $fotka = $this->fotkaSOriginalem();
        $odkaz = SharedLink::create([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->adrian->id,
            'target_type' => 'media', 'target_id' => $fotka->id,
            'allow_download' => true, 'max_uses' => 1,
        ]);

        $this->get("/s/{$odkaz->token}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Shares/Show'));
        $this->assertSame(1, (int) $odkaz->fresh()->use_count);

        $this->get("/s/{$odkaz->token}/media/{$fotka->uuid}/download")->assertOk();

        // Obnovení stránky v témž sezení nic nepřičte.
        $this->get("/s/{$odkaz->token}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Shares/Show'));
        $this->assertSame(1, (int) $odkaz->fresh()->use_count);

        // Nový návštěvník za limit neprojde.
        $this->flushSession();
        $this->get("/s/{$odkaz->token}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Shares/Expired'));
        $this->get("/s/{$odkaz->token}/media/{$fotka->uuid}/download")->assertForbidden();
        $this->assertSame(1, (int) $odkaz->fresh()->use_count);
    }

    // ——— kdo smí sdílet ———

    /**
     * Host cizí galerie z ní nesdílí.
     *
     * Obsah ke sdílení se hledal ve všech prostorech účtu a oprávnění
     * rozhodovalo i `users.role` — to má `owner` každý zaregistrovaný. Host
     * galerie X, který má vlastní galerii, tak mohl vystavit veřejný odkaz
     * na recept dvojice X.
     */
    public function test_host_cizi_galerie_z_ni_nesdili(): void
    {
        $recept = Recipe::create([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->adrian->id,
            'title' => 'Tajný recept', 'category' => 'main_course', 'difficulty' => 'medium',
            'status' => 'published', 'base_servings' => 2, 'currency' => 'CZK',
        ]);

        $host = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        // Vlastní galerie je u něj první (výchozí) — brána `dvojice` ho tak pustí
        // do aplikace a o cizí galerii rozhoduje až sdílení samo.
        $vlastni = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Host', 'slug' => 'host', 'owner_id' => $host->id, 'is_default' => true]);
        $vlastni->members()->attach($host->id, ['role' => 'owner']);
        $this->space->members()->attach($host->id, ['role' => 'viewer']);

        $this->actingAs($host)->postJson('/api/v1/shares', [
            'target_type' => 'recipe', 'target_uuid' => $recept->uuid,
        ])->assertNotFound();

        $this->assertDatabaseMissing('shared_links', ['target_type' => 'recipe', 'target_id' => $recept->id]);
    }

    private function odkazSHeslem(array $navic = []): SharedLink
    {
        return SharedLink::create(array_merge([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->adrian->id,
            'target_type' => 'selection', 'password_hash' => bcrypt('tajne-heslo'),
        ], $navic));
    }

    private function fotkaSOriginalem(): MediaItem
    {
        $m = MediaItem::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id,
            'owner_user_id' => $this->adrian->id, 'uploaded_by' => $this->adrian->id,
            'original_filename' => 'IMG.jpg', 'safe_filename' => 'img.jpg', 'extension' => 'jpg',
            'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 4,
            'uploaded_at' => now(), 'status' => 'ready', 'storage_status' => 'local',
        ]);
        $cesta = 'media/'.$m->uuid.'/original.jpg';
        Storage::disk('public')->put($cesta, 'jpeg');
        DB::table('media_variants')->insert([
            'media_item_id' => $m->id, 'type' => 'original', 'disk' => 'public', 'path' => $cesta,
            'size_bytes' => 4, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $m;
    }

    /** @test */
    public function test_draft_or_another_persons_review_cannot_be_shared(): void
    {
        $partner = User::factory()->create(['role' => 'partner']);
        $this->space->members()->attach($partner->id, ['role' => 'editor', 'can_share' => true]);
        $place = Place::create(['gallery_space_id' => $this->space->id, 'name' => 'Kavárna', 'type' => 'cafe', 'created_by' => $this->adrian->id]);
        $draft = PlaceReview::create([
            'gallery_space_id' => $this->space->id, 'place_id' => $place->id,
            'author_user_id' => $this->adrian->id, 'status' => 'draft', 'currency' => 'CZK',
        ]);
        $published = PlaceReview::create([
            'gallery_space_id' => $this->space->id, 'place_id' => $place->id,
            'author_user_id' => $this->adrian->id, 'status' => 'published', 'overall_rating' => 4, 'currency' => 'CZK',
        ]);

        $this->actingAs($this->adrian)->postJson('/api/v1/shares', ['target_type' => 'place_review', 'target_uuid' => $draft->uuid])->assertNotFound();
        $this->actingAs($partner)->postJson('/api/v1/shares', ['target_type' => 'place_review', 'target_uuid' => $published->uuid])->assertNotFound();
    }
}
