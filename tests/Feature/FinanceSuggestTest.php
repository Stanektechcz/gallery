<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Návrh kategorie podle obchodníka.
 *
 * Je to návrh, ne rozhodnutí — obrazovka ho ukáže jako nabídku a bez klepnutí se
 * nepoužije. Testy proto hlídají hlavně to, kdy návrh **nesmí** vzniknout: z krátkého
 * řetězce, z podobného jména nebo bez historie.
 */
class FinanceSuggestTest extends TestCase
{
    use RefreshDatabase;

    private User $uzivatel;
    private GallerySpace $space;
    private Wallet $ucet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uzivatel = User::factory()->create();
        $this->space = GallerySpace::create(['name' => 'Zkouška', 'owner_id' => $this->uzivatel->id]);
        $this->uzivatel->gallerySpaces()->syncWithoutDetaching([$this->space->id => ['role' => 'owner']]);
        $this->actingAs($this->uzivatel);

        $this->ucet = Wallet::create([
            'gallery_space_id' => $this->space->id, 'name' => 'EUR', 'kind' => 'card',
            'currency' => 'EUR', 'opening_balance' => 2000, 'is_active' => true,
        ]);

        FinanceCategory::nachystej($this->space->id);
    }

    private function vydaj(string $obchodnik, string $kategorie): Transaction
    {
        return Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'expense',
            'occurred_at' => now()->toDateString(), 'wallet_from_id' => $this->ucet->id,
            'amount_from' => 20, 'currency_from' => 'EUR',
            'counterparty' => $obchodnik,
            'category_id' => FinanceCategory::where('gallery_space_id', $this->space->id)
                ->where('name', $kategorie)->value('id'),
            'state' => 'approved', 'created_by' => $this->uzivatel->id,
        ]);
    }

    public function test_navrhne_naposledy_pouzitou_kategorii(): void
    {
        $this->vydaj('Lidl', 'Potraviny');
        $this->vydaj('Lidl', 'Potraviny');

        $odpoved = $this->getJson('/api/v1/rozpocet/navrh-kategorie?obchodnik=Lidl')->assertOk();

        $this->assertSame('Potraviny', $odpoved->json('category.name'));
        $this->assertSame(2, $odpoved->json('used'));
    }

    /** Když se kategorie u téhož obchodníka změnila, platí ta poslední. */
    public function test_plati_posledni_volba(): void
    {
        $this->vydaj('Rossmann', 'Potraviny');
        $this->vydaj('Rossmann', 'Drogerie a domácnost');

        $this->assertSame('Drogerie a domácnost',
            $this->getJson('/api/v1/rozpocet/navrh-kategorie?obchodnik=Rossmann')->json('category.name'));
    }

    /**
     * Podobné jméno není totéž jméno.
     *
     * Hádat, že „Kaufland" a „Kaufmann" jsou jeden obchod, by dalo návrh, který je
     * hůř než žádný — vypadá stejně důvěryhodně jako správný.
     */
    public function test_podobne_jmeno_se_nenavrhuje(): void
    {
        $this->vydaj('Kaufland', 'Potraviny');

        $this->assertNull($this->getJson('/api/v1/rozpocet/navrh-kategorie?obchodnik=Kaufmann')->json('category'));
        $this->assertNull($this->getJson('/api/v1/rozpocet/navrh-kategorie?obchodnik=Kaufland Drážďany')->json('category'));
    }

    /** Z krátkého řetězce se nenavrhuje — při psaní by to blikalo. */
    public function test_kratky_retezec(): void
    {
        $this->vydaj('DM', 'Drogerie a domácnost');

        $this->assertNull($this->getJson('/api/v1/rozpocet/navrh-kategorie?obchodnik=DM')->json('category'));
    }

    public function test_bez_historie_zadny_navrh(): void
    {
        $this->assertNull($this->getJson('/api/v1/rozpocet/navrh-kategorie?obchodnik=Neznámý')->json('category'));
    }

    /** Výdaj bez kategorie se jako návrh nenabízí. */
    public function test_vydaj_bez_kategorie_nenavrhuje(): void
    {
        Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'expense',
            'occurred_at' => now()->toDateString(), 'wallet_from_id' => $this->ucet->id,
            'amount_from' => 20, 'currency_from' => 'EUR', 'counterparty' => 'Trafika',
            'state' => 'approved', 'created_by' => $this->uzivatel->id,
        ]);

        $this->assertNull($this->getJson('/api/v1/rozpocet/navrh-kategorie?obchodnik=Trafika')->json('category'));
    }
}
