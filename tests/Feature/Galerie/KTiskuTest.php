<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;
use ZipArchive;

/**
 * Fotky „k tisku" — rychlá ikona, stažení všech najednou a historie sad.
 *
 * Dvojice chtěla fotku jedním ťuknutím označit k tisku a pak všechny označené
 * stáhnout do telefonu. Co se jednou stáhlo, zůstane jako sada, kterou jde
 * stáhnout znovu. Označení je společné (oba vidí totéž) a neleží ve sdíleném
 * stavu, ale ve vlastních tabulkách.
 */
class KTiskuTest extends TestCase
{
    use DvojiceSHostem, RefreshDatabase;

    private User $adri;

    private User $makinka;

    private User $host;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        [$this->adri, $this->makinka, $this->host, $this->prostor] = $this->dvojiceSHostem();
        $this->adri->update(['name' => 'Adrian']);
        $this->makinka->update(['name' => 'Makinka']);

        Sanctum::actingAs($this->adri);
    }

    // ——— označení ———

    public function test_fotku_jde_oznacit_a_odznacit(): void
    {
        $f = $this->foto('IMG_1.jpg');

        $this->postJson('/api/k-tisku/oznacene', ['id' => $f->uuid])
            ->assertOk()
            ->assertJsonPath('oznaceno', true)
            ->assertJsonPath('pocet', 1);

        $radek = DB::table('print_marks')->first();
        $this->assertSame($this->prostor->id, (int) $radek->gallery_space_id);
        $this->assertSame($f->id, (int) $radek->media_item_id);
        $this->assertSame($this->adri->id, (int) $radek->marked_by);

        // Druhé označení nic nezdvojí.
        $this->postJson('/api/k-tisku/oznacene', ['id' => $f->uuid])->assertOk()->assertJsonPath('pocet', 1);
        $this->assertSame(1, DB::table('print_marks')->count());

        $this->deleteJson('/api/k-tisku/oznacene/'.$f->uuid)
            ->assertOk()
            ->assertJsonPath('oznaceno', false)
            ->assertJsonPath('pocet', 0);

        $this->assertSame(0, DB::table('print_marks')->count());
    }

    /** Oba z dvojice vidí tentýž seznam — označení patří prostoru, ne člověku. */
    public function test_oznaceni_je_spolecne_pro_dvojici(): void
    {
        $f = $this->foto('IMG_1.jpg');
        $this->postJson('/api/k-tisku/oznacene', ['id' => $f->uuid])->assertOk();

        Sanctum::actingAs($this->makinka);

        $this->getJson('/api/k-tisku')
            ->assertOk()
            ->assertJsonPath('pocet', 1)
            ->assertJsonPath('oznacene.0.id', $f->uuid);

        // Knihovna u dlaždice ukáže ikonu i druhému.
        $fotky = collect($this->getJson('/api/data/knihovna')->assertOk()->json('data.PHOTOS'));
        $this->assertTrue((bool) ($fotky->firstWhere('id', $f->uuid)['tisk'] ?? false));
        $mobil = collect($this->getJson('/api/data/knihovna')->json('data.MOBIL.PHOTOS'));
        $this->assertTrue((bool) ($mobil->firstWhere('id', $f->uuid)['tisk'] ?? false));

        // A odznačit ji může i ten druhý.
        $this->deleteJson('/api/k-tisku/oznacene/'.$f->uuid)->assertOk()->assertJsonPath('pocet', 0);
    }

    /** Neoznačená fotka pole `tisk` v dlaždici nemá — dvě stě dlaždic × `false` jsou zbytečné bajty. */
    public function test_neoznacena_dlazdice_pole_nenese(): void
    {
        $f = $this->foto('IMG_1.jpg');

        $fotky = collect($this->getJson('/api/data/knihovna')->assertOk()->json('data.PHOTOS'));
        $this->assertArrayNotHasKey('tisk', $fotky->firstWhere('id', $f->uuid));
    }

    public function test_host_k_tisku_nesmi(): void
    {
        $f = $this->foto('IMG_1.jpg');

        Sanctum::actingAs($this->host);

        $this->postJson('/api/k-tisku/oznacene', ['id' => $f->uuid])->assertForbidden();
        $this->getJson('/api/k-tisku')->assertForbidden();
        $this->postJson('/api/k-tisku/sady')->assertForbidden();
        $this->assertSame(0, DB::table('print_marks')->count());
    }

    /** Fotka z trezoru se neoznačí — se zamčeným trezorem se tváří jako neexistující. */
    public function test_trezor_kos_video_a_cizi_fotka_se_neoznaci(): void
    {
        $skryta = $this->foto('TREZOR.jpg', ['is_hidden' => true]);
        $vKosi = $this->foto('KOS.jpg', ['trashed_at' => now()]);
        $video = $this->foto('VIDEO.mp4', ['media_type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4']);

        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $ciziFoto = $this->fotka($ciziProstor, $cizi, 'CIZI.jpg');

        $this->postJson('/api/k-tisku/oznacene', ['id' => $skryta->uuid])->assertNotFound();
        $this->postJson('/api/k-tisku/oznacene', ['id' => $vKosi->uuid])->assertNotFound();
        $this->postJson('/api/k-tisku/oznacene', ['id' => $video->uuid])->assertStatus(422);
        $this->postJson('/api/k-tisku/oznacene', ['id' => $ciziFoto->uuid])->assertNotFound();
        $this->postJson('/api/k-tisku/oznacene', ['id' => 'nesmysl'])->assertNotFound();

        $this->assertSame(0, DB::table('print_marks')->count());
    }

    /** S odemčeným trezorem se neoznačí taky — vysvětlí proč. */
    public function test_ani_s_odemcenym_trezorem_se_skryta_neoznaci(): void
    {
        $skryta = $this->foto('TREZOR.jpg', ['is_hidden' => true]);

        $this->sOdemcenymTrezorem($this->adri)
            ->postJson('/api/k-tisku/oznacene', ['id' => $skryta->uuid])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Fotky z trezoru se k tisku neoznačují — nejdřív ji z trezoru vyjměte.');
    }

    /** Označená fotka, která mezitím skončila v koši nebo v trezoru, se v seznamu neukáže. */
    public function test_seznam_vynecha_kos_a_trezor(): void
    {
        $a = $this->foto('A.jpg');
        $b = $this->foto('B.jpg');
        $c = $this->foto('C.jpg');
        foreach ([$a, $b, $c] as $f) {
            $this->postJson('/api/k-tisku/oznacene', ['id' => $f->uuid])->assertOk();
        }

        $b->forceFill(['trashed_at' => now()])->save();
        $c->forceFill(['is_hidden' => true])->save();

        $this->getJson('/api/k-tisku')
            ->assertOk()
            ->assertJsonPath('pocet', 1)
            ->assertJsonCount(1, 'oznacene')
            ->assertJsonPath('oznacene.0.id', $a->uuid);
    }

    // ——— sada ———

    /** „Stáhnout vše" založí sadu, označení se do ní přesunou a seznam se vyprázdní. */
    public function test_stazeni_zalozi_sadu_a_vyprazdni_seznam(): void
    {
        $this->travelTo(now()->setTimezone('Europe/Prague')->setDate(2026, 9, 27)->setTime(14, 0));

        $a = $this->foto('A.jpg');
        $b = $this->foto('B.jpg');
        $this->postJson('/api/k-tisku/oznacene', ['id' => $a->uuid])->assertOk();
        $this->postJson('/api/k-tisku/oznacene', ['id' => $b->uuid])->assertOk();

        $odpoved = $this->postJson('/api/k-tisku/sady')
            ->assertCreated()
            ->assertJsonPath('sada.nazev', 'K tisku 27. 9. 2026')
            ->assertJsonPath('sada.pocet', 2)
            ->assertJsonPath('sada.stazeniPocet', 0)
            ->assertJsonCount(2, 'soubory');

        $this->assertSame([$a->uuid, $b->uuid], array_column($odpoved->json('soubory'), 'id'));
        $this->assertSame('media/'.$a->uuid.'/raw', $odpoved->json('soubory.0.cesta'));
        $this->assertSame('A.jpg', $odpoved->json('soubory.0.name'));

        $this->assertSame(0, DB::table('print_marks')->count());
        $this->assertSame(1, DB::table('print_batches')->count());
        $this->assertSame(2, DB::table('print_batch_items')->count());

        $this->getJson('/api/k-tisku')
            ->assertOk()
            ->assertJsonPath('pocet', 0)
            ->assertJsonCount(1, 'sady')
            ->assertJsonPath('sady.0.nazev', 'K tisku 27. 9. 2026')
            ->assertJsonPath('sady.0.kdo', 'Adrian');

        // Druhá sada téhož dne má pořadí v názvu i v archivu.
        $this->postJson('/api/k-tisku/oznacene', ['id' => $a->uuid])->assertOk();
        $this->postJson('/api/k-tisku/sady')->assertCreated()->assertJsonPath('sada.nazev', 'K tisku 27. 9. 2026 (2)');
        $this->assertSame(['K-tisku-2026-09-27', 'K-tisku-2026-09-27-2'],
            DB::table('print_batches')->orderBy('id')->pluck('file_stem')->all());
    }

    public function test_bez_oznacenych_se_sada_nezalozi(): void
    {
        $this->postJson('/api/k-tisku/sady')->assertStatus(422);
        $this->assertSame(0, DB::table('print_batches')->count());
    }

    /** Sadu jde stáhnout znovu — tentýž výběr, i když se mezitím označilo něco dalšího. */
    public function test_stazeni_znovu_vrati_stejny_vyber(): void
    {
        $a = $this->foto('A.jpg');
        $b = $this->foto('B.jpg');
        $this->postJson('/api/k-tisku/oznacene', ['id' => $a->uuid])->assertOk();
        $this->postJson('/api/k-tisku/oznacene', ['id' => $b->uuid])->assertOk();
        $sada = $this->postJson('/api/k-tisku/sady')->assertCreated()->json('sada.id');

        // Nové označení do staré sady nepatří.
        $c = $this->foto('C.jpg');
        $this->postJson('/api/k-tisku/oznacene', ['id' => $c->uuid])->assertOk();

        Sanctum::actingAs($this->makinka);

        $znovu = $this->getJson('/api/k-tisku/sady/'.$sada)->assertOk();
        $this->assertSame([$a->uuid, $b->uuid], array_column($znovu->json('soubory'), 'id'));

        $this->postJson('/api/k-tisku/sady/'.$sada.'/stazeno')->assertOk()->assertJsonPath('sada.stazeniPocet', 1);
        $radek = DB::table('print_batches')->first();
        $this->assertSame(1, (int) $radek->download_count);
        $this->assertNotNull($radek->downloaded_at);
    }

    /** Archiv sady nese originály ve složce pojmenované po sadě a zapíše stažení. */
    public function test_archiv_sady_nese_spravne_soubory(): void
    {
        $this->travelTo(now()->setTimezone('Europe/Prague')->setDate(2026, 9, 27)->setTime(14, 0));

        $a = $this->foto('A.jpg');
        $b = $this->foto('B.jpg');
        $mimo = $this->foto('MIMO.jpg');
        $this->postJson('/api/k-tisku/oznacene', ['id' => $a->uuid])->assertOk();
        $this->postJson('/api/k-tisku/oznacene', ['id' => $b->uuid])->assertOk();
        $sada = $this->postJson('/api/k-tisku/sady')->assertCreated()->json('sada.id');

        $odpoved = $this->get('/api/k-tisku/sady/'.$sada.'/archiv')->assertOk();

        $this->assertSame('application/zip', $odpoved->headers->get('content-type'));
        $this->assertStringContainsString('K-tisku-2026-09-27.zip', (string) $odpoved->headers->get('content-disposition'));
        $jmena = $this->vArchivu($odpoved);
        sort($jmena);
        $this->assertSame(['K tisku 27. 9. 2026/A.jpg', 'K tisku 27. 9. 2026/B.jpg'], $jmena);
        $this->assertNotContains('K tisku 27. 9. 2026/MIMO.jpg', $jmena);
        $this->assertSame(1, (int) DB::table('print_batches')->value('download_count'));

        // Fotka, která mezitím skončila v trezoru, se do archivu nedostane.
        $b->forceFill(['is_hidden' => true])->save();
        $this->assertSame(['K tisku 27. 9. 2026/A.jpg'], $this->vArchivu($this->get('/api/k-tisku/sady/'.$sada.'/archiv')->assertOk()));
        $this->assertSame(2, (int) DB::table('print_batches')->value('download_count'));
        $this->assertSame([$a->uuid], array_column($this->getJson('/api/k-tisku/sady/'.$sada)->json('soubory'), 'id'));
    }

    /** Cizí sadu nejde otevřít ani stáhnout. */
    public function test_cizi_sada_se_nestahne(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $uuid = (string) Str::uuid();
        DB::table('print_batches')->insert([
            'uuid' => $uuid, 'gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id,
            'name' => 'K tisku', 'file_stem' => 'K-tisku', 'items_count' => 0, 'download_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson('/api/k-tisku/sady/'.$uuid)->assertNotFound();
        $this->get('/api/k-tisku/sady/'.$uuid.'/archiv')->assertNotFound();
        $this->postJson('/api/k-tisku/sady/'.$uuid.'/stazeno')->assertNotFound();
    }

    /**
     * Migrace drží MySQL: krátká jména indexů a žádná funkce jen pro SQLite.
     *
     * SQLite v testech délku jména indexu ani `timestamp` bez výchozí hodnoty
     * neodhalí, MySQL 8 ve striktním režimu ano.
     */
    public function test_migrace_je_bezpecna_pro_mysql(): void
    {
        $zdroj = File::get(database_path('migrations/2026_09_29_140000_create_print_marks_tables.php'));

        preg_match_all("/, '([a-z_]+)'\)/", $zdroj, $indexy);
        $this->assertNotEmpty($indexy[1]);
        foreach ($indexy[1] as $jmeno) {
            $this->assertLessThanOrEqual(64, strlen($jmeno), $jmeno);
        }

        $this->assertStringNotContainsString('strftime', $zdroj);
        $this->assertStringNotContainsString('DB::statement', $zdroj);
        $this->assertStringContainsString("timestamp('marked_at')->useCurrent()", $zdroj);
        $this->assertStringContainsString("timestamp('downloaded_at')->nullable()", $zdroj);
    }

    // ——— pomocné ———

    private function foto(string $jmeno, array $navic = []): MediaItem
    {
        $m = $this->fotka($this->prostor, $this->adri, $jmeno, $navic);

        $cesta = 'media/'.$m->uuid.'/original.jpg';
        Storage::disk('public')->put($cesta, 'obsah '.$jmeno);
        DB::table('media_variants')->insert([
            'media_item_id' => $m->id, 'type' => 'original', 'disk' => 'public', 'path' => $cesta,
            'size_bytes' => 1024, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $m;
    }

    /** @return list<string> */
    private function vArchivu($odpoved): array
    {
        $soubor = tempnam(sys_get_temp_dir(), 'test_').'.zip';
        file_put_contents($soubor, $odpoved->streamedContent());

        $zip = new ZipArchive;
        $zip->open($soubor);

        $jmena = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $jmena[] = $zip->getNameIndex($i);
        }

        $zip->close();
        @unlink($soubor);

        return $jmena;
    }
}
