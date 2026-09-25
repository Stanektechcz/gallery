<?php

namespace Tests\Feature\Penize;

use App\Models\FinanceRecurring;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\RecurringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Splátka předpisu vzniká jednou za **měsíc**, ne jednou za datum.
 *
 * Generátor dřív poznával hotovou splátku podle přesného data. Stačilo posunout
 * zářijový nájem z prvního na třetího (protože banka ho strhla později) a další
 * načtení přehledu dopsalo nájem na prvního znovu. Změna dne v měsíci z 1 na 5
 * vyrobila najednou devět nájmů navíc.
 */
class PravidelnePlatbyObdobiTest extends TestCase
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
            'gallery_space_id' => $this->space->id, 'name' => 'CZK účet', 'kind' => 'bank',
            'currency' => 'CZK', 'opening_balance' => 100000, 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function najem(?string $do = null): FinanceRecurring
    {
        return FinanceRecurring::create([
            'gallery_space_id' => $this->space->id,
            'name' => 'Nájem', 'type' => 'expense', 'amount' => 15000, 'currency' => 'CZK',
            'wallet_id' => $this->ucet->id, 'day_of_month' => 1,
            'starts_on' => '2026-01-01', 'ends_on' => $do, 'is_active' => true,
            'created_by' => $this->uzivatel->id,
        ]);
    }

    private function pocet(FinanceRecurring $p): int
    {
        return Transaction::where('recurring_id', $p->id)->count();
    }

    public function test_posunuta_splatka_se_znovu_nedopise(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        $p = $this->najem();

        $this->assertSame(9, app(RecurringService::class)->generovat($this->space, Carbon::parse('2026-09-15')));

        // Banka strhla zářijový nájem až třetího — člověk to v zápisu opraví.
        Transaction::where('recurring_id', $p->id)->whereDate('occurred_at', '2026-09-01')->firstOrFail()
            ->update(['occurred_at' => '2026-09-03']);

        app(RecurringService::class)->generovat($this->space, Carbon::parse('2026-09-15'));
        $this->assertSame(9, $this->pocet($p), 'Září už nájem má, jen o dva dny později.');

        // Přehled generuje při každém načtení — ani ten nic nepřidá.
        $this->getJson('/api/v1/rozpocet/pravidelne')->assertOk();
        $this->assertSame(9, $this->pocet($p));
    }

    public function test_zmena_dne_v_mesici_nevyrobi_dalsi_splatky(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        $p = $this->najem();
        app(RecurringService::class)->generovat($this->space, Carbon::parse('2026-09-15'));

        $this->patchJson("/api/v1/rozpocet/pravidelne/{$p->uuid}", ['day_of_month' => 5])->assertOk();
        $this->getJson('/api/v1/rozpocet/pravidelne')->assertOk();

        $this->assertSame(9, $this->pocet($p), 'Leden až září nájem mají; pátého nevzniká další.');

        // Říjen ale přijde — pátého, podle nového dne.
        app(RecurringService::class)->generovat($this->space, Carbon::parse('2026-10-06'));
        $this->assertSame(10, $this->pocet($p));
        $this->assertTrue(Transaction::where('recurring_id', $p->id)->whereDate('occurred_at', '2026-10-05')->exists());
    }

    /** Splátka přesunutá do jiného, už uzavřeného měsíce ten měsíc znovu neotevře. */
    public function test_uzavreny_mesic_se_nedopisuje(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        $p = $this->najem();
        app(RecurringService::class)->generovat($this->space, Carbon::parse('2026-09-15'));

        // Srpnový nájem se omylem zapsal jako zářijový a člověk to tak nechal.
        Transaction::where('recurring_id', $p->id)->whereDate('occurred_at', '2026-08-01')->firstOrFail()
            ->update(['occurred_at' => '2026-09-02']);

        app(RecurringService::class)->generovat($this->space, Carbon::parse('2026-09-15'));

        $this->assertSame(9, $this->pocet($p), 'Srpen generátor už jednou prošel — znovu ho neotevře.');
    }

    /** Prodloužený předpis dopíše měsíce, které dřív zakrýval konec. */
    public function test_prodlouzeni_konce_dopise_chybejici_mesice(): void
    {
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));
        $p = $this->najem('2026-03-31');
        app(RecurringService::class)->generovat($this->space, Carbon::parse('2026-09-15'));
        $this->assertSame(3, $this->pocet($p));

        $this->patchJson("/api/v1/rozpocet/pravidelne/{$p->uuid}", ['ends_on' => '2026-12-31'])->assertOk();
        app(RecurringService::class)->generovat($this->space, Carbon::parse('2026-09-15'));

        $this->assertSame(9, $this->pocet($p), 'Duben až září předtím nešly, protože předpis končil.');
    }

    /** Dvě souběžná načtení přehledu: druhé počká, nebo nic nezapíše. */
    public function test_drzeny_zamek_zabrani_soubeznemu_generovani(): void
    {
        // Bez `travelTo()`: čekání na zámek měří čas hodinami, a zmrazené hodiny
        // by ho nechaly čekat navěky.
        $p = $this->najem();

        $zamek = Cache::lock('fin-recurring:'.$this->space->id, 30);
        $this->assertTrue($zamek->get());

        try {
            $this->assertSame(0, app(RecurringService::class)->generovat($this->space, Carbon::parse('2026-09-15')));
            $this->assertSame(0, $this->pocet($p), 'Druhý běh nezapisuje, dokud první drží zámek.');
        } finally {
            $zamek->release();
        }

        $this->assertSame(9, app(RecurringService::class)->generovat($this->space, Carbon::parse('2026-09-15')));
    }
}
