<?php

namespace Tests\Feature\Galerie;

use App\Models\ChatMessage;
use App\Models\GallerySpace;
use App\Models\User;
use App\Support\SpaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Hranice převodníků stavu: kdo patří do dvojice, kolik se smí zapsat
 * naráz, co smí starší klient smazat a kterým dnem je „dnes".
 *
 * Host je tu **založený jako první** (nejnižší id). Převodníky braly členy
 * prostoru a řadily je podle id — host pozvaný dřív, než se přidal partner,
 * tak seděl na místě „toho druhého". Trait `DvojiceSHostem` hosta přidává
 * až nakonec, takže by tuhle chybu nikdy neukázal.
 */
class HraniceVeStavuTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = User::factory()->create(['name' => 'Host']);
        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->maki = User::factory()->create(['name' => 'Makinka']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->host->id => ['role' => 'viewer'],
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        $this->assertLessThan($this->maki->id, $this->host->id, 'Host musí mít nižší id než partner, jinak test nic nedokazuje.');
    }

    /** Oprava kapacity „druhého" patří partnerovi, ne hostovi s nižším id. */
    public function test_host_neni_druhy_v_kapacite_tydne(): void
    {
        $this->stav(['capWeek' => [['key' => 'po', 'fixM' => true, 'm' => 3]]])->assertOk();

        $kapacita = DB::table('house_week_capacity')->get();

        $this->assertCount(1, $kapacita);
        $this->assertSame($this->maki->id, (int) $kapacita[0]->user_id);
    }

    /** Druhá verze „dvou pravd" je partnerova, ne hostova. */
    public function test_host_neni_druha_strana_dvou_pravd(): void
    {
        $this->stav(['truths' => [['id' => 't-n1', 'title' => 'Výlet na Pustevny', 'a' => 'Moje verze', 'm' => 'Tvoje verze']]])->assertOk();

        $pravda = DB::table('couple_truths')->sole();

        $this->assertSame($this->adri->id, (int) $pravda->first_user_id);
        $this->assertSame($this->maki->id, (int) $pravda->second_user_id);
    }

    /** Jméno hosta v seznamu z prohlížeče nikoho z dvojice nedělá. */
    public function test_jmeno_hosta_neni_clen_dvojice(): void
    {
        $this->stav([
            'chores' => [['id' => 'c1', 'name' => 'Vysát', 'who' => 'Host', 'mins' => 20]],
            'proms' => [['id' => 'p1', 'who' => 'Host', 'to' => 'Adrian', 'what' => 'Umýt auto']],
            'nudges' => [['id' => 'n1', 'text' => 'Koupit mléko', 'from' => 'Host', 'to' => 'Adrian']],
            'favList' => [['id' => 'f-n1', 'from' => 'Host', 'what' => 'Pohlídal kytky', 'date' => '2026-09-01']],
            'kapsules' => [['id' => 'z1', 'from' => 'Host', 'title' => 'Za rok', 'open' => '2027-09-04', 'body' => 'Ahoj']],
        ])->assertOk();

        $this->assertNull(DB::table('house_chores')->value('assigned_to'));
        $this->assertSame(0, DB::table('couple_promises')->count(), 'Slib hosta se nezakládá.');
        $this->assertSame(0, DB::table('couple_nudges')->count(), 'Žádost hosta se nezakládá.');
        $this->assertNull(DB::table('couple_favours')->value('from_user_id'));
        $this->assertSame($this->adri->id, (int) DB::table('time_capsules')->value('created_by'));
    }

    /**
     * Jeden zápis přidá nejvýš pár připomínek.
     *
     * `rem` je číslo z prohlížeče; bez stropu založilo tolik řádků, kolik kdo
     * napsal — v transakci, která drží zámek stavu celé dvojice.
     */
    public function test_pripominky_maji_strop_na_jeden_zapis(): void
    {
        $this->stav(['nudges' => [['id' => 'n-1', 'text' => 'x', 'from' => 'Adrian', 'to' => 'Makinka', 'rem' => 500]]])->assertOk();

        $this->assertGreaterThan(0, DB::table('couple_nudges')->count(), 'Žádost se měla založit.');
        $this->assertLessThanOrEqual(5, DB::table('couple_nudge_reminders')->count());

        // Běžné klepnutí (o jednu víc) dál přidá právě jednu.
        $uz = DB::table('couple_nudge_reminders')->count();
        $this->stav(['nudges' => [['id' => 'n-1', 'text' => 'x', 'from' => 'Adrian', 'to' => 'Makinka', 'rem' => $uz + 1]]])->assertOk();
        $this->assertSame($uz + 1, DB::table('couple_nudge_reminders')->count());
    }

    /**
     * Prázdný seznam od staršího klienta historii práce nesmaže.
     *
     * Bez `__odebrane` se prázdný seznam nepozná od „nic jsem neodebral"
     * (viz OdebraneVStavu::smiMazat()); vzít zpět jde dál s `__odebrane`.
     */
    public function test_prazdny_zaznam_prace_bez_odebranych_nic_nesmaze(): void
    {
        $this->stav(['choreLog' => [['id' => 'l1725', 'chore' => 'Rostliny', 'who' => 'Makinka', 'mins' => 10, 'when' => 'právě teď']]])->assertOk();
        $this->assertSame(1, DB::table('house_chore_log')->count());

        $this->stav(['choreLog' => []])->assertOk();
        $this->assertSame(1, DB::table('house_chore_log')->count(), 'Prázdný seznam bez __odebrane smazal historii.');

        $this->stav(['choreLog' => [], '__odebrane' => ['choreLog' => ['l1725']]])->assertOk();
        $this->assertSame(0, DB::table('house_chore_log')->count(), 'Výslovně vzaté zpět se smazat má.');
    }

    /** Prázdný seznam lhůt od staršího klienta nic „nevyřídí". */
    public function test_prazdny_seznam_lhut_bez_odebranych_nic_nevyridi(): void
    {
        $this->stav(['dues' => [['id' => 'q1', 'what' => 'STK', 'date' => now()->addMonth()->format('j. n. Y'), 'who' => 'Adrian']]])->assertOk();
        $this->assertSame(1, DB::table('house_dues')->count());

        $this->stav(['dues' => []])->assertOk();
        $this->assertNull(DB::table('house_dues')->value('settled_at'), 'Prázdný seznam bez __odebrane vyřídil lhůtu.');

        $this->stav(['dues' => [], '__odebrane' => ['dues' => ['q1']]])->assertOk();
        $this->assertNotNull(DB::table('house_dues')->value('settled_at'), 'Výslovně odebraná lhůta se vyřídit má.');
    }

    /**
     * „Dnes" je dnešek dvojice, ne UTC.
     *
     * 00:30 v Praze je 22:30 UTC předchozího dne; rozhodnutí „dnes",
     * odložení o týden i kapsle „za rok" se počítaly od včerejška.
     */
    public function test_dnes_je_dnesek_dvojice(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-25 22:30:00', 'UTC'));

        $this->stav([
            'decs' => [['id' => 'd1', 'title' => 'Dovolená v září', 'date' => 'dnes']],
            'kapsules' => [['id' => 'z1', 'from' => 'Adrian', 'title' => 'Za rok', 'body' => 'Ahoj']],
            'inboxSt' => ['inbox:fotky-bez-data' => 'snooze'],
        ])->assertOk();

        $this->assertSame('2026-09-26', substr((string) DB::table('couple_decisions')->value('decided_on'), 0, 10));
        $this->assertSame('2027-09-26', substr((string) DB::table('time_capsules')->value('deliver_at'), 0, 10));
        $this->assertSame('2026-10-03', substr((string) DB::table('inbox_states')->value('snoozed_until'), 0, 10));
    }

    /**
     * Mentální zátěž a pauza se neztrácejí.
     *
     * Klíče byly mezi „serverovými", ale nikam se nezapisovaly: kontroler je
     * ze zápisu vyhodil a ze stavu smazal, takže po uložení zmizely.
     */
    public function test_mentalni_zatez_a_pauza_zustanou_ve_stavu(): void
    {
        $zatez = [['task' => 'Hlídat termíny u zubaře', 'doer' => 'Makinka', 'head' => 'Makinka', 'freq' => 4, 'min' => 20]];
        $plan = [['step' => 'Dvacet minut ticha', 'agreed' => true]];
        $historie = [['when' => '4. září', 'who' => 'Adrian', 'topic' => 'Peníze', 'after' => 'Domluveno.']];

        $this->stav(['mlLoad' => $zatez, 'pausePlan' => $plan, 'pauseLog' => $historie])->assertOk();

        // A ani pozdější zápis mechanismů je ze stavu nesmaže.
        $this->stav(['favList' => [['id' => 'f-n1', 'from' => 'Makinka', 'what' => 'Pojištění', 'date' => '2026-08-28']]])->assertOk();

        $data = (array) $this->actingAs($this->adri)->getJson('/api/state')->assertOk()->json('data');

        $this->assertSame($zatez, $data['mlLoad'] ?? null);
        $this->assertSame($plan, $data['pausePlan'] ?? null);
        $this->assertSame($historie, $data['pauseLog'] ?? null);
    }

    /** Zpráva přes stav má stejný strop jako zpráva z chatu a dlouhý odkaz nahrávky nic neshodí. */
    public function test_zprava_pres_stav_se_vejde(): void
    {
        $this->stav(['chat' => [[
            'id' => 'm-n1', 'who' => 'a', 'text' => str_repeat('Ahoj, ', 3000), 'meta' => '7:11', 'audio' => str_repeat('r', 300),
        ]]])->assertOk();

        $zprava = ChatMessage::withoutGlobalScope(SpaceContext::SCOPE)->sole();

        $this->assertLessThanOrEqual(4000, mb_strlen((string) $zprava->body));
        $this->assertNull($zprava->attachment_ref, 'Useknutý odkaz by ukazoval na nahrávku, která neexistuje.');
    }

    /**
     * Dlouhý identifikátor z prohlížeče napodruhé nesmí shodit zápis.
     *
     * `client_id` se ořezával na 64 znaků, ale hledalo se podle celého —
     * řádek se napodruhé nenašel, založil se znovu a narazil na unikátní
     * klíč. PATCH spadl a prohlížeč ho zkoušel znovu donekonečna.
     */
    public function test_dlouhy_identifikator_napodruhe_neshodi_zapis(): void
    {
        $id = 'x-'.str_repeat('a', 120);
        $patch = [
            'dues' => [['id' => $id.'1', 'what' => 'STK', 'date' => now()->addMonth()->format('j. n. Y'), 'who' => 'Adrian']],
            'proms' => [['id' => $id.'2', 'who' => 'Adrian', 'to' => 'Makinka', 'what' => 'Umýt auto']],
            'inv' => [['id' => $id.'3', 'name' => 'Pračka']],
            'decs' => [['id' => $id.'4', 'title' => 'Dovolená']],
        ];

        $this->stav($patch)->assertOk();
        $this->stav($patch)->assertOk();

        $this->assertSame(1, DB::table('house_dues')->count());
        $this->assertNull(DB::table('house_dues')->value('settled_at'), 'Lhůta, která v seznamu je, se nevyřizuje.');
        $this->assertSame(1, DB::table('couple_promises')->count());
        $this->assertSame('open', DB::table('couple_promises')->value('state'));
        $this->assertSame(1, DB::table('house_inventory')->count());
        $this->assertSame(1, DB::table('couple_decisions')->count());
    }

    private function stav(array $patch)
    {
        return $this->actingAs($this->adri)->patchJson('/api/state', ['data' => $patch]);
    }
}
