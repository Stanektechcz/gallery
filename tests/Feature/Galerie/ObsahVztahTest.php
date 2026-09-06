<?php

namespace Tests\Feature\Galerie;

use App\Models\CoupleCoolingPurchase;
use App\Models\CoupleDecision;
use App\Models\CoupleDecisionRevision;
use App\Models\CoupleDisagreementPoint;
use App\Models\CoupleVeto;
use App\Models\CoupleVetoProposal;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mechanismy vztahu ve tvaru, ve kterém je kreslí prototyp — a zpátky.
 *
 * Paměť rozhodnutí, rozvaha před nákupem, protokol nesouhlasu a veto banka jsou
 * jediné čtyři, které se v prototypu dají měnit, a proto jediné, které dostaly
 * tabulku. Test je v obou směrech: tabulka bez zápisu je horší než žádná.
 */
class ObsahVztahTest extends TestCase
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
     * Co rozhodl čas: tři vzorce, každý ze svých dat.
     *
     * Obrazovka o sobě říká, že hledá propadlou rozvahu, uplynulou lhůtu a věc,
     * o které se mluvilo a nikdy se nezavřela. Dosud to byl seznam napsaný
     * v souboru s ukázkovými daty.
     */
    public function test_rozhodl_cas_najde_tri_vzorce(): void
    {
        CoupleCoolingPurchase::create([
            'gallery_space_id' => $this->prostor->id,
            'what' => 'Gauč z výprodeje',
            'price' => 34000,
            'requested_by' => $this->maki->id,
            'opened_at' => now()->subDays(5),
            'cools_until' => now()->subDays(2),
        ]);

        $this->ukol('Objednat servis kotle', ['due_at' => now()->subDays(40)]);
        $this->ukol('Vyfotit a prodat kolo', ['created_at' => now()->subDays(200)]);
        // Čerstvá věc bez termínu ještě nikdo neodkládá.
        $this->ukol('Zalít kytky', ['created_at' => now()->subDays(3)]);

        $rows = $this->getJson('/api/data/vztah')->assertOk()->json('data.AUTO_DEC');

        $this->assertCount(3, $rows);

        // Nejdřív to, co stálo nejvíc.
        $this->assertSame('Gauč z výprodeje', $rows[0]['what']);
        $this->assertSame('vyprodáno', $rows[0]['kind']);
        $this->assertSame(34000, $rows[0]['cost']);

        $druhy = collect($rows)->pluck('kind')->all();
        $this->assertContains('lhůta', $druhy);
        $this->assertContains('mlčení', $druhy);
    }

    /**
     * Rozvaha, ke které se někdo vyjádřil, mezi nerozhodnutí nepatří.
     *
     * Nerozhodnout je rozhodnutí bez podpisu — jenže tady podpis je, jen se
     * nestihl zavřít.
     */
    public function test_rozvaha_s_nazorem_neni_nerozhodnuti(): void
    {
        CoupleCoolingPurchase::create([
            'gallery_space_id' => $this->prostor->id,
            'what' => 'Sluchátka',
            'price' => 4900,
            'requested_by' => $this->maki->id,
            'opened_at' => now()->subDays(5),
            'cools_until' => now()->subDays(2),
            'opinion' => 'Radši ne, počkejme na slevu.',
            'opinion_by' => $this->adri->id,
        ]);

        $data = $this->getJson('/api/data/vztah')->assertOk()->json('data');

        $this->assertArrayNotHasKey('AUTO_DEC', $data);
    }

    /** Bez rozhodnutí se nic neposílá — klient si nechá ukázková data. */
    public function test_bez_rozhodnuti_se_skupina_neposila(): void
    {
        $this->assertSame([], $this->getJson('/api/data/vztah')->assertOk()->json('data'));
    }

    /**
     * Rozhodnutí nese hlavně proč — a co jsme zavrhli.
     *
     * Za rok se nikdo neptá, co jste rozhodli, ale proč a co tehdy bylo na stole.
     */
    public function test_rozhodnuti_nese_duvody_i_zavrzene(): void
    {
        CoupleDecision::create([
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Zůstat v nájmu do konce 2027',
            'decided_on' => '2026-01-14',
            'together' => true,
            'status' => 'platí',
            'why' => ['Hypotéka by nám sebrala rezervu na cesty.'],
            'rejected' => ['Koupit 2+kk v Porubě'],
            'review_note' => 'v lednu 2027',
        ]);

        $r = $this->getJson('/api/data/vztah')->assertOk()->json('data.DEC_LIST.0');

        $this->assertSame('Zůstat v nájmu do konce 2027', $r['title']);
        $this->assertSame('14. 1. 2026', $r['date']);
        $this->assertSame('platí', $r['status']);
        $this->assertSame(['Hypotéka by nám sebrala rezervu na cesty.'], $r['why']);
        $this->assertSame(['Koupit 2+kk v Porubě'], $r['rejected']);
        $this->assertSame('v lednu 2027', $r['review']);
    }

    /**
     * Arbitráž se odvozuje z rozhodnutí, ne z druhého seznamu.
     *
     * Dva seznamy téhož by se dřív nebo později rozešly.
     */
    public function test_arbitraz_se_odvozuje_z_rozhodnuti(): void
    {
        CoupleDecision::create([
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Jaké dlaždice do koupelny',
            'decided_on' => '2026-08-20',
            'together' => false,
            'decided_by' => $this->maki->id,
            'arbiter_user_id' => $this->maki->id,
            'arbiter_method' => 'poslední slovo',
        ]);

        CoupleDecision::create([
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Bez arbitra',
            'decided_on' => '2026-08-21',
            'together' => true,
        ]);

        $arb = $this->getJson('/api/data/vztah')->assertOk()->json('data.ARB');

        $this->assertCount(1, $arb);
        $this->assertSame('Jaké dlaždice do koupelny', $arb[0]['q']);
        $this->assertSame('Makinka', $arb[0]['w']);
        $this->assertSame('2026-08-20', $arb[0]['date']);
        $this->assertSame('poslední slovo', $arb[0]['how']);
    }

    /** Záznam verzí drží znění, které tehdy platilo. */
    public function test_zaznam_verzi_drzi_puvodni_zneni(): void
    {
        $r = CoupleDecision::create([
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Jen obklady a baterie, 140 000',
            'decided_on' => '2026-02-20',
            'together' => true,
            'status' => 'změněno',
        ]);

        CoupleDecisionRevision::create([
            'couple_decision_id' => $r->id,
            'changed_by' => $this->adri->id,
            'wording' => 'Celá koupelna i s podlahou, 380 000',
            'valid_from' => '2026-01-12',
        ]);

        $verze = $this->getJson('/api/data/vztah')->assertOk()->json('data.VERSIONS');

        $this->assertSame('Jen obklady a baterie, 140 000', $verze[0]['dec']);
        $this->assertSame('Celá koupelna i s podlahou, 380 000', $verze[0]['steps'][0]['v']);
        $this->assertSame('Adrian', $verze[0]['steps'][0]['by']);
    }

    /**
     * Zbývající hodiny rozvahy se počítají teď.
     *
     * Uložené číslo by po zavření prohlížeče zamrzlo a rozvaha by nikdy
     * neskončila.
     */
    public function test_rozvaha_pocita_zbyvajici_hodiny(): void
    {
        CoupleCoolingPurchase::create([
            'gallery_space_id' => $this->prostor->id,
            'what' => 'Sluchátka s potlačením hluku',
            'price' => 4900,
            'requested_by' => $this->maki->id,
            'opened_at' => now()->subHours(41),
            'cools_until' => now()->addHours(31),
        ]);

        $n = $this->getJson('/api/data/vztah')->assertOk()->json('data.DEC_COOL.0');

        $this->assertSame('Sluchátka s potlačením hluku', $n['what']);
        $this->assertSame('Makinka', $n['who']);
        $this->assertSame(31, $n['left']);
    }

    /** Prošlá rozvaha má nulu, ne záporné číslo. */
    public function test_prosla_rozvaha_ma_nulu(): void
    {
        CoupleCoolingPurchase::create([
            'gallery_space_id' => $this->prostor->id,
            'what' => 'Robotický vysavač',
            'price' => 8400,
            'opened_at' => now()->subDays(9),
            'cools_until' => now()->subDays(6),
        ]);

        $this->assertSame(0, $this->getJson('/api/data/vztah')->assertOk()->json('data.DEC_COOL.0.left'));
    }

    /**
     * Protokol nesouhlasu vypadá jinak pro každého z dvojice.
     *
     * „Moje podmínky" a „jeho podmínky" jsou tytéž řádky obrácené — proto se
     * dělí podle přihlášeného člověka, ne podle uloženého sloupce.
     */
    public function test_protokol_se_deli_podle_toho_kdo_se_diva(): void
    {
        CoupleDisagreementPoint::create([
            'gallery_space_id' => $this->prostor->id,
            'author_user_id' => $this->adri->id,
            'text' => 'Nesmí to být dražší než 34 000',
            'tag' => 'cena',
            'kind' => 'podmínka',
        ]);

        CoupleDisagreementPoint::create([
            'gallery_space_id' => $this->prostor->id,
            'author_user_id' => $this->maki->id,
            'text' => 'Chci hory, u vody se nudím',
            'tag' => 'místo',
            'kind' => 'podmínka',
        ]);

        $data = $this->getJson('/api/data/vztah')->assertOk()->json('data');

        $this->assertSame('Nesmí to být dražší než 34 000', $data['SPOR_MINE'][0]['text']);
        $this->assertSame('Chci hory, u vody se nudím', $data['SPOR_THEIRS'][0]['text']);

        // A z druhé strany opačně.
        Sanctum::actingAs($this->maki);
        $data = $this->getJson('/api/data/vztah')->assertOk()->json('data');

        $this->assertSame('Chci hory, u vody se nudím', $data['SPOR_MINE'][0]['text']);
        $this->assertSame('Nesmí to být dražší než 34 000', $data['SPOR_THEIRS'][0]['text']);
    }

    /** Veto nese datum, ne popisek — jinak se nedá spočítat, kolik jich zbývá. */
    public function test_veto_nese_datum_i_duvod(): void
    {
        CoupleVeto::create([
            'gallery_space_id' => $this->prostor->id,
            'user_id' => $this->maki->id,
            'text' => 'Pes',
            'used_on' => '2026-02-12',
            'reason' => 'Ve všední dny bych ho nezvládla sama.',
        ]);

        $v = $this->getJson('/api/data/vztah')->assertOk()->json('data.VETO_USED.0');

        $this->assertSame('Makinka', $v['who']);
        $this->assertSame('12. února 2026', $v['date']);
        $this->assertSame('Ve všední dny bych ho nezvládla sama.', $v['reason']);
    }

    // ——— zpátky ze stavu do databáze ———

    /** Nové rozhodnutí z obrazovky končí v databázi, ne ve stavu. */
    public function test_nove_rozhodnuti_se_zapise_a_ze_stavu_zmizi(): void
    {
        $odpoved = $this->patchJson('/api/state', ['data' => ['decs' => [[
            'id' => 'd1725',
            'title' => 'Nekupovat druhé auto',
            'date' => 'dnes',
            'by' => 'Adrian a Makinka',
            'status' => 'platí',
            'why' => ['MHD stačí a parkování stojí víc než jízdenky.'],
            'rejected' => ['Ojetá Fabia za 180 000'],
            'review' => 'za rok',
        ]]]])->assertOk();

        $r = CoupleDecision::where('gallery_space_id', $this->prostor->id)->firstOrFail();

        $this->assertSame('Nekupovat druhé auto', $r->title);
        $this->assertTrue($r->together);
        $this->assertSame(['MHD stačí a parkování stojí víc než jízdenky.'], $r->why);
        $this->assertSame('za rok', $r->review_note);
        $this->assertArrayNotHasKey('decs', (array) $odpoved->json('data'));
    }

    /**
     * Změna rozhodnutí zakládá revizi.
     *
     * Původní znění se nepřepisuje — právě proto, aby za rok bylo vidět, co jste
     * si tehdy mysleli.
     */
    public function test_zmena_rozhodnuti_zaklada_revizi(): void
    {
        $r = CoupleDecision::create([
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Celá koupelna i s podlahou',
            'decided_on' => '2026-01-12',
            'together' => true,
            'status' => 'platí',
        ]);

        $this->patchJson('/api/state', ['data' => ['decs' => [[
            'id' => $r->uuid, 'title' => 'Celá koupelna i s podlahou', 'status' => 'změněno',
        ]]]])->assertOk();

        $r->refresh();

        $this->assertSame('změněno', $r->status);
        $this->assertNotNull($r->changed_at);
        $this->assertSame('Celá koupelna i s podlahou', $r->revize->first()->wording);
    }

    /** Táž změna podruhé nezaloží druhou revizi. */
    public function test_revize_se_nezalozi_dvakrat(): void
    {
        $r = CoupleDecision::create([
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Rozhodnutí',
            'decided_on' => '2026-01-12',
            'together' => true,
            'status' => 'platí',
        ]);

        $patch = ['data' => ['decs' => [['id' => $r->uuid, 'title' => 'Rozhodnutí', 'status' => 'změněno']]]];

        $this->patchJson('/api/state', $patch)->assertOk();
        $this->patchJson('/api/state', $patch)->assertOk();

        $this->assertSame(1, $r->revize()->count());
    }

    /** Rozvaha, která zmizí ze seznamu, se zavře — nemaže se. */
    public function test_zavrena_rozvaha_se_neztrati(): void
    {
        $zustane = CoupleCoolingPurchase::create([
            'gallery_space_id' => $this->prostor->id,
            'what' => 'Sluchátka', 'price' => 4900,
            'opened_at' => now(), 'cools_until' => now()->addHours(31),
        ]);

        $zmizi = CoupleCoolingPurchase::create([
            'gallery_space_id' => $this->prostor->id,
            'what' => 'Vysavač', 'price' => 8400,
            'opened_at' => now(), 'cools_until' => now()->addHours(10),
        ]);

        $this->patchJson('/api/state', ['data' => ['cools' => [[
            'id' => $zustane->uuid, 'what' => 'Sluchátka', 'price' => 4900, 'who' => 'Adrian', 'left' => 31,
        ]]]])->assertOk();

        $this->assertNull($zustane->refresh()->closed_at);
        $this->assertNotNull($zmizi->refresh()->closed_at);
        $this->assertDatabaseHas('couple_cooling_purchases', ['what' => 'Vysavač']);
    }

    /** Z podmínky se dá udělat přání a zůstane to tak. */
    public function test_zmena_podminky_na_prani_se_zapise(): void
    {
        $bod = CoupleDisagreementPoint::create([
            'gallery_space_id' => $this->prostor->id,
            'author_user_id' => $this->adri->id,
            'text' => 'Nejvýš šest hodin cesty',
            'tag' => 'cesta',
            'kind' => 'podmínka',
        ]);

        $this->patchJson('/api/state', ['data' => ['sporMine' => [[
            'text' => 'Nejvýš šest hodin cesty', 'tag' => 'cesta', 'kind' => 'přání',
        ]]]])->assertOk();

        $this->assertSame('přání', $bod->refresh()->kind);
    }

    /**
     * Použité veto se zapíše ke skutečnému člověku a s dnešním datem.
     *
     * Prototyp datum posílá jako text („4. září 2026"); veto se ale vrací po
     * dvanácti měsících, takže musí být spočitatelné.
     */
    public function test_pouzite_veto_se_zapise_s_datem(): void
    {
        $navrh = CoupleVetoProposal::create([
            'gallery_space_id' => $this->prostor->id,
            'proposed_by' => $this->maki->id,
            'text' => 'Koupit gauč za 34 000',
            'price' => 34000,
            'proposed_on' => now()->subDays(3),
        ]);

        $this->patchJson('/api/state', ['data' => [
            'vetoProps' => [[
                'id' => $navrh->uuid, 'by' => 'Makinka', 'text' => 'Koupit gauč za 34 000',
                'date' => '2. září', 'price' => 34000, 'done' => 'veto',
            ]],
            'vetoLog' => [[
                'who' => 'Adrian', 'text' => 'Koupit gauč za 34 000',
                'date' => '4. září 2026', 'reason' => 'Máme gauč, který drží.',
            ]],
        ]])->assertOk();

        $veto = CoupleVeto::where('gallery_space_id', $this->prostor->id)->firstOrFail();

        $this->assertSame($this->adri->id, $veto->user_id);
        $this->assertTrue($veto->used_on->isToday());
        $this->assertSame('veto', $navrh->refresh()->outcome);
    }

    /** Totéž veto podruhé nezaloží druhý řádek. */
    public function test_veto_se_nezapise_dvakrat(): void
    {
        $patch = ['data' => ['vetoLog' => [[
            'who' => 'Adrian', 'text' => 'Pes', 'date' => '4. září 2026', 'reason' => 'Nezvládneme to.',
        ]]]];

        $this->patchJson('/api/state', $patch)->assertOk();
        $this->patchJson('/api/state', $patch)->assertOk();

        $this->assertSame(1, CoupleVeto::where('gallery_space_id', $this->prostor->id)->count());
    }

    /** Rozhodnutí jiného páru se do odpovědi nedostane. */
    public function test_rozhodnuti_jineho_paru_se_neposila(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        CoupleDecision::create([
            'gallery_space_id' => $this->prostor->id, 'title' => 'Naše',
            'decided_on' => '2026-01-14', 'together' => true,
        ]);
        CoupleDecision::create([
            'gallery_space_id' => $ciziProstor->id, 'title' => 'Cizí',
            'decided_on' => '2026-01-14', 'together' => true,
        ]);

        $r = collect($this->getJson('/api/data/vztah')->assertOk()->json('data.DEC_LIST'))->pluck('title');

        $this->assertSame(['Naše'], $r->all());
    }

    private function ukol(string $nazev, array $navic = []): void
    {
        DB::table('shared_todos')->insert(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => $nazev,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }
}
