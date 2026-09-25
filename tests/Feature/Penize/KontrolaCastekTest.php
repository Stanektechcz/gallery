<?php

namespace Tests\Feature\Penize;

use App\Models\GallerySpace;
use App\Models\Partner;
use App\Models\Transaction;
use App\Models\TransactionShare;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Částky, které nemůžou být pravda, se odmítnou hláškou — ne pětistovkou z databáze
 * a ne tichým zápisem peněz, které nikde nebyly.
 */
class KontrolaCastekTest extends TestCase
{
    use RefreshDatabase;

    private User $uzivatel;

    private GallerySpace $space;

    private Wallet $czk;

    private Wallet $hotovost;

    private Wallet $eur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uzivatel = User::factory()->create();
        $this->space = GallerySpace::create(['name' => 'Zkouška', 'owner_id' => $this->uzivatel->id]);
        $this->uzivatel->gallerySpaces()->syncWithoutDetaching([$this->space->id => ['role' => 'owner']]);
        $this->actingAs($this->uzivatel);

        $this->czk = $this->ucet('CZK banka', 'CZK', 50000);
        $this->hotovost = $this->ucet('CZK hotovost', 'CZK', 0);
        $this->eur = $this->ucet('EUR', 'EUR', 0);
    }

    private function ucet(string $jmeno, string $mena, float $pocatek): Wallet
    {
        return Wallet::create([
            'gallery_space_id' => $this->space->id, 'name' => $jmeno, 'kind' => 'bank',
            'currency' => $mena, 'opening_balance' => $pocatek, 'is_active' => true,
        ]);
    }

    private function zapis(array $data)
    {
        return $this->postJson('/api/v1/rozpocet/transakce', $data + [
            'occurred_at' => '2026-09-10', 'potvrzeno' => true,
        ]);
    }

    public function test_zaporna_prijata_castka_u_smeny_neprojde(): void
    {
        $this->zapis([
            'type' => 'exchange', 'wallet_from' => $this->czk->uuid, 'wallet_to' => $this->eur->uuid,
            'amount_from' => 2500, 'amount_to' => -100,
        ])->assertStatus(422)->assertJsonValidationErrors('amount_to');

        $this->assertSame(0, Transaction::count());
    }

    public function test_prevod_ve_stejne_mene_s_ruznymi_castkami_neprojde(): void
    {
        $this->zapis([
            'type' => 'withdrawal', 'wallet_from' => $this->czk->uuid, 'wallet_to' => $this->hotovost->uuid,
            'amount_from' => 200, 'amount_to' => 250,
        ])->assertStatus(422)->assertJsonValidationErrors('amount_to');

        $this->assertSame(0, Transaction::count(), 'Padesát korun z ničeho se nezapíše.');

        // Stejné částky, nebo jen jedna, projdou.
        $this->zapis([
            'type' => 'transfer', 'wallet_from' => $this->czk->uuid, 'wallet_to' => $this->hotovost->uuid,
            'amount_from' => 200, 'amount_to' => 200,
        ])->assertCreated();
        $this->zapis([
            'type' => 'transfer', 'wallet_from' => $this->czk->uuid, 'wallet_to' => $this->hotovost->uuid,
            'amount_from' => 300,
        ])->assertCreated();
    }

    public function test_obri_castka_se_odmitne_hlaskou(): void
    {
        $this->zapis([
            'type' => 'expense', 'wallet_from' => $this->czk->uuid, 'amount_from' => 1e13,
        ])->assertStatus(422)->assertJsonValidationErrors('amount_from');

        $this->zapis([
            'type' => 'expense', 'wallet_from' => $this->czk->uuid, 'amount_from' => 100, 'fee_amount' => 1e13,
        ])->assertStatus(422)->assertJsonValidationErrors('fee_amount');

        $this->assertSame(0, Transaction::count());
    }

    public function test_kurz_mimo_sloupec_se_odmitne(): void
    {
        $this->zapis([
            'type' => 'exchange', 'wallet_from' => $this->czk->uuid, 'wallet_to' => $this->eur->uuid,
            'amount_from' => 999999999, 'amount_to' => 0.01,
        ])->assertStatus(422)->assertJsonValidationErrors('amount_to');

        $this->assertSame(0, Transaction::count());
    }

    public function test_obri_castka_predpisu_a_rozpoctu_se_odmitne(): void
    {
        $this->postJson('/api/v1/rozpocet/pravidelne', [
            'name' => 'Nájem', 'amount' => 1e13, 'wallet_uuid' => $this->czk->uuid,
            'day_of_month' => 1, 'starts_on' => '2026-09-01',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->postJson('/api/v1/rozpocet/rozpocty', [
            'name' => 'Září', 'budget_kind' => 'monthly', 'amount' => 1e13, 'currency' => 'CZK',
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_dvakrat_tentyz_partner_v_rozdeleni_se_odmitne_a_nic_nezapise(): void
    {
        $adri = Partner::create(['gallery_space_id' => $this->space->id, 'kind' => 'person', 'name' => 'Adri', 'is_active' => true]);

        $this->zapis([
            'type' => 'expense', 'wallet_from' => $this->czk->uuid, 'amount_from' => 100,
            'split' => [
                ['partner_id' => $adri->id, 'amount' => 50],
                ['partner_id' => $adri->id, 'amount' => 50],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('split.1.partner_id');

        $this->assertSame(0, Transaction::count(), 'Výdaj bez platného rozdělení v knize nezůstane.');
        $this->assertSame(0, TransactionShare::count());
    }
}
