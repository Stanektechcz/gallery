<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rychlá ruční platba z telefonu i z počítače se zapíše do knihy.
 *
 * Dosud přidala řádek jen na obrazovku (s datem „30. 8." napevno) a rozpočet
 * o ní nevěděl.
 */
class RucniPlatbaTest extends TestCase
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

    public function test_platba_se_zapise_do_hlavniho_uctu_s_dnesnim_datem(): void
    {
        $ucet = $this->ucet('Společný účet', 0);
        $this->ucet('Spoření', 1);

        $odpoved = $this->postJson('/api/platby/rucne', ['popis' => 'Albert', 'castka' => 432.5])
            ->assertStatus(201)
            ->assertJsonPath('ok', true);

        $platba = Transaction::where('uuid', $odpoved->json('platba'))->sole();

        $this->assertSame('expense', $platba->type);
        $this->assertSame($ucet->id, $platba->wallet_from_id);
        $this->assertEquals(432.5, (float) $platba->amount_from);
        $this->assertSame('CZK', $platba->currency_from);
        $this->assertSame(now()->toDateString(), $platba->occurred_at->toDateString());
        $this->assertSame('Albert', $odpoved->json('data.TX.0.2'));
    }

    public function test_prijem_jde_na_ucet(): void
    {
        $ucet = $this->ucet('Společný účet', 0);

        $odpoved = $this->postJson('/api/platby/rucne', ['popis' => 'Vrácená záloha', 'castka' => 1500, 'prijem' => true])->assertStatus(201);
        $platba = Transaction::where('uuid', $odpoved->json('platba'))->sole();

        $this->assertSame('income', $platba->type);
        $this->assertSame($ucet->id, $platba->wallet_to_id);
        $this->assertNull($platba->wallet_from_id);
    }

    /** Stejný klíč z fronty offline — jedna platba, ne dvě. */
    public function test_opakovane_odeslani_nezdvoji_platbu(): void
    {
        $this->ucet('Společný účet', 0);

        $this->postJson('/api/platby/rucne', ['popis' => 'Benzín', 'castka' => 1200, 'klic' => 'tel-1'])->assertStatus(201);
        $this->postJson('/api/platby/rucne', ['popis' => 'Benzín', 'castka' => 1200, 'klic' => 'tel-1'])->assertOk();

        $this->assertSame(1, Transaction::where('gallery_space_id', $this->prostor->id)->count());
    }

    public function test_bez_uctu_rekne_kam_ho_zalozit(): void
    {
        $this->postJson('/api/platby/rucne', ['popis' => 'Albert', 'castka' => 100])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertSame(0, Transaction::count());
    }

    public function test_nulova_castka_neprojde(): void
    {
        $this->ucet('Společný účet', 0);

        $this->postJson('/api/platby/rucne', ['popis' => 'Nic', 'castka' => 0])->assertStatus(422);
        $this->postJson('/api/platby/rucne', ['popis' => 'Záporná', 'castka' => -50])->assertStatus(422);
    }

    /** Cizí účet se nepoužije ani tehdy, když vlastní dvojice žádný nemá. */
    public function test_cizi_ucet_se_nepouzije(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        Wallet::create(['gallery_space_id' => $ciziProstor->id, 'name' => 'Cizí účet', 'kind' => 'bank', 'currency' => 'CZK', 'opening_balance' => 0, 'is_active' => true, 'sort_order' => 0]);

        $this->postJson('/api/platby/rucne', ['popis' => 'Albert', 'castka' => 100])->assertStatus(422);
        $this->assertSame(0, Transaction::count());
    }

    private function ucet(string $nazev, int $poradi): Wallet
    {
        return Wallet::create([
            'gallery_space_id' => $this->prostor->id, 'name' => $nazev,
            'kind' => 'bank', 'currency' => 'CZK', 'opening_balance' => 0, 'is_active' => true, 'sort_order' => $poradi,
        ]);
    }
}
