<?php

namespace Tests\Feature\Penize;

use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\FinanceService;
use App\Services\Finance\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `FinanceService::balances` smí počítat jen zapsané pohyby, stejně jako galerie.
 *
 * `LedgerService::walletBalances` počítala jen `approved`/`settled` (viz
 * `Transaction::ZAPSANE`), zatímco Rozpočet neměl na stav žádný filtr — nedokončený
 * (`draft`) záznam se mu tiše připočítal do zůstatku, který podle galerie neexistuje.
 */
class ZustatekJenZapsaneTest extends TestCase
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
    }

    public function test_nezapsana_transakce_se_do_zustatku_nepocita(): void
    {
        $ucet = Wallet::create([
            'gallery_space_id' => $this->space->id, 'name' => 'Účet', 'kind' => 'bank',
            'currency' => 'CZK', 'opening_balance' => 1000, 'is_active' => true,
        ]);

        Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'expense', 'occurred_at' => '2026-09-10',
            'wallet_from_id' => $ucet->id, 'amount_from' => 100, 'currency_from' => 'CZK',
            'state' => 'approved', 'created_by' => $this->uzivatel->id,
        ]);

        // Rozepsaný záznam, který ještě nikdo neschválil — nesmí zúžit zůstatek.
        Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'expense', 'occurred_at' => '2026-09-11',
            'wallet_from_id' => $ucet->id, 'amount_from' => 50, 'currency_from' => 'CZK',
            'state' => 'draft', 'created_by' => $this->uzivatel->id,
        ]);

        $rozpocet = app(FinanceService::class)->balances($this->space)['wallets'][0]['balance'];
        $galerie = app(LedgerService::class)->walletBalances($this->space)->first()['balance'];

        $this->assertSame(900.0, $rozpocet, 'Draft se nesmí počítat — dnes 850, mělo by být 900.');
        $this->assertSame(900.0, $galerie);
        $this->assertSame($galerie, $rozpocet, 'Rozpočet a galerie musí ukazovat týž zůstatek.');
    }
}
