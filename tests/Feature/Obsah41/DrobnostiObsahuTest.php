<?php

namespace Tests\Feature\Obsah41;

use App\Models\CycleSetting;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Integrations\FreeTravelDataService;
use App\Services\Obsah\TichaPravidla;
use App\Services\Obsah\UcetRadosti;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Drobnější nálezy auditu obsahu obrazovek — každý test jedna díra.
 */
class DrobnostiObsahuTest extends TestCase
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

    public function test_smazany_recept_v_kucharce_neni(): void
    {
        $this->recept('Polévka');
        $this->recept('Smazaný koláč', ['deleted_at' => now()]);

        $nazvy = collect($this->getJson('/api/data/kucharka')->assertOk()->json('data.RECIPES'))->pluck('title')->all();

        $this->assertContains('Polévka', $nazvy);
        $this->assertNotContains('Smazaný koláč', $nazvy);
    }

    /** Výpadek Open-Meteo se pamatuje — další načtení na službu nečeká. */
    public function test_nedostupna_predpoved_se_nezkousi_pri_kazdem_nacteni(): void
    {
        $this->recept('Polévka');
        $this->fotkySPolohou();

        $this->mock(FreeTravelDataService::class, function ($mock) {
            $mock->shouldReceive('weather')->once()->andThrow(new \RuntimeException('mimo provoz'));
        });

        $this->getJson('/api/data/kucharka')->assertOk();
        $this->getJson('/api/data/kucharka')->assertOk();
    }

    /** Dárek s cizím `person_id` nedostane jméno člověka z jiné galerie. */
    public function test_darek_nebere_jmena_lidi_z_jineho_prostoru(): void
    {
        $cizi = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $this->maki->id]);
        $clovek = DB::table('people')->insertGetId([
            'gallery_space_id' => $cizi->id, 'name' => 'Cizí babička', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $nas = DB::table('people')->insertGetId([
            'gallery_space_id' => $this->prostor->id, 'name' => 'Naše teta', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->darek('Šála', $clovek);
        $this->darek('Kniha', $nas);

        $napady = collect($this->getJson('/api/data/darky')->assertOk()->json('data.GIFT_IDEAS'))->pluck('forWhom', 'title');

        $this->assertSame('—', $napady['Šála']);
        $this->assertSame('Naše teta', $napady['Kniha']);
    }

    public function test_zruseny_ukol_neni_propadla_lhuta(): void
    {
        DB::table('couple_decisions')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'title' => 'Aby skupina nebyla prázdná',
            'decided_on' => '2026-01-01', 'together' => true, 'status' => 'platí', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([['Zrušený', 'cancelled'], ['Otevřený', 'open']] as [$nazev, $stav]) {
            DB::table('shared_todos')->insert([
                'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
                'title' => $nazev, 'status' => $stav, 'due_at' => now()->subDays(3), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $co = collect($this->getJson('/api/data/vztah')->assertOk()->json('data.AUTO_DEC'))->pluck('what')->all();

        $this->assertContains('Otevřený', $co);
        $this->assertNotContains('Zrušený', $co);
    }

    /**
     * Rok v číslech bez kurzu: čekající platba není zapsaná a eura se nepíšou jako koruny.
     *
     * Kurz ECB v testu známý není (síť je zavřená), takže se eura sečíst nedají —
     * ukážou se jen koruny a výslovně se řekne, že eura v součtu nejsou.
     */
    public function test_rok_v_cislech_bez_kurzu_bez_cekajicich_a_bez_michani_men(): void
    {
        $finance = $this->rokVCislechSEury();

        $this->assertSame("3\u{00A0}000 Kč", $finance[4][0][1]);
        $this->assertStringContainsString('jen v CZK', $finance[4][0][2]);
        $this->assertStringContainsString('50 € stranou', str_replace("\u{00A0}", ' ', $finance[4][0][2]));
    }

    /** S kurzem se eura přepočtou do korun a řekne se, k jakému dni kurz platí. */
    public function test_rok_v_cislech_s_kurzem_secte_v_korunach(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['date' => '2026-09-24', 'base' => 'EUR', 'quote' => 'CZK', 'rate' => 25.0]),
        ]);

        $finance = $this->rokVCislechSEury();

        // 1 000 + 2 000 + 50 € × 25; čekajících 7 000 se nepočítá.
        $this->assertSame("4\u{00A0}250 Kč", $finance[4][0][1]);
        $this->assertStringContainsString('přepočteno kurzem ECB k 24. 9. 2026', $finance[4][0][2]);
        $this->assertStringNotContainsString('jen v', $finance[4][0][2]);
    }

    /** @return array<int, mixed> kapitola „finance" letošního roku v číslech */
    private function rokVCislechSEury(): array
    {
        $rok = (int) $this->dnes()->year;
        $den = $this->dnes()->startOfYear()->addDays(5)->toDateTimeString();
        $this->transakce(['amount_from' => 1000, 'occurred_at' => $den]);
        $this->transakce(['amount_from' => 2000, 'occurred_at' => $den]);
        $this->transakce(['amount_from' => 7000, 'occurred_at' => $den, 'state' => 'pending']);
        $this->transakce(['amount_from' => 50, 'occurred_at' => $den, 'currency_from' => 'EUR']);

        $kapitoly = collect($this->getJson('/api/data/tyden')->assertOk()->json('data.ROKVCISLECH.'.$rok));

        return $kapitoly->firstWhere(0, 'finance');
    }

    /** Čekající příjem (`pending`) ještě na účet nepřišel. */
    public function test_prijem_osoby_bez_cekajiciho(): void
    {
        $adrian = DB::table('partners')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'kind' => 'person',
            'name' => 'Adrian', 'user_id' => $this->adri->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->rozpocet();
        foreach ([[30000, 'approved'], [10000, 'pending']] as [$castka, $stav]) {
            $this->transakce([
                'type' => 'income', 'amount_from' => null, 'currency_from' => null, 'amount_to' => $castka, 'currency_to' => 'CZK',
                'beneficiary_partner_id' => $adrian, 'state' => $stav, 'occurred_at' => $this->dnes()->startOfMonth()->toDateTimeString(),
            ]);
        }

        $this->assertSame(['Adrian' => 30000], $this->getJson('/api/data/finance')->assertOk()->json('data.INCOMES'));
    }

    /** Smazaná útrata nezvedá „kolik stojí" radost. */
    public function test_ucet_radosti_nepocita_smazane_utraty(): void
    {
        foreach ([10, 20, 30] as $pred) {
            $den = $this->dnes()->subDays($pred)->setTime(18, 0);
            DB::table('calendar_events')->insert([
                'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
                'title' => 'Kino', 'type' => 'event', 'status' => 'confirmed', 'activity_kind' => 'kino',
                'starts_at' => $den->utc(), 'ends_at' => $den->addHours(2)->utc(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->transakce(['amount_from' => 500, 'occurred_at' => $den->utc()->toDateString(), 'deleted_at' => now()]);
        }

        $radek = collect(app(UcetRadosti::class)->spocitej($this->prostor))->firstWhere('name', 'kino');

        $this->assertSame(0, $radek['cost']);
    }

    /** Smazané zápisy nerozhodují, do kolika hodin se řeší peníze. */
    public function test_ticha_pravidla_nepocitaji_smazane_transakce(): void
    {
        foreach (range(1, 8) as $i) {
            $this->transakce(['created_at' => $this->dnes()->subDays($i)->setTime(10, 0)->utc()]);
        }
        foreach (range(1, 3) as $i) {
            $this->transakce(['created_at' => $this->dnes()->subDays($i)->setTime(23, 30)->utc(), 'deleted_at' => now()]);
        }

        $pravidla = collect(app(TichaPravidla::class)->najdi($this->prostor))->where('kind', 'klid')->values();

        $this->assertCount(1, $pravidla);
        $this->assertSame(8, $pravidla[0]['of']);
    }

    /** Sdílení „jen termínů": partnerovy dny jen s poznámkou se nevypisují. */
    public function test_denik_pri_sdileni_terminu_neukaze_partnerovy_dny_s_poznamkou(): void
    {
        CycleSetting::create(['gallery_space_id' => $this->prostor->id, 'user_id' => $this->maki->id, 'share_level' => CycleSetting::SHARE_DATES]);
        DB::table('cycle_days')->insert([
            ['uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'user_id' => $this->maki->id, 'day' => $this->dnes()->subDays(3)->toDateString(),
                'is_cycle_start' => true, 'note' => null, 'created_at' => now(), 'updated_at' => now()],
            ['uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'user_id' => $this->maki->id, 'day' => $this->dnes()->subDays(2)->toDateString(),
                'is_cycle_start' => false, 'note' => 'Bolest hlavy', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $zaznamy = $this->getJson('/api/data/denik')->assertOk()->json('data.ADIARY.cycleLog');

        $this->assertSame(['Začátek cyklu'], array_column($zaznamy, 1));
    }

    /** Pravidelná platba, která teprve začne, se v předpovědi objeví až od svého začátku. */
    public function test_predpoved_nebere_platbu_pred_jejim_zacatkem(): void
    {
        DB::table('wallets')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'name' => 'Účet', 'kind' => 'bank',
            'currency' => 'CZK', 'opening_balance' => 10000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->transakce();
        $zacatek = $this->dnes()->startOfMonth()->addMonthsNoOverflow(2);
        DB::table('finance_recurring')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'name' => 'Nový nájem', 'type' => 'expense',
            'amount' => 12000, 'currency' => 'CZK', 'day_of_month' => 1, 'starts_on' => $zacatek->toDateString(),
            'is_active' => true, 'created_by' => $this->adri->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $udalosti = $this->getJson('/api/data/rozbory')->assertOk()->json('data.P60.events') ?? [];
        $pred = collect($udalosti)->filter(fn (array $u) => $this->dnes()->addDays($u['d'])->lt($zacatek));

        $this->assertCount(0, $pred);
    }

    // ——— pomůcky ———

    private function recept(string $nazev, array $navic = []): void
    {
        DB::table('recipes')->insert(array_merge([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
            'title' => $nazev, 'status' => 'published', 'created_at' => now(), 'updated_at' => now(),
        ], $navic));
    }

    private function darek(string $nazev, int $osoba): void
    {
        DB::table('gift_ideas')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
            'title' => $nazev, 'budget' => 500, 'currency' => 'CZK', 'status' => 'idea', 'person_id' => $osoba,
            'created_at' => now(), 'updated_at' => now(),
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

    private function rozpocet(): void
    {
        DB::table('budgets')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
            'name' => 'Rozpočet', 'currency' => 'CZK', 'starts_on' => now()->startOfMonth()->toDateString(),
            'is_shared' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function fotkySPolohou(): void
    {
        foreach ([[49.84, 18.29], [49.83, 18.26], [49.85, 18.28], [49.84, 18.27]] as $i => $bod) {
            MediaItem::create([
                'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id,
                'owner_user_id' => $this->adri->id, 'uploaded_by' => $this->adri->id,
                'original_filename' => 'geo_'.$i.'.jpg', 'safe_filename' => 'geo-'.$i.'.jpg', 'extension' => 'jpg',
                'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1024,
                'latitude' => $bod[0], 'longitude' => $bod[1],
                'taken_at' => CarbonImmutable::now()->subDays(10 + $i), 'uploaded_at' => CarbonImmutable::now()->subDays(10 + $i),
                'status' => 'ready', 'storage_status' => 'local',
            ]);
        }
    }
}
