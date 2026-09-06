<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mechanismy pro dva: účet laskavostí, odpuštěné věci, anti-rozpočet,
 * mentální zátěž, rodina, dvě pravdy, pauza a ticho v datech.
 *
 * Osm kolekcí, které se dosud ukládaly do jednoho JSON dokumentu. Nebylo to
 * ztracené, ale nešlo se na to zeptat.
 */
class ObsahMechanismyTest extends TestCase
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

    /** Prázdný účet nechává ukázku. */
    public function test_bez_zaznamu_se_nic_neposila(): void
    {
        $data = $this->getJson('/api/data/mechanismy')->assertOk()->json('data');

        $this->assertArrayNotHasKey('FAV', $data);
    }

    /** Laskavost ví, kdo ji udělal a jestli je vyrovnaná. */
    public function test_laskavost_zna_sveho_cloveka(): void
    {
        DB::table('couple_favours')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'from_user_id' => $this->maki->id,
            'what' => 'Vzala celé pojištění a papíry na sebe',
            'happened_on' => '2026-08-28',
            'weight' => 3,
            'is_settled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $l = $this->getJson('/api/data/mechanismy')->assertOk()->json('data.FAV.0');

        $this->assertSame('Makinka', $l['from']);
        $this->assertSame('2026-08-28', $l['date']);
        $this->assertSame(3, $l['w']);
        $this->assertFalse($l['settled']);
    }

    /**
     * Odpuštěné neznamená zapomenuté.
     *
     * `tries` je jediné, co o tom něco řekne — kolikrát se to vrátilo do řeči.
     */
    public function test_odpustene_nese_kolikrat_se_vratilo(): void
    {
        DB::table('couple_forgiven')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'forgiven_by' => $this->maki->id,
            'what' => 'Zapomenuté výročí v roce 2024',
            'happened_on' => '2024-10-02',
            'tries' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $o = $this->getJson('/api/data/mechanismy')->assertOk()->json('data.FORGIVEN.0');

        $this->assertSame('Makinka', $o['by']);
        $this->assertSame(2, $o['tries']);
    }

    /** Mentální zátěž rozlišuje, kdo to dělá a kdo na to musí myslet. */
    public function test_mentalni_zatez_rozlisi_ruce_od_hlavy(): void
    {
        DB::table('couple_mental_load')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'doer_id' => $this->adri->id,
            'keeper_id' => $this->maki->id,
            'task' => 'Objednat servis auta',
            'times_a_year' => 2,
            'minutes' => 25,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $z = $this->getJson('/api/data/mechanismy')->assertOk()->json('data.ML_LOAD.0');

        $this->assertSame('Adrian', $z['doer']);
        $this->assertSame('Makinka', $z['head']);
        $this->assertSame(2, $z['freq']);
    }

    /**
     * Jestli je kontakt po lhůtě, se počítá teď.
     *
     * Uložený příznak by byl den po termínu vedle.
     */
    public function test_po_lhute_se_pocita_ted(): void
    {
        $this->kontakt('Máma Adriana', 7, now()->subDays(4));
        $this->kontakt('Táta Adriana', 14, now()->subDays(19));

        $r = collect($this->getJson('/api/data/mechanismy')->assertOk()->json('data.FAMILY'))->keyBy('name');

        $this->assertFalse($r['Máma Adriana']['over']);
        $this->assertSame('před 4 dny', $r['Máma Adriana']['last']);
        $this->assertTrue($r['Táta Adriana']['over']);
    }

    /** Dvě pravdy stojí vedle sebe a žádná nevyhrává. */
    public function test_dve_pravdy_stoji_vedle_sebe(): void
    {
        DB::table('couple_truths')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Kdo přišel na nádraží pozdě',
            'context' => 'červenec 2026 · cesta do Zadaru',
            'first_user_id' => $this->adri->id,
            'first_version' => 'Přišel jsem v 6:40.',
            'second_user_id' => $this->maki->id,
            'second_version' => 'Byla jsem tam první.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $p = $this->getJson('/api/data/mechanismy')->assertOk()->json('data.TRUTHS.0');

        $this->assertSame('Přišel jsem v 6:40.', $p['a']);
        $this->assertSame('Byla jsem tam první.', $p['m']);
    }

    /**
     * Ticho v datech se nezapisuje — počítá se.
     *
     * Uložené by bylo druhou, zastarávající pravdou.
     */
    public function test_ticho_se_pocita_z_poslednich_zapisu(): void
    {
        DB::table('journal_entries')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Zápis',
            'body' => 'Text',
            'entry_date' => now()->toDateString(),
            'visibility' => 'shared',
            'created_at' => now()->subDays(2),
            'updated_at' => now(),
        ]);

        DB::table('recipes')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Focaccia',
            'created_at' => now()->subDays(40),
            'updated_at' => now(),
        ]);

        $t = $this->getJson('/api/data/mechanismy')->assertOk()->json('data.TICHO');

        // Nejtišší nahoře — o to na téhle obrazovce jde.
        $this->assertSame('Kuchařka', $t[0]['name']);
        $this->assertSame('Deník', $t[1]['name']);
        $this->assertSame('x-kucharka', $t[0]['route']);
    }

    /** Mechanismy druhého páru se do odpovědi nedostanou. */
    public function test_mechanismy_jineho_paru_se_neposilaji(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        DB::table('couple_favours')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $ciziProstor->id,
            'from_user_id' => $cizi->id,
            'what' => 'Cizí laskavost',
            'happened_on' => now()->toDateString(),
            'weight' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertArrayNotHasKey('FAV', $this->getJson('/api/data/mechanismy')->assertOk()->json('data'));
    }

    // ——— pomůcky ———

    private function kontakt(string $jmeno, int $kazdych, $naposledy): void
    {
        DB::table('couple_family_contacts')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'name' => $jmeno,
            'side_user_id' => $this->adri->id,
            'every_days' => $kazdych,
            'last_contact_on' => $naposledy->toDateString(),
            'last_contact_by' => $this->adri->id,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
