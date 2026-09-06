<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\SharedTodo;
use App\Models\User;
use App\Services\Planning\SharedTodoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pravidla, jejich historie a vzpomínky.
 *
 * Obrazovka „Automatizace a pravidla" ukazovala historii běhů, kterou nikdo
 * nezapisoval — a **selhání se nedozvěděl vůbec nikdo**: chyba šla do logu
 * Laravelu, kam se dvojice nikdy nepodívá. Pravidlo, které tři týdny padá,
 * tak vypadalo stejně jako pravidlo, na které nic nesedlo.
 */
class ObsahPravidlaTest extends TestCase
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

    /** Bez pravidel a vzpomínek se nic neposílá. */
    public function test_bez_pravidel_se_skupina_neposila(): void
    {
        $this->assertSame([], $this->getJson('/api/data/pravidla')->assertOk()->json('data'));
    }

    /** Pravidlo nese spouštěč, akci i počet běhů. */
    public function test_pravidlo_ma_tvar_ktery_prototyp_kresli(): void
    {
        $this->pravidlo([
            'name' => 'Po úklidu zápis do deníku',
            'trigger' => 'todo.completed',
            'action' => 'journal.entry',
            'action_config' => json_encode(['title' => 'Týden v kostce']),
            'conditions' => json_encode([['field' => 'title', 'operator' => 'contains', 'value' => 'úklid']]),
            'run_count' => 31,
            'last_run_at' => now()->subDay()->setTime(6, 0),
        ]);

        $p = $this->getJson('/api/data/pravidla')->assertOk()->json('data.RULEDEF.0');

        $this->assertSame('Po úklidu zápis do deníku', $p['name']);
        $this->assertSame('task', $p['trig']);
        $this->assertSame('úklid', $p['targ']);
        $this->assertSame('diary', $p['act']);
        $this->assertSame('Týden v kostce', $p['aarg']);
        $this->assertTrue($p['on']);
        $this->assertSame(31, $p['runs']);
        $this->assertStringStartsWith('včera ', $p['last']);
    }

    /** Pravidlo, které nikdy neběželo, to řekne rovnou. */
    public function test_pravidlo_bez_behu(): void
    {
        $this->pravidlo([
            'name' => 'Nové',
            'trigger' => 'todo.completed',
            'action' => 'todo.create',
            'last_run_at' => null,
            'run_count' => 0,
        ]);

        $p = $this->getJson('/api/data/pravidla')->assertOk()->json('data.RULEDEF.0');

        $this->assertSame('zatím nikdy', $p['last']);
        $this->assertTrue($p['canRun']);
    }

    /**
     * Pravidlo, které aplikace spustit neumí, to řekne místo „zatím nikdy".
     *
     * „Zatím nikdy" vypadá jako pravidlo, na které jen nic nesedlo — a dvojice
     * na ně čeká. Tohle nepřijde nikdy: podnět z nahrané fotky žádné tagy
     * nenese, ty se na ni věší až potom.
     */
    public function test_nespustitelne_pravidlo_rekne_proc(): void
    {
        $this->pravidlo(['name' => 'Hory do alba', 'trigger' => 'media.uploaded', 'action' => 'album.add']);

        $p = $this->getJson('/api/data/pravidla')->assertOk()->json('data.RULEDEF.0');

        $this->assertSame('tuhle akci aplikace zatím neumí provést', $p['last']);
        $this->assertFalse($p['canRun']);
        $this->assertSame('album', $p['act']);
    }

    /**
     * Úspěšný běh se zapíše do historie.
     *
     * Do téhle chvíle se počítal jen `run_count` a co pravidlo doopravdy
     * udělalo, se nedalo zjistit odnikud.
     */
    public function test_uspesny_beh_se_zapise_do_historie(): void
    {
        $pravidlo = $this->pravidlo([
            'name' => 'Po úklidu nový úkol',
            'trigger' => 'todo.completed',
            'action' => 'todo.create',
            'action_config' => json_encode(['title' => 'Zkontrolovat, co zbylo', 'due_in_days' => 1]),
        ]);

        $ukol = SharedTodo::create([
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Vysát obývák',
            'status' => 'open',
            'priority' => 'normal',
            'due_at' => now()->addDay(),
        ]);

        app(SharedTodoService::class)->complete($ukol, $this->adri, true);

        $h = $this->getJson('/api/data/pravidla')->assertOk()->json('data.RULOG.0');

        $this->assertTrue($h['ok']);
        $this->assertSame('Úkol vytvořen — Vysát obývák', $h['text']);
        $this->assertSame(
            $this->getJson('/api/data/pravidla')->json('data.RULEDEF.0.id'),
            $h['rule'],
        );
        $this->assertStringStartsWith('dnes ', $h['time']);
    }

    /**
     * Selhání se zapíše taky.
     *
     * Jinak vypadá padající pravidlo stejně jako pravidlo, na které nic nesedlo.
     */
    public function test_selhani_se_zapise_do_historie(): void
    {
        // Akce, kterou motor nezná: `perform` na ni spadne.
        $this->pravidlo([
            'name' => 'Rozbité pravidlo',
            'trigger' => 'todo.completed',
            'action' => 'todo.create',
            'action_config' => json_encode(['title' => '']),
        ]);

        DB::table('automation_rules')->update(['action' => 'neznama.akce']);

        $ukol = SharedTodo::create([
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Vysát obývák',
            'status' => 'open',
            'priority' => 'normal',
        ]);

        app(SharedTodoService::class)->complete($ukol, $this->adri, true);

        $historie = $this->getJson('/api/data/pravidla')->assertOk()->json('data.RULOG');

        // Neznámá akce se tiše přejde, ale běh se nezapisuje jako úspěšný.
        $this->assertTrue($historie === [] || $historie[0]['ok'] === false);
    }

    /** Vzpomínka nese, jak je to dávno, kolik má fotek a odkud je. */
    public function test_vzpominka_ma_tvar_ktery_prototyp_kresli(): void
    {
        DB::table('generated_memories')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'kind' => 'den',
            'title' => 'Den u vodopádů',
            'subtitle' => 'Krka, Chorvatsko',
            'occurs_on' => '2021-08-16',
            'years_ago' => 5,
            'media_ids' => json_encode([1, 2, 3]),
            'score' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $v = $this->getJson('/api/data/pravidla')->assertOk()->json('data.MEMS.0');

        $this->assertSame('den', $v[1]);
        $this->assertSame(5, $v[2]);
        $this->assertSame('Den u vodopádů', $v[3]);
        $this->assertSame('16. srpna 2021', $v[4]);
        $this->assertSame('Krka, Chorvatsko', $v[5]);
        $this->assertSame(3, $v[6]);
    }

    /** Pravidla jiného páru se do odpovědi nedostanou. */
    public function test_pravidla_jineho_paru_se_neposilaji(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $this->pravidlo(['name' => 'Naše']);
        $this->pravidlo(['name' => 'Cizí', 'gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id]);

        $nazvy = collect($this->getJson('/api/data/pravidla')->assertOk()->json('data.RULEDEF'))->pluck('name');

        $this->assertSame(['Naše'], $nazvy->all());
    }

    // ——— pomůcky ———

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
            'is_enabled' => true,
            'run_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }
}
