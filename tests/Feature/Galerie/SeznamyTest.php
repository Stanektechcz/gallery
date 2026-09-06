<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Seznamy `AL` ze skutečných tabulek — a zpátky.
 *
 * `xRows` je společný sklad třiceti dvou seznamů napříč aplikací a většina
 * z nich se kreslila z `galerie-data.js`: vedle skutečné cesty do Chorvatska
 * stál Lisabon někoho cizího, v kuchařce cizí recepty, v účtech „Revolut ·
 * Adrian" se synchronizací, která nikdy neproběhla.
 */
class SeznamyTest extends TestCase
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

    /** Uložená randíčka chodí z tabulky, ne z ukázky. */
    public function test_randicka_chodi_ze_serveru(): void
    {
        DB::table('couple_date_ideas')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'generation_key' => '',
            'title' => 'Kolo k přehradě a zmrzlina',
            'summary' => '',
            'theme' => '',
            'estimated_minutes' => 0,
            'parameters' => json_encode([]),
            'plan' => json_encode([]),
            'status' => 'saved',
            'estimated_cost' => 320,
            'currency' => 'CZK',
            'created_at' => CarbonImmutable::parse('2026-06-12'),
            'updated_at' => now(),
        ]);

        $radek = $this->getJson('/api/data/vztah')->assertOk()->json('data.AL.datesSaved.0');

        $this->assertSame('Kolo k přehradě a zmrzlina', $radek[0]);
        $this->assertStringContainsString('uloženo 12. 6.', $radek[1]);
        $this->assertStringContainsString('320 CZK', $radek[1]);
        $this->assertSame('uloženo', $radek[2]);
    }

    /** Nový nápad na randíčko se zapíše do tabulky. */
    public function test_novy_napad_na_randicko_se_ulozi(): void
    {
        $this->patchJson('/api/state', ['data' => ['xRows' => [
            'datesSaved' => [['t' => 'Noční pozorování hvězd', 'm' => 'právě uloženo', 'g' => 'uloženo', 'id' => 'datesSaved-n1']],
        ]]])->assertOk();

        $this->assertSame('Noční pozorování hvězd', DB::table('couple_date_ideas')->value('title'));
        $this->assertSame('saved', DB::table('couple_date_ideas')->value('status'));

        // A do stavu se neukládá — má tabulku.
        $stav = (array) $this->getJson('/api/state')->assertOk()->json('data');
        $this->assertArrayNotHasKey('xRows', $stav);
    }

    /** Tentýž nápad podruhé druhý řádek nezaloží. */
    public function test_stejny_napad_nevznikne_dvakrat(): void
    {
        $telo = ['data' => ['xRows' => ['datesSaved' => [['t' => 'Keramika pro dva', 'm' => '', 'g' => 'uloženo']]]]];

        $this->patchJson('/api/state', $telo)->assertOk();
        $this->patchJson('/api/state', $telo)->assertOk();

        $this->assertSame(1, DB::table('couple_date_ideas')->count());
    }

    /**
     * Cizí seznamy ve `xRows` zůstanou ve stavu.
     *
     * Nákupní seznam ani nápady na fotky tabulku nemají; vyhodit celý klíč
     * kvůli sousedovi by znamenalo je ztratit.
     */
    public function test_seznamy_bez_tabulky_zustavaji_ve_stavu(): void
    {
        $this->patchJson('/api/state', ['data' => ['xRows' => [
            'datesSaved' => [['t' => 'Keramika pro dva', 'm' => '', 'g' => 'uloženo']],
            'shopping' => [['t' => 'Rajčata 1 kg', 'm' => 'ručně přidáno', 'g' => null, 'id' => 'shopping-n1']],
        ]]])->assertOk();

        $stav = (array) $this->getJson('/api/state')->assertOk()->json('data');

        $this->assertSame('Rajčata 1 kg', $stav['xRows']['shopping'][0]['t']);
        $this->assertArrayNotHasKey('datesSaved', $stav['xRows']);
    }

    /** Nová jízdenka vznikne, trasa se z názvu nehádá. */
    public function test_jizdenka_se_ulozi_bez_hadani_trasy(): void
    {
        $this->patchJson('/api/state', ['data' => ['xRows' => [
            'ticket' => [['t' => 'Letenky BRQ – LIS', 'm' => 'doplňte trasu a datum', 'g' => 'rezervace']],
        ]]])->assertOk();

        $radek = DB::table('saved_transport_routes')->sole();

        $this->assertSame('Letenky BRQ – LIS', $radek->name);
        $this->assertSame('', $radek->origin, 'Odkud kam doplní člověk — z názvu se to nepozná.');

        $seznam = $this->getJson('/api/data/cesty')->assertOk()->json('data.AL.ticket.0');
        $this->assertSame('Letenky BRQ – LIS', $seznam[0]);
        $this->assertSame('uloženo', $seznam[2]);
    }

    /** Nápad na dárek se pozná podle předpony; příležitost do nápadů nepatří. */
    public function test_z_milniku_se_uklada_jen_napad(): void
    {
        $this->patchJson('/api/state', ['data' => ['xRows' => [
            'gifts' => [
                ['t' => 'Deset let spolu', 'm' => '18. 10. 2026 · za 42 dní', 'g' => 'blíží se'],
                ['t' => 'Nápad: kurz keramiky', 'm' => 'pro Makinku', 'g' => 'nápad'],
            ],
        ]]])->assertOk();

        $this->assertSame(['kurz keramiky'], DB::table('gift_ideas')->pluck('title')->all());
        $this->assertSame('idea', DB::table('gift_ideas')->value('status'));
    }

    /** Zařazení v cestovní schránce se zapíše; nová položka odsud nevzniká. */
    public function test_zarazeni_v_cestovni_schrance(): void
    {
        DB::table('travel_inbox_items')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'added_by' => $this->adri->id,
            'title' => 'Odkaz na apartmán v Sintře',
            'kind' => 'odkaz',
            'state' => 'new',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->patchJson('/api/state', ['data' => ['xRows' => [
            'travelInbox' => [
                ['t' => 'Odkaz na apartmán v Sintře', 'm' => 'odkaz · 2. 8.', 'g' => 'zařazeno'],
                ['t' => 'Něco, co v schránce není', 'm' => '', 'g' => 'zařazeno'],
            ],
        ]]])->assertOk();

        $this->assertSame('filed', DB::table('travel_inbox_items')->value('state'));
        $this->assertSame(1, DB::table('travel_inbox_items')->count(), 'Z téhle obrazovky se nové položky nezakládají.');
    }

    /** Účty v seznamu jsou skutečné peněženky. */
    public function test_ucty_v_seznamu_jsou_skutecne(): void
    {
        DB::table('wallets')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'name' => 'EUR karta',
            'kind' => 'bank',
            'currency' => 'EUR',
            'opening_balance' => 500,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $radek = $this->getJson('/api/data/finance')->assertOk()->json('data.AL.accounts.0');

        $this->assertSame('EUR karta', $radek[0]);
        $this->assertSame('napojeno', $radek[2]);
    }
}
