<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Chybná validace na webové cestě vrací klientovi, který čeká JSON, 422.
 *
 * Staré rozhraní ukládá přes `axios` i webové cesty (`PATCH /albums/{uuid}`
 * v nastavení alba). Na chybu jim server posílal přesměrování zpět; prohlížeč
 * ho u XHR tiše následuje, dostane 200 s HTML stránky a `axios` ohlásí úspěch —
 * `catch` s hláškou „nepodařilo se uložit" se nespustil nikdy a chyby se ztratily.
 *
 * Inertia a obyčejný formulář chtějí dál přesměrování s chybami v sezení.
 *
 * Stav se tu porovnává přes `assertSame`, ne `assertStatus`: když selže
 * `assertStatus` na přesměrování, Laravel do hlášky přidává chyby ze sezení
 * a s `session.serialization = json` jsou po uložení pole — místo „čekal 422,
 * přišlo 302" by test spadl na „Call to a member function all() on array".
 */
class JsonKlientWebovychCestTest extends TestCase
{
    use RefreshDatabase;

    private const HLAVICKY_AXIOS = [
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'application/json, text/plain, */*',
    ];

    private const HLAVICKY_INERTIA = [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ];

    private User $adri;

    private Album $album;

    protected function setUp(): void
    {
        parent::setUp();

        // `users.role` čte staré rozhraní (AlbumPolicy); galerie má role v členství.
        $this->adri = User::factory()->create(['name' => 'Adrian', 'role' => 'owner']);
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        $this->album = Album::create([
            'gallery_space_id' => $prostor->id,
            'title' => 'Léto',
            'slug' => 'leto',
            'visibility' => 'shared',
            'created_by' => $this->adri->id,
            'updated_by' => $this->adri->id,
        ]);
    }

    public function test_json_klient_webove_cesty_dostane_422_s_chybami(): void
    {
        $odpoved = $this->actingAs($this->adri)
            ->patchJson('/albums/'.$this->album->uuid, ['visibility' => 'nesmysl']);

        $this->assertSame(422, $odpoved->status());
        $odpoved->assertJsonValidationErrors('visibility');
        $this->assertSame('shared', DB::table('albums')->where('id', $this->album->id)->value('visibility'));
    }

    public function test_axios_z_nastaveni_alba_dostane_422_misto_tiche_presmerovani(): void
    {
        $odpoved = $this->actingAs($this->adri)
            ->json('PATCH', '/albums/'.$this->album->uuid, ['latitude' => 999], self::HLAVICKY_AXIOS);

        $this->assertSame(422, $odpoved->status());
        $odpoved->assertJsonValidationErrors('latitude');
        $this->assertNull(DB::table('albums')->where('id', $this->album->id)->value('latitude'));
    }

    public function test_plati_i_pro_validaci_v_kontroleru_jine_webove_cesty(): void
    {
        // `PATCH /privacy/legacy` vrací jen JSON; přesměrování tu nemá kam vést.
        $odpoved = $this->actingAs($this->adri)
            ->patchJson('/privacy/legacy', ['status' => 'nesmysl', 'inactivity_months' => 12]);

        $this->assertSame(422, $odpoved->status());
        $odpoved->assertJsonValidationErrors('status');
    }

    public function test_inertia_dostane_dal_presmerovani_s_chybami(): void
    {
        $odpoved = $this->actingAs($this->adri)
            ->from('/albums/'.$this->album->uuid)
            ->withHeaders(self::HLAVICKY_INERTIA)
            ->patch('/albums/'.$this->album->uuid, ['visibility' => 'nesmysl']);

        $odpoved->assertRedirect('/albums/'.$this->album->uuid);
        $odpoved->assertSessionHasErrors('visibility');
    }

    public function test_obycejny_formular_dostane_dal_presmerovani_s_chybami(): void
    {
        $this->actingAs($this->adri)
            ->from('/albums/'.$this->album->uuid)
            ->patch('/albums/'.$this->album->uuid, ['visibility' => 'nesmysl'])
            ->assertRedirect('/albums/'.$this->album->uuid)
            ->assertSessionHasErrors('visibility');
    }
}
