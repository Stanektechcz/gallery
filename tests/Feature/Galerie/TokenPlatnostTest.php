<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\PrihlaseniZarizeni;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Přihlášení zařízení má klouzavou platnost.
 *
 * Token z přihlášení leží v prohlížeči (`localStorage`) a dřív platil, dokud
 * se zařízení nepřestalo používat na tři měsíce — ukradený token (XSS, cizí
 * záloha prohlížeče, zapomenutý počítač) tak otevíral galerii prakticky
 * natrvalo. Teď platí šedesát dní od posledního použití: dvojice, která
 * aplikaci otevírá denně, se nikdy znovu přihlašovat nemusí, zapomenuté
 * zařízení se po dvou měsících odhlásí samo a úklid `gallery:uklid-prihlaseni`
 * jeho řádek smaže.
 *
 * Klíče k API z administrace platnost nedostávají — skript zálohy, který běží
 * jednou za čas, se nesmí jednoho dne tiše přestat přihlašovat.
 */
class TokenPlatnostTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create([
            'email' => 'adrian@vzpominky.test',
            'password' => Hash::make('zadar2026'),
        ]);

        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$prostor->id => ['role' => 'owner']]);
    }

    public function test_prihlaseni_heslem_plati_sedesat_dni(): void
    {
        $this->freezeTime();

        $this->prihlas();

        $token = $this->adri->tokens()->sole();

        $this->assertNotNull($token->expires_at, 'Token z přihlášení nesmí platit navždy.');
        $this->assertSame(now()->addDays(60)->getTimestamp(), $token->expires_at->getTimestamp());
    }

    /** Prošlý token neotevře stav — prototyp pak ukáže přihlašovací obrazovku (401). */
    public function test_prosly_token_neotevre_stav(): void
    {
        $token = $this->prihlas();

        $this->travel(61)->days();

        $this->withToken($token)->getJson('/api/state')->assertStatus(401);
    }

    /**
     * Používané zařízení se neodhlásí.
     *
     * Po deseti dnech zbývá z platnosti padesát dní; použití ji posune zase na
     * šedesát. Bez posunu by se dvojice musela znovu přihlašovat každé dva měsíce
     * i při každodenním používání.
     */
    public function test_pouzivany_token_se_prodlouzi(): void
    {
        $token = $this->prihlas();

        $this->travel(10)->days();
        $this->freezeTime();

        $this->withToken($token)->getJson('/api/state')->assertOk();

        $radek = $this->adri->tokens()->sole();
        $this->assertSame(now()->addDays(60)->getTimestamp(), $radek->expires_at->getTimestamp());

        // Pětašedesát dní od přihlášení — bez posunu by token už neplatil.
        $this->travel(55)->days();
        $this->resetAuth();

        $this->withToken($token)->getJson('/api/state')->assertOk();
    }

    /**
     * Posun platnosti se zapisuje nejvýš jednou denně.
     *
     * Každý náhled a každý zápis stavu jde přes tentýž token — zápis platnosti
     * při každém požadavku by byl další UPDATE navíc ke `last_used_at`.
     */
    public function test_prodlouzeni_zapisuje_nejvys_jednou_denne(): void
    {
        $token = $this->prihlas();

        $this->travel(2)->days();
        $this->withToken($token)->getJson('/api/state')->assertOk();
        $poPrvnim = $this->adri->tokens()->sole()->expires_at->getTimestamp();

        $zapisy = $this->zapisyPlatnosti(function () use ($token) {
            $this->travel(3)->hours();
            $this->resetAuth();
            $this->withToken($token)->getJson('/api/state')->assertOk();
            $this->resetAuth();
            $this->withToken($token)->getJson('/api/state')->assertOk();
        });

        $this->assertSame(0, $zapisy, 'Tentýž den se platnost znovu zapisovat nemá.');
        $this->assertSame($poPrvnim, $this->adri->tokens()->sole()->expires_at->getTimestamp());

        // Druhý den už ano.
        $zapisy = $this->zapisyPlatnosti(function () use ($token) {
            $this->travel(1)->days();
            $this->resetAuth();
            $this->withToken($token)->getJson('/api/state')->assertOk();
        });

        $this->assertSame(1, $zapisy);
    }

    /**
     * Zrušení, které přijde uprostřed požadavku, posun platnosti nepřepíše.
     *
     * Sanctum načte token a teprve pak ohlásí, že prošel; mezi tím ho druhé
     * zařízení může zrušit (`expires_at` = teď). Posun s načteným modelem by
     * zrušení tiše vrátil na dalších šedesát dní.
     */
    public function test_zruseni_behem_pozadavku_posun_neprepise(): void
    {
        $this->prihlas();
        $this->travel(10)->days();
        $this->freezeTime();

        $nacteny = $this->adri->tokens()->sole();
        PersonalAccessToken::query()->whereKey($nacteny->id)->update(['expires_at' => now()]);

        PrihlaseniZarizeni::prodluz($nacteny);
        $nacteny->save();

        $this->assertSame(now()->getTimestamp(), $this->adri->tokens()->sole()->expires_at->getTimestamp(),
            'Zrušený token se posunem platnosti nesmí znovu rozsvítit.');
    }

    /** Klíč k API z administrace platnost nemá — ani po použití ji nedostane. */
    public function test_klic_k_api_platnost_nema(): void
    {
        Sanctum::actingAs($this->adri);

        $klic = $this->postJson('/api/admin/keys', ['name' => 'Záloha', 'scope' => 'jen čtení'])
            ->assertOk()
            ->json('token');

        $this->assertNull(PersonalAccessToken::where('name', 'Záloha')->sole()->expires_at);

        $this->resetAuth();
        $this->travel(30)->days();

        $this->withToken($klic)->getJson('/api/state')->assertOk();

        $this->assertNull(PersonalAccessToken::where('name', 'Záloha')->sole()->expires_at);
    }

    /**
     * Starší přihlášení bez platnosti se neprodlužuje.
     *
     * Přihlašovací token se od klíče z administrace u starších řádků nedá
     * spolehlivě rozeznat (stejná oprávnění, jméno si volí klient), takže
     * dostat platnost by mohl i klíč skriptu. Starší řádky hlídá dál limit
     * nečinnosti (`gallery.token_idle_days`) a s dalším přihlášením je
     * nahradí token s platností.
     */
    public function test_starsi_token_bez_platnosti_se_neprodlouzi(): void
    {
        $token = $this->adri->createToken('telefon')->plainTextToken;

        $this->travel(5)->days();

        $this->withToken($token)->getJson('/api/state')->assertOk();

        $this->assertNull($this->adri->tokens()->sole()->expires_at);
    }

    /** Úklid prošlých přihlášení smaže řádek, platné nechá. */
    public function test_uklid_smaze_prosle_prihlaseni(): void
    {
        $this->prihlas('stary-telefon');

        $this->travel(59)->days();
        $this->prihlas('novy-telefon');

        // Starý telefon prošel před víc než 48 hodinami, nový platí.
        $this->travel(4)->days();
        $this->artisan('gallery:uklid-prihlaseni')->assertSuccessful();

        $this->assertSame(['novy-telefon'], $this->adri->tokens()->pluck('name')->all());
    }

    private function prihlas(string $zarizeni = 'telefon'): string
    {
        return (string) $this->postJson('/sanctum/token', [
            'email' => 'adrian@vzpominky.test',
            'password' => 'zadar2026',
            'device_name' => $zarizeni,
        ])->assertOk()->json('token');
    }

    /** Přihlášení z minulého požadavku by se jinak vzalo z paměti strážce. */
    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /** Kolikrát se během `$akce` zapsala platnost tokenu. */
    private function zapisyPlatnosti(callable $akce): int
    {
        $pocet = 0;

        DB::listen(function ($dotaz) use (&$pocet) {
            if (str_starts_with(strtolower($dotaz->sql), 'update')
                && str_contains($dotaz->sql, 'personal_access_tokens')
                && str_contains($dotaz->sql, 'expires_at')) {
                $pocet++;
            }
        });

        $akce();

        return $pocet;
    }
}
