<?php

namespace Tests\Feature\Galerie;

use App\Models\CycleDay;
use App\Models\CycleSetting;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dárky, přání a deník ve tvaru, ve kterém je kreslí prototyp.
 *
 * Obě sekce mají společné to, že **jedna strana nesmí vidět všechno**: dárek se
 * nesmí prozradit tomu, pro koho je, a soukromý zápis v deníku patří jen tomu,
 * kdo ho psal. Prozrazený dárek se nedá vzít zpět.
 */
class ObsahDarkyTest extends TestCase
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

    /** Bez dárků se nic neposílá — klient si nechá ukázková data. */
    public function test_bez_darku_se_skupina_neposila(): void
    {
        $this->assertSame([], $this->getJson('/api/data/darky')->assertOk()->json('data'));
    }

    /** Přání je veřejné — o to jde, aby druhý věděl, co si přeju. */
    public function test_prani_nese_kdo_si_ho_preje(): void
    {
        $this->darek([
            'title' => 'Kurz keramiky na tři měsíce',
            'status' => 'wish',
            'budget' => 4200,
            'created_by' => $this->maki->id,
            'source_url' => 'Studio v Nové Karolině.',
        ]);

        $p = $this->getJson('/api/data/darky')->assertOk()->json('data.GIFT_WISHES.0');

        $this->assertSame('Makinka', $p['who']);
        $this->assertSame('Kurz keramiky na tři měsíce', $p['title']);
        $this->assertSame(4200, $p['price']);
        // Nad dva tisíce je to velký dárek — na tom se dvojice musí domluvit.
        $this->assertSame('big', $p['size']);
    }

    /**
     * Chystaný dárek se neprozradí tomu, pro koho je.
     *
     * Tohle je nejdůležitější věc celé sekce: prozrazený dárek se nedá vzít
     * zpět.
     */
    public function test_chystany_darek_se_neprozradi(): void
    {
        if (! Schema::hasColumn('gift_ideas', 'private_to_user_id')) {
            $this->markTestSkipped('Sloupec soukromí v téhle verzi schématu není.');
        }

        $this->darek([
            'title' => 'Stativ Manfrotto',
            'status' => 'bought',
            'budget' => 3600,
            'created_by' => $this->maki->id,
            'private_to_user_id' => $this->maki->id,
        ]);

        // Adrian je obdarovaný — nesmí to vidět.
        $this->assertSame([], $this->getJson('/api/data/darky')->assertOk()->json('data'));

        Sanctum::actingAs($this->maki);

        $n = $this->getJson('/api/data/darky')->assertOk()->json('data.GIFT_BUYS.0');

        $this->assertSame('Stativ Manfrotto', $n['what']);
        $this->assertSame('Makinka', $n['owner']);
        $this->assertSame('bought', $n['status']);
    }

    /** Stav dárku se překládá do slov, která prototyp umí obarvit. */
    public function test_stavy_darku(): void
    {
        $this->darek(['title' => 'Nápad', 'status' => 'idea']);
        $this->darek(['title' => 'Zamluvený', 'status' => 'reserved']);
        $this->darek(['title' => 'Zabalený', 'status' => 'wrapped']);
        $this->darek(['title' => 'Předaný', 'status' => 'given']);

        $data = $this->getJson('/api/data/darky')->assertOk()->json('data');
        $nakupy = collect($data['GIFT_BUYS'])->keyBy('what');

        $this->assertSame('Nápad', $data['GIFT_IDEAS'][0]['title']);
        $this->assertSame('reserved', $nakupy['Zamluvený']['status']);
        $this->assertSame('wrapped', $nakupy['Zabalený']['status']);
        // Předaný dárek je zaplacený a promítnutý do útrat.
        $this->assertTrue($nakupy['Předaný']['expensed']);
    }

    /**
     * Odpočet do příležitosti se počítá teď.
     *
     * Uložený by den po Vánocích tvrdil, že jsou za týden.
     */
    public function test_prilezitost_pocita_dny(): void
    {
        DB::table('gift_budgets')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'scope_key' => 'occasion',
            'budget_year' => 2026,
            'title' => 'Výročí — čtyři roky',
            'occasion' => 'Výročí 4. září',
            'planned_amount' => 6000,
            'currency' => 'CZK',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->darek(['title' => 'Něco', 'status' => 'idea']);

        $p = $this->getJson('/api/data/darky')->assertOk()->json('data.GIFT_OCC.0');

        $this->assertSame('Výročí — čtyři roky', $p['name']);
        $this->assertSame('Výročí 4. září', $p['name2']);
        $this->assertSame(6000, $p['budget']);
    }

    // ——— deník ———

    /** Bez zápisů se nic neposílá. */
    public function test_bez_zapisu_se_denik_neposila(): void
    {
        $this->assertSame([], $this->getJson('/api/data/denik')->assertOk()->json('data'));
    }

    /** Zápis nese datum slovy, nadpis i text. */
    public function test_zapis_z_deniku(): void
    {
        $this->zapis([
            'title' => 'Nad mlhou',
            'body' => 'Vstávání ve čtyři se vyplatilo.',
            'entry_date' => '2026-08-16',
            'visibility' => 'shared',
            'mood' => 'klid',
        ]);

        $z = $this->getJson('/api/data/denik')->assertOk()->json('data.ADIARY.diary.0');

        $this->assertSame('16. srpna 2026', $z[0]);
        $this->assertSame('Nad mlhou', $z[1]);
        $this->assertSame('Vstávání ve čtyři se vyplatilo.', $z[2]);
        $this->assertSame('nálada klid', $z[3]);
    }

    /** Cizí soukromý zápis se neposílá — deník je deník. */
    public function test_cizi_soukromy_zapis_se_neposila(): void
    {
        $this->zapis(['title' => 'Sdílený', 'visibility' => 'shared', 'created_by' => $this->maki->id]);
        $this->zapis(['title' => 'Její soukromý', 'visibility' => 'private', 'created_by' => $this->maki->id]);
        $this->zapis(['title' => 'Můj soukromý', 'visibility' => 'private', 'created_by' => $this->adri->id]);

        $nazvy = collect($this->getJson('/api/data/denik')->assertOk()->json('data.ADIARY.diary'))
            ->map(fn ($z) => $z[1]);

        $this->assertTrue($nazvy->contains('Sdílený'));
        $this->assertTrue($nazvy->contains('Můj soukromý'));
        $this->assertFalse($nazvy->contains('Její soukromý'));
    }

    /** Milník ví, jak je to dávno. */
    public function test_milnik_rika_jak_je_to_davno(): void
    {
        DB::table('relationship_milestones')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Poprvé jsme se potkali',
            'description' => 'Na výstavě, kam ani jeden nechtěl jít.',
            'occurred_on' => now()->subYears(10)->toDateString(),
            'visibility' => 'shared',
            'remind_annually' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $m = $this->getJson('/api/data/denik')->assertOk()->json('data.ADIARY.ms.0');

        $this->assertSame('Poprvé jsme se potkali', $m[1]);
        $this->assertSame('před 10 lety', $m[3]);
    }

    /**
     * Záznamy cyklu se v deníku řídí týmž svolením jako v kalendáři.
     *
     * Jedna obrazovka nesmí obcházet nastavení jiné.
     */
    public function test_cyklus_v_deniku_drzi_uroven_sdileni(): void
    {
        CycleDay::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->maki->id,
            'gallery_space_id' => $this->prostor->id,
            'day' => '2026-08-14',
            'flow' => 'medium',
            'is_cycle_start' => true,
            'note' => 'Délka předchozího 28 dní.',
        ]);

        CycleSetting::updateOrCreate(
            ['user_id' => $this->maki->id, 'gallery_space_id' => $this->prostor->id],
            ['share_level' => CycleSetting::SHARE_NONE],
        );

        $this->assertSame([], $this->getJson('/api/data/denik')->assertOk()->json('data'));

        CycleSetting::where('user_id', $this->maki->id)->update(['share_level' => CycleSetting::SHARE_DATES]);

        $z = $this->getJson('/api/data/denik')->assertOk()->json('data.ADIARY.cycleLog.0');

        $this->assertSame('Začátek cyklu', $z[1]);
        // Poznámka je příznak, ne termín — u „jen termínů" nejde ven.
        $this->assertSame('', $z[2]);

        CycleSetting::where('user_id', $this->maki->id)->update(['share_level' => CycleSetting::SHARE_FULL]);

        $this->assertSame(
            'Délka předchozího 28 dní.',
            $this->getJson('/api/data/denik')->assertOk()->json('data.ADIARY.cycleLog.0.2'),
        );
    }

    /** Dárky jiného páru se do odpovědi nedostanou. */
    public function test_darky_jineho_paru_se_neposilaji(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $this->darek(['title' => 'Náš', 'status' => 'idea']);
        $this->darek([
            'title' => 'Cizí', 'status' => 'idea',
            'gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id,
        ]);

        $nazvy = collect($this->getJson('/api/data/darky')->assertOk()->json('data.GIFT_IDEAS'))->pluck('title');

        $this->assertSame(['Náš'], $nazvy->all());
    }

    // ——— pomůcky ———

    private function darek(array $navic = []): void
    {
        DB::table('gift_ideas')->insert(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Dárek',
            'status' => 'idea',
            'currency' => 'CZK',
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }

    private function zapis(array $navic = []): void
    {
        DB::table('journal_entries')->insert(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Zápis',
            'body' => 'Text zápisu.',
            'entry_date' => now()->toDateString(),
            'visibility' => 'shared',
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }
}
