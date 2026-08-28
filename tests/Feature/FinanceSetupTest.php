<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\FinanceProject;
use App\Models\GallerySpace;
use App\Models\Partner;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Správa účtů, cest a kategorií.
 *
 * Všechno tady stojí na jednom pravidle: historie se nepřepisuje. Testy míří přesně
 * na místa, kde se to dá porušit tak, že si toho nikdo hned nevšimne — smazání
 * použitého účtu, tichý posun počátečního zůstatku, druhá aktivní cesta.
 */
class FinanceSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $uzivatel;
    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uzivatel = User::factory()->create();
        $this->space = GallerySpace::create(['name' => 'Zkouška', 'owner_id' => $this->uzivatel->id]);
        $this->uzivatel->gallerySpaces()->syncWithoutDetaching([$this->space->id => ['role' => 'owner']]);
        $this->actingAs($this->uzivatel);
    }

    private function ucet(string $jmeno, string $mena, float $pocatek = 0): Wallet
    {
        return Wallet::create([
            'gallery_space_id' => $this->space->id, 'name' => $jmeno, 'kind' => 'bank',
            'currency' => $mena, 'opening_balance' => $pocatek, 'is_active' => true,
        ]);
    }

    private function vydaj(Wallet $z, float $castka, ?int $kategorie = null, ?int $cesta = null): Transaction
    {
        return Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'expense',
            'occurred_at' => now()->toDateString(), 'wallet_from_id' => $z->id,
            'amount_from' => $castka, 'currency_from' => $z->currency,
            'category_id' => $kategorie, 'finance_project_id' => $cesta,
            'state' => 'approved', 'created_by' => $this->uzivatel->id,
        ]);
    }

    public function test_zalozeni_uctu(): void
    {
        $odpoved = $this->postJson('/api/v1/rozpocet/ucty', [
            'name' => 'EUR karta', 'kind' => 'card', 'currency' => 'eur', 'opening_balance' => 250,
        ])->assertCreated();

        $u = collect($odpoved->json('wallets'))->firstWhere('name', 'EUR karta');

        $this->assertSame('EUR', $u['currency'], 'Měna se ukládá velkými písmeny.');
        $this->assertEqualsWithDelta(250, $u['balance'], 0.001);
    }

    /** Počáteční zůstatek není příjem — nesmí se objevit v součtech. */
    public function test_pocatecni_zustatek_neni_prijem(): void
    {
        $this->postJson('/api/v1/rozpocet/ucty', [
            'name' => 'Hotovost', 'kind' => 'cash', 'currency' => 'EUR', 'opening_balance' => 500,
        ])->assertCreated();

        $prehled = $this->getJson('/api/v1/rozpocet/prehled')->assertOk();

        $this->assertSame([], $prehled->json('summary'), 'Založení účtu nevytvoří žádný pohyb.');
        $this->assertEqualsWithDelta(500, collect($prehled->json('balances'))->firstWhere('currency', 'EUR')['total'], 0.001);
    }

    /** Použitý účet nejde smazat — a hláška nabídne odložení. */
    public function test_pouzity_ucet_nejde_smazat(): void
    {
        $u = $this->ucet('EUR', 'EUR', 1000);
        $this->vydaj($u, 50);

        $odpoved = $this->deleteJson("/api/v1/rozpocet/ucty/{$u->uuid}")->assertStatus(409);

        $this->assertStringContainsString('odložit', $odpoved->json('message'));
        $this->assertSame(1, Wallet::count());

        // Odložení projde a historii nechá být.
        $this->patchJson("/api/v1/rozpocet/ucty/{$u->uuid}", ['is_active' => false])->assertOk();
        $this->assertSame(1, Transaction::count());
        $this->assertFalse($u->fresh()->is_active);
    }

    /** Prázdný účet smazat jde. */
    public function test_prazdny_ucet_smazat_jde(): void
    {
        $u = $this->ucet('Nepoužitý', 'CZK');

        $this->deleteJson("/api/v1/rozpocet/ucty/{$u->uuid}")->assertOk();
        $this->assertNull(Wallet::find($u->id));
    }

    /** Počáteční zůstatek se u používaného účtu tiše posunout nedá. */
    public function test_pocatecni_zustatek_nejde_posunout_zpetne(): void
    {
        $u = $this->ucet('EUR', 'EUR', 1000);
        $this->vydaj($u, 100);

        $odpoved = $this->patchJson("/api/v1/rozpocet/ucty/{$u->uuid}", ['opening_balance' => 5000])->assertStatus(409);

        $this->assertStringContainsString('korekci', $odpoved->json('message'));
        $this->assertEqualsWithDelta(1000, (float) $u->fresh()->opening_balance, 0.001);

        // Přejmenovat ale jde, i když se účet používá.
        $this->patchJson("/api/v1/rozpocet/ucty/{$u->uuid}", ['name' => 'EUR Revolut'])->assertOk();
        $this->assertSame('EUR Revolut', $u->fresh()->name);
    }

    /** Korekce vznikne jako zapsaný rozdíl s důvodem, ne přepsáním čísla. */
    public function test_korekce_zustatku(): void
    {
        $u = $this->ucet('Hotovost', 'EUR', 500);
        $this->vydaj($u, 100);   // podle knihy zbývá 400

        $odpoved = $this->postJson("/api/v1/rozpocet/ucty/{$u->uuid}/korekce", [
            'actual_balance' => 380, 'reason' => 'Někde se ztratilo 20 €',
        ])->assertOk();

        $this->assertEqualsWithDelta(-20, $odpoved->json('difference'), 0.001);
        $this->assertEqualsWithDelta(380, collect($odpoved->json('wallets'))->firstWhere('name', 'Hotovost')['balance'], 0.001);

        // Vznikl dohledatelný záznam, ne tichá změna.
        $korekce = Transaction::where('description', 'like', 'Korekce%')->first();
        $this->assertNotNull($korekce);
        $this->assertStringContainsString('Někde se ztratilo', $korekce->description);

        // A do rozpočtu se nepočítá — nikdo za ni nic nekoupil.
        $this->assertTrue($korekce->excluded_from_budget);
        $this->assertFalse($korekce->countsTowardsBudget());
    }

    public function test_korekce_kdyz_zustatek_sedi(): void
    {
        $u = $this->ucet('EUR', 'EUR', 500);

        $odpoved = $this->postJson("/api/v1/rozpocet/ucty/{$u->uuid}/korekce", [
            'actual_balance' => 500, 'reason' => 'kontrola',
        ])->assertOk();

        $this->assertSame(0, $odpoved->json('difference'));
        $this->assertSame(0, Transaction::count(), 'Zbytečná korekce nevytvoří prázdný záznam.');
    }

    // ---------------------------------------------------------------- cesty

    public function test_zalozeni_cesty_i_s_rozpoctem(): void
    {
        $odpoved = $this->postJson('/api/v1/rozpocet/cesty', [
            'name' => 'Drážďany', 'country' => 'Německo', 'city' => 'Drážďany',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(9)->toDateString(),
            'base_currency' => 'EUR', 'budget_amount' => 1000, 'reserve_amount' => 100,
            'activate' => true,
        ])->assertCreated();

        $this->assertSame('Drážďany', $odpoved->json('trip.name'));
        $this->assertTrue($odpoved->json('trip.is_active'));
        $this->assertSame(10, $odpoved->json('trip.days_total'));
        // (1000 − 0 − 100) / 10 dní včetně dneška
        $this->assertEqualsWithDelta(90, $odpoved->json('trip.safe_daily.per_day'), 0.001);
    }

    /** Rezerva větší než rozpočet nedává smysl a neprojde. */
    public function test_rezerva_nesmi_prevysit_rozpocet(): void
    {
        $this->postJson('/api/v1/rozpocet/cesty', [
            'name' => 'Nesmysl', 'starts_on' => now()->toDateString(),
            'base_currency' => 'EUR', 'budget_amount' => 500, 'reserve_amount' => 900,
        ])->assertStatus(422);

        $this->assertSame(0, FinanceProject::count());
    }

    /** Konec před začátkem neprojde. */
    public function test_konec_nesmi_byt_pred_zacatkem(): void
    {
        $this->postJson('/api/v1/rozpocet/cesty', [
            'name' => 'Pozpátku', 'starts_on' => now()->toDateString(),
            'ends_on' => now()->subDays(3)->toDateString(), 'base_currency' => 'EUR',
        ])->assertStatus(422)->assertJsonValidationErrors('ends_on');
    }

    /** Aktivace druhé cesty zhasne první. */
    public function test_aktivni_cesta_je_jedna(): void
    {
        $a = $this->postJson('/api/v1/rozpocet/cesty', ['name' => 'První', 'starts_on' => now()->toDateString(),
            'base_currency' => 'EUR', 'activate' => true])->json('trip.uuid');
        $b = $this->postJson('/api/v1/rozpocet/cesty', ['name' => 'Druhá', 'starts_on' => now()->toDateString(),
            'base_currency' => 'EUR'])->json('trip.uuid');

        $this->postJson("/api/v1/rozpocet/cesty/{$b}/aktivovat")->assertOk();

        $cesty = collect($this->getJson('/api/v1/rozpocet/cesty')->json('trips'));

        $this->assertFalse($cesty->firstWhere('uuid', $a)['is_active']);
        $this->assertTrue($cesty->firstWhere('uuid', $b)['is_active']);
        $this->assertSame(1, $cesty->where('is_active', true)->count());
    }

    /** Cesta se záznamy nejde smazat. */
    public function test_cesta_se_zaznamy_nejde_smazat(): void
    {
        $u = $this->ucet('EUR', 'EUR', 1000);
        $cesta = FinanceProject::create(['gallery_space_id' => $this->space->id, 'kind' => 'trip',
            'name' => 'Berlín', 'starts_on' => now()->toDateString(), 'base_currency' => 'EUR']);

        $this->vydaj($u, 40, null, $cesta->id);

        $this->deleteJson("/api/v1/rozpocet/cesty/{$cesta->uuid}")->assertStatus(409);
        $this->assertSame(1, FinanceProject::count());
    }

    /** Ukončení cesty vrátí shrnutí — čísla, ne soubor. */
    public function test_ukonceni_cesty_da_shrnuti(): void
    {
        FinanceCategory::nachystej($this->space->id);
        $jidlo = FinanceCategory::where('gallery_space_id', $this->space->id)->where('name', 'Potraviny')->first();

        $u = $this->ucet('EUR', 'EUR', 2000);
        $cesta = FinanceProject::create(['gallery_space_id' => $this->space->id, 'kind' => 'trip',
            'name' => 'Berlín', 'starts_on' => now()->subDays(3)->toDateString(),
            'ends_on' => now()->toDateString(), 'base_currency' => 'EUR', 'budget_amount' => 600]);

        $this->vydaj($u, 120, $jidlo->id, $cesta->id);
        $this->vydaj($u, 80, $jidlo->id, $cesta->id);

        $odpoved = $this->postJson("/api/v1/rozpocet/cesty/{$cesta->uuid}/ukoncit")->assertOk();

        $this->assertSame('closed', $odpoved->json('trip.state'));
        $this->assertFalse($odpoved->json('trip.is_active'));
        $this->assertEqualsWithDelta(600, $odpoved->json('summary.budget'), 0.001);
        $this->assertEqualsWithDelta(200, $odpoved->json('summary.spent'), 0.001);
        $this->assertEqualsWithDelta(400, $odpoved->json('summary.difference'), 0.001);
        $this->assertSame('Potraviny', $odpoved->json('summary.top_categories.0.name'));
        $this->assertSame(2, $odpoved->json('summary.transactions'));
    }

    // ------------------------------------------------------------ kategorie

    /** Použitá kategorie nejde smazat, ale jde odložit. */
    public function test_pouzita_kategorie_nejde_smazat(): void
    {
        FinanceCategory::nachystej($this->space->id);
        $k = FinanceCategory::where('gallery_space_id', $this->space->id)->where('name', 'Doprava')->first();

        $u = $this->ucet('EUR', 'EUR', 500);
        $this->vydaj($u, 12, $k->id);

        $this->deleteJson("/api/v1/rozpocet/kategorie/{$k->uuid}")->assertStatus(409);

        $odlozena = $this->patchJson("/api/v1/rozpocet/kategorie/{$k->uuid}", ['is_active' => false])->assertOk();

        // Zmizí z nabídky ve formuláři, ale u staré transakce zůstane.
        $nabidka = collect($this->getJson('/api/v1/rozpocet/ciselniky')->json('categories'));
        $this->assertFalse($nabidka->contains('name', 'Doprava'));

        $seznam = $this->getJson('/api/v1/rozpocet/transakce')->json('transactions');
        $this->assertSame('Doprava', $seznam[0]['category']['name']);
    }

    public function test_vlastni_kategorie(): void
    {
        FinanceCategory::nachystej($this->space->id);

        $odpoved = $this->postJson('/api/v1/rozpocet/kategorie', [
            'name' => 'Sauna', 'kind' => 'expense', 'color' => 'var(--graf-7)', 'is_favourite' => true,
        ])->assertCreated();

        $this->assertTrue(collect($odpoved->json('categories'))->contains(
            fn ($k) => $k['name'] === 'Sauna' && $k['is_favourite'],
        ));
    }
}
