<?php

namespace Tests\Feature\Galerie;

use App\Models\CoupleCoolingPurchase;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mlčky platná pravidla — vzorce, které aplikace najde v tom, co se opravdu děje.
 *
 * Obrazovka o sobě říká: „Tohle nejsou pravidla, na kterých jste se dohodli.
 * Jsou to vzorce, které aplikace našla." Byl to seznam sedmi vět napsaných
 * v souboru s ukázkovými daty — tedy pravý opak.
 */
class TichaPravidlaTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->maki = User::factory()->create(['name' => 'Makinka']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    /** Bez zápisů se žádné pravidlo nenajde — a nic se nepošle. */
    public function test_bez_dat_se_nic_neposila(): void
    {
        $this->assertArrayNotHasKey('TACIT', $this->getJson('/api/data/vztah')->assertOk()->json('data'));
    }

    /**
     * „Kdo vaří, neuklízí kuchyň."
     *
     * Deset dní, kdy oba dělali obojí, ale nikdy tentýž člověk. Tohle nikde
     * zapsané není — jen se to tak děje.
     */
    public function test_najde_se_kdo_dela_jedno_nedela_druhe(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $den = now()->subDays($i * 3);
            $this->prace('Vaření', $this->adri, $den);
            $this->prace('Úklid kuchyně', $this->maki, $den);
        }

        $tacit = collect($this->getJson('/api/data/vztah')->assertOk()->json('data.TACIT'));
        $pravidlo = $tacit->firstWhere('rule', 'Kdo dělá vaření, nedělá úklid kuchyně');

        $this->assertNotNull($pravidlo, 'Vzorec „kdo vaří, neuklízí" se nenašel.');
        $this->assertSame(10, $pravidlo['of']);
        $this->assertSame(10, $pravidlo['holds']);
        $this->assertSame('práce', $pravidlo['kind']);

        // Vyhýbání je symetrické — hlásí se jednou, ne v obou směrech.
        $this->assertCount(1, $tacit->where('kind', 'práce'));
    }

    /**
     * Náhoda pravidlo není.
     *
     * Když tentýž člověk dělá obojí zhruba v polovině případů, žádný vzorec
     * v tom není — a tvrdit opak by znamenalo vyrobit pravidlo z ničeho.
     */
    public function test_polovicni_shoda_neni_pravidlo(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $den = now()->subDays($i * 3);
            $this->prace('Vaření', $this->adri, $den);
            $this->prace('Úklid kuchyně', $i % 2 === 0 ? $this->adri : $this->maki, $den);
        }

        $tacit = collect($this->getJson('/api/data/vztah')->assertOk()->json('data.TACIT') ?? []);

        $this->assertNull($tacit->firstWhere('kind', 'práce'));
    }

    /** Málo případů se za pravidlo nevydává. */
    public function test_par_pripadu_na_pravidlo_nestaci(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $den = now()->subDays($i * 3);
            $this->prace('Vaření', $this->adri, $den);
            $this->prace('Úklid kuchyně', $this->maki, $den);
        }

        $tacit = collect($this->getJson('/api/data/vztah')->assertOk()->json('data.TACIT') ?? []);

        $this->assertNull($tacit->firstWhere('kind', 'práce'));
    }

    /** „Po 22:00 se peníze neřeší" — z časů zápisů, ne z dohody. */
    public function test_najde_se_hodina_klidu_na_penize(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->transakce(now()->subDays($i)->setTime(18, 0));
        }

        $tacit = collect($this->getJson('/api/data/vztah')->assertOk()->json('data.TACIT'));
        $pravidlo = $tacit->firstWhere('kind', 'klid');

        $this->assertNotNull($pravidlo);
        $this->assertSame('Po 21:00 se peníze neřeší', $pravidlo['rule']);
        $this->assertSame(20, $pravidlo['holds']);
    }

    /** Když se peníze řeší i v noci, pravidlo o klidu se nehlásí. */
    public function test_nocni_zapisy_pravidlo_klidu_zabiji(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->transakce(now()->subDays($i)->setTime($i % 2 === 0 ? 23 : 18, 30));
        }

        $tacit = collect($this->getJson('/api/data/vztah')->assertOk()->json('data.TACIT') ?? []);

        $this->assertNull($tacit->firstWhere('kind', 'klid'));
    }

    /**
     * „Nákup nad X se dopředu řekne."
     *
     * Hranice se nevymýšlí — je to devátý desetil útrat té dvojice. Rozvaha
     * musí být **před** nákupem: po něm už je to vysvětlování, ne domluva.
     */
    public function test_najde_se_ze_velky_nakup_ma_rozvahu(): void
    {
        for ($i = 1; $i <= 40; $i++) {
            $this->transakce(now()->subDays($i)->setTime(18, 0), 200);
        }

        for ($i = 1; $i <= 10; $i++) {
            $den = now()->subDays($i * 5);
            $this->transakce($den->copy()->setTime(18, 0), 9000);

            CoupleCoolingPurchase::create([
                'gallery_space_id' => $this->prostor->id,
                'what' => 'Velký nákup '.$i,
                'price' => 9000,
                'requested_by' => $this->adri->id,
                'opened_at' => $den->copy()->subDays(3),
                'cools_until' => $den->copy()->subDay(),
            ]);
        }

        $tacit = collect($this->getJson('/api/data/vztah')->assertOk()->json('data.TACIT'));
        $pravidlo = $tacit->firstWhere('kind', 'peníze');

        $this->assertNotNull($pravidlo);
        $this->assertStringContainsString('se dopředu řekne', $pravidlo['rule']);
        $this->assertSame(10, $pravidlo['of']);
        $this->assertSame(10, $pravidlo['holds']);
    }

    private function prace(string $nazev, User $kdo, $kdy): void
    {
        DB::table('house_chore_log')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'chore_name' => $nazev,
            'user_id' => $kdo->id,
            'minutes' => 30,
            'done_at' => $kdy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function transakce($kdy, int $castka = 300): void
    {
        DB::table('transactions')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'type' => 'expense',
            'occurred_at' => $kdy,
            'amount_from' => $castka,
            'currency_from' => 'CZK',
            'description' => 'Výdaj',
            'created_at' => $kdy,
            'updated_at' => $kdy,
        ]);
    }
}
