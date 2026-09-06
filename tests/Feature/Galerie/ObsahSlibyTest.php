<?php

namespace Tests\Feature\Galerie;

use App\Models\CoupleNudge;
use App\Models\CoupleNudgeReminder;
use App\Models\CouplePromise;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sliby, žádosti mezi partnery a trpělivost — ze stavu do databáze a zpátky.
 *
 * Sliby dosud žily jen v jednom JSON dokumentu, takže se na ně nedalo zeptat
 * odjinud. Trpělivost neměla vlastní věc vůbec: je to pohled na žádosti mezi
 * partnery a na to, kolikrát se něco muselo připomínat — a to tlačítko
 * do téhle chvíle jen ukázalo hlášku a nikam nic nezapsalo.
 */
class ObsahSlibyTest extends TestCase
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

    // ——— sliby ———

    /**
     * Slib po termínu se pozná z data, ne z uloženého slova.
     *
     * Uložený stav by po termínu pořád tvrdil, že slib platí — a „čtyři dny po
     * termínu" platí jen ten den, kdy se to čte.
     */
    public function test_slib_po_terminu_se_pozna_z_data(): void
    {
        $this->slib(['what' => 'Objednám servis kola', 'due_on' => now()->subDays(13), 'state' => 'open']);
        $this->slib(['what' => 'Zavolám tvé mámě', 'due_on' => now()->addDays(4), 'state' => 'open']);

        $sliby = collect($this->getJson('/api/data/vztah')->assertOk()->json('data.PROMISES'))
            ->keyBy('what');

        $this->assertSame('late', $sliby['Objednám servis kola']['state']);
        $this->assertSame(-13, $sliby['Objednám servis kola']['days']);
        $this->assertSame('open', $sliby['Zavolám tvé mámě']['state']);
        $this->assertSame(4, $sliby['Zavolám tvé mámě']['days']);
    }

    /** Slib nese, kdo komu ho dal a kde to zaznělo. */
    public function test_slib_nese_kdo_komu_a_kde_to_zaznelo(): void
    {
        $this->slib([
            'what' => 'Vyberu fotky do knihy',
            'promised_by' => $this->maki->id,
            'promised_to' => $this->adri->id,
            'due_label' => 'do neděle',
            'said' => 'nahlas u večeře',
        ]);

        $s = $this->getJson('/api/data/vztah')->assertOk()->json('data.PROMISES.0');

        $this->assertSame('Makinka', $s['who']);
        $this->assertSame('Adrian', $s['to']);
        $this->assertSame('do neděle', $s['due']);
        $this->assertSame('nahlas u večeře', $s['said']);
    }

    /** Slib zrušený po dohodě se na obrazovku nevrací, ale v databázi zůstává. */
    public function test_zruseny_slib_se_neposila(): void
    {
        $this->slib(['what' => 'Platí']);
        $this->slib(['what' => 'Zrušený po dohodě', 'state' => 'released']);

        $sliby = collect($this->getJson('/api/data/vztah')->assertOk()->json('data.PROMISES'))->pluck('what');

        $this->assertSame(['Platí'], $sliby->all());
        $this->assertDatabaseHas('couple_promises', ['what' => 'Zrušený po dohodě']);
    }

    /** Nový slib z obrazovky končí v databázi, ne ve stavu. */
    public function test_novy_slib_se_zapise(): void
    {
        $odpoved = $this->patchJson('/api/state', ['data' => ['proms' => [[
            'id' => 'p'.now()->getTimestampMs(),
            'who' => 'Adrian', 'to' => 'Makinka',
            'what' => 'Vyřídím STK',
            'due' => 'bez termínu', 'days' => 99,
            'state' => 'open', 'said' => 'zapsáno ručně',
        ]]]])->assertOk();

        $s = CouplePromise::firstOrFail();

        $this->assertSame('Vyřídím STK', $s->what);
        $this->assertSame($this->adri->id, $s->promised_by);
        $this->assertSame($this->maki->id, $s->promised_to);
        $this->assertNull($s->due_on);
        $this->assertArrayNotHasKey('proms', (array) $odpoved->json('data'));
    }

    /** Dodržený slib se uzavře i s časem. */
    public function test_dodrzeny_slib_se_uzavre(): void
    {
        $s = $this->slib(['what' => 'Zavolám tvé mámě', 'due_on' => now()->addDays(4)]);

        $this->patchJson('/api/state', ['data' => ['proms' => [
            $this->radekSlibu($s, ['state' => 'kept']),
        ]]])->assertOk();

        $s->refresh();

        $this->assertSame('kept', $s->state);
        $this->assertNotNull($s->settled_at);
    }

    /**
     * Slib, který zmizel ze seznamu, je zrušený po dohodě — ne nedodržený.
     *
     * Ten rozdíl je celý smysl téhle sekce a prototyp ho umí říct jen tím, že
     * řádek odebere.
     */
    public function test_zmizely_slib_je_zruseny_po_dohode(): void
    {
        $zustane = $this->slib(['what' => 'Zůstane']);
        $zmizi = $this->slib(['what' => 'Zrušíme po dohodě']);

        $this->patchJson('/api/state', ['data' => ['proms' => [
            $this->radekSlibu($zustane),
        ]]])->assertOk();

        $this->assertSame('open', $zustane->refresh()->state);
        $this->assertSame('released', $zmizi->refresh()->state);
        // Nedodržený to není — to je jiná věc a jiné číslo ve statistice.
        $this->assertNotSame('broken', $zmizi->state);
    }

    /** Posunutý termín se přepočítá na datum. */
    public function test_posunuty_termin_se_prepocita(): void
    {
        $s = $this->slib(['what' => 'Zavolám tvé mámě', 'due_on' => now()->addDays(4)]);

        $this->patchJson('/api/state', ['data' => ['proms' => [
            $this->radekSlibu($s, ['due' => 'do konce příštího týdne', 'days' => 11]),
        ]]])->assertOk();

        $s->refresh();

        $this->assertSame('do konce příštího týdne', $s->due_label);
        $this->assertSame(now()->startOfDay()->addDays(11)->toDateString(), $s->due_on->toDateString());
    }

    /** Stav `late` se neukládá — odvozuje se z data. */
    public function test_stav_late_se_neuklada(): void
    {
        $s = $this->slib(['what' => 'Objednám servis', 'due_on' => now()->subDays(3)]);

        $this->patchJson('/api/state', ['data' => ['proms' => [
            $this->radekSlibu($s, ['state' => 'late', 'days' => -3]),
        ]]])->assertOk();

        $this->assertSame('open', $s->refresh()->state);
    }

    // ——— žádosti a trpělivost ———

    /** Žádost nese druh, stav i poznámku — a počet už odeslaných připomínek. */
    public function test_zadost_nese_druh_stav_i_pripominky(): void
    {
        $z = $this->zadost([
            'text' => 'Vezmeš cestou chleba a vodu?',
            'kind' => 'cestou',
            'state' => 'ceka',
        ]);

        CoupleNudgeReminder::create(['couple_nudge_id' => $z->id, 'reminded_by' => $this->maki->id, 'created_at' => now()]);

        $r = $this->getJson('/api/data/vztah')->assertOk()->json('data.NUDGES.0');

        $this->assertSame($z->uuid, $r[0]);
        $this->assertSame('Makinka', $r[1]);
        $this->assertSame('Adrian', $r[2]);
        $this->assertSame('Vezmeš cestou chleba a vodu?', $r[3]);
        $this->assertSame('cestou', $r[4]);
        $this->assertSame('ceka', $r[6]);
        $this->assertSame(1, $r[8]);
    }

    /**
     * Trpělivost je pohled na žádosti, ne vlastní seznam.
     *
     * `who` je ten, kdo to má na starost — připomínal ten druhý.
     */
    public function test_trpelivost_pocita_pripominky_za_mesic(): void
    {
        $z = $this->zadost(['text' => 'Zavolat pojišťovně kvůli pračce']);

        foreach ([now(), now()->subDays(3), now()->subDays(50)] as $kdy) {
            CoupleNudgeReminder::create([
                'couple_nudge_id' => $z->id,
                'reminded_by' => $this->maki->id,
                'created_at' => $kdy,
            ]);
        }

        $r = $this->getJson('/api/data/vztah')->assertOk()->json('data.PATIENCE.0');

        $this->assertSame('Zavolat pojišťovně kvůli pračce', $r['task']);
        $this->assertSame('Adrian', $r['who']);
        // Padesát dní stará připomínka se do měsíčního součtu nepočítá.
        $this->assertSame(2, $r['rem']);
        $this->assertFalse($r['done']);
    }

    /** Co převzalo pravidlo, se nemá nikomu připomínat — ani v přehledu. */
    public function test_automatizovana_zadost_v_trpelivosti_neni(): void
    {
        $this->zadost(['text' => 'Zůstane']);
        $this->zadost(['text' => 'Převzalo pravidlo', 'automated_at' => now()]);

        $ukoly = collect($this->getJson('/api/data/vztah')->assertOk()->json('data.PATIENCE'))->pluck('task');

        $this->assertSame(['Zůstane'], $ukoly->all());
    }

    /** Odmítnutá žádost není nesplněný slib. */
    public function test_odmitnuta_zadost_v_trpelivosti_neni(): void
    {
        $this->zadost(['text' => 'Zůstane']);
        $this->zadost(['text' => 'Odmítnuto', 'state' => 'odmitnuto']);

        $ukoly = collect($this->getJson('/api/data/vztah')->assertOk()->json('data.PATIENCE'))->pluck('task');

        $this->assertSame(['Zůstane'], $ukoly->all());
    }

    /** Nová žádost z obrazovky končí v databázi. */
    public function test_nova_zadost_se_zapise(): void
    {
        $this->patchJson('/api/state', ['data' => ['nudges' => [[
            'id' => 'n-n1', 'from' => 'Adrian', 'to' => 'Makinka',
            'text' => 'Vezmeš cestou chleba?', 'kind' => 'cestou',
            'when' => 'právě teď', 'state' => 'ceka', 'note' => '',
        ]]]])->assertOk();

        $z = CoupleNudge::firstOrFail();

        $this->assertSame('Vezmeš cestou chleba?', $z->text);
        $this->assertSame($this->adri->id, $z->asked_by);
        $this->assertSame($this->maki->id, $z->asked_of);
        $this->assertSame('ceka', $z->state);
    }

    /** Přijatá i hotová žádost se pozná i v databázi. */
    public function test_stav_zadosti_se_zapise(): void
    {
        $z = $this->zadost(['text' => 'Zavolat do servisu']);

        $this->patchJson('/api/state', ['data' => ['nudges' => [
            $this->radekZadosti($z, ['state' => 'prijato', 'note' => 'Adrian: zkusím ve čtvrtek']),
        ]]])->assertOk();

        $this->assertSame('prijato', $z->refresh()->state);
        $this->assertSame('Adrian: zkusím ve čtvrtek', $z->note);
        $this->assertNull($z->closed_at);

        $this->patchJson('/api/state', ['data' => ['nudges' => [
            $this->radekZadosti($z, ['state' => 'hotovo', 'note' => 'hotovo dnes']),
        ]]])->assertOk();

        $this->assertSame('hotovo', $z->refresh()->state);
        $this->assertNotNull($z->closed_at);
    }

    /**
     * Odeslaná připomínka se zapíše jako záznam s časem.
     *
     * Do stavu se vejde jen číslo; z čítače se ale měsíc vyčíst nedá, a přehled
     * trpělivosti mluví o posledním měsíci.
     */
    public function test_pripominka_se_zapise_jako_zaznam(): void
    {
        $z = $this->zadost(['text' => 'Vyzvednout balík']);

        $this->patchJson('/api/state', ['data' => ['nudges' => [
            $this->radekZadosti($z, ['rem' => 1]),
        ]]])->assertOk();

        $this->assertSame(1, $z->pripominky()->count());
        $this->assertSame($this->adri->id, $z->pripominky()->first()->reminded_by);
    }

    /** Zapisuje se rozdíl, ne celý počet — jinak by z jedné byly tři. */
    public function test_pripominky_se_nezapisuji_znovu(): void
    {
        $z = $this->zadost(['text' => 'Vyzvednout balík']);

        $this->patchJson('/api/state', ['data' => ['nudges' => [$this->radekZadosti($z, ['rem' => 2])]]])->assertOk();
        $this->patchJson('/api/state', ['data' => ['nudges' => [$this->radekZadosti($z, ['rem' => 2])]]])->assertOk();
        $this->patchJson('/api/state', ['data' => ['nudges' => [$this->radekZadosti($z, ['rem' => 3])]]])->assertOk();

        $this->assertSame(3, $z->pripominky()->count());
    }

    /** Zrušená žádost zmizí — je to prosba, ne závazek. */
    public function test_zrusena_zadost_zmizi(): void
    {
        $zustane = $this->zadost(['text' => 'Zůstane']);
        $zmizi = $this->zadost(['text' => 'Zrušeno']);

        $this->patchJson('/api/state', ['data' => ['nudges' => [
            $this->radekZadosti($zustane),
        ]]])->assertOk();

        $this->assertNotNull($zustane->fresh());
        $this->assertNull($zmizi->fresh());
    }

    /** „Převzalo pravidlo" se zapíše k žádosti, ne jen do prohlížeče. */
    public function test_prevzeti_pravidlem_se_zapise(): void
    {
        $z = $this->zadost(['text' => 'Odnést papíry do sběru']);

        $this->patchJson('/api/state', ['data' => ['patAuto' => ['Odnést papíry do sběru' => true]]])->assertOk();

        $this->assertNotNull($z->refresh()->automated_at);

        $this->patchJson('/api/state', ['data' => ['patAuto' => []]])->assertOk();

        $this->assertNull($z->refresh()->automated_at);
    }

    /** Sliby a trpělivost se do stavu páru neukládají. */
    public function test_sliby_a_trpelivost_se_do_stavu_neukladaji(): void
    {
        $odpoved = $this->patchJson('/api/state', ['data' => [
            'proms' => [],
            'nudges' => [],
            'patAuto' => [],
            'dcTab' => 'prom',
        ]])->assertOk();

        $stav = (array) $odpoved->json('data');

        $this->assertArrayNotHasKey('proms', $stav);
        $this->assertArrayNotHasKey('nudges', $stav);
        $this->assertArrayNotHasKey('patAuto', $stav);
        $this->assertSame('prom', $stav['dcTab']);
    }

    /** Sliby jiného páru se do odpovědi nedostanou. */
    public function test_sliby_jineho_paru_se_neposilaji(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $this->slib(['what' => 'Náš']);
        CouplePromise::create([
            'gallery_space_id' => $ciziProstor->id,
            'promised_by' => $cizi->id,
            'what' => 'Cizí',
            'state' => 'open',
        ]);

        $sliby = collect($this->getJson('/api/data/vztah')->assertOk()->json('data.PROMISES'))->pluck('what');

        $this->assertSame(['Náš'], $sliby->all());
    }

    // ——— pomůcky ———

    private function slib(array $navic = []): CouplePromise
    {
        return CouplePromise::create(array_merge([
            'gallery_space_id' => $this->prostor->id,
            'promised_by' => $this->adri->id,
            'promised_to' => $this->maki->id,
            'what' => 'Slib',
            'state' => 'open',
        ], $navic));
    }

    /** @return array<string, mixed> */
    private function radekSlibu(CouplePromise $s, array $navic = []): array
    {
        return array_merge([
            'id' => $s->uuid,
            'who' => 'Adrian',
            'to' => 'Makinka',
            'what' => $s->what,
            'due' => $s->due_label ?? 'bez termínu',
            'days' => $s->due_on ? (int) now()->startOfDay()->diffInDays($s->due_on, false) : 99,
            'state' => $s->state,
            'said' => $s->said ?? 'zapsáno ručně',
        ], $navic);
    }

    private function zadost(array $navic = []): CoupleNudge
    {
        return CoupleNudge::create(array_merge([
            'gallery_space_id' => $this->prostor->id,
            'asked_by' => $this->maki->id,
            'asked_of' => $this->adri->id,
            'text' => 'Žádost',
            'kind' => 'cestou',
            'state' => 'ceka',
        ], $navic));
    }

    /** @return array<string, mixed> */
    private function radekZadosti(CoupleNudge $z, array $navic = []): array
    {
        return array_merge([
            'id' => $z->uuid,
            'from' => 'Makinka',
            'to' => 'Adrian',
            'text' => $z->text,
            'kind' => $z->kind,
            'when' => 'dnes 8:15',
            'state' => $z->state,
            'note' => (string) ($z->note ?? ''),
            'rem' => $z->pripominky()->count(),
        ], $navic);
    }
}
