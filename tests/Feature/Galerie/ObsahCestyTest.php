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
 * Cesty a místa ve tvaru, ve kterém je kreslí prototyp.
 *
 * Aplikace má celý cestovní modul — cesty, dny, program, útraty, balení,
 * doklady, deník i místa s návštěvami. Prototyp z toho neukazoval nic: tři
 * napsané cesty a osm míst, o kterých dvojice nikdy nerozhodla.
 */
class ObsahCestyTest extends TestCase
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

    /**
     * Bez cest a míst chodí jen prázdná běžící cesta.
     *
     * Seznamy si klient nechá ukázkové, ale „jsme na cestě" nesmí tvrdit nikdy,
     * když se nejede.
     */
    public function test_bez_cest_chodi_jen_prazdna_bezici_cesta(): void
    {
        $odpoved = $this->getJson('/api/data/cesty')->assertOk();

        $this->assertPrazdne($odpoved->json('data'));
        // Mapy musí přijít jako `{}` a úplné, jinak by u klienta zůstaly ukázkové cesty.
        $this->assertSame('{}', json_encode(json_decode($odpoved->getContent())->data->TRIPS));
        $this->assertSame('{}', json_encode(json_decode($odpoved->getContent())->data->NOWTRIP));
        $this->assertContains('NOWTRIP', $odpoved->json('uplne'));
        $this->assertContains('TRIPS', $odpoved->json('uplne'));
        $this->assertContains('PLACES', $odpoved->json('uplne'));
    }

    /**
     * Značka cesty vychází z data, ne z toho, co kdo napsal.
     *
     * Prototyp podle ní řadí i obarvuje: „plánujeme" nahoře, „byli jsme" do
     * archivu. Uložený řetězec by po návratu domů lhal.
     */
    public function test_znacka_cesty_vychazi_z_data(): void
    {
        $this->cesta(['name' => 'Portugalsko', 'start_date' => now()->addMonth(), 'end_date' => now()->addMonth()->addWeek()]);
        $this->cesta(['name' => 'Brač', 'start_date' => now()->subDay(), 'end_date' => now()->addDays(5)]);
        $this->cesta(['name' => 'Vídeň', 'start_date' => now()->subYear(), 'end_date' => now()->subYear()->addDay()]);

        $cesty = collect($this->getJson('/api/data/cesty')->assertOk()->json('data.TRIPS'))->keyBy('title');

        $this->assertSame('plánujeme', $cesty['Portugalsko']['tag']);
        $this->assertSame('jsme tam', $cesty['Brač']['tag']);
        $this->assertSame('byli jsme', $cesty['Vídeň']['tag']);
        $this->assertTrue($cesty['Vídeň']['past']);
    }

    /**
     * Nová cesta z počítače i telefonu (`POST /v1/trips`) je hned v Cestách.
     *
     * Dialog na počítači ji dřív ukládal jen do sdíleného stavu — server ji
     * neznal a zapsat k ní útratu nebo program nešlo. Celkový rozpočet z
     * dialogu se ukáže, dokud cesta nemá limity po kategoriích.
     */
    public function test_nova_cesta_je_hned_v_cestach_i_s_rozpoctem(): void
    {
        $this->postJson('/api/v1/trips', [
            'name' => 'Pálava', 'description' => 'Mikulov', 'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDays(2)->toDateString(), 'budget' => 6000, 'currency' => 'CZK', 'status' => 'planned',
        ])->assertCreated();

        $cesta = collect($this->getJson('/api/data/cesty')->assertOk()->json('data.TRIPS'))->firstWhere('title', 'Pálava');

        $this->assertNotNull($cesta);
        $this->assertSame('plánujeme', $cesta['tag']);
        $this->assertContains(['Rozpočet', '6 000 Kč'], $cesta['stats']);

        // Telefon dostane id a rozsah — z nich vybírá den pro bod programu.
        $telefon = collect($this->getJson('/api/data/cesty')->json('data.MOBIL.TRIPS'))->firstWhere('title', 'Pálava');
        $this->assertSame($cesta['n'], $telefon['n']);
        $this->assertSame(now()->addWeek()->toDateString(), $telefon['od']);

        $this->postJson('/api/cesty/'.$telefon['n'].'/program', ['den' => 1, 'nazev' => 'Svatý Kopeček', 'cas' => '10:30'])->assertCreated();
        // Bod programu založí všechny dny cesty (TripDayShiftService), ne jen ten jeden.
        $den = collect($this->getJson('/api/data/cesty')->json('data.MOBIL.TRIPS'))->firstWhere('title', 'Pálava')['days'][1];
        $this->assertSame('Den 2', $den[0]);
        [$nazev, $cas, $hotovo, $id] = $den[2][0];
        $this->assertSame(['Svatý Kopeček', '10:30', 0], [$nazev, $cas, $hotovo]);

        // „Splněno" z telefonu se uloží k bodu — dřív jen v jednom telefonu.
        $this->postJson('/api/cesty/program/'.$id.'/hotovo', ['hotovo' => true])->assertOk();
        $den = collect($this->getJson('/api/data/cesty')->json('data.MOBIL.TRIPS'))->firstWhere('title', 'Pálava')['days'][1];
        $this->assertSame(1, $den[2][0][2]);
    }

    /** Rozsah se píše česky a měsíc se neopakuje, když je stejný. */
    public function test_rozsah_dat_se_pise_cesky(): void
    {
        $this->cesta(['name' => 'Doma', 'start_date' => '2027-04-12', 'end_date' => '2027-04-18']);
        $this->cesta(['name' => 'Přes měsíc', 'start_date' => '2027-04-30', 'end_date' => '2027-05-03']);

        $cesty = collect($this->getJson('/api/data/cesty')->assertOk()->json('data.TRIPS'))->keyBy('title');

        $this->assertSame('12. – 18. dubna 2027', $cesty['Doma']['when']);
        $this->assertSame('30. dubna – 3. května 2027', $cesty['Přes měsíc']['when']);
    }

    /**
     * Program dne přichází z databáze, ne z napsaného itineráře.
     *
     * Prototyp z něj kreslí hodinu, ikonu podle druhu a stav; bez nich je řádek
     * jen text.
     */
    public function test_program_dne_nese_cas_ikonu_i_stav(): void
    {
        $cesta = $this->cesta(['name' => 'Portugalsko', 'start_date' => '2027-04-12', 'end_date' => '2027-04-13']);
        $den = $this->den($cesta, '2027-04-12', 'přílet · Alfama');

        $this->cinnost($den, ['title' => 'Odlet BRQ – LIS', 'type' => 'flight', 'starts_at' => '06:40', 'status' => 'booked']);
        $this->cinnost($den, ['title' => 'Check-in', 'type' => 'lodging', 'starts_at' => '12:30', 'status' => 'planned']);

        $dny = $this->getJson('/api/data/cesty')->assertOk()->json('data.TRIPS.portugalsko.days');

        $this->assertSame('Den 1', $dny[0][0]);
        $this->assertSame('Pondělí 12. 4.', $dny[0][1]);
        $this->assertSame('přílet · Alfama', $dny[0][2]);
        $this->assertSame(['6:40', 'ph-airplane-tilt', 'Odlet BRQ – LIS'], array_slice($dny[0][3][0], 0, 3));
        $this->assertSame('zaplaceno', $dny[0][3][0][4]);
        $this->assertNull($dny[0][3][1][4]);
    }

    /**
     * Rozpočet cesty ukazuje utracené proti plánu.
     *
     * Kategorie, ve které se ještě nic neutratilo, dostane příznak „jen plán" —
     * jinak by prázdný pruh vypadal jako vyčerpaný.
     */
    public function test_rozpocet_cesty_srovnava_utracene_s_planem(): void
    {
        $cesta = $this->cesta(['name' => 'Portugalsko', 'start_date' => '2027-04-12', 'end_date' => '2027-04-18']);

        DB::table('trip_budget_limits')->insert([
            ['trip_id' => $cesta, 'category' => 'lodging', 'amount' => 9800, 'currency' => 'CZK', 'warn_percent' => 80, 'created_at' => now(), 'updated_at' => now()],
            ['trip_id' => $cesta, 'category' => 'food', 'amount' => 9000, 'currency' => 'CZK', 'warn_percent' => 80, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('trip_expenses')->insert([
            'trip_id' => $cesta, 'created_by' => $this->adri->id, 'title' => 'Apartmán',
            'category' => 'lodging', 'amount' => 6800, 'currency' => 'CZK', 'state' => 'actual',
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rozpocet = collect($this->getJson('/api/data/cesty')->assertOk()->json('data.TRIPS.portugalsko.budget'))
            ->keyBy(0);

        $this->assertSame('6 800 z 9 800 Kč', $rozpocet['Nocleh'][1]);
        $this->assertSame(69, $rozpocet['Nocleh'][2]);
        $this->assertSame(0, $rozpocet['Nocleh'][3]);
        // Nic utraceného: jen plán, a pruh se nekreslí jako naplněný.
        $this->assertSame('plán 9 000 Kč', $rozpocet['Jídlo'][1]);
        $this->assertSame(2, $rozpocet['Jídlo'][3]);
    }

    /** Balení říká, kdo to má na starost a jestli už je sbaleno. */
    public function test_baleni_zna_kdo_a_jestli_je_sbaleno(): void
    {
        $cesta = $this->cesta(['name' => 'Portugalsko', 'start_date' => '2027-04-12', 'end_date' => '2027-04-18']);

        DB::table('trip_packing_items')->insert([
            [
                'uuid' => (string) Str::uuid(), 'trip_id' => $cesta, 'created_by' => $this->adri->id,
                'assigned_to' => $this->maki->id, 'title' => 'Krém na sluníčko', 'category' => 'other',
                'quantity' => 1, 'is_essential' => false, 'is_packed' => false, 'sort_order' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'uuid' => (string) Str::uuid(), 'trip_id' => $cesta, 'created_by' => $this->adri->id,
                'assigned_to' => null, 'title' => 'Pasy a doklady', 'category' => 'documents',
                'quantity' => 2, 'is_essential' => true, 'is_packed' => true, 'sort_order' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $pack = $this->getJson('/api/data/cesty')->assertOk()->json('data.TRIPS.portugalsko.pack');

        $this->assertSame(['Krém na sluníčko', 'Makinka', 0], array_slice($pack[0], 0, 3));
        $this->assertSame(['Pasy a doklady', 'oba', 1], array_slice($pack[1], 0, 3));

        // Id položky: zaškrtnutí jde na server a druhý ho uvidí.
        $id = $pack[0][3];
        $this->assertSame($id, (int) DB::table('trip_packing_items')->where('title', 'Krém na sluníčko')->value('id'));
        $this->patchJson('/api/v1/trips/'.$cesta.'/packing-items/'.$id, ['is_packed' => true])->assertOk();
        $this->assertSame(1, $this->getJson('/api/data/cesty')->json('data.TRIPS.portugalsko.pack.0.2'));
    }

    /** Doklad, který chybí, se pozná — to je celý smysl té záložky. */
    public function test_doklady_nesou_stav(): void
    {
        $cesta = $this->cesta(['name' => 'Portugalsko', 'start_date' => '2027-04-12', 'end_date' => '2027-04-18']);

        DB::table('trip_document_checks')->insert([
            [
                'trip_id' => $cesta, 'created_by' => $this->adri->id, 'type' => 'insurance',
                'title' => 'Cestovní pojištění', 'expires_on' => '2027-12-31', 'status' => 'ready',
                'reference' => 'PDF', 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'trip_id' => $cesta, 'created_by' => $this->adri->id, 'type' => 'lodging',
                'title' => 'Pension Sintra', 'expires_on' => null, 'status' => 'missing', 'reference' => null,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $doklady = collect($this->getJson('/api/data/cesty')->assertOk()->json('data.TRIPS.portugalsko.docs'))
            ->keyBy(0);

        $this->assertSame('zaplaceno', $doklady['Cestovní pojištění'][2]);
        $this->assertSame('ph-shield-check', $doklady['Cestovní pojištění'][3]);
        $this->assertSame('zařadit', $doklady['Pension Sintra'][2]);
    }

    /**
     * Cesta, která právě běží, má vlastní obrazovku.
     *
     * Počasí a západ slunce se neposílají: aplikace je odnikud nebere a
     * vymyslet je by znamenalo tvrdit dvojici na cestě něco o obloze nad nimi.
     */
    public function test_bezici_cesta_ma_dnesni_den_a_utraty(): void
    {
        // Dny cesty počítá aplikace podle hodin dvojice, ne serveru.
        $cesta = $this->cesta([
            'name' => 'Brač a Šolta',
            'start_date' => $this->dnes()->subDays(4)->toDateString(),
            'end_date' => $this->dnes()->addDays(3)->toDateString(),
        ]);

        $den = $this->den($cesta, $this->dnes()->toDateString(), 'Šolta');
        $this->cinnost($den, ['title' => 'Loď na Šoltu', 'type' => 'boat', 'starts_at' => '10:30', 'status' => 'done']);

        DB::table('trip_expenses')->insert([
            [
                'trip_id' => $cesta, 'created_by' => $this->adri->id, 'title' => 'Trajekt Bol – Šolta',
                'category' => 'transport', 'amount' => 620, 'currency' => 'CZK', 'state' => 'actual',
                'occurred_at' => $this->dnes()->setTime(10, 24), 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'trip_id' => $cesta, 'created_by' => $this->adri->id, 'title' => 'Včerejší večeře',
                'category' => 'food', 'amount' => 480, 'currency' => 'CZK', 'state' => 'actual',
                'occurred_at' => $this->dnes()->subDay(), 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $ted = $this->getJson('/api/data/cesty')->assertOk()->json('data.NOWTRIP');

        $this->assertSame('Brač a Šolta', $ted['title']);
        $this->assertSame(5, $ted['day']);
        $this->assertSame(8, $ted['days']);
        $this->assertSame(1100, $ted['spent']);
        // Dnešní útrata je jen ta dnešní, ne celý fond.
        $this->assertSame(620, $ted['todaySpent']);
        $this->assertSame('hotovo', $ted['plan'][0][4]);
        $this->assertSame('Trajekt Bol – Šolta', $ted['spendRows'][0][0]);
        $this->assertArrayNotHasKey('weather', $ted);
    }

    /** Když nikam nejedeme, běžící cesta je prázdná — ukázkový Brač se smaže. */
    public function test_bez_bezici_cesty_je_nowtrip_prazdny(): void
    {
        $this->cesta(['name' => 'Portugalsko', 'start_date' => now()->addMonth(), 'end_date' => now()->addMonth()->addWeek()]);

        $odpoved = $this->getJson('/api/data/cesty')->assertOk();

        $this->assertArrayHasKey('TRIPS', $odpoved->json('data'));
        $this->assertSame('{}', json_encode(json_decode($odpoved->getContent())->data->NOWTRIP));
    }

    /**
     * Rejstřík název → klíč se posílá taky.
     *
     * Prototyp si ho staví při načtení z ukázkových dat, takže by po výměně
     * ukazoval na klíče, které už neexistují.
     */
    public function test_rejstrik_ukazuje_na_skutecne_klice(): void
    {
        $this->cesta(['name' => 'Portugalsko na podzim', 'start_date' => '2027-09-17', 'end_date' => '2027-09-27']);

        $data = $this->getJson('/api/data/cesty')->assertOk()->json('data');

        $klic = array_key_first($data['TRIPS']);

        $this->assertSame($klic, $data['TRIP_BY_TITLE']['Portugalsko na podzim']);
    }

    /** Cesty a místa přicházejí celé — ukázkové vedle skutečných nemají co dělat. */
    public function test_cesty_a_mista_jsou_uplne_kolekce(): void
    {
        $this->cesta(['name' => 'Portugalsko', 'start_date' => '2027-04-12', 'end_date' => '2027-04-18']);

        $uplne = $this->getJson('/api/data/cesty')->assertOk()->json('uplne');

        $this->assertContains('TRIPS', $uplne);
        $this->assertContains('TRIP_BY_TITLE', $uplne);
    }

    /** Značka místa vychází ze stavu i z toho, jestli tam dvojice byla. */
    public function test_misto_nese_znacku_fakta_i_poznamky(): void
    {
        $misto = $this->misto([
            'name' => 'Kavárna Alma',
            'city' => 'Brno',
            'district' => 'Veveří',
            'address' => 'Veveří 22, Brno',
            'description' => 'Doporučila Klára v srpnu.',
            'price_level' => 2,
            'lifecycle_status' => 'idea',
        ]);

        DB::table('place_notes')->insert([
            'place_id' => $misto, 'gallery_space_id' => $this->prostor->id,
            'user_id' => $this->adri->id, 'visibility' => 'shared', 'scope_key' => 'obecne',
            'content' => 'Filtr z Etiopie a vlastní pražírna.',
            'created_by' => $this->adri->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $misto = $this->getJson('/api/data/cesty')->assertOk()->json('data.PLACES.kavarnaalma');

        $this->assertSame('Kavárna Alma', $misto['title']);
        $this->assertSame('chceme', $misto['tag']);
        $this->assertSame('Brno, Veveří', $misto['city']);
        $this->assertSame(['Adresa', 'Veveří 22, Brno'], $misto['facts'][0]);
        $this->assertSame('Filtr z Etiopie a vlastní pražírna.', $misto['notes'][0][2]);
    }

    /** Navštívené místo se pozná ze záznamu návštěvy, ne z popisku. */
    public function test_navstivene_misto_ma_navstevu(): void
    {
        $misto = $this->misto(['name' => 'Pustevny', 'lifecycle_status' => 'idea']);

        DB::table('place_plans')->insert([
            'uuid' => (string) Str::uuid(), 'place_id' => $misto,
            'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
            'state' => 'visited', 'visited_on' => '2026-08-16',
            'notes' => 'Vyšli jsme v pět a na hřebeni byli před svítáním.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $misto = $this->getJson('/api/data/cesty')->assertOk()->json('data.PLACES.pustevny');

        $this->assertSame('byli jsme', $misto['tag']);
        $this->assertSame('16. 8. 2026', $misto['visits'][0][0]);
        $this->assertSame('Vyšli jsme v pět a na hřebeni byli před svítáním.', $misto['visits'][0][2]);
    }

    /** Osobní poznámka k místu patří jednomu člověku, ne oběma. */
    public function test_osobni_poznamka_se_neposila(): void
    {
        $misto = $this->misto(['name' => 'Alma']);

        DB::table('place_notes')->insert([
            'place_id' => $misto, 'gallery_space_id' => $this->prostor->id,
            'user_id' => $this->adri->id, 'visibility' => 'personal', 'scope_key' => 'obecne',
            'content' => 'Jen pro mě.', 'created_by' => $this->adri->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([], $this->getJson('/api/data/cesty')->assertOk()->json('data.PLACES.alma.notes'));
    }

    /** Cesty jiného páru se do odpovědi nedostanou. */
    public function test_cesty_jineho_paru_se_neposilaji(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $this->cesta(['name' => 'Naše', 'start_date' => '2027-04-12', 'end_date' => '2027-04-18']);
        $this->cesta([
            'name' => 'Cizí', 'start_date' => '2027-04-12', 'end_date' => '2027-04-18',
            'gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id,
        ]);

        $cesty = collect($this->getJson('/api/data/cesty')->assertOk()->json('data.TRIPS'))->pluck('title');

        $this->assertSame(['Naše'], $cesty->values()->all());
    }

    /**
     * Do travel inboxu jde přidat i z galerie; druh je česky.
     *
     * Šel jen vyřizovat — a prázdný stav sliboval položky „z e-mailu".
     */
    public function test_do_travel_inboxu_jde_pridat(): void
    {
        $odpoved = $this->postJson('/api/cesty/inbox', [
            'nazev' => 'Apartmán Lisabon, 3 noci', 'odkaz' => 'https://example.com/rezervace/123', 'druh' => 'rezervace',
        ])->assertStatus(201)->assertJsonPath('ok', true);

        $this->assertSame('reservation', DB::table('travel_inbox_items')->value('kind'));
        $radek = collect($odpoved->json('data.AL.travelInbox'))->firstWhere(0, 'Apartmán Lisabon, 3 noci');
        $this->assertStringStartsWith('rezervace · ', $radek[1]);
        $this->assertSame('zařadit', $radek[2]);

        $this->postJson('/api/cesty/inbox', ['nazev' => 'Odkaz', 'odkaz' => 'http://nebezpecne.cz'])->assertStatus(422);
        $this->postJson('/api/cesty/inbox', ['nazev' => ''])->assertStatus(422);
    }

    /**
     * Zařazené a archivované se v travel inboxu poznají.
     *
     * Zařazení přes API (`assigned`) se ukazovalo jako „zařadit" a
     * archivované položky zůstávaly v seznamu navždy.
     */
    public function test_travel_inbox_pozna_zarazene_a_archivovane(): void
    {
        $cesta = $this->cesta(['name' => 'Lisabon']);
        foreach ([['Letenky', 'assigned', $cesta], ['Starý odkaz', 'archived', null], ['Kavárna', 'inbox', null]] as [$nazev, $stav, $kam]) {
            DB::table('travel_inbox_items')->insert([
                'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'added_by' => $this->adri->id,
                'title' => $nazev, 'kind' => 'link', 'state' => $stav, 'trip_id' => $kam,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $radky = collect($this->getJson('/api/data/cesty')->assertOk()->json('data.AL.travelInbox'));

        $this->assertSame(['Letenky', 'Kavárna'], $radky->pluck(0)->sort()->reverse()->values()->all());
        $letenky = $radky->firstWhere(0, 'Letenky');
        $this->assertSame('zařazeno', $letenky[2]);
        $this->assertStringContainsString('cesta Lisabon', $letenky[1]);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $letenky[7]);
        $this->assertSame('zařadit', $radky->firstWhere(0, 'Kavárna')[2]);
    }

    /**
     * Jízdenka jde přidat s trasou a smazat.
     *
     * „Přidat jízdenku" zakládalo „Nová jízdenka — doplňte trasu a datum",
     * ale doplnit nebylo kde.
     */
    public function test_jizdenka_jde_pridat_a_smazat(): void
    {
        $odpoved = $this->postJson('/api/cesty/jizdenky', ['nazev' => 'Vlak do Vídně', 'odkud' => 'Brno', 'kam' => 'Vídeň'])
            ->assertStatus(201)->assertJsonPath('ok', true);

        $radek = collect($odpoved->json('data.AL.ticket'))->firstWhere(0, 'Vlak do Vídně');
        $this->assertStringStartsWith('Brno – Vídeň · uloženo ', $radek[1]);
        $uuid = $radek[7];
        $this->assertSame($uuid, DB::table('saved_transport_routes')->value('uuid'));

        $this->postJson('/api/cesty/jizdenky', ['nazev' => 'vlak do vídně'])->assertStatus(422);

        // Stará místní kopie se smazanou jízdenkou ji nevzkřísí.
        $this->deleteJson('/api/cesty/jizdenky/'.$uuid)->assertOk();
        $this->assertSame(0, DB::table('saved_transport_routes')->count());
        $this->patchJson('/api/state', ['data' => ['xRows' => ['ticket' => [
            ['t' => 'Vlak do Vídně', 'm' => 'Brno – Vídeň', 'g' => 'uloženo', 'id' => $uuid],
        ]]]])->assertOk();
        $this->assertSame(0, DB::table('saved_transport_routes')->count());

        $this->deleteJson('/api/cesty/jizdenky/'.$uuid)->assertNotFound();
    }

    // ——— pomůcky ———

    private function cesta(array $navic = []): int
    {
        return DB::table('trips')->insertGetId(array_merge([
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'name' => 'Cesta',
            'description' => '',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeeks(2)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }

    private function den(int $cesta, string $datum, ?string $nazev = null): int
    {
        return DB::table('trip_days')->insertGetId([
            'trip_id' => $cesta,
            'date' => $datum,
            'title' => $nazev,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function cinnost(int $den, array $navic = []): void
    {
        DB::table('trip_activities')->insert(array_merge([
            'trip_day_id' => $den,
            'created_by' => $this->adri->id,
            'type' => 'activity',
            'title' => 'Činnost',
            'status' => 'planned',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }

    private function misto(array $navic = []): int
    {
        return DB::table('places')->insertGetId(array_merge([
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'name' => 'Místo',
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }
}
