<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\FinanceSettings;
use App\Models\GallerySpace;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Předvolby modulu.
 *
 * Tabulka existovala od začátku a nepoužívalo ji nic — dvacet sloupců, které se nikde
 * nečetly. Testy proto hlídají hlavně jedno: že se předvolba **někde projeví.**
 * Uložená hodnota, kterou nikdo nečte, je přepínač bez účinku, a ten je horší než
 * chybějící: jednou se s ním pohne, nic se nestane a od té chvíle nikdo nevěří ani
 * ostatním.
 */
class FinancePredvolbyTest extends TestCase
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

        Wallet::create([
            'gallery_space_id' => $this->space->id, 'name' => 'CZK', 'kind' => 'bank',
            'currency' => 'CZK', 'opening_balance' => 1000, 'is_active' => true,
        ]);

        FinanceCategory::nachystej($this->space->id);
    }

    /** Výchozí hodnoty odpovídají tomu, jak se modul choval dosud — nasazení nic nepřevrátí. */
    public function test_vychozi_hodnoty_zachovaji_dosavadni_chovani(): void
    {
        $this->getJson('/api/v1/rozpocet/nastaveni')
            ->assertOk()
            ->assertJsonPath('settings.default_tab', 'prehled')
            ->assertJsonPath('settings.default_period', 'mesic')
            ->assertJsonPath('settings.home_currency', 'CZK')
            ->assertJsonPath('settings.show_partner_balance', true);
    }

    public function test_zmena_se_ulozi_a_vrati(): void
    {
        $this->patchJson('/api/v1/rozpocet/nastaveni', [
            'default_tab' => 'rozpocty',
            'default_period' => 'cesta',
            'default_reserve' => 150,
            'show_partner_balance' => false,
        ])->assertOk()->assertJsonPath('settings.default_tab', 'rozpocty');

        $this->getJson('/api/v1/rozpocet/nastaveni')
            ->assertOk()
            ->assertJsonPath('settings.default_period', 'cesta')
            ->assertJsonPath('settings.show_partner_balance', false);

        $this->assertEqualsWithDelta(150, FinanceSettings::proProstor($this->space->id)->default_reserve, 0.01);
    }

    /** Měna se ukládá velkými písmeny — „eur" a „EUR" by byly dvě různé měny. */
    public function test_mena_se_normalizuje(): void
    {
        $this->patchJson('/api/v1/rozpocet/nastaveni', ['home_currency' => 'eur'])
            ->assertOk()->assertJsonPath('settings.home_currency', 'EUR');
    }

    /** Nesmyslná hodnota neprojde — jinak by se modul otevřel na neexistující záložce. */
    public function test_neplatna_zalozka_neprojde(): void
    {
        $this->patchJson('/api/v1/rozpocet/nastaveni', ['default_tab' => 'vymyslene'])
            ->assertStatus(422);

        $this->getJson('/api/v1/rozpocet/nastaveni')->assertJsonPath('settings.default_tab', 'prehled');
    }

    /**
     * Předvolby jdou s číselníky, ne dalším požadavkem.
     *
     * Formuláře je potřebují hned při otevření; druhé kolečko by znamenalo prázdné
     * pole, které se za okamžik samo vyplní — přesně ve chvíli, kdy do něj člověk
     * začal psát.
     */
    public function test_predvolby_prijdou_s_ciselniky(): void
    {
        $this->patchJson('/api/v1/rozpocet/nastaveni', ['default_reserve' => 200])->assertOk();

        $odpoved = $this->getJson('/api/v1/rozpocet/ciselniky')->assertOk();

        $this->assertEqualsWithDelta(200, $odpoved->json('settings.default_reserve'), 0.01);
    }

    /** Stránka nese předvolby už v odpovědi, aby modul nepřeskočil na jinou záložku. */
    public function test_stranka_nese_predvolby_rovnou(): void
    {
        $this->patchJson('/api/v1/rozpocet/nastaveni', ['default_tab' => 'ucty'])->assertOk();

        $this->get('/rozpocty')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Rozpocet/Index')
                ->where('nastaveni.default_tab', 'ucty'));
    }

    /** Předvolby jsou na prostor: co si nastaví jeden, platí pro oba. */
    public function test_predvolby_plati_pro_cely_prostor(): void
    {
        $druhy = User::factory()->create();
        $druhy->gallerySpaces()->syncWithoutDetaching([$this->space->id => ['role' => 'owner']]);

        $this->patchJson('/api/v1/rozpocet/nastaveni', ['default_period' => 'tyden'])->assertOk();

        $this->actingAs($druhy)->getJson('/api/v1/rozpocet/nastaveni')
            ->assertOk()->assertJsonPath('settings.default_period', 'tyden');
    }
}
