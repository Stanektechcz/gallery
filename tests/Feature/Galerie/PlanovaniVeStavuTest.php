<?php

namespace Tests\Feature\Galerie;

use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\SharedTodo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kalendář a úkoly z prototypu zpátky do modulu, který je vlastní.
 *
 * `calendar_events` a `shared_todos` nepatří prototypu — ptají se na ně
 * připomínky, automatizace i cesty. Bez téhle vrstvy má dvojice dva kalendáře:
 * jeden v prohlížeči a druhý v databázi, který o jejích změnách neví.
 *
 * Právě proto, že tabulky vlastní někdo jiný, je zápis opatrnější než u
 * domácnosti: ukázkové řádky se neimportují a maže se jen to, co server poslal.
 */
class PlanovaniVeStavuTest extends TestCase
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

    // ——— kalendář ———

    /** Nová událost z dialogu skončí v kalendáři aplikace, ne jen v prohlížeči. */
    public function test_nova_udalost_se_zapise(): void
    {
        $odpoved = $this->patchJson('/api/state', ['data' => ['evList' => [[
            'id' => 'ev-n1',
            'y' => 2026, 'm' => 8, 'd' => 19, 'time' => '11:00',
            't' => 'Plavba na Ugljan', 'kind' => 'cesta', 'who' => 'spolu',
            'remind' => 'den předem', 'note' => 'Trajekt v 10:40',
        ]]]])->assertOk();

        $u = CalendarEvent::where('gallery_space_id', $this->prostor->id)->firstOrFail();

        $this->assertSame('Plavba na Ugljan', $u->title);
        // Prototyp počítá měsíce od nuly — 8 je září.
        $this->assertSame('2026-09-19 11:00', $u->starts_at->format('Y-m-d H:i'));
        $this->assertSame('trip', $u->type);
        $this->assertFalse((bool) $u->all_day);
        $this->assertArrayNotHasKey('evList', (array) $odpoved->json('data'));
    }

    /** Událost bez času je celodenní, ne o půlnoci. */
    public function test_udalost_bez_casu_je_celodenni(): void
    {
        $this->patchJson('/api/state', ['data' => ['evList' => [[
            'id' => 'ev-n1', 'y' => 2026, 'm' => 8, 'd' => 19, 'time' => '',
            't' => 'Výročí', 'kind' => 'oslava', 'who' => 'spolu',
        ]]]])->assertOk();

        $this->assertTrue((bool) CalendarEvent::firstOrFail()->all_day);
    }

    /**
     * „Spolu" znamená oba, jméno znamená jednoho.
     *
     * Prototyp podle toho posílá připomenutí, takže to nesmí zůstat v popisku.
     */
    public function test_kdo_ma_udalost_se_zapise_do_ucastniku(): void
    {
        $this->patchJson('/api/state', ['data' => ['evList' => [
            ['id' => 'ev-n1', 'y' => 2026, 'm' => 8, 'd' => 19, 'time' => '9:00', 't' => 'Spolu', 'kind' => 'jine', 'who' => 'spolu'],
            ['id' => 'ev-n2', 'y' => 2026, 'm' => 8, 'd' => 20, 'time' => '9:00', 't' => 'Sama', 'kind' => 'jine', 'who' => 'Makinka'],
        ]]])->assertOk();

        $spolu = CalendarEvent::where('title', 'Spolu')->firstOrFail();
        $sama = CalendarEvent::where('title', 'Sama')->firstOrFail();

        $this->assertSame(2, DB::table('event_participants')->where('event_id', $spolu->id)->count());
        $this->assertSame(
            [$this->maki->id],
            DB::table('event_participants')->where('event_id', $sama->id)->pluck('user_id')->all(),
        );
    }

    /**
     * Připomenutí se z nabídky přeloží na okamžik.
     *
     * Prototyp umí čtyři možnosti, databáze drží čas — jinak by připomínka
     * nikdy nepřišla.
     */
    public function test_pripomenuti_se_prelozi_na_okamzik(): void
    {
        $this->patchJson('/api/state', ['data' => ['evList' => [[
            'id' => 'ev-n1', 'y' => 2026, 'm' => 8, 'd' => 19, 'time' => '11:00',
            't' => 'Zubař', 'kind' => 'zdravi', 'who' => 'Adrian', 'remind' => 'den předem',
        ]]]])->assertOk();

        $kdy = DB::table('event_reminders')->value('remind_at');

        $this->assertSame('2026-09-18 11:00', CalendarEvent::firstOrFail()
            ->starts_at->subDay()->format('Y-m-d H:i'));
        $this->assertStringStartsWith('2026-09-18 11:00', (string) $kdy);
    }

    /** Zrušené připomenutí přestane chodit. */
    public function test_zrusene_pripomenuti_se_smaze(): void
    {
        $u = $this->udalost(['title' => 'Zubař']);

        DB::table('event_reminders')->insert([
            'event_id' => $u->id, 'user_id' => $this->adri->id, 'channel' => 'database',
            'remind_at' => now()->addDay(), 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->patchJson('/api/state', ['data' => ['evList' => [
            $this->radekUdalosti($u, ['remind' => '']),
        ]]])->assertOk();

        $this->assertSame(0, DB::table('event_reminders')->where('event_id', $u->id)->count());
    }

    /** Úprava se zapíše do téže události, nezaloží druhou. */
    public function test_uprava_udalosti_nezaklada_druhou(): void
    {
        $u = $this->udalost(['title' => 'Původní']);

        $this->patchJson('/api/state', ['data' => ['evList' => [
            $this->radekUdalosti($u, ['t' => 'Přejmenovaná']),
        ]]])->assertOk();

        $this->assertSame(1, CalendarEvent::count());
        $this->assertSame('Přejmenovaná', $u->refresh()->title);
    }

    /** Smazaná událost zmizí i z kalendáře aplikace. */
    public function test_smazana_udalost_zmizi(): void
    {
        $zustane = $this->udalost(['title' => 'Zůstane']);
        $zmizi = $this->udalost(['title' => 'Zmizí', 'starts_at' => now()->addDays(5)]);

        $this->patchJson('/api/state', ['data' => [
            'evList' => [$this->radekUdalosti($zustane)],
            'evZmenene' => [],
            'evZrusene' => ['ev-'.$zmizi->uuid],
        ]])->assertOk();

        $this->assertNotNull($zustane->fresh());
        $this->assertNull($zmizi->fresh());
    }

    /**
     * Událost, která v odeslaném seznamu chybí, se nemaže.
     *
     * Seznam v prohlížeči je kopie z doby načtení a má limit. Úprava jedné
     * události dřív smazala všechno, co přidal mezitím ten druhý (nebo cesta
     * či automatizace), i co se do seznamu nevešlo.
     */
    public function test_chybejici_udalost_se_nemaze_a_nezmenena_neprepisuje(): void
    {
        $upravena = $this->udalost(['title' => 'Upravená']);
        $odDruheho = $this->udalost(['title' => 'Přidal ten druhý', 'starts_at' => now()->addDays(4)]);
        $zmenilDruhy = $this->udalost(['title' => 'Druhý přejmenoval']);

        $this->patchJson('/api/state', ['data' => [
            'evList' => [
                $this->radekUdalosti($upravena, ['t' => 'Upravená znovu']),
                // Stará kopie: ten druhý ji mezitím přejmenoval.
                $this->radekUdalosti($zmenilDruhy, ['t' => 'Původní název']),
            ],
            'evZmenene' => ['ev-'.$upravena->uuid],
        ]])->assertOk();

        $this->assertSame('Upravená znovu', $upravena->refresh()->title);
        $this->assertNotNull($odDruheho->fresh());
        $this->assertSame('Druhý přejmenoval', $zmenilDruhy->refresh()->title);
    }

    /** Smazaná a vrácená událost (Zpět po odeslání) se v kalendáři obnoví. */
    public function test_vracena_smazana_udalost_se_obnovi(): void
    {
        $u = $this->udalost(['title' => 'Vrátit']);
        $radek = $this->radekUdalosti($u);

        $this->patchJson('/api/state', ['data' => ['evList' => [], 'evZmenene' => [], 'evZrusene' => [$radek['id']]]])->assertOk();
        $this->assertNull($u->fresh());

        $this->patchJson('/api/state', ['data' => ['evList' => [$radek], 'evZmenene' => [], 'evObnovene' => [$radek['id']], 'evZrusene' => []]])->assertOk();
        $this->assertSame(1, CalendarEvent::where('title', 'Vrátit')->count());

        // Další úprava téže (starým identifikátorem) nezaloží třetí.
        $this->patchJson('/api/state', ['data' => ['evList' => [array_merge($radek, ['t' => 'Vráceno'])], 'evZmenene' => [$radek['id']], 'evObnovene' => [$radek['id']]]])->assertOk();
        $this->assertSame(['Vráceno'], CalendarEvent::pluck('title')->all());
    }

    /** Doručená připomínka se dalším uložením kalendáře nezaloží znovu. */
    public function test_dorucena_pripominka_se_neposle_znovu(): void
    {
        $u = $this->udalost(['title' => 'S připomínkou', 'starts_at' => now()->addHours(10)]);
        $radek = $this->radekUdalosti($u, ['remind' => 'den předem']);

        $this->patchJson('/api/state', ['data' => ['evList' => [$radek]]])->assertOk();
        $this->assertSame(1, DB::table('event_reminders')->where('event_id', $u->id)->count());

        DB::table('event_reminders')->where('event_id', $u->id)->update(['status' => 'delivered']);

        $this->patchJson('/api/state', ['data' => ['evList' => [$radek]]])->assertOk();

        $this->assertSame(0, DB::table('event_reminders')->where('event_id', $u->id)->where('status', 'pending')->count());
    }

    /**
     * Událost mimo okno, které server posílá, se nemaže.
     *
     * Klient může mít v paměti starší seznam z doby, kdy okno leželo jinde;
     * jeho odesláním by jinak zmizely události, na které se nikdo nepodíval.
     */
    public function test_udalost_mimo_okno_se_nemaze(): void
    {
        $stara = $this->udalost(['title' => 'Loni', 'starts_at' => now()->subYear()]);

        $this->patchJson('/api/state', ['data' => ['evList' => []]])->assertOk();

        $this->assertNotNull($stara->fresh());
    }

    /** Ukázkové řádky prototypu se do kalendáře nedostanou. */
    public function test_ukazkove_udalosti_se_neimportuji(): void
    {
        $this->patchJson('/api/state', ['data' => ['evList' => [
            ['id' => 'ev0', 'y' => 2026, 'm' => 6, 'd' => 19, 'time' => '11:00', 't' => 'Plavba na Ugljan', 'kind' => 'cesta', 'who' => 'spolu'],
            ['id' => 'ev1', 'y' => 2026, 'm' => 6, 'd' => 24, 'time' => '20:30', 't' => 'Západ na Punta Bajlo', 'kind' => 'cesta', 'who' => 'spolu'],
        ]]])->assertOk();

        $this->assertSame(0, CalendarEvent::count());
    }

    /** Odškrtnutá událost se pozná i v databázi. */
    public function test_odskrtnuta_udalost_ma_stav_hotovo(): void
    {
        $u = $this->udalost(['title' => 'Nákup na týden']);

        $this->patchJson('/api/state', ['data' => [
            'evList' => [$this->radekUdalosti($u)],
            'evDoneMap' => ['ev-'.$u->uuid => true],
        ]])->assertOk();

        $this->assertSame('completed', $u->refresh()->status);

        $this->patchJson('/api/state', ['data' => ['evDoneMap' => ['ev-'.$u->uuid => false]]])->assertOk();

        $this->assertSame('planned', $u->refresh()->status);
    }

    /**
     * Odškrtnutí jedné události neodznačí ostatní.
     *
     * Mapa v prohlížeči začíná prázdná; každá hotová událost, která v ní
     * chyběla, se dřív vrátila na „naplánováno".
     */
    public function test_odskrtnuti_jedne_neodznaci_ostatni(): void
    {
        $hotova = $this->udalost(['title' => 'Už hotová', 'status' => 'completed']);
        $nova = $this->udalost(['title' => 'Právě odškrtnutá']);

        $this->patchJson('/api/state', ['data' => ['evDoneMap' => ['ev-'.$nova->uuid => true]]])->assertOk();

        $this->assertSame('completed', $nova->refresh()->status);
        $this->assertSame('completed', $hotova->refresh()->status);
    }

    /** Zrušená událost se odškrtnutím neoživí — prototyp o tom stavu neví. */
    public function test_zrusena_udalost_zustane_zrusena(): void
    {
        $u = $this->udalost(['title' => 'Zrušené', 'status' => 'cancelled']);

        $this->patchJson('/api/state', ['data' => ['evDoneMap' => []]])->assertOk();

        $this->assertSame('cancelled', $u->refresh()->status);
    }

    // ——— nástěnka úkolů ———

    /** Nový úkol z nástěnky skončí ve sdílených úkolech. */
    public function test_novy_ukol_se_zapise(): void
    {
        $stavajici = $this->ukol(['title' => 'Už tam je', 'due_at' => now()->addDays(2)]);

        $this->patchJson('/api/state', ['data' => ['xBoard' => ['all' => [
            ['label' => 'Tento týden', 'items' => [
                ['id' => $stavajici->uuid, 't' => 'Už tam je', 'w' => 'spolu', 'd' => 'čtvrtek', 'pr' => 0, 'note' => ''],
                ['id' => 'all-n1', 't' => 'Objednat servis kola', 'w' => 'Adrian', 'd' => 'do pátku', 'pr' => 2, 'note' => 'V Bike centru'],
            ]],
            ['label' => 'Hotovo', 'done' => true, 'items' => []],
        ]]]])->assertOk();

        $novy = SharedTodo::where('title', 'Objednat servis kola')->firstOrFail();

        $this->assertSame($this->adri->id, $novy->assigned_to);
        $this->assertSame('high', $novy->priority);
        $this->assertSame('V Bike centru', $novy->description);
        $this->assertNotNull($novy->due_at);
    }

    /**
     * Do ukázkové nástěnky se nezapisuje.
     *
     * `shared_todos` vlastní modul; naplnit ho vymyšlenými řádky z ukázky by
     * bylo horší než nezapsat nic.
     */
    public function test_do_ukazkove_nastenky_se_nezapisuje(): void
    {
        $this->patchJson('/api/state', ['data' => ['xBoard' => ['all' => [
            ['label' => 'Tento týden', 'items' => [
                ['id' => 'all0-0', 't' => 'Zaplatit pension v Sintře', 'w' => 'Adrian', 'd' => 'do pátku', 'pr' => 0],
                ['id' => 'all-n1', 't' => 'Nový úkol', 'w' => 'Adrian', 'd' => 'dnes', 'pr' => 0],
            ]],
        ]]]])->assertOk();

        $this->assertSame(0, SharedTodo::count());
    }

    /** Odškrtnutý úkol je hotový i v modulu — a dá se vrátit. */
    public function test_odskrtnuty_ukol_se_uzavre_a_da_vratit(): void
    {
        $u = $this->ukol(['title' => 'Vysát obývák', 'due_at' => now()->addDay()]);

        $this->patchJson('/api/state', ['data' => ['xBoard' => ['all' => [
            ['label' => 'Tento týden', 'items' => []],
            ['label' => 'Hotovo', 'done' => true, 'items' => [
                ['id' => $u->uuid, 't' => 'Vysát obývák', 'w' => 'spolu', 'd' => 'zítra', 'on' => true],
            ]],
        ]]]])->assertOk();

        $u->refresh();
        $this->assertSame('completed', $u->status);
        $this->assertNotNull($u->completed_at);
        $this->assertSame($this->adri->id, $u->completed_by);

        $this->patchJson('/api/state', ['data' => ['xBoard' => ['all' => [
            ['label' => 'Tento týden', 'items' => [
                ['id' => $u->uuid, 't' => 'Vysát obývák', 'w' => 'spolu', 'd' => 'zítra', 'on' => false],
            ]],
            ['label' => 'Hotovo', 'done' => true, 'items' => []],
        ]]]])->assertOk();

        $u->refresh();
        $this->assertSame('open', $u->status);
        $this->assertNull($u->completed_at);
    }

    /**
     * Přesun do „Někdy" termín zruší.
     *
     * Sloupce jsou odvozené z termínu, takže přesun **je** změna termínu —
     * jinak by se karta po obnovení vrátila tam, odkud ji někdo přetáhl.
     */
    public function test_presun_do_nekdy_zrusi_termin(): void
    {
        $u = $this->ukol(['title' => 'Vybrat fotky', 'due_at' => now()->addDays(2)]);

        $this->patchJson('/api/state', ['data' => ['xBoard' => ['all' => [
            ['label' => 'Někdy', 'items' => [
                ['id' => $u->uuid, 't' => 'Vybrat fotky', 'w' => 'spolu', 'd' => 'čtvrtek'],
            ]],
        ]]]])->assertOk();

        $this->assertNull($u->refresh()->due_at);
    }

    /** Přesun do „Tento týden" termín naopak nastaví. */
    public function test_presun_do_tohoto_tydne_nastavi_termin(): void
    {
        $u = $this->ukol(['title' => 'Zavolat o kauci', 'due_at' => null]);

        $this->patchJson('/api/state', ['data' => ['xBoard' => ['all' => [
            ['label' => 'Tento týden', 'items' => [
                ['id' => $u->uuid, 't' => 'Zavolat o kauci', 'w' => 'spolu', 'd' => 'bez termínu'],
            ]],
        ]]]])->assertOk();

        $u->refresh();

        $this->assertNotNull($u->due_at);
        $this->assertTrue($u->due_at->lte(now()->addWeek()->endOfDay()));
    }

    /** Termín, který se nezměnil, zůstane přesně takový, jaký byl. */
    public function test_nezmeneny_popisek_nechava_termin(): void
    {
        $termin = now()->addDays(3)->setTime(12, 0);
        $u = $this->ukol(['title' => 'Nákup', 'due_at' => $termin]);

        $popisek = collect($this->getJson('/api/data/planovani')->json('data.ATASKS.all.0.1'))
            ->firstWhere(0, 'Nákup');

        $this->patchJson('/api/state', ['data' => ['xBoard' => ['all' => [
            ['label' => 'Tento týden', 'items' => [
                ['id' => $u->uuid, 't' => 'Nákup', 'w' => 'spolu', 'd' => $popisek[2], 'due' => $popisek[5]],
            ]],
        ]]]])->assertOk();

        $this->assertSame(
            $termin->format('Y-m-d H:i'),
            $u->refresh()->due_at->format('Y-m-d H:i'),
        );
    }

    /** Přepsaný popisek termín přeloží — „zítra" je zítra. */
    public function test_prepsany_popisek_prelozi_termin(): void
    {
        $u = $this->ukol(['title' => 'Nákup', 'due_at' => now()->addDays(3)]);

        $this->patchJson('/api/state', ['data' => ['xBoard' => ['all' => [
            ['label' => 'Tento týden', 'items' => [
                ['id' => $u->uuid, 't' => 'Nákup', 'w' => 'spolu', 'd' => 'zítra'],
            ]],
        ]]]])->assertOk();

        $this->assertTrue($u->refresh()->due_at->isTomorrow());
    }

    /** Opakování se zapíše tak, aby ho uměl vyhodnotit modul. */
    public function test_opakovani_se_zapise_ve_tvaru_modulu(): void
    {
        $u = $this->ukol(['title' => 'Zálivka', 'due_at' => now()->addDay()]);

        $this->patchJson('/api/state', ['data' => ['xBoard' => ['all' => [
            ['label' => 'Tento týden', 'items' => [
                ['id' => $u->uuid, 't' => 'Zálivka', 'w' => 'spolu', 'd' => 'zítra', 'rep' => 'týdně'],
            ]],
        ]]]])->assertOk();

        $this->assertSame(['frequency' => 'weekly', 'interval' => 1], $u->refresh()->recurrence);
    }

    /** Smazaný úkol se zruší, nemaže — „uklidit hotové" nemá být ztráta historie. */
    public function test_smazany_ukol_se_zrusi_a_nezmizi(): void
    {
        $zustane = $this->ukol(['title' => 'Zůstane', 'due_at' => now()->addDay()]);
        $zmizi = $this->ukol(['title' => 'Zmizí', 'due_at' => now()->addDay()]);

        $this->patchJson('/api/state', ['data' => [
            'xBoard' => ['all' => [
                ['label' => 'Tento týden', 'items' => [
                    ['id' => $zustane->uuid, 't' => 'Zůstane', 'w' => 'spolu', 'd' => 'zítra'],
                ]],
            ]],
            'xBoardZmenene' => [],
            'xBoardZrusene' => [$zmizi->uuid],
        ]])->assertOk();

        $this->assertSame('open', $zustane->refresh()->status);
        $this->assertSame('cancelled', $zmizi->refresh()->status);
        $this->assertDatabaseHas('shared_todos', ['title' => 'Zmizí']);
    }

    /**
     * Úkol, který na odeslané nástěnce chybí, se neruší.
     *
     * Nástěnka má limit a je to kopie z doby načtení — úkol přidaný mezitím
     * druhým (nebo rychlým zápisem z telefonu) se dřív zrušil při prvním
     * odškrtnutí čehokoli jiného.
     */
    public function test_chybejici_ukol_se_nerusi(): void
    {
        $naNastence = $this->ukol(['title' => 'Na nástěnce', 'due_at' => now()->addDay()]);
        $zTelefonu = $this->ukol(['title' => 'Z telefonu', 'due_at' => now()->addDay()]);

        $this->patchJson('/api/state', ['data' => [
            'xBoard' => ['all' => [
                ['label' => 'Hotovo', 'done' => true, 'items' => [
                    ['id' => $naNastence->uuid, 't' => 'Na nástěnce', 'w' => 'spolu', 'd' => 'zítra', 'on' => true],
                ]],
            ]],
            'xBoardZmenene' => [$naNastence->uuid],
        ]])->assertOk();

        $this->assertSame('completed', $naNastence->refresh()->status);
        $this->assertSame('open', $zTelefonu->refresh()->status);
    }

    /** „Uklidit hotové" hotový úkol archivuje — z nástěnky zmizí, v Hotovo zůstane. */
    public function test_uklizeny_hotovy_ukol_zustane_hotovy(): void
    {
        $u = $this->ukol(['title' => 'Hotový', 'status' => 'completed', 'completed_at' => now(), 'due_at' => now()]);

        $this->patchJson('/api/state', ['data' => ['xBoard' => ['all' => [['label' => 'Hotovo', 'done' => true, 'items' => []]]], 'xBoardZmenene' => [], 'xBoardZrusene' => [$u->uuid]]])->assertOk();

        $this->assertSame('completed', $u->refresh()->status);
        $this->assertTrue((bool) ($u->metadata['archivovano'] ?? false));

        $data = $this->getJson('/api/data/planovani')->assertOk()->json('data');
        $this->assertStringNotContainsString($u->uuid, json_encode($data['ATASKS'] ?? []));
        $this->assertStringContainsString('Hotový', json_encode($data['AL'] ?? [], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Zásah na nástěnce domácnosti neruší zbytek.
     *
     * Ta nástěnka je výřez, ne celý seznam — kdyby se bral jako celek, zmizely
     * by všechny úkoly, které do domácnosti nepatří.
     */
    public function test_nastenka_domacnosti_nerusi_ostatni(): void
    {
        $seznam = DB::table('shared_todo_lists')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Domácnost', 'kind' => 'household',
            'color' => '#14b8a6', 'icon' => '🏠', 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $domaci = $this->ukol(['title' => 'Vyprat', 'due_at' => now()->addDay(), 'list_id' => $seznam]);
        $jiny = $this->ukol(['title' => 'Zaplatit pension', 'due_at' => now()->addDay()]);

        $this->patchJson('/api/state', ['data' => ['xBoard' => ['home' => [
            ['label' => 'Tento týden', 'items' => [
                ['id' => $domaci->uuid, 't' => 'Vyprat', 'w' => 'Makinka', 'd' => 'zítra'],
            ]],
        ]]]])->assertOk();

        $this->assertSame('open', $jiny->refresh()->status);
        $this->assertSame($this->maki->id, $domaci->refresh()->assigned_to);
    }

    // ——— až budeme mít čas ———

    /** „Jde se to udělat" znamená termín na tento týden. */
    public function test_polozka_ze_seznamu_nekdy_dostane_termin(): void
    {
        $u = $this->ukol(['title' => 'Roztřídit fotky', 'due_at' => null]);

        $this->patchJson('/api/state', ['data' => ['hsLater' => [
            ['id' => $u->uuid, 'text' => 'Roztřídit fotky', 'by' => 'Adrian', 'added' => '2024-04-02', 'state' => 'done'],
        ]]])->assertOk();

        $u->refresh();

        $this->assertNotNull($u->due_at);
        $this->assertSame('open', $u->status);
    }

    /** „Propuštěno" je zrušení, ne smazání. */
    public function test_propustena_polozka_se_zrusi(): void
    {
        $u = $this->ukol(['title' => 'Prodat kolo', 'due_at' => null]);

        $this->patchJson('/api/state', ['data' => ['hsLater' => [
            ['id' => $u->uuid, 'text' => 'Prodat kolo', 'by' => 'Adrian', 'added' => '2024-04-02', 'state' => 'dropped'],
        ]]])->assertOk();

        $this->assertSame('cancelled', $u->refresh()->status);
        $this->assertDatabaseHas('shared_todos', ['title' => 'Prodat kolo']);
    }

    /** Nová věc na seznam se založí bez termínu — na to ten seznam je. */
    public function test_nova_vec_na_seznamu_nekdy(): void
    {
        $this->ukol(['title' => 'Něco skutečného', 'due_at' => null]);

        $this->patchJson('/api/state', ['data' => ['hsLater' => [
            ['id' => 'w'.now()->getTimestamp(), 'text' => 'Naučit se rizoto', 'by' => 'Adrian', 'added' => '2026-09-06', 'state' => 'open'],
        ]]])->assertOk();

        $novy = SharedTodo::where('title', 'Naučit se rizoto')->firstOrFail();

        $this->assertNull($novy->due_at);
        $this->assertSame('open', $novy->status);
    }

    /** Ukázkové řádky seznamu se neimportují. */
    public function test_ukazkovy_seznam_nekdy_se_neimportuje(): void
    {
        $this->patchJson('/api/state', ['data' => ['hsLater' => [
            ['id' => 'w1', 'text' => 'Vyfotit a prodat kolo z garáže', 'by' => 'Adrian', 'added' => '2024-04-02', 'state' => 'open'],
        ]]])->assertOk();

        $this->assertSame(0, SharedTodo::count());
    }

    // ——— opakované odeslání a první záznam ———

    /**
     * Nový úkol se při dalším zápisu nezaloží znovu.
     *
     * Nástěnka v prohlížeči si nový úkol drží pod svým identifikátorem
     * (`all-n1`) až do obnovení stránky a posílá ho s každou další změnou.
     * Server ho pokaždé založil znovu a ten předchozí zrušil — v aktivitě
     * přibýval „nový úkol" za každé odškrtnutí.
     */
    public function test_znovu_poslany_novy_ukol_nezalozi_druhy(): void
    {
        $stavajici = $this->ukol(['title' => 'Už tam je', 'due_at' => now()->addDays(2)]);
        $nastenka = fn (bool $hotovo) => ['xBoard' => ['all' => [
            ['label' => 'Tento týden', 'items' => [
                ['id' => $stavajici->uuid, 't' => 'Už tam je', 'w' => 'spolu', 'd' => 'čtvrtek'],
                ['id' => 'all-n1', 't' => 'Objednat servis kola', 'w' => 'Adrian', 'd' => 'zítra', 'on' => $hotovo],
            ]],
            ['label' => 'Hotovo', 'done' => true, 'items' => []],
        ]]];

        $this->patchJson('/api/state', ['data' => $nastenka(false)])->assertOk();
        $prvni = SharedTodo::where('title', 'Objednat servis kola')->sole();

        $this->patchJson('/api/state', ['data' => $nastenka(true)])->assertOk();

        $this->assertSame(1, SharedTodo::where('title', 'Objednat servis kola')->count());
        $this->assertSame('completed', $prvni->refresh()->status);
        $this->assertSame('open', $stavajici->refresh()->status);
    }

    /**
     * První úkol dvojice, která zatím žádný nemá.
     *
     * Nástěnka bez jediného úkolu z databáze se brala jako ukázková, takže
     * se první úkol nezapsal nikdy — a po obnovení stránky zmizel.
     */
    public function test_prvni_ukol_na_prazdne_nastence_se_zapise(): void
    {
        $this->patchJson('/api/state', ['data' => ['xBoard' => ['all' => [
            ['label' => 'Tento týden', 'items' => [
                ['id' => 'all-n1', 't' => 'První úkol', 'w' => 'Adrian', 'd' => 'dnes'],
                // Šablona, rychlý zápis a žádost mají vlastní identifikátory.
                ['id' => 'tpl4', 't' => 'Zkontrolovat pasy', 'w' => 'Makinka', 'd' => 'bez termínu'],
                ['id' => 'q7', 't' => 'Zavolat do servisu', 'w' => 'oba', 'd' => 'bez termínu'],
            ]],
            ['label' => 'Hotovo', 'done' => true, 'items' => []],
        ]]]])->assertOk();

        $this->assertEqualsCanonicalizing(['První úkol', 'Zkontrolovat pasy', 'Zavolat do servisu'], SharedTodo::pluck('title')->all());
    }

    /** Nový úkol „dnes" v Tento týden má termín dnes a po obnovení zůstane v Tento týden. */
    public function test_novy_ukol_dostane_termin_z_popisku(): void
    {
        $this->patchJson('/api/state', ['data' => ['xBoard' => ['all' => [
            ['label' => 'Tento týden', 'items' => [
                ['id' => 'all-n1', 't' => 'Dnešní', 'w' => 'spolu', 'd' => 'dnes'],
                ['id' => 'all-n2', 't' => 'Bez popisku', 'w' => 'spolu', 'd' => 'nové'],
            ]],
        ]]]])->assertOk();

        $this->assertSame(now()->toDateString(), SharedTodo::where('title', 'Dnešní')->sole()->due_at->toDateString());

        $sloupce = collect($this->getJson('/api/data/planovani')->json('data.ATASKS.all'))->mapWithKeys(fn ($s) => [$s[0] => collect($s[1])->pluck(0)->all()]);
        $this->assertEqualsCanonicalizing(['Dnešní', 'Bez popisku'], $sloupce['Tento týden']);
    }

    /** Smazaný poslední úkol se zruší — prázdná nástěnka sama nic nemaže. */
    public function test_smazany_posledni_ukol_se_zrusi(): void
    {
        $posledni = $this->ukol(['title' => 'Poslední', 'due_at' => now()->addDay()]);
        $mimo = $this->ukol(['title' => 'Nenačtený', 'due_at' => now()->addDay()]);

        $this->patchJson('/api/state', ['data' => [
            'xBoard' => ['all' => [['label' => 'Tento týden', 'items' => []]]],
            'xBoardZrusene' => [$posledni->uuid, 'all-n9'],
        ]])->assertOk();

        $this->assertSame('cancelled', $posledni->refresh()->status);
        $this->assertSame('open', $mimo->refresh()->status);
    }

    /** Vrácený smazaný úkol (Zpět) se znovu otevře. */
    public function test_vraceny_zruseny_ukol_se_otevre(): void
    {
        $u = $this->ukol(['title' => 'Vrátit', 'due_at' => now()->addDay(), 'status' => 'cancelled']);
        $jiny = $this->ukol(['title' => 'Jiný', 'due_at' => now()->addDay()]);

        $this->patchJson('/api/state', ['data' => ['xBoard' => ['all' => [['label' => 'Tento týden', 'items' => [
            ['id' => $u->uuid, 't' => 'Vrátit', 'w' => 'spolu', 'd' => 'zítra'],
            ['id' => $jiny->uuid, 't' => 'Jiný', 'w' => 'spolu', 'd' => 'zítra'],
        ]]]]]])->assertOk();

        $this->assertSame('open', $u->refresh()->status);
    }

    /** Nová událost se při dalším zápisu seznamu nezaloží znovu (a ta první nezmizí). */
    public function test_znovu_poslana_nova_udalost_nezalozi_druhou(): void
    {
        $radek = ['id' => 'ev-n1', 'y' => 2026, 'm' => 8, 'd' => 19, 'time' => '11:00', 't' => 'Plavba na Ugljan', 'kind' => 'cesta', 'who' => 'spolu', 'remind' => '', 'note' => ''];

        $this->patchJson('/api/state', ['data' => ['evList' => [$radek]]])->assertOk();
        $prvni = CalendarEvent::where('title', 'Plavba na Ugljan')->sole();

        $this->patchJson('/api/state', ['data' => ['evList' => [
            array_merge($radek, ['time' => '12:00']),
            ['id' => 'ev-n2', 'y' => 2026, 'm' => 8, 'd' => 20, 'time' => '', 't' => 'Návrat', 'kind' => 'cesta', 'who' => 'spolu', 'remind' => '', 'note' => ''],
        ]]])->assertOk();

        $this->assertSame(1, CalendarEvent::where('title', 'Plavba na Ugljan')->count());
        $this->assertSame('12:00', CalendarEvent::where('title', 'Plavba na Ugljan')->sole()->starts_at->format('H:i'));
        $this->assertSame($prvni->uuid, CalendarEvent::where('title', 'Plavba na Ugljan')->sole()->uuid);
        $this->assertSame(1, CalendarEvent::where('title', 'Návrat')->count());
    }

    /** První věc na seznamu „až budeme mít čas" se zapíše a znovu odeslaná se nezdvojí. */
    public function test_seznam_nekdy_prvni_vec_a_opakovane_odeslani(): void
    {
        $radek = ['id' => 'w1789000000', 'text' => 'Naučit se rizoto', 'by' => 'Adrian', 'added' => '2026-09-06', 'state' => 'open'];

        $this->patchJson('/api/state', ['data' => ['hsLater' => [$radek]]])->assertOk();
        $this->patchJson('/api/state', ['data' => ['hsLater' => [$radek]]])->assertOk();

        $this->assertSame(1, SharedTodo::where('title', 'Naučit se rizoto')->count());
    }

    /** Plán se do stavu páru neukládá — jediná pravda je modul. */
    public function test_plan_se_do_stavu_neuklada(): void
    {
        $u = $this->ukol(['title' => 'Nákup', 'due_at' => now()->addDay()]);

        $odpoved = $this->patchJson('/api/state', ['data' => [
            'xBoard' => ['all' => [['label' => 'Tento týden', 'items' => [
                ['id' => $u->uuid, 't' => 'Nákup', 'w' => 'spolu', 'd' => 'zítra'],
            ]]]],
            'hsLater' => [],
            'calMonth' => 8,
        ]])->assertOk();

        $stav = (array) $odpoved->json('data');

        $this->assertArrayNotHasKey('xBoard', $stav);
        $this->assertArrayNotHasKey('hsLater', $stav);
        // Co k plánování nepatří, se ukládá dál.
        $this->assertSame(8, $stav['calMonth']);
    }

    // ——— pomůcky ———

    private function udalost(array $navic = []): CalendarEvent
    {
        return CalendarEvent::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Událost',
            'type' => 'event',
            'status' => 'planned',
            'starts_at' => now()->addDays(3)->setTime(11, 0),
            'timezone' => 'Europe/Prague',
        ], $navic));
    }

    /** @return array<string, mixed> */
    private function radekUdalosti(CalendarEvent $u, array $navic = []): array
    {
        return array_merge([
            'id' => 'ev-'.$u->uuid,
            'y' => (int) $u->starts_at->year,
            'm' => (int) $u->starts_at->month - 1,
            'd' => (int) $u->starts_at->day,
            'time' => $u->starts_at->format('G:i'),
            't' => $u->title,
            'kind' => 'jine',
            'who' => 'spolu',
            'remind' => '',
            'note' => '',
        ], $navic);
    }

    private function ukol(array $navic = []): SharedTodo
    {
        return SharedTodo::create(array_merge([
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Úkol',
            'status' => 'open',
            'priority' => 'normal',
        ], $navic));
    }
}
