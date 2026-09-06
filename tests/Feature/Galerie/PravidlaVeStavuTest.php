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
 * Pravidla založená na obrazovce se opravdu založí.
 *
 * `rulesAll()` v prototypu vrací `state.rules || RULEDEF`, takže jedno
 * přepnutí vypínače navždy zastínilo skutečná pravidla ze serveru: obrazovka
 * od té chvíle kreslila kopii z prohlížeče, nová pravidla nikde nedoběhla
 * a ta skutečná z ní zmizela.
 */
class PravidlaVeStavuTest extends TestCase
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

    /** Nové pravidlo z obrazovky vznikne v tabulce, ne ve stavu. */
    public function test_nove_pravidlo_vznikne_v_tabulce(): void
    {
        $odpoved = $this->stav(['rules' => [[
            'id' => 'r1757000000000',
            'name' => 'Po úklidu zápis',
            'trig' => 'task',
            'targ' => 'úklid',
            'act' => 'diary',
            'aarg' => 'Týden v kostce',
            'on' => true,
            'who' => 'Makinka',
        ]]])->assertOk();

        $radek = DB::table('automation_rules')->where('gallery_space_id', $this->prostor->id)->first();

        $this->assertSame('Po úklidu zápis', $radek->name);
        $this->assertSame('todo.completed', $radek->trigger);
        $this->assertSame('journal.entry', $radek->action);
        $this->assertSame($this->maki->id, $radek->created_by);
        $this->assertSame(
            [['field' => 'title', 'operator' => 'contains', 'value' => 'úklid']],
            json_decode($radek->conditions, true),
        );

        // Ve stavu klíč nezůstane — jedinou pravdou je tabulka.
        $this->assertArrayNotHasKey('rules', (array) $this->getJson('/api/state')->assertOk()->json('data'));
        // Odpověď ale seznam nese, aby obrazovka neblikla.
        $this->assertSame('Po úklidu zápis', $odpoved->json('data.rules.0.name'));
        $this->assertNotSame('r1757000000000', $odpoved->json('data.rules.0.id'));
        // A klient ho nemá ukládat ani k sobě — jinak by při dalším spuštění
        // stará kopie zase zastínila skutečná pravidla.
        $this->assertContains('rules', $odpoved->json('docasne'));
    }

    /** Vypnutí pravidla je vypnutí pravidla, ne jen v prohlížeči. */
    public function test_vypnuti_se_ulozi(): void
    {
        $uuid = (string) Str::uuid();
        $this->pravidlo(['uuid' => $uuid, 'name' => 'Hory do alba', 'is_enabled' => true]);

        $this->stav(['rules' => [[
            'id' => $uuid, 'name' => 'Hory do alba', 'trig' => 'tag', 'targ' => 'hory',
            'act' => 'album', 'aarg' => 'Hory 2026', 'on' => false, 'who' => 'oba',
        ]]])->assertOk();

        $this->assertFalse((bool) DB::table('automation_rules')->where('uuid', $uuid)->value('is_enabled'));
    }

    /** Co v seznamu není, dvojice smazala. */
    public function test_chybejici_pravidlo_se_smaze(): void
    {
        $zustane = (string) Str::uuid();
        $this->pravidlo(['uuid' => $zustane, 'name' => 'Zůstává']);
        $this->pravidlo(['uuid' => (string) Str::uuid(), 'name' => 'Mizí']);

        $this->stav(['rules' => [[
            'id' => $zustane, 'name' => 'Zůstává', 'trig' => 'task', 'targ' => '',
            'act' => 'task', 'aarg' => 'Něco', 'on' => true, 'who' => 'oba',
        ]]])->assertOk();

        $this->assertSame(
            ['Zůstává'],
            DB::table('automation_rules')->where('gallery_space_id', $this->prostor->id)->pluck('name')->all(),
        );
    }

    /**
     * Historie z prohlížeče se nepřebírá vůbec.
     *
     * Prototyp do ní psal větu o tom, co by se bývalo stalo. Zápis o něčem,
     * co neproběhlo, je horší než prázdná historie.
     */
    public function test_historie_z_prohlizece_se_nepreveze(): void
    {
        $this->pravidlo(['name' => 'Pravidlo']);

        $odpoved = $this->stav(['ruleLog' => [
            ['id' => 'l1', 'rule' => 'r1', 'time' => 'právě teď', 'text' => 'Do albumu přidáno 6 fotek', 'ok' => true],
        ]])->assertOk();

        $this->assertSame(0, DB::table('automation_runs')->count());
        $this->assertArrayNotHasKey('ruleLog', (array) $this->getJson('/api/state')->assertOk()->json('data'));
        $this->assertNull($odpoved->json('data.ruleLog'));
    }

    /** „Spustit teď" úkol doopravdy založí — a zapíše to do historie. */
    public function test_rucni_spusteni_provede_akci(): void
    {
        $uuid = (string) Str::uuid();
        $this->pravidlo([
            'uuid' => $uuid,
            'name' => 'Projít rozpočet',
            'trigger' => 'todo.completed',
            'action' => 'todo.create',
            'action_config' => json_encode(['title' => 'Projít rozpočet']),
        ]);

        $odpoved = $this->postJson('/api/pravidla/'.$uuid.'/spustit')->assertOk();

        $this->assertTrue($odpoved->json('ok'));
        $this->assertSame('Projít rozpočet', DB::table('shared_todos')->value('title'));
        $this->assertSame('automation', DB::table('shared_todos')->value('created_from'));
        $this->assertSame(1, (int) DB::table('automation_rules')->where('uuid', $uuid)->value('run_count'));

        $beh = DB::table('automation_runs')->first();
        $this->assertTrue((bool) $beh->succeeded);
        $this->assertStringContainsString('ručně', $beh->message);
        // Odpověď nese novou historii, aby ji obrazovka ukázala hned.
        $this->assertSame('run-'.$beh->id, $odpoved->json('ruleLog.0.id'));
    }

    /** Akci, kterou aplikace neumí, ručně nespustí ani ona. */
    public function test_rucni_spusteni_nezname_akce_odmitne(): void
    {
        $uuid = (string) Str::uuid();
        $this->pravidlo(['uuid' => $uuid, 'action' => 'album.add']);

        $this->postJson('/api/pravidla/'.$uuid.'/spustit')
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('zprava', 'Tuhle akci aplikace zatím neumí provést.');

        $this->assertSame(0, DB::table('shared_todos')->count());
        $this->assertSame(0, DB::table('automation_runs')->count());
    }

    /** Cizí pravidlo nespustí nikdo. */
    public function test_cizi_pravidlo_spustit_nejde(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $uuid = (string) Str::uuid();
        $this->pravidlo(['uuid' => $uuid, 'gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id]);

        $this->postJson('/api/pravidla/'.$uuid.'/spustit')->assertNotFound();
    }

    // ——— pomůcky ———

    private function stav(array $patch)
    {
        return $this->patchJson('/api/state', ['data' => $patch]);
    }

    private function pravidlo(array $navic = []): int
    {
        return DB::table('automation_rules')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'name' => 'Pravidlo',
            'trigger' => 'media.uploaded',
            'action' => 'todo.create',
            'action_config' => json_encode(['title' => 'Něco']),
            'conditions' => json_encode([]),
            'is_enabled' => true,
            'run_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }
}
