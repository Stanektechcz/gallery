<?php

namespace Tests\Feature\Obsah41;

use App\Models\CoupleDecision;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Obsah\Formulare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Host není partner.
 *
 * Obrazovky braly „dvojici" z `members()` — i s hosty a v pořadí, jaké se
 * databázi zrovna hodilo. Host, který do prostoru přišel dřív než partner
 * (a má nižší id), tak seděl na místě partnera: jeho hvězdičky, jeho obavy,
 * jeho jméno ve „rozhodli jsme spolu".
 */
class HosteVeDvojiciTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $host;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        // Host je založený i přidaný PŘED partnerem — nižší id i dřívější vstup.
        $this->adri = User::factory()->create(['name' => 'Adrian', 'is_active' => true]);
        $this->host = User::factory()->create(['name' => 'Host', 'is_active' => true]);
        $this->maki = User::factory()->create(['name' => 'Makinka', 'is_active' => true]);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->attach($this->adri->id, ['role' => 'owner', 'joined_at' => now()->subDays(3)]);
        $this->prostor->members()->attach($this->host->id, ['role' => 'viewer', 'joined_at' => now()->subDays(2)]);
        $this->prostor->members()->attach($this->maki->id, ['role' => 'editor', 'joined_at' => now()->subDay()]);

        Sanctum::actingAs($this->adri);
    }

    public function test_obalka_ma_na_druhem_miste_partnera_ne_hosta(): void
    {
        $obalka = $this->kategorie('Osobní obálka');
        $this->partner($this->adri);
        $this->partner($this->host);
        $makinka = $this->partner($this->maki);
        $this->transakce(['category_id' => $obalka, 'amount_from' => 640, 'payer_partner_id' => $makinka]);

        $tento = collect($this->getJson('/api/data/rozbory')->assertOk()->json('data.ENV.months'))->last();

        $this->assertSame(640, $tento['k']);
    }

    public function test_cena_cesty_pocita_jen_dvojici(): void
    {
        $cesta = DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id, 'name' => 'Vídeň',
            'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->subMonth()->addDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('trip_expenses')->insert([
            'trip_id' => $cesta, 'created_by' => $this->adri->id, 'title' => 'Hotel', 'category' => 'lodging',
            'amount' => 4000, 'currency' => 'CZK', 'state' => 'actual', 'occurred_at' => now()->subMonth(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(2, $this->getJson('/api/data/rozbory')->assertOk()->json('data.TRIPCOST.viden.people'));
    }

    public function test_hvezdicky_druheho_jsou_partnerovy(): void
    {
        $titul = DB::table('watch_titles')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
            'title' => 'Dune', 'kind' => 'film', 'status' => 'hotovo', 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[$this->adri, 4], [$this->host, 1], [$this->maki, 5]] as [$kdo, $znamka]) {
            DB::table('watch_title_ratings')->insert([
                'watch_title_id' => $titul, 'user_id' => $kdo->id, 'rating' => $znamka,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $radek = $this->getJson('/api/data/pribeh')->assertOk()->json('data.AL.films.0');

        $this->assertSame(['a' => 4, 'm' => 5], $radek[4]);
    }

    public function test_energie_nema_radek_hosta(): void
    {
        foreach ([$this->adri, $this->host, $this->maki] as $kdo) {
            DB::table('wellbeing_energy')->insert([
                'gallery_space_id' => $this->prostor->id, 'user_id' => $kdo->id,
                'weekday' => 0, 'slot' => 0, 'level' => 2, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $energie = $this->getJson('/api/data/klid')->assertOk()->json('data.KL_EN');

        $this->assertSame(['Adrian', 'Makinka'], array_keys($energie));
    }

    public function test_kdo_mluvi_za_nas_ma_na_druhem_miste_partnera(): void
    {
        DB::table('couple_outreach_log')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'area' => 'Rodina',
            'by_user_id' => $this->maki->id, 'happened_on' => now()->subDay()->toDateString(), 'asked_partner' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $radek = $this->getJson('/api/data/mechanismy')->assertOk()->json('data.SPEAK.0');

        $this->assertSame(0, $radek['a']);
        $this->assertSame(1, $radek['k']);
    }

    public function test_obavy_druheho_jsou_partnerovy(): void
    {
        $rozvaha = DB::table('couple_premortems')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'title' => 'Koupelna',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[$this->host, 'Hostova obava'], [$this->maki, 'Makinčina obava']] as [$kdo, $riziko]) {
            DB::table('couple_premortem_risks')->insert([
                'uuid' => (string) Str::uuid(), 'couple_premortem_id' => $rozvaha, 'author_user_id' => $kdo->id,
                'risk' => $riziko, 'likelihood' => 2, 'severity' => 2, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $jeho = $this->getJson('/api/data/rozhodovani')->assertOk()->json('data.PM_THEIRS');

        $this->assertSame(['Makinčina obava'], array_column($jeho, 'risk'));
    }

    public function test_rozhodli_jsme_spolu_bez_hosta(): void
    {
        CoupleDecision::create([
            'gallery_space_id' => $this->prostor->id, 'title' => 'Zůstat v nájmu', 'decided_on' => '2026-01-14',
            'together' => true, 'status' => 'platí',
        ]);

        $this->assertSame('Adrian a Makinka', $this->getJson('/api/data/vztah')->assertOk()->json('data.DEC_LIST.0.by'));
    }

    public function test_nalada_nema_krivku_hosta(): void
    {
        foreach ([$this->adri, $this->host, $this->maki] as $kdo) {
            DB::table('wellbeing_moods')->insert([
                'gallery_space_id' => $this->prostor->id, 'user_id' => $kdo->id,
                'day' => $this->dnes()->toDateString(), 'value' => 3, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $nalady = $this->getJson('/api/data/zdravi')->assertOk()->json('data.KL_MOOD');

        $this->assertSame(['Adrian', 'Makinka'], array_keys($nalady));
    }

    public function test_darek_bez_osoby_je_pro_partnera_ne_pro_hosta(): void
    {
        DB::table('gift_ideas')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
            'title' => 'Nápad', 'budget' => 1000, 'currency' => 'CZK', 'status' => 'idea',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('Makinka', $this->getJson('/api/data/darky')->assertOk()->json('data.GIFT_IDEAS.0.forWhom'));
    }

    /** Přístup k trezoru: host v seznamu „člen páru" nemá co dělat. */
    public function test_pristup_k_trezoru_bez_hosta(): void
    {
        $sekce = app(Formulare::class)->sekce('vault', $this->prostor, $this->adri);

        $this->assertSame(['Adrian', 'Makinka'], array_column($sekce[0]['rows'], 'label'));
    }

    // ——— pomůcky ———

    private function kategorie(string $nazev): int
    {
        return DB::table('finance_categories')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'name' => $nazev,
            'kind' => 'expense', 'is_favourite' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function partner(User $kdo): int
    {
        return DB::table('partners')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'kind' => 'person',
            'name' => $kdo->name, 'user_id' => $kdo->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function transakce(array $navic = []): void
    {
        DB::table('transactions')->insert(array_merge([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
            'type' => 'expense', 'occurred_at' => $this->dnes()->toDateTimeString(), 'amount_from' => 100,
            'currency_from' => 'CZK', 'description' => 'Výdaj', 'created_at' => now(), 'updated_at' => now(),
        ], $navic));
    }
}
