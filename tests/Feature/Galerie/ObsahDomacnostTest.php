<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\HouseChore;
use App\Models\HouseChoreLogEntry;
use App\Models\HouseDue;
use App\Models\HouseInventoryItem;
use App\Models\HousePantryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Domácnost ve tvaru, ve kterém ji kreslí prototyp — a zpátky.
 *
 * Dělba práce, lhůty a byt se do téhle chvíle odehrávaly celé v jednom JSON
 * dokumentu stavu páru. Teď mají tabulky, a proto je tenhle test v obou směrech:
 * co server pošle na obrazovku, a co se stane s tím, co obrazovka pošle zpátky.
 * Tabulka, do které nikdo nepíše, je horší než žádná tabulka.
 */
class ObsahDomacnostTest extends TestCase
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

    /** Prázdná domácnost nechává ukázku — prázdná obrazovka vypadá jako rozbitá. */
    public function test_bez_domacnosti_se_skupina_neposila(): void
    {
        $this->assertSame([], $this->getJson('/api/data/domacnost')->assertOk()->json('data'));
    }

    /**
     * „Naposledy" je popisek, ne uložená věta.
     *
     * V databázi je okamžik; „před 11 dny" platí jen ten den, kdy se to čte.
     */
    public function test_prace_nese_kdy_naposledy_a_zda_je_po_terminu(): void
    {
        $this->prace(['name' => 'Vysát celý byt', 'every' => 'týdně', 'last_done_at' => now()->subDays(11)]);
        $this->prace(['name' => 'Nádobí a kuchyň', 'every' => 'denně', 'last_done_at' => now()]);

        $prace = collect($this->getJson('/api/data/domacnost')->assertOk()->json('data.HOUSE_CHORES'))
            ->keyBy('name');

        $this->assertSame('před 11 dny', $prace['Vysát celý byt']['last']);
        $this->assertTrue($prace['Vysát celý byt']['overdue']);
        $this->assertSame('dnes', $prace['Nádobí a kuchyň']['last']);
        $this->assertFalse($prace['Nádobí a kuchyň']['overdue']);
    }

    /** Práce, kterou nikdo nikdy neudělal, se nemá tvářit jako čerstvá. */
    public function test_prace_bez_zaznamu_hlasi_ze_se_nikdy_nedelala(): void
    {
        $this->prace(['name' => 'Umýt okna', 'last_done_at' => null]);

        $prace = $this->getJson('/api/data/domacnost')->assertOk()->json('data.HOUSE_CHORES.0');

        $this->assertSame('zatím nikdy', $prace['last']);
        $this->assertFalse($prace['overdue']);
    }

    /** Kdo má práci na starost, je člověk — ne jméno v řetězci. */
    public function test_prace_zna_odpovedneho_i_rotaci(): void
    {
        $this->prace(['name' => 'Prádlo', 'assigned_to' => $this->maki->id, 'rotate' => false, 'day' => 'st']);

        $prace = $this->getJson('/api/data/domacnost')->assertOk()->json('data.HOUSE_CHORES.0');

        $this->assertSame('Makinka', $prace['who']);
        $this->assertFalse($prace['rotate']);
        $this->assertSame('st', $prace['day']);
    }

    /** Lhůta počítá dny do termínu; záporné číslo kreslí prototyp jako po termínu. */
    public function test_lhuta_pocita_dny_do_terminu(): void
    {
        $this->zavazek(['what' => 'STK a emise', 'due_on' => now()->addDays(41), 'amount' => 1900]);
        $this->zavazek(['what' => 'Nájem', 'due_on' => now()->subDays(2), 'amount' => 14500]);

        $lhuty = collect($this->getJson('/api/data/domacnost')->assertOk()->json('data.HOUSE_DUES'))
            ->keyBy('what');

        $this->assertSame(41, $lhuty['STK a emise']['days']);
        $this->assertSame(-2, $lhuty['Nájem']['days']);
    }

    /** Vyřízená lhůta se nevrací — v seznamu nemá co dělat. */
    public function test_vyrizena_lhuta_se_neposila(): void
    {
        $this->zavazek(['what' => 'Platí', 'due_on' => now()->addWeek()]);
        $this->zavazek(['what' => 'Hotovo', 'due_on' => now()->addWeek(), 'settled_at' => now()]);

        $lhuty = collect($this->getJson('/api/data/domacnost')->assertOk()->json('data.HOUSE_DUES'))->pluck('what');

        $this->assertSame(['Platí'], $lhuty->all());
    }

    /** Záruka se píše měsícem a rokem; den u ní nikoho nezajímá. */
    public function test_vec_v_byte_nese_zaruku_i_servis(): void
    {
        $this->vec([
            'name' => 'Myčka Bosch',
            'warranty_to' => '2026-03-31',
            'needs_service' => true,
            'service_next_on' => now()->addDays(6)->toDateString(),
            'service_price' => 1600,
        ]);

        $vec = $this->getJson('/api/data/domacnost')->assertOk()->json('data.HOUSE_INV.0');

        $this->assertSame('3/2026', $vec['warrantyTo']);
        $this->assertTrue($vec['service']);
        $this->assertSame(6, $vec['serviceDays']);
        $this->assertSame(1600, $vec['servicePrice']);
    }

    /**
     * Spíž je pole, ne objekt — prototyp ji čte podle pořadí (`p[0]`, `p[1]`).
     *
     * Dní do zkažení se počítá z data: ze dne datum spočítat nejde.
     */
    public function test_spiz_je_pole_a_pocita_dny_do_zkazeni(): void
    {
        HousePantryItem::create([
            'gallery_space_id' => $this->prostor->id,
            'name' => 'Smetana 33 %',
            'category' => 'Lednice',
            'quantity' => 200,
            'unit' => 'ml',
            'expires_on' => now()->addDays(4)->toDateString(),
            'keywords' => ['smetana'],
        ]);

        $radek = $this->getJson('/api/data/domacnost')->assertOk()->json('data.PANTRY.0');

        $this->assertSame('Smetana 33 %', $radek[1]);
        $this->assertSame('Lednice', $radek[2]);
        $this->assertSame(200, $radek[3]);
        $this->assertSame('ml', $radek[4]);
        $this->assertSame(4, $radek[5]);
        $this->assertSame(['smetana'], $radek[6]);
    }

    /** Kapacita týdne drží hodiny obou lidí, ne dvě jména v katalogu. */
    public function test_kapacita_tydne_zna_oba(): void
    {
        $den = DB::table('house_week')->insertGetId([
            'gallery_space_id' => $this->prostor->id,
            'weekday' => 'po',
            'note' => 'Oba v práci do 17.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([[$this->adri->id, 2.5], [$this->maki->id, 1.5]] as [$kdo, $hodin]) {
            DB::table('house_week_capacity')->insert([
                'house_week_id' => $den, 'user_id' => $kdo, 'free_hours' => $hodin,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $tyden = $this->getJson('/api/data/domacnost')->assertOk()->json('data.HOUSE_WEEK');

        $this->assertCount(1, $tyden);
        $this->assertSame('po', $tyden[0]['key']);
        $this->assertSame('Pondělí', $tyden[0]['name']);
        $this->assertSame(2.5, $tyden[0]['a']);
        $this->assertSame(1.5, $tyden[0]['m']);
        $this->assertSame('Oba v práci do 17.', $tyden[0]['note']);
    }

    /** Telefon kreslí domácnost z vlastních kolekcí, ne z `GalerieData`. */
    public function test_telefon_dostane_svuj_tvar(): void
    {
        $this->prace(['name' => 'Vysát celý byt']);
        HousePantryItem::create([
            'gallery_space_id' => $this->prostor->id,
            'name' => 'Česnek', 'category' => 'Špajz', 'quantity' => 1, 'unit' => 'palice',
            'keywords' => ['česnek', 'stroužek'],
        ]);

        $mobil = $this->getJson('/api/data/domacnost')->assertOk()->json('data.MOBIL');

        $this->assertSame('Vysát celý byt', $mobil['HOUSE_CHORES'][0]['name']);
        // Ve spíži telefonu je poslední pole jedno slovo, ne seznam.
        $this->assertSame('česnek', $mobil['MPANTRY'][0][6]);
    }

    // ——— zpátky ze stavu do databáze ———

    /**
     * Zásah z obrazovky končí v databázi, ne ve stavu.
     *
     * Kdyby zůstal ve stavu, byly by dvě pravdy — a druhá by se od první po
     * prvním kliknutí rozešla.
     */
    public function test_predani_prace_se_zapise_a_ze_stavu_zmizi(): void
    {
        $prace = $this->prace(['name' => 'Vysát celý byt', 'assigned_to' => $this->adri->id]);

        $odpoved = $this->patchJson('/api/state', ['data' => ['chores' => [[
            'id' => $prace->uuid,
            'name' => 'Vysát celý byt',
            'who' => 'Makinka',
            'rotate' => true,
            'mins' => 45,
            'day' => 'so',
        ]]]])->assertOk();

        $this->assertSame($this->maki->id, $prace->refresh()->assigned_to);
        $this->assertSame('so', $prace->day);
        $this->assertArrayNotHasKey('chores', (array) $odpoved->json('data'));
    }

    /**
     * Odškrtnutí práce zapíše záznam a posune „naposledy".
     *
     * Čas se bere ze serveru: prototyp posílá popisek „právě teď".
     */
    public function test_odskrtnuta_prace_zalozi_zaznam(): void
    {
        $prace = $this->prace(['name' => 'Nádobí a kuchyň', 'assigned_to' => $this->maki->id, 'last_done_at' => null]);

        $this->patchJson('/api/state', ['data' => [
            'choreLog' => [['id' => 'l1725', 'chore' => 'Nádobí a kuchyň', 'who' => 'Makinka', 'mins' => 15, 'when' => 'právě teď']],
            'chores' => [['id' => $prace->uuid, 'name' => 'Nádobí a kuchyň', 'who' => 'Makinka', 'rotate' => true, 'mins' => 15, 'day' => null]],
        ]])->assertOk();

        $zaznam = HouseChoreLogEntry::where('gallery_space_id', $this->prostor->id)->firstOrFail();

        $this->assertSame('Nádobí a kuchyň', $zaznam->chore_name);
        $this->assertSame($this->maki->id, $zaznam->user_id);
        $this->assertSame(15, $zaznam->minutes);
        $this->assertSame($prace->id, $zaznam->house_chore_id);
        $this->assertNotNull($prace->refresh()->last_done_at);
    }

    /** Tentýž záznam podruhé nezaloží druhý řádek — jinak by se historie množila. */
    public function test_zaznam_se_nezalozi_dvakrat(): void
    {
        $this->prace(['name' => 'Rostliny']);

        $patch = ['data' => ['choreLog' => [
            ['id' => 'l1', 'chore' => 'Rostliny', 'who' => 'Makinka', 'mins' => 10, 'when' => 'právě teď'],
        ]]];

        $this->patchJson('/api/state', $patch)->assertOk();
        $this->patchJson('/api/state', $patch)->assertOk();

        $this->assertSame(1, HouseChoreLogEntry::where('gallery_space_id', $this->prostor->id)->count());
    }

    /**
     * Napsané řádky ukázky se do historie nepropíšou.
     *
     * Prototyp posílá celý seznam, takže při prvním kliknutí přijde i dvacet
     * ukázkových záznamů. Kdyby se zapsaly, měly by všechny dnešní čas
     * a statistika posledního měsíce by lhala hned v první vteřině.
     */
    public function test_ukazkove_zaznamy_se_nezapisuji(): void
    {
        $this->prace(['name' => 'Rostliny']);

        $this->patchJson('/api/state', ['data' => ['choreLog' => [
            ['id' => 'l1725', 'chore' => 'Rostliny', 'who' => 'Makinka', 'mins' => 10, 'when' => 'právě teď'],
            ['id' => 'l1', 'chore' => 'Nádobí a kuchyň', 'who' => 'Makinka', 'mins' => 15, 'when' => 'dnes 8:10'],
            ['id' => 'l2', 'chore' => 'Odpadky', 'who' => 'Adrian', 'mins' => 10, 'when' => 'včera 19:40'],
        ]]])->assertOk();

        $zaznamy = HouseChoreLogEntry::where('gallery_space_id', $this->prostor->id)->get();

        $this->assertCount(1, $zaznamy);
        $this->assertSame('Rostliny', $zaznamy[0]->chore_name);
    }

    /** Vzato zpět: „mám hotovo" má tlačítko zpět a záznam po něm nesmí zůstat. */
    public function test_vzeti_zpet_zaznam_odstrani(): void
    {
        $this->prace(['name' => 'Rostliny']);

        $this->patchJson('/api/state', ['data' => ['choreLog' => [
            ['id' => 'l1725', 'chore' => 'Rostliny', 'who' => 'Makinka', 'mins' => 10, 'when' => 'právě teď'],
        ]]])->assertOk();

        $this->patchJson('/api/state', ['data' => ['choreLog' => []]])->assertOk();

        $this->assertSame(0, HouseChoreLogEntry::where('gallery_space_id', $this->prostor->id)->count());
    }

    /**
     * První dotek prázdné domácnosti ji založí.
     *
     * Na obrazovce je rozdělení, se kterým dvojice právě pracovala; zahodit ho
     * by znamenalo, že jejich klik po obnovení stránky zmizí.
     */
    public function test_prvni_zasah_zalozi_delbu_z_toho_co_bylo_na_obrazovce(): void
    {
        $this->patchJson('/api/state', ['data' => ['chores' => [
            ['id' => 'c1', 'name' => 'Vysát celý byt', 'every' => 'týdně', 'who' => 'Makinka', 'rotate' => true, 'mins' => 45, 'day' => 'so', 'icon' => 'ph-wind'],
            ['id' => 'c2', 'name' => 'Nádobí a kuchyň', 'every' => 'denně', 'who' => 'Adrian', 'rotate' => true, 'mins' => 15, 'day' => null, 'icon' => 'ph-cooking-pot'],
        ]]])->assertOk();

        $prace = HouseChore::where('gallery_space_id', $this->prostor->id)->get()->keyBy('name');

        $this->assertCount(2, $prace);
        $this->assertSame($this->maki->id, $prace['Vysát celý byt']->assigned_to);
        $this->assertSame(45, $prace['Vysát celý byt']->minutes);
        $this->assertSame('c1', $prace['Vysát celý byt']->client_id);
    }

    /** Druhá změna v témž sezení nesmí založit duplikát — klient stále posílá svůj `c1`. */
    public function test_druhy_zasah_v_temz_sezeni_nezaloz_duplikat(): void
    {
        $radek = ['id' => 'c1', 'name' => 'Vysát celý byt', 'every' => 'týdně', 'who' => 'Makinka', 'rotate' => true, 'mins' => 45, 'day' => 'so', 'icon' => 'ph-wind'];

        $this->patchJson('/api/state', ['data' => ['chores' => [$radek]]])->assertOk();
        $this->patchJson('/api/state', ['data' => ['chores' => [['...' => null] + $radek + ['who' => 'Adrian']]]])->assertOk();

        $this->assertSame(1, HouseChore::where('gallery_space_id', $this->prostor->id)->count());
    }

    /** Lhůta zmizelá ze seznamu je vyřízená, ne smazaná: rok co rok se ptáme, kdy byla STK. */
    public function test_lhuta_ktera_zmizela_ze_seznamu_je_vyrizena(): void
    {
        $zustane = $this->zavazek(['what' => 'Nájem', 'due_on' => now()->addWeek()]);
        $zmizi = $this->zavazek(['what' => 'STK', 'due_on' => now()->addMonth()]);

        $this->patchJson('/api/state', ['data' => ['dues' => [[
            'id' => $zustane->uuid, 'what' => 'Nájem', 'kind' => 'platba',
            'date' => $zustane->due_on->format('j. n. Y'), 'amount' => 14500, 'who' => 'Adrian',
        ]]]])->assertOk();

        $this->assertNull($zustane->refresh()->settled_at);
        $this->assertNotNull($zmizi->refresh()->settled_at);
    }

    /** Lhůta založená ze servisu v inventáři dostane skutečné datum. */
    public function test_lhuta_ze_servisu_se_zalozi_s_datem(): void
    {
        $this->zavazek(['what' => 'Nájem', 'due_on' => now()->addWeek()]);

        $this->patchJson('/api/state', ['data' => ['dues' => [
            [
                'id' => 'd1725', 'what' => 'Servis — Kotel Vaillant', 'kind' => 'lhůta',
                'date' => '9. 9. 2026', 'amount' => 1600, 'who' => 'Adrian',
                'note' => 'Zapsáno z inventáře bytu.',
            ],
        ]]])->assertOk();

        $novy = HouseDue::where('what', 'Servis — Kotel Vaillant')->firstOrFail();

        $this->assertSame('2026-09-09', $novy->due_on->toDateString());
        $this->assertSame(1600, $novy->amount);
        $this->assertSame($this->adri->id, $novy->user_id);
    }

    /** Doklad uložený k věci se zapíše k té věci. */
    public function test_doklad_k_veci_se_zapise(): void
    {
        $vec = $this->vec(['name' => 'Kotel Vaillant', 'has_doc' => false]);

        $this->patchJson('/api/state', ['data' => ['inv' => [[
            'id' => $vec->uuid, 'name' => 'Kotel Vaillant', 'doc' => true,
        ]]]])->assertOk();

        $this->assertTrue($vec->refresh()->has_doc);
    }

    /** Domácnost jiného páru se do odpovědi nedostane. */
    public function test_domacnost_jineho_paru_se_neposila(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $this->prace(['name' => 'Naše']);
        $this->prace(['name' => 'Cizí', 'gallery_space_id' => $ciziProstor->id]);

        $prace = collect($this->getJson('/api/data/domacnost')->assertOk()->json('data.HOUSE_CHORES'))->pluck('name');

        $this->assertSame(['Naše'], $prace->all());
    }

    // ——— pomůcky ———

    private function prace(array $navic = []): HouseChore
    {
        return HouseChore::create(array_merge([
            'gallery_space_id' => $this->prostor->id,
            'name' => 'Práce',
            'every' => 'týdně',
            'assigned_to' => $this->adri->id,
            'rotate' => true,
            'minutes' => 30,
            'icon' => 'ph-broom',
        ], $navic));
    }

    private function zavazek(array $navic = []): HouseDue
    {
        return HouseDue::create(array_merge([
            'gallery_space_id' => $this->prostor->id,
            'what' => 'Závazek',
            'kind' => 'lhůta',
            'due_on' => now()->addWeek(),
            'amount' => 0,
            'user_id' => $this->adri->id,
        ], $navic));
    }

    private function vec(array $navic = []): HouseInventoryItem
    {
        return HouseInventoryItem::create(array_merge([
            'gallery_space_id' => $this->prostor->id,
            'name' => 'Věc',
            'room' => 'kuchyň',
            'price' => 1000,
        ], $navic));
    }
}
