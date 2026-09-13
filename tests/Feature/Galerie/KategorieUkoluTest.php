<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\SharedTodo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kategorie úkolů jako seznamy v databázi.
 *
 * Dřív žila jen ve stavu prohlížeče: úkoly v ní se nezapsaly a po obnovení
 * stránky nástěnka kategorie ukazovala všechny úkoly dvojice.
 */
class KategorieUkoluTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $maki = User::factory()->create(['name' => 'Makinka']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    public function test_kategorie_se_zalozi_a_ukol_v_ni_patri_jen_do_ni(): void
    {
        $odpoved = $this->postJson('/api/ukoly/kategorie', ['nazev' => 'Zahrada'])
            ->assertStatus(201)
            ->assertJsonPath('zprava', 'Kategorie „Zahrada“ založena');

        $klic = $odpoved->json('klic');
        $this->assertStringStartsWith('seznam-', $klic);
        $this->assertSame([['key' => $klic, 'label' => 'Zahrada']], $odpoved->json('data.PLANCATS'));
        // Prázdná nástěnka kategorie přijde — prototyp by jinak ukázal hlavní.
        $this->assertSame([], $odpoved->json('data.ATASKS.'.$klic));

        $this->patchJson('/api/state', ['data' => [
            'xBoard' => [$klic => [['label' => 'Tento týden', 'items' => [
                ['id' => $klic.'-n1', 't' => 'Zastřihnout keře', 'w' => 'Adrian', 'd' => 'zítra'],
            ]]]],
            'xBoardZmenene' => [$klic.'-n1'],
        ]])->assertOk();

        $ukol = SharedTodo::where('title', 'Zastřihnout keře')->sole();
        $seznam = DB::table('shared_todo_lists')->where('title', 'Zahrada')->first();
        $this->assertSame((int) $seznam->id, (int) $ukol->list_id);

        $this->ukol('Mimo kategorii');
        $data = $this->getJson('/api/data/planovani')->assertOk()->json('data');

        $vKategorii = json_encode($data['ATASKS'][$klic], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Zastřihnout keře', $vKategorii);
        $this->assertStringNotContainsString('Mimo kategorii', $vKategorii);
        // Hlavní nástěnka ukazuje všechno.
        $this->assertStringContainsString('Zastřihnout keře', json_encode($data['ATASKS']['all'], JSON_UNESCAPED_UNICODE));
    }

    public function test_prejmenovani_a_smazani_kategorie(): void
    {
        $klic = $this->postJson('/api/ukoly/kategorie', ['nazev' => 'Rekonstrukce'])->json('klic');
        $uuid = substr($klic, strlen('seznam-'));
        $seznamId = DB::table('shared_todo_lists')->where('uuid', $uuid)->value('id');

        $this->patchJson('/api/ukoly/kategorie/'.$uuid, ['nazev' => 'Koupelna'])
            ->assertOk()
            ->assertJsonPath('data.PLANCATS.0.label', 'Koupelna');

        $otevreny = $this->ukol('Vybrat obklady', ['list_id' => $seznamId]);
        $hotovy = $this->ukol('Změřit koupelnu', ['list_id' => $seznamId, 'status' => 'completed', 'completed_at' => now()]);
        $jinde = $this->ukol('Nákup');

        $odpoved = $this->deleteJson('/api/ukoly/kategorie/'.$uuid)
            ->assertOk()
            ->assertJsonPath('zprava', 'Kategorie „Koupelna“ smazána · 1 otevřený úkol zrušen');

        // Po smazání poslední kategorie přijde prázdný seznam, ne nic.
        $this->assertSame([], $odpoved->json('data.PLANCATS'));
        $this->assertSame('cancelled', $otevreny->refresh()->status);
        $this->assertSame('completed', $hotovy->refresh()->status);
        $this->assertSame('open', $jinde->refresh()->status);
        $this->assertNotNull(DB::table('shared_todo_lists')->where('uuid', $uuid)->value('archived_at'));

        $this->deleteJson('/api/ukoly/kategorie/'.$uuid)->assertNotFound();
    }

    public function test_domacnost_ani_cizi_kategorie_se_upravit_nedaji(): void
    {
        $domaci = (string) Str::uuid();
        DB::table('shared_todo_lists')->insert([
            'uuid' => $domaci, 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
            'title' => 'Domácnost', 'kind' => 'household', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->deleteJson('/api/ukoly/kategorie/'.$domaci)->assertNotFound();

        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $ciziProstor->members()->syncWithoutDetaching([$cizi->id => ['role' => 'owner']]);
        $ciziKategorie = (string) Str::uuid();
        DB::table('shared_todo_lists')->insert([
            'uuid' => $ciziKategorie, 'gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id,
            'title' => 'Tajné', 'kind' => 'kategorie', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->patchJson('/api/ukoly/kategorie/'.$ciziKategorie, ['nazev' => 'Moje'])->assertNotFound();
        $this->assertSame('Tajné', DB::table('shared_todo_lists')->where('uuid', $ciziKategorie)->value('title'));
        $this->postJson('/api/ukoly/kategorie', ['nazev' => '   '])->assertStatus(422);
    }

    private function ukol(string $nazev, array $navic = []): SharedTodo
    {
        return SharedTodo::create(array_merge([
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => $nazev,
            'status' => 'open',
            'priority' => 'normal',
            'due_at' => now()->addDay(),
        ], $navic));
    }
}
