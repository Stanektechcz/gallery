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
 * Plánování ve tvaru, ve kterém je kreslí prototyp.
 *
 * Aplikace má celý plánovací modul — události, sdílené úkoly, připomínky
 * i společnou stopu toho, co se stalo. Prototyp z toho neukazoval nic:
 * čtyři napsané události z léta 2026 a nástěnku o devíti vymyšlených úkolech.
 */
class ObsahPlanovaniTest extends TestCase
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

    /** Bez událostí a úkolů se nic neposílá — klient si nechá ukázková data. */
    public function test_bez_planu_se_skupina_neposila(): void
    {
        $this->assertSame([], $this->getJson('/api/data/planovani')->assertOk()->json('data'));
    }

    /**
     * Měsíc se posílá **od nuly**.
     *
     * Prototyp ho tak čte i zapisuje (`m: +p[1] - 1`); poslat lidský měsíc by
     * znamenalo, že se celý kalendář posune o jeden dopředu.
     */
    public function test_udalost_ma_mesic_od_nuly(): void
    {
        $this->udalost(['starts_at' => '2026-09-19 11:00:00', 'title' => 'Plavba na Ugljan']);

        $ev = $this->getJson('/api/data/planovani')->assertOk()->json('data.CALEV.0');

        $this->assertSame(2026, $ev['y']);
        $this->assertSame(8, $ev['m']);
        $this->assertSame(19, $ev['d']);
        $this->assertSame('11:00', $ev['time']);
        $this->assertSame('Plavba na Ugljan', $ev['t']);
    }

    /** Celodenní událost nemá čas — prototyp na jeho místo kreslí pomlčku. */
    public function test_celodenni_udalost_nema_cas(): void
    {
        $this->udalost(['all_day' => true]);

        $this->assertSame('', $this->getJson('/api/data/planovani')->assertOk()->json('data.CALEV.0.time'));
    }

    /** Typ události se překládá na druh, který prototyp umí obarvit. */
    public function test_typ_udalosti_se_prelozi_na_druh(): void
    {
        $this->udalost(['type' => 'trip', 'title' => 'Chorvatsko']);
        $this->udalost(['type' => 'birthday', 'title' => 'Narozeniny', 'starts_at' => '2026-09-20 09:00:00']);
        $this->udalost(['type' => 'event', 'title' => 'Něco', 'starts_at' => '2026-09-21 09:00:00']);

        $druhy = collect($this->getJson('/api/data/planovani')->assertOk()->json('data.CALEV'))
            ->pluck('kind', 't');

        $this->assertSame('cesta', $druhy['Chorvatsko']);
        $this->assertSame('oslava', $druhy['Narozeniny']);
        $this->assertSame('jine', $druhy['Něco']);
    }

    /** Událost obou je „spolu"; událost jednoho nese jeho jméno. */
    public function test_kdo_ma_udalost_se_pozna_z_ucastniku(): void
    {
        $spolecna = $this->udalost(['title' => 'Spolu']);
        $sama = $this->udalost(['title' => 'Sama', 'starts_at' => '2026-09-20 09:00:00']);

        foreach ([$this->adri, $this->maki] as $kdo) {
            DB::table('event_participants')->insert([
                'event_id' => $spolecna->id, 'user_id' => $kdo->id,
                'role' => 'owner', 'response' => 'yes',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('event_participants')->insert([
            'event_id' => $sama->id, 'user_id' => $this->maki->id,
            'role' => 'owner', 'response' => 'yes',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $kdo = collect($this->getJson('/api/data/planovani')->assertOk()->json('data.CALEV'))
            ->pluck('who', 't');

        $this->assertSame('spolu', $kdo['Spolu']);
        $this->assertSame('Makinka', $kdo['Sama']);
    }

    /**
     * Připomínka se přeloží do slov, která nabízí dialog.
     *
     * Databáze drží přesný okamžik, prototyp umí čtyři možnosti. Poslat mu
     * datum by znamenalo, že se v nabídce nevybere nic.
     */
    public function test_pripominka_se_prelozi_do_slov_dialogu(): void
    {
        $ev = $this->udalost(['starts_at' => '2026-09-19 11:00:00']);

        DB::table('event_reminders')->insert([
            'event_id' => $ev->id, 'user_id' => $this->adri->id,
            'channel' => 'database', 'remind_at' => '2026-09-18 09:00:00',
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(
            'den předem',
            $this->getJson('/api/data/planovani')->assertOk()->json('data.CALEV.0.remind'),
        );
    }

    /** Soukromá událost se druhému neposílá. */
    public function test_soukroma_udalost_se_neposila(): void
    {
        $this->udalost(['title' => 'Veřejná']);
        $this->udalost(['title' => 'Soukromá', 'is_private' => true, 'starts_at' => '2026-09-20 09:00:00']);

        $nazvy = collect($this->getJson('/api/data/planovani')->assertOk()->json('data.CALEV'))->pluck('t');

        $this->assertTrue($nazvy->contains('Veřejná'));
        $this->assertFalse($nazvy->contains('Soukromá'));
    }

    /**
     * Nástěnka řadí podle času, ne podle seznamů.
     *
     * „Tento týden" je jediné, co dvojice ráno potřebuje vidět; „Někdy" je to,
     * co se nemá tvářit jako úkol na dnešek.
     */
    public function test_nastenka_deli_ukoly_podle_casu(): void
    {
        $this->ukol(['title' => 'Zaplatit pension', 'due_at' => now()->addDays(2)]);
        $this->ukol(['title' => 'Servis kola', 'due_at' => now()->addMonth()]);
        $this->ukol(['title' => 'Vybrat fotky', 'due_at' => null]);

        $sloupce = collect($this->getJson('/api/data/planovani')->assertOk()->json('data.ATASKS.all'));

        $this->assertSame(['Tento týden', 'Později', 'Někdy'], $sloupce->map(fn ($s) => $s[0])->all());
        $this->assertSame('Zaplatit pension', $sloupce[0][1][0][0]);
        $this->assertSame('Vybrat fotky', $sloupce[2][1][0][0]);
    }

    /**
     * Termín po datu se pojmenuje slovy, ne datem.
     *
     * Prototyp podle nich zvedá prioritu (`/po termínu|dnes/`); u data by
     * spěchající úkol vypadal jako každý jiný.
     */
    public function test_ukol_po_terminu_se_pozna(): void
    {
        $this->ukol(['title' => 'Zapomenutý', 'due_at' => now()->subDays(3)]);
        $this->ukol(['title' => 'Dnešní', 'due_at' => now()]);

        $terminy = collect($this->getJson('/api/data/planovani')->assertOk()->json('data.ATASKS.all.0.1'))
            ->mapWithKeys(fn ($r) => [$r[0] => $r[2]]);

        $this->assertSame('po termínu', $terminy['Zapomenutý']);
        $this->assertSame('dnes', $terminy['Dnešní']);
    }

    /** Úkol bez odpovědného je společný, ne ničí. */
    public function test_ukol_bez_odpovedneho_je_spolu(): void
    {
        $this->ukol(['title' => 'Společný', 'due_at' => now()->addDay(), 'assigned_to' => null]);
        $this->ukol(['title' => 'Makinčin', 'due_at' => now()->addDay(), 'assigned_to' => $this->maki->id]);

        $kdo = collect($this->getJson('/api/data/planovani')->assertOk()->json('data.ATASKS.all.0.1'))
            ->mapWithKeys(fn ($r) => [$r[0] => $r[1]]);

        $this->assertSame('spolu', $kdo['Společný']);
        $this->assertSame('Makinka', $kdo['Makinčin']);
    }

    /** Zrušený úkol na nástěnce nemá co dělat. */
    public function test_zruseny_ukol_se_na_nastenku_nedostane(): void
    {
        $this->ukol(['title' => 'Platí', 'due_at' => now()->addDay()]);
        $this->ukol(['title' => 'Zrušený', 'due_at' => now()->addDay(), 'status' => 'cancelled']);

        $nazvy = collect($this->getJson('/api/data/planovani')->assertOk()->json('data.ATASKS.all.0.1'))
            ->map(fn ($r) => $r[0]);

        $this->assertTrue($nazvy->contains('Platí'));
        $this->assertFalse($nazvy->contains('Zrušený'));
    }

    /**
     * „Až budeme mít čas" měří věk, takže potřebuje datum přidání.
     *
     * Obrazovka po roce položku sama označí za propadlou; bez `added` by
     * všechny vypadaly jako čerstvé.
     */
    public function test_seznam_nekdy_nese_vek_a_stav(): void
    {
        $this->ukol([
            'title' => 'Vyfotit a prodat kolo',
            'due_at' => null,
            'created_at' => '2024-04-02 10:00:00',
        ]);
        $this->ukol(['title' => 'Hotová věc', 'due_at' => null, 'status' => 'completed']);
        $this->ukol(['title' => 'Pustili jsme to', 'due_at' => null, 'status' => 'cancelled']);

        $radky = collect($this->getJson('/api/data/planovani')->assertOk()->json('data.LATER_ITEMS'))
            ->keyBy('text');

        $this->assertSame('2024-04-02', $radky['Vyfotit a prodat kolo']['added']);
        $this->assertSame('open', $radky['Vyfotit a prodat kolo']['state']);
        $this->assertSame('Adrian', $radky['Vyfotit a prodat kolo']['by']);
        $this->assertSame('done', $radky['Hotová věc']['state']);
        $this->assertSame('dropped', $radky['Pustili jsme to']['state']);
    }

    /** Hotové úkoly mají vlastní záložku pod nástěnkou. */
    public function test_hotove_ukoly_maji_kdo_a_kdy(): void
    {
        $this->ukol([
            'title' => 'Vysát obývák',
            'due_at' => now()->subWeek(),
            'status' => 'completed',
            'completed_at' => '2026-08-24 18:00:00',
            'completed_by' => $this->adri->id,
        ]);

        $radek = $this->getJson('/api/data/planovani')->assertOk()->json('data.AL.doneTasks.0');

        $this->assertSame(['Vysát obývák', 'Adrian · 24. 8.', 'hotovo'], $radek);
    }

    /**
     * Do „Co se změnilo" se posílá společná stopa, ne cizí den.
     *
     * Moduly si do ní zapisují samy, takže kalendář, kuchařka i úkoly jsou
     * v jednom seznamu — přesně jak to obrazovka kreslí.
     */
    public function test_co_se_zmenilo_bere_spolecnou_stopu(): void
    {
        $this->udalost(['title' => 'Plavba na Ugljan']);

        $radek = $this->getJson('/api/data/planovani')->assertOk()->json('data.EVSEED.0');

        $this->assertSame('Přidala/l do kalendáře Plavba na Ugljan', $radek[0]);
        $this->assertSame('ph-calendar-dots', $radek[1]);
        $this->assertSame('Adrian', $radek[2]);
        $this->assertSame('dnes', $radek[4]);
    }

    /** Plán jiného páru se do odpovědi nedostane. */
    public function test_plan_jineho_paru_se_neposila(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $this->udalost(['title' => 'Naše']);
        $this->udalost([
            'title' => 'Cizí',
            'gallery_space_id' => $ciziProstor->id,
            'created_by' => $cizi->id,
            'starts_at' => '2026-09-20 09:00:00',
        ]);

        $nazvy = collect($this->getJson('/api/data/planovani')->assertOk()->json('data.CALEV'))->pluck('t');

        $this->assertSame(['Naše'], $nazvy->all());
    }

    /**
     * Dokončení úkolu spouští automatizaci.
     *
     * Podmínka se ptala na stav `done`, který nikdo nikdy nezapsal — modul píše
     * `completed`. Pravidlo, které si dvojice zapnula, tak tiše nedělalo nic.
     */
    public function test_dokonceni_ukolu_spusti_pravidlo(): void
    {
        DB::table('automation_rules')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'name' => 'Po úklidu zapsat do deníku',
            'trigger' => 'todo.completed',
            'action' => 'todo.create',
            'action_config' => json_encode(['title' => 'Zkontrolovat, co zbylo', 'due_in_days' => 1]),
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ukol = $this->ukol(['title' => 'Vysát obývák', 'due_at' => now()->addDay()]);

        app(\App\Services\Planning\SharedTodoService::class)->complete($ukol, $this->adri, true);

        $this->assertDatabaseHas('shared_todos', [
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Zkontrolovat, co zbylo',
        ]);
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

    private function ukol(array $navic = []): SharedTodo
    {
        $ukol = SharedTodo::create(array_merge([
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Úkol',
            'status' => 'open',
            'priority' => 'normal',
        ], $navic));

        // `created_at` plní model sám; „až budeme mít čas" na něm ale stojí,
        // takže si ho test musí umět nastavit.
        if (isset($navic['created_at'])) {
            $ukol->forceFill(['created_at' => $navic['created_at']])->saveQuietly();
        }

        return $ukol->refresh();
    }
}
