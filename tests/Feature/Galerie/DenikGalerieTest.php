<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Cas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deník z počítače končí v `journal_entries` a řídí se soukromím zápisu.
 */
class DenikGalerieTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian Stanek']);
        $this->maki = User::factory()->create(['name' => 'Makinka Kubíčková']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    /**
     * „Přidat milník" zapíše milník vztahu a ten je hned na ose.
     *
     * Dřív zakládalo nápad na dárek „Nový milník" — osa ho nikdy neukázala.
     */
    public function test_milnik_se_zapise_na_osu(): void
    {
        $odpoved = $this->postJson('/api/milniky', ['nazev' => 'Poprvé jsme se potkali', 'datum' => '18. 10. 2016'])
            ->assertStatus(201)
            ->assertJsonPath('zprava', 'Milník „Poprvé jsme se potkali“ je na ose · 18. 10. 2016');

        $this->assertSame('2016-10-18', (string) DB::table('relationship_milestones')->value('occurred_on'));
        $this->assertSame('shared', DB::table('relationship_milestones')->value('visibility'));
        $this->assertSame('Poprvé jsme se potkali', $odpoved->json('data.ADIARY.ms.0.1'));
        $this->assertSame(0, DB::table('gift_ideas')->count(), 'Milník není nápad na dárek.');

        $this->postJson('/api/milniky', ['nazev' => 'Nesmysl', 'datum' => '31. 2. 2020'])->assertStatus(422);
        $this->postJson('/api/milniky', ['nazev' => 'Bez data', 'datum' => 'loni'])->assertStatus(422);
    }

    public function test_novy_zapis_se_ulozi_a_vrati_s_identifikatorem(): void
    {
        $odpoved = $this->postJson('/api/denik', [
            'nadpis' => 'Nad mlhou', 'text' => 'Vstávání ve čtyři se vyplatilo.',
            'nalada' => 'klid', 'soukromy' => false, 'datum' => now()->subDay()->toDateString(),
        ])->assertStatus(201);

        $zapis = JournalEntry::withoutGlobalScopes()->sole();
        $this->assertSame('shared', $zapis->visibility);
        $this->assertNotNull($zapis->shared_at);
        $this->assertSame(now()->subDay()->toDateString(), $zapis->entry_date->toDateString());

        $radek = $odpoved->json('data.ADIARY.diary.0');
        $this->assertSame('Nad mlhou', $radek[1]);
        $this->assertSame('nálada klid', $radek[3]);
        $this->assertSame($zapis->uuid, $radek[4]['uuid']);
        $this->assertFalse($radek[4]['priv']);
        $this->assertSame('A', $radek[4]['who']);
    }

    public function test_soukromy_zapis_druhy_nevidi_ani_nezmeni(): void
    {
        $this->postJson('/api/denik', ['nadpis' => 'Jen moje', 'text' => 'Tajné.', 'soukromy' => true])->assertStatus(201);
        $uuid = JournalEntry::withoutGlobalScopes()->value('uuid');

        Sanctum::actingAs($this->maki);

        $this->assertSame([], $this->getJson('/api/data/denik')->assertOk()->json('data.ADIARY.diary') ?? []);
        $this->patchJson('/api/denik/'.$uuid, ['nadpis' => 'Cizí', 'text' => 'x'])->assertNotFound();
        $this->deleteJson('/api/denik/'.$uuid)->assertNotFound();
        $this->assertSame('Jen moje', JournalEntry::withoutGlobalScopes()->value('title'));
    }

    public function test_sdileny_cizi_zapis_upravit_nejde(): void
    {
        $this->postJson('/api/denik', ['nadpis' => 'Společný', 'text' => 'Pro oba.', 'soukromy' => false])->assertStatus(201);
        $uuid = JournalEntry::withoutGlobalScopes()->value('uuid');

        Sanctum::actingAs($this->maki);

        $radek = $this->getJson('/api/data/denik')->assertOk()->json('data.ADIARY.diary.0');
        $this->assertSame('M', $radek[4]['who']);
        $this->assertFalse($radek[4]['mine']);

        $this->patchJson('/api/denik/'.$uuid, ['nadpis' => 'Přepsáno', 'text' => 'x'])->assertForbidden();
        $this->deleteJson('/api/denik/'.$uuid)->assertForbidden();
    }

    public function test_uprava_a_smazani_vlastniho_zapisu(): void
    {
        $this->postJson('/api/denik', ['nadpis' => 'Původní', 'text' => 'Text.', 'soukromy' => false])->assertStatus(201);
        $uuid = JournalEntry::withoutGlobalScopes()->value('uuid');

        $this->patchJson('/api/denik/'.$uuid, ['nadpis' => 'Nový', 'text' => 'Jiný text.', 'soukromy' => true])
            ->assertOk()->assertJsonPath('data.ADIARY.diary.0.1', 'Nový');

        $zapis = JournalEntry::withoutGlobalScopes()->sole();
        $this->assertSame('private', $zapis->visibility);
        $this->assertNull($zapis->shared_at);

        // Smazaný zápis se po obnovení nevrátí (DB::table o měkkém mazání neví).
        $this->deleteJson('/api/denik/'.$uuid)->assertOk();
        $this->assertSame([], $this->getJson('/api/data/denik')->assertOk()->json('data.ADIARY.diary') ?? []);
    }

    public function test_budouci_datum_a_prazdny_text_neprojdou(): void
    {
        $this->postJson('/api/denik', ['nadpis' => 'Zítra', 'text' => 'x', 'datum' => Cas::dnes()->addDay()->toDateString()])->assertStatus(422);
        $this->postJson('/api/denik', ['nadpis' => 'Prázdný', 'text' => ''])->assertStatus(422);
        $this->postJson('/api/denik', ['nadpis' => 'Nálada', 'text' => 'x', 'nalada' => 'hurá'])->assertStatus(422);
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->count());
    }

    /**
     * Po půlnoci v Praze je „dnes" už nový den.
     *
     * Ve 23:30 UTC server odmítal dnešní pražské datum jako budoucí a zápis
     * bez data dostal včerejšek.
     */
    public function test_dnes_po_pulnoci_podle_prahy(): void
    {
        config(['app.display_timezone' => 'Europe/Prague']);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 23:30:00', 'UTC'));

        $this->postJson('/api/denik', ['nadpis' => 'S datem', 'text' => 'x', 'datum' => '2026-10-09'])->assertStatus(201);
        $this->postJson('/api/denik', ['nadpis' => 'Bez data', 'text' => 'x'])->assertStatus(201);
        $this->postJson('/api/denik', ['nadpis' => 'Pozítří', 'text' => 'x', 'datum' => '2026-10-10'])->assertStatus(422);

        $this->assertSame(['2026-10-09'], JournalEntry::withoutGlobalScopes()->get()
            ->map(fn (JournalEntry $z) => $z->entry_date->toDateString())->unique()->values()->all());
    }

    public function test_cizi_prostor_je_nedostupny(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $uuid = (string) Str::uuid();
        DB::table('journal_entries')->insert([
            'uuid' => $uuid, 'gallery_space_id' => $ciziProstor->id, 'created_by' => $this->adri->id,
            'title' => 'Cizí', 'body' => 'x', 'entry_date' => now()->toDateString(), 'visibility' => 'shared',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->deleteJson('/api/denik/'.$uuid)->assertNotFound();
        $this->assertSame(1, DB::table('journal_entries')->whereNull('deleted_at')->count());
    }

    public function test_hlasovka_nese_identifikator_a_prepis(): void
    {
        $uuid = (string) Str::uuid();
        DB::table('voice_notes')->insert([
            'uuid' => $uuid, 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
            'title' => 'Ráno', 'path' => 'hlasovky/x.webm', 'mime_type' => 'audio/webm', 'size_bytes' => 10,
            'duration_ms' => 12000, 'transcript' => 'Dobré ráno.', 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $radek = $this->getJson('/api/data/denik')->assertOk()->json('data.AL.voice.0');
        $this->assertSame('Ráno', $radek[0]);
        $this->assertSame('přepsáno', $radek[2]);
        $this->assertSame($uuid, $radek[7]);
        $this->assertSame('Dobré ráno.', $radek[8]);
    }
}
