<?php

namespace Tests\Feature\Meny;

use App\Models\FinanceCategory;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Hlavní měna prostoru je CZK, další nabízené jsou EUR a USD.
 *
 * Předvolby (`home_currency`, `travel_currency`) jde nastavit jen na tuhle trojici —
 * jsou to měny, ve kterých modul umí počítat souhrny. Výpisy z banky, peněženky
 * a rezervace na cestách naproti tomu musí přijmout libovolný třípísmenný kód: výpis
 * z Revolutu běžně nese GBP nebo PLN a ty se do knihy zapisují, i když se v nich
 * nepočítá rozpočet.
 */
class NastaveniMenTest extends TestCase
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

        FinanceCategory::nachystej($this->space->id);
    }

    /** Nabízené měny jsou jen CZK, EUR, USD — v nich modul umí počítat souhrny. */
    public function test_home_currency_prijme_jen_nabizenou_menu(): void
    {
        $this->patchJson('/api/v1/rozpocet/nastaveni', ['home_currency' => 'GBP'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('home_currency');
    }

    public function test_travel_currency_prijme_jen_nabizenou_menu(): void
    {
        $this->patchJson('/api/v1/rozpocet/nastaveni', ['travel_currency' => 'PLN'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('travel_currency');
    }

    /** Velikost písmen nesmí rozhodovat — „eur" je nabízená měna stejně jako „EUR". */
    public function test_mena_se_uppercasuje_pred_validaci(): void
    {
        $this->patchJson('/api/v1/rozpocet/nastaveni', ['home_currency' => 'eur', 'travel_currency' => 'usd'])
            ->assertOk()
            ->assertJsonPath('settings.home_currency', 'EUR')
            ->assertJsonPath('settings.travel_currency', 'USD');
    }

    /** Obrazovka dostane nabízené měny rovnou s předvolbami, ne dalším požadavkem. */
    public function test_predvolby_nesou_nabizene_meny(): void
    {
        $this->getJson('/api/v1/rozpocet/nastaveni')
            ->assertOk()
            ->assertJsonPath('settings.currency_options', ['CZK', 'EUR', 'USD']);
    }

    /**
     * Peněženka v cizí měně dál projde.
     *
     * Nabídka CZK/EUR/USD platí jen pro předvolby modulu Rozpočet — účet vedený
     * v librách existuje (třeba z pobytu v Anglii) a založit se musí dát dál.
     */
    public function test_penezenka_v_libre_projde(): void
    {
        $this->postJson('/api/v1/rozpocet/ucty', [
            'name' => 'Anglický účet', 'kind' => 'bank', 'currency' => 'gbp',
        ])->assertCreated();

        $this->assertSame('GBP', Wallet::where('name', 'Anglický účet')->value('currency'));
    }

    /**
     * Výpis z banky v librách se dál zapíše na librový účet.
     *
     * Revolut do výpisu píše měnu každého řádku zvlášť a dvojice v Anglii platí
     * v librách — omezení na CZK/EUR/USD by takový výpis zahodilo celý.
     */
    public function test_vypis_v_libre_na_librovem_uctu_se_zapise(): void
    {
        $ucet = Wallet::create([
            'gallery_space_id' => $this->space->id, 'name' => 'Librový účet',
            'kind' => 'bank', 'currency' => 'GBP', 'opening_balance' => 0, 'is_active' => true,
        ]);

        $hlavicka = "Type,Product,Started Date,Completed Date,Description,Amount,Fee,Currency,State,Balance\n";
        $radek = "CARD_PAYMENT,Current,2026-09-05 12:00:00,2026-09-05 14:22:10,Tesco,-12.50,0.00,GBP,COMPLETED,100\n";

        $this->post('/api/finance/import', [
            'vypis' => UploadedFile::fake()->createWithContent('revolut.csv', $hlavicka.$radek),
            'ucet' => $ucet->uuid,
        ], ['Accept' => 'application/json'])->assertStatus(201)->assertJsonPath('zapsano', 1);

        $this->assertSame('GBP', Transaction::where('description', 'Tesco')->value('currency_from'));
    }
}
