<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\GallerySpace;
use App\Models\Partner;
use App\Models\Transaction;
use App\Models\TransactionShare;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\FinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Scénáře ze zadání, spočítané proti databázi.
 *
 * Testuje se to, co se v aplikacích na peníze plete nejčastěji a co při ručním
 * proklikání vypadá správně: že se převod a směna nepočítají jako útrata, že se
 * poplatek započítá právě jednou a že platba ze společného účtu nevyrábí dluh.
 */
class FinanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private GallerySpace $space;
    private FinanceService $sluzba;
    private User $uzivatel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uzivatel = User::factory()->create();
        $this->space = GallerySpace::create(['name' => 'Zkouška', 'owner_id' => $this->uzivatel->id]);
        $this->sluzba = app(FinanceService::class);
    }

    private function partner(string $jmeno): Partner
    {
        return Partner::create([
            'gallery_space_id' => $this->space->id,
            'kind' => 'person', 'name' => $jmeno, 'is_active' => true,
        ]);
    }

    private function penezenka(string $jmeno, string $mena, float $pocatek = 0, ?int $partnerId = null, string $druh = 'bank'): Wallet
    {
        return Wallet::create([
            'gallery_space_id' => $this->space->id,
            'name' => $jmeno, 'kind' => $druh, 'currency' => $mena,
            'opening_balance' => $pocatek, 'partner_id' => $partnerId, 'is_active' => true,
        ]);
    }

    private function pohyb(array $data): Transaction
    {
        return Transaction::create($data + [
            'gallery_space_id' => $this->space->id,
            'occurred_at' => '2026-08-10',
            'created_by' => $this->uzivatel->id,
            'state' => 'approved',
        ]);
    }

    private function nactiPohyby()
    {
        return Transaction::where('gallery_space_id', $this->space->id)
            ->with(['walletFrom', 'walletTo', 'shares', 'category', 'refundOf'])
            ->get();
    }

    /** Scénář C: výběr z bankomatu není výdaj, výdaj je jen poplatek. */
    public function test_vyber_hotovosti_neni_vydaj_ale_poplatek_ano(): void
    {
        $karta = $this->penezenka('EUR karta', 'EUR', 1000);
        $hotovost = $this->penezenka('EUR hotovost', 'EUR', 0, null, 'cash');

        $this->pohyb([
            'type' => 'withdrawal',
            'wallet_from_id' => $karta->id, 'wallet_to_id' => $hotovost->id,
            'amount_from' => 200, 'currency_from' => 'EUR',
            'amount_to' => 200, 'currency_to' => 'EUR',
            'fee_amount' => 3.5, 'fee_currency' => 'EUR', 'fee_included' => false,
        ]);

        $souhrn = $this->sluzba->summary($this->nactiPohyby());
        $eur = collect($souhrn)->firstWhere('currency', 'EUR');

        // Dvě stě eur se nikam neztratilo — jen se přesunulo.
        $this->assertSame(0.0, $eur['expense'], 'Vybraná částka se nesmí počítat jako výdaj.');
        $this->assertSame(3.5, $eur['fees'], 'Poplatek za výběr je skutečný náklad.');
        $this->assertSame(3.5, $eur['spent']);

        $zustatky = collect($this->sluzba->balances($this->space)['wallets']);
        $this->assertSame(796.5, $zustatky->firstWhere('name', 'EUR karta')['balance']);
        $this->assertSame(200.0, $zustatky->firstWhere('name', 'EUR hotovost')['balance']);

        // Celkem eur v systému: 1000 − 3,5 poplatku.
        $this->assertSame(996.5, collect($this->sluzba->balances($this->space)['by_currency'])
            ->firstWhere('currency', 'EUR')['total']);
    }

    /** Scénář B: směna se nepočítá jako příjem ani výdaj, poplatek právě jednou. */
    public function test_smena_neni_prijem_ani_vydaj(): void
    {
        $czk = $this->penezenka('CZK účet', 'CZK', 60000);
        $eur = $this->penezenka('EUR účet', 'EUR', 0);

        $this->pohyb([
            'type' => 'exchange',
            'wallet_from_id' => $czk->id, 'wallet_to_id' => $eur->id,
            'amount_from' => 50000, 'currency_from' => 'CZK',
            'amount_to' => 2040, 'currency_to' => 'EUR',
            'fee_amount' => 150, 'fee_currency' => 'CZK', 'fee_included' => false,
        ]);

        $souhrn = collect($this->sluzba->summary($this->nactiPohyby()));

        $this->assertSame(0.0, $souhrn->firstWhere('currency', 'CZK')['expense']);
        $this->assertSame(150.0, $souhrn->firstWhere('currency', 'CZK')['fees']);
        $this->assertNull($souhrn->firstWhere('currency', 'EUR'), 'Směna nesmí vyrobit eurový příjem.');

        $zustatky = collect($this->sluzba->balances($this->space)['wallets']);
        $this->assertSame(9850.0, $zustatky->firstWhere('name', 'CZK účet')['balance']);
        $this->assertSame(2040.0, $zustatky->firstWhere('name', 'EUR účet')['balance']);
    }

    /** Efektivní kurz: poplatek navíc ho zhorší, zahrnutý poplatek se nepřičítá dvakrát. */
    public function test_efektivni_kurz_zapocita_poplatek_prave_jednou(): void
    {
        $czk = $this->penezenka('CZK', 'CZK', 100000);
        $eur = $this->penezenka('EUR', 'EUR');

        $navic = $this->pohyb([
            'type' => 'exchange',
            'wallet_from_id' => $czk->id, 'wallet_to_id' => $eur->id,
            'amount_from' => 24000, 'currency_from' => 'CZK',
            'amount_to' => 1000, 'currency_to' => 'EUR',
            'fee_amount' => 200, 'fee_currency' => 'CZK', 'fee_included' => false,
        ]);

        $k = $this->sluzba->exchangeRate($navic);

        $this->assertSame(24.0, $k['nominal'], 'Bez poplatku je kurz 24 Kč za euro.');
        $this->assertSame(24.2, $k['effective'], 'S dvousetkorunovým poplatkem je 24,20.');
        $this->assertSame(41.32, $k['eur_per_1000_czk']);

        // Tytéž peníze, ale poplatek už je v odepsané částce: kurz se nesmí zhoršit znovu.
        $zahrnuty = $this->pohyb([
            'type' => 'exchange',
            'wallet_from_id' => $czk->id, 'wallet_to_id' => $eur->id,
            'amount_from' => 24200, 'currency_from' => 'CZK',
            'amount_to' => 1000, 'currency_to' => 'EUR',
            'fee_amount' => 200, 'fee_currency' => 'CZK', 'fee_included' => true,
        ]);

        $this->assertSame(24.2, $this->sluzba->exchangeRate($zahrnuty)['effective']);
    }

    /** Směna zpět se hlásí taky jako koruny za euro, ať jdou obě porovnat. */
    public function test_smena_zpet_ma_kurz_ve_stejnych_jednotkach(): void
    {
        $czk = $this->penezenka('CZK', 'CZK');
        $eur = $this->penezenka('EUR', 'EUR', 5000);

        $zpet = $this->pohyb([
            'type' => 'exchange',
            'wallet_from_id' => $eur->id, 'wallet_to_id' => $czk->id,
            'amount_from' => 1000, 'currency_from' => 'EUR',
            'amount_to' => 23500, 'currency_to' => 'CZK',
            'fee_amount' => 0, 'fee_currency' => 'CZK', 'fee_included' => false,
        ]);

        $k = $this->sluzba->exchangeRate($zpet);

        $this->assertSame('eur_to_czk', $k['direction']);
        $this->assertSame(23.5, $k['effective'], 'I zpětná směna se měří v Kč za 1 €.');
    }

    /** Vážený pořizovací kurz eur, včetně toho, co nejde zjistit. */
    public function test_vazeny_porizovaci_kurz(): void
    {
        $czk = $this->penezenka('CZK', 'CZK', 200000);
        $eur = $this->penezenka('EUR', 'EUR');

        // 1000 € za 24,00 a pak 1000 € za 26,00 → průměr 25,00.
        foreach ([[24000, 1000], [26000, 1000]] as [$zaplaceno, $prijato]) {
            $this->pohyb([
                'type' => 'exchange',
                'wallet_from_id' => $czk->id, 'wallet_to_id' => $eur->id,
                'amount_from' => $zaplaceno, 'currency_from' => 'CZK',
                'amount_to' => $prijato, 'currency_to' => 'EUR',
            ]);
        }

        $p = $this->sluzba->eurAcquisition($this->space);

        $this->assertSame(2000.0, $p['held_eur']);
        $this->assertSame(25.0, $p['average_rate']);
        $this->assertFalse($p['has_unknown']);

        // Útrata 500 € sníží zásobu, ale ne průměr — z peněženky nejde utratit
        // „to euro z března".
        $this->pohyb([
            'type' => 'expense', 'occurred_at' => '2026-08-20',
            'wallet_from_id' => $eur->id,
            'amount_from' => 500, 'currency_from' => 'EUR',
        ]);

        $po = $this->sluzba->eurAcquisition($this->space);

        $this->assertSame(1500.0, $po['held_eur']);
        $this->assertSame(25.0, $po['average_rate'], 'Útrata nemění pořizovací kurz zbytku.');
    }

    /** Eura z počátečního zůstatku mají neznámou cenu a nesmí se dopočítat. */
    public function test_neznama_porizovaci_cena_se_nevymysli(): void
    {
        $czk = $this->penezenka('CZK', 'CZK', 100000);
        $eur = $this->penezenka('EUR', 'EUR', 800);

        $this->pohyb([
            'type' => 'exchange',
            'wallet_from_id' => $czk->id, 'wallet_to_id' => $eur->id,
            'amount_from' => 24000, 'currency_from' => 'CZK',
            'amount_to' => 1000, 'currency_to' => 'EUR',
        ]);

        $p = $this->sluzba->eurAcquisition($this->space);

        $this->assertSame(1800.0, $p['held_eur']);
        $this->assertSame(800.0, $p['unknown_eur']);
        $this->assertSame(24.0, $p['average_rate'], 'Průměr platí jen pro známou část.');
        $this->assertTrue($p['has_unknown']);
    }

    /** Scénář D: společný výdaj placený Adrim vytvoří Maki podíl vůči němu. */
    public function test_spolecny_vydaj_placeny_jednim_vytvori_saldo(): void
    {
        $adri = $this->partner('Adri');
        $maki = $this->partner('Maki');
        $ucetAdri = $this->penezenka('Adri EUR', 'EUR', 1000, $adri->id);

        $t = $this->pohyb([
            'type' => 'expense',
            'wallet_from_id' => $ucetAdri->id,
            'amount_from' => 60, 'currency_from' => 'EUR',
            'payer_partner_id' => $adri->id,
        ]);

        foreach ([$adri, $maki] as $p) {
            TransactionShare::create([
                'transaction_id' => $t->id, 'partner_id' => $p->id,
                'amount' => 30, 'currency' => 'EUR', 'basis' => 'equal',
            ]);
        }

        $saldo = $this->sluzba->partnerBalance($this->nactiPohyby(), collect([$adri, $maki]));
        $eur = collect($saldo['by_currency'])->firstWhere('currency', 'EUR');

        $this->assertSame(30.0, collect($eur['partners'])->firstWhere('name', 'Adri')['balance']);
        $this->assertSame(-30.0, collect($eur['partners'])->firstWhere('name', 'Maki')['balance']);

        $this->assertCount(1, $eur['settlement']);
        $this->assertSame('Maki', $eur['settlement'][0]['from']);
        $this->assertSame('Adri', $eur['settlement'][0]['to']);
        $this->assertSame(30.0, $eur['settlement'][0]['amount']);
    }

    /** Výdaj ze společného účtu nesmí vyrobit dluh vůči nikomu. */
    public function test_spolecny_ucet_nevytvari_dluh(): void
    {
        $adri = $this->partner('Adri');
        $maki = $this->partner('Maki');
        $spolecny = $this->penezenka('Společný EUR', 'EUR', 1000);   // bez partner_id

        $t = $this->pohyb([
            'type' => 'expense',
            'wallet_from_id' => $spolecny->id,
            'amount_from' => 80, 'currency_from' => 'EUR',
        ]);

        foreach ([$adri, $maki] as $p) {
            TransactionShare::create([
                'transaction_id' => $t->id, 'partner_id' => $p->id,
                'amount' => 40, 'currency' => 'EUR', 'basis' => 'equal',
            ]);
        }

        $saldo = $this->sluzba->partnerBalance($this->nactiPohyby(), collect([$adri, $maki]));
        $eur = collect($saldo['by_currency'])->firstWhere('currency', 'EUR');

        $this->assertSame(-40.0, collect($eur['partners'])->firstWhere('name', 'Adri')['balance']);
        $this->assertSame(-40.0, collect($eur['partners'])->firstWhere('name', 'Maki')['balance']);

        // Oba jsou „ve stejném mínusu" vůči společné kase, takže si navzájem nedluží nic.
        $this->assertSame([], $eur['settlement'], 'Ze společného účtu nevzniká osobní dluh.');
    }

    /** Bezpečná částka na den — okrajové případy, na kterých to jinde padá. */
    public function test_bezpecne_na_den(): void
    {
        $od = Carbon::parse('2026-08-01');
        $do = Carbon::parse('2026-08-31');

        // Deset dní pryč, utraceno 300 z 1000, rezerva 100.
        $b = $this->sluzba->safeDaily(1000, 300, 100, $od, $do, Carbon::parse('2026-08-10'));
        $this->assertSame('ok', $b['state']);
        $this->assertSame(22, $b['days_left'], 'Dnešek se ještě dá utratit.');
        $this->assertSame(27.27, $b['per_day']);   // (1000−300−100)/22

        // Překročeno: záporná „doporučená částka" nedává smysl, hlásí se přesah.
        $p = $this->sluzba->safeDaily(1000, 1200, 0, $od, $do, Carbon::parse('2026-08-20'));
        $this->assertSame('over', $p['state']);
        $this->assertNull($p['per_day'], 'Nikdo neumí utratit mínus.');
        $this->assertSame(200.0, $p['over_by']);

        // Poslední den má pořád jeden den na utracení, ne nula.
        $k = $this->sluzba->safeDaily(1000, 900, 0, $od, $do, Carbon::parse('2026-08-31'));
        $this->assertSame(1, $k['days_left']);
        $this->assertSame(100.0, $k['per_day']);

        // Období skončilo.
        $s = $this->sluzba->safeDaily(1000, 900, 0, $od, $do, Carbon::parse('2026-09-05'));
        $this->assertSame('ended', $s['state']);
        $this->assertNull($s['per_day']);

        // Ještě nezačalo.
        $n = $this->sluzba->safeDaily(1000, 0, 0, $od, $do, Carbon::parse('2026-07-20'));
        $this->assertSame('not_started', $n['state']);

        // Počet dnů se nesmí přes noc odjezdu změnit. Období 1.–5. 9. je pět dní jak
        // den před ním, tak první den v něm — jinak by denní částka poskočila, aniž by
        // se cokoli utratilo, a nikdo by nevěděl, které z těch dvou čísel platí.
        $zari = [Carbon::parse('2026-09-01'), Carbon::parse('2026-09-05')];
        $pred = $this->sluzba->safeDaily(1000, 0, 0, ...$zari, dnes: Carbon::parse('2026-08-31'));
        $prvni = $this->sluzba->safeDaily(1000, 0, 0, ...$zari, dnes: Carbon::parse('2026-09-01'));

        $this->assertSame(5, $pred['days_left']);
        $this->assertSame(5, $prvni['days_left']);
        $this->assertSame($pred['per_day'], $prvni['per_day']);
    }

    /** Refundace snižuje čisté čerpání kategorie, ale zůstává samostatně vidět. */
    public function test_refundace_snizi_ciste_cerpani_kategorie(): void
    {
        FinanceCategory::nachystej($this->space->id);
        $kategorie = FinanceCategory::where('gallery_space_id', $this->space->id)->where('name', 'Oblečení a nákupy')->first();
        $ucet = $this->penezenka('EUR', 'EUR', 1000);

        $nakup = $this->pohyb([
            'type' => 'expense', 'wallet_from_id' => $ucet->id,
            'amount_from' => 120, 'currency_from' => 'EUR', 'category_id' => $kategorie->id,
        ]);

        $this->pohyb([
            'type' => 'income', 'occurred_at' => '2026-08-15',
            'wallet_to_id' => $ucet->id,
            'amount_to' => 40, 'currency_to' => 'EUR', 'currency_from' => 'EUR',
            'refund_of_id' => $nakup->id,
        ]);

        $rozpad = collect($this->sluzba->byCategory($this->nactiPohyby(), 'EUR'))
            ->firstWhere('name', 'Oblečení a nákupy');

        $this->assertSame(120.0, $rozpad['gross']);
        $this->assertSame(40.0, $rozpad['refunded']);
        $this->assertSame(80.0, $rozpad['amount'], 'Čisté čerpání je po odečtení vrácených peněz.');

        // A pořád jsou to dva dohledatelné záznamy, ne jeden opravený.
        $this->assertSame(2, Transaction::where('gallery_space_id', $this->space->id)->count());
    }

    /** Výdaj vyřazený z rozpočtu se nepočítá do čerpání, ale zůstává v knize. */
    public function test_vydaj_mimo_rozpocet(): void
    {
        $ucet = $this->penezenka('EUR', 'EUR', 1000);

        $this->pohyb([
            'type' => 'expense', 'wallet_from_id' => $ucet->id,
            'amount_from' => 500, 'currency_from' => 'EUR',
            'excluded_from_budget' => true, 'exclusion_reason' => 'Dárek mimo cestu',
        ]);

        $pohyby = $this->nactiPohyby();

        $this->assertSame([], $this->sluzba->byCategory($pohyby, 'EUR'), 'Do čerpání nepatří.');
        $this->assertSame(500.0, collect($this->sluzba->summary($pohyby))->firstWhere('currency', 'EUR')['expense'],
            'Ale peníze opravdu odešly, takže ve výdajích být musí.');
        $this->assertSame(500.0, collect($this->sluzba->balances($this->space)['wallets'])->firstWhere('name', 'EUR')['balance']);
    }
}
