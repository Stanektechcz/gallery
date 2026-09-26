<?php

namespace Tests\Feature\Galerie;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\PrihlaseniZarizeni;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Úklid prošlých přihlášení zařízení (`gallery:uklid-prihlaseni`).
 *
 * Nahrazuje `sanctum:prune-expired`, který maže **každý** token s prošlou
 * `expires_at` — i klíč k API zrušený z administrace. Zrušený klíč se ale
 * schválně nemaže (`PersonalAccessToken::zrusen()`): v seznamu má zůstat jako
 * zrušený, aby se dalo zpětně zjistit, který klíč to byl a kdy přestal platit.
 */
class UklidPrihlaseniTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create();
    }

    public function test_smaze_prihlaseni_prosle_pred_vic_nez_48_hodinami(): void
    {
        $this->prihlaseni('stary-telefon');
        $this->travel(PrihlaseniZarizeni::PLATNOST_DNI)->days();
        $this->travel(49)->hours();

        $this->artisan('gallery:uklid-prihlaseni')->assertSuccessful();

        $this->assertSame([], $this->jmena());
    }

    /**
     * Čerstvě prošlé přihlášení ještě zůstává.
     *
     * Dva dny rezervy: kdo se vrátí k telefonu den po vypršení, uvidí
     * přihlašovací obrazovku, ne chybu — a v administraci je řádek pořád
     * vidět jako prošlý.
     */
    public function test_necha_prihlaseni_prosle_pred_mene_nez_48_hodinami(): void
    {
        $this->prihlaseni('telefon');
        $this->travel(PrihlaseniZarizeni::PLATNOST_DNI)->days();
        $this->travel(47)->hours();

        $this->artisan('gallery:uklid-prihlaseni')->assertSuccessful();

        $this->assertSame(['telefon'], $this->jmena());
    }

    /** Zrušený klíč k API zůstává v seznamu jako zrušený — i dlouho po zrušení. */
    public function test_zruseny_klic_k_api_zustane(): void
    {
        $klic = $this->adri->createToken('Záloha', ['read'])->accessToken;
        // Totéž, co dělá „Zrušit" v administraci (`AdminController::destroyKey`).
        $klic->forceFill(['expires_at' => now()])->save();

        $this->travel(30)->days();
        $this->artisan('gallery:uklid-prihlaseni')->assertSuccessful();

        $this->assertTrue(PersonalAccessToken::whereKey($klic->id)->exists());
        $this->assertTrue(PersonalAccessToken::findOrFail($klic->id)->zrusen());
    }

    public function test_platne_tokeny_zustanou(): void
    {
        $this->prihlaseni('telefon');
        $this->adri->createToken('Skript', ['*']);
        // Starší přihlášení bez platnosti — hlídá ho jen limit nečinnosti.
        $this->adri->createToken('stary-prohlizec');

        $this->travel(10)->days();
        $this->artisan('gallery:uklid-prihlaseni')->assertSuccessful();

        $this->assertSame(['Skript', 'stary-prohlizec', 'telefon'], $this->jmena());
    }

    /** Plánovač spouští tenhle příkaz pod jménem, které zná administrace. */
    public function test_planovac_spousti_uklid_pod_jmenem_token_prune(): void
    {
        $uloha = collect(app(Schedule::class)->events())->first(fn ($u) => $u->description === 'token-prune');

        $this->assertNotNull($uloha, 'Úloha `token-prune` v plánovači chybí.');
        $this->assertStringContainsString('gallery:uklid-prihlaseni', (string) $uloha->command);
        $this->assertStringNotContainsString('sanctum:prune-expired', (string) $uloha->command);
    }

    private function prihlaseni(string $zarizeni): void
    {
        PrihlaseniZarizeni::vydej($this->adri, $zarizeni);
    }

    /** @return list<string> */
    private function jmena(): array
    {
        return PersonalAccessToken::orderBy('name')->pluck('name')->all();
    }
}
