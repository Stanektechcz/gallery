<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Provoz\AdministraceZasahy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pozvánka nesmí otevřít cizí účet.
 *
 * `invitation_accepted_at` nemají vyplněné účty ze seedu ani čekající pozvaní
 * jiných galerií. Pozvánka takovému účtu přepsala `invitation_token` a odkaz
 * vrátila volajícímu — vlastník *jiné* galerie si pak přes `/invite/{token}`
 * nastavil nové heslo a byl přihlášený jako Adrian.
 */
class PozvankaCizihoUctuTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $nase;

    private User $cizi;

    private GallerySpace $jina;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();

        // Adrian jako ze seedu: používaný účet s heslem, ale bez data přijetí pozvánky.
        $this->adri = User::factory()->create([
            'name' => 'Adrian',
            'email' => 'adrian@vzpominky.test',
            'password' => Hash::make('adrianovo-heslo'),
        ]);
        $this->nase = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->nase->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        $this->cizi = User::factory()->create(['name' => 'Cizí vlastník', 'email' => 'cizi@jinde.test']);
        $this->jina = GallerySpace::create(['name' => 'Jiná galerie', 'owner_id' => $this->cizi->id]);
        $this->jina->members()->syncWithoutDetaching([$this->cizi->id => ['role' => 'owner']]);
    }

    public function test_vlastnik_jine_galerie_neprevezme_ucet_ze_seedu(): void
    {
        $this->assertNull($this->adri->invitation_accepted_at, 'Předpoklad: účet ze seedu datum přijetí nemá.');
        $heslo = $this->adri->password;

        Sanctum::actingAs($this->cizi);

        $odpoved = $this->postJson('/api/admin/users', ['email' => 'adrian@vzpominky.test'])->assertStatus(422);

        $this->assertStringContainsString('účet', (string) $odpoved->json('message'));
        $this->assertNull($odpoved->json('invite_url'), 'Odkaz do cizího účtu se nesmí vrátit.');

        $this->adri->refresh();
        $this->assertNull($this->adri->invitation_token, 'Cizí pozvánka nesmí účtu nasadit token.');
        $this->assertSame($heslo, $this->adri->password);
        $this->assertFalse($this->jina->members()->where('users.id', $this->adri->id)->exists());
    }

    public function test_nelze_prevzit_cekajici_pozvanku_jine_galerie(): void
    {
        Sanctum::actingAs($this->adri);
        $this->postJson('/api/admin/users', ['email' => 'klara@vzpominky.test'])->assertOk();

        $klara = User::where('email', 'klara@vzpominky.test')->sole();
        $token = $klara->invitation_token;
        $heslo = $klara->password;
        $this->assertNotNull($token);

        Sanctum::actingAs($this->cizi);
        $this->postJson('/api/admin/users', ['email' => 'klara@vzpominky.test'])->assertStatus(422);

        $klara->refresh();
        $this->assertSame($token, $klara->invitation_token, 'Pozvánka Adriana musí zůstat jeho.');
        $this->assertSame($heslo, $klara->password);
        $this->assertFalse($this->jina->members()->where('users.id', $klara->id)->exists());
    }

    /** Stejné pravidlo platí i pro záměr poslaný jako změna stavu. */
    public function test_stavova_cesta_cizi_ucet_neprevezme(): void
    {
        Sanctum::actingAs($this->cizi);

        $this->patchJson('/api/state', ['data' => [
            'admUsers' => [
                ['id' => (string) $this->cizi->id, 'name' => 'Cizí vlastník', 'role' => 'vlastník', 'state' => 'aktivní'],
                ['id' => 'u9'.time(), 'name' => 'Adrian', 'mail' => 'adrian@vzpominky.test', 'role' => 'host', 'state' => 'pozvaná'],
            ],
        ]])->assertOk();

        $this->assertNull($this->adri->refresh()->invitation_token);
        $this->assertFalse($this->jina->members()->where('users.id', $this->adri->id)->exists());
    }

    /**
     * „Poslat znovu" jen tam, kde pozvánka opravdu čeká.
     *
     * Člen galerie ze seedu nemá datum přijetí, takže opětovné zaslání mu
     * nasadilo token a vlastník dostal odkaz na nastavení cizího hesla.
     */
    public function test_opetovne_zaslani_neotevre_ucet_bez_cekajici_pozvanky(): void
    {
        $makinka = User::factory()->create(['name' => 'Makinka']);
        $this->nase->members()->syncWithoutDetaching([$makinka->id => ['role' => 'editor']]);

        Sanctum::actingAs($this->adri);
        $this->postJson('/api/admin/users/'.$makinka->id.'/resend')->assertStatus(422);

        $this->assertNull($makinka->refresh()->invitation_token);
    }

    public function test_pozvanka_po_tydnu_vyprsi(): void
    {
        $klara = $this->pozviKlaru();
        $heslo = $klara->password;

        $this->travel(8)->days();

        $this->get('/invite/'.$klara->invitation_token)->assertRedirect('/login');
        $this->post('/invite/'.$klara->invitation_token, [
            'password' => 'nove-heslo-klary',
            'password_confirmation' => 'nove-heslo-klary',
        ])->assertRedirect('/login');

        $this->assertSame($heslo, $klara->refresh()->password);
        $this->assertNull($klara->invitation_accepted_at);
        $this->assertGuest('web');
    }

    public function test_pozvanka_v_tydnu_plati_s_heslem_na_deset_znaku(): void
    {
        $klara = $this->pozviKlaru();

        $this->travel(6)->days();

        // Stejné minimum jako obnova a změna hesla (deset znaků).
        $this->post('/invite/'.$klara->invitation_token, [
            'password' => 'kratke12',
            'password_confirmation' => 'kratke12',
        ])->assertSessionHasErrors('password');

        $this->post('/invite/'.$klara->invitation_token, [
            'password' => 'dost-dlouhe-heslo',
            'password_confirmation' => 'dost-dlouhe-heslo',
        ])->assertRedirect('/timeline');

        $this->assertTrue(Hash::check('dost-dlouhe-heslo', $klara->refresh()->password));
    }

    /**
     * Pozvánka přímo přes službu — přijetí běží ve webovém sezení a
     * `Sanctum::actingAs` by přepnulo výchozí strážce na token.
     */
    private function pozviKlaru(): User
    {
        $vysledek = app(AdministraceZasahy::class)->pozvi($this->nase, $this->adri, 'klara@vzpominky.test');
        $this->assertNotNull($vysledek);

        return $vysledek['user'];
    }
}
