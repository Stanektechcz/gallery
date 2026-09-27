<?php

namespace Tests\Feature\Hledani;

use App\Models\Person;
use App\Models\User;
use App\Services\Hledani\ObnovaHledani;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hledání ve starém API (`/api/v1/search`) i v prototypu (`/api/hledat`).
 *
 * Dřív: celý dotaz jako jeden kus (jiné pořadí slov = nic), na SQLite bez
 * ohledu na diakritiku jen ASCII, jedno neznámé slovo = prázdno. Prototyp
 * hledal jen ve 240 nejnovějších fotkách v prohlížeči.
 */
class HledaniTest extends TestCase
{
    use RefreshDatabase;
    use VytvariFotky;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zalozDvojici();
        Sanctum::actingAs($this->adri);
    }

    public function test_velka_pismena_ani_diakritika_nevadi(): void
    {
        $chata = $this->hledatelna(['caption' => 'Chata na Lysé hoře']);
        $this->hledatelna(['caption' => 'Pláž v Chorvatsku']);

        $this->assertSame([$chata->uuid], $this->v1('LYSE hore'));
        $this->assertSame([$chata->uuid], $this->prototyp('LYSE hore'));
        $this->assertSame([$chata->uuid], $this->prototyp('lysé HOŘE'));
    }

    public function test_slova_v_jinem_poradi_i_v_jinem_tvaru(): void
    {
        $chata = $this->hledatelna(['caption' => 'Chata na Lysé hoře', 'location_name' => 'Beskydy']);
        $this->hledatelna(['caption' => 'Chata u moře']);

        $this->assertSame([$chata->uuid], $this->v1('hoře Lysé chata'));
        // „v Beskydech" je jiný pád než „Beskydy" — kmen „beskyd" najde obojí.
        $this->assertSame([$chata->uuid], $this->prototyp('chata v Beskydech'));
    }

    public function test_nezname_slovo_vrati_castecne_shody_a_nejlepsi_navrch(): void
    {
        $obe = $this->hledatelna(['caption' => 'Chata na Lysé hoře', 'taken_at' => '2020-01-01 10:00:00']);
        $jedno = $this->hledatelna(['caption' => 'Chata u moře', 'taken_at' => '2024-01-01 10:00:00']);
        $this->hledatelna(['caption' => 'Pláž v Chorvatsku']);

        $v1 = $this->getJson('/api/v1/search?q='.urlencode('chata hoře xyzzy'))->assertOk();
        $v1->assertJsonPath('meta.uroven', 'or')->assertJsonPath('meta.total', 2);
        $this->assertSame([$obe->uuid, $jedno->uuid], array_column($v1->json('data'), 'uuid'));

        $proto = $this->getJson('/api/hledat?q='.urlencode('chata hoře xyzzy'))->assertOk();
        $proto->assertJsonPath('uroven', 'or')->assertJsonPath('celkem', 2);
        $this->assertSame([$obe->uuid, $jedno->uuid], array_column($proto->json('polozky'), 'id'));
    }

    public function test_vsechna_slova_maji_uroven_and(): void
    {
        $this->hledatelna(['caption' => 'Chata na Lysé hoře']);

        $this->getJson('/api/v1/search?q=chata')->assertOk()->assertJsonPath('meta.uroven', 'and');
        $this->getJson('/api/hledat?q=chata')->assertOk()->assertJsonPath('uroven', 'and');
    }

    public function test_hledani_podle_mesice_roku_a_druhu(): void
    {
        $video = $this->hledatelna(['media_type' => 'video', 'extension' => 'mp4', 'taken_at' => '2025-08-10 18:00:00']);
        $this->hledatelna(['taken_at' => '2025-08-11 18:00:00']);
        $this->hledatelna(['media_type' => 'video', 'extension' => 'mp4', 'taken_at' => '2024-08-10 18:00:00']);

        $this->assertSame([$video->uuid], $this->prototyp('video srpen 2025'));
        $this->assertSame([$video->uuid], $this->prototyp('video v srpnu 2025'));
    }

    public function test_cislo_z_nazvu_souboru(): void
    {
        $foto = $this->hledatelna(['original_filename' => 'IMG_4821.JPG']);
        $this->hledatelna(['original_filename' => 'IMG_1111.JPG']);

        $this->assertSame([$foto->uuid], $this->v1('4821'));
        $this->assertSame([$foto->uuid], $this->prototyp('4821'));
    }

    public function test_prototyp_najde_fotku_starsi_nez_prvnich_240(): void
    {
        $nejstarsi = $this->hledatelna(['caption' => 'Borůvkový koláč u babičky', 'taken_at' => '2010-06-01 10:00:00']);

        $radky = [];
        for ($i = 0; $i < 240; $i++) {
            $radky[] = $this->fotka(['taken_at' => '2024-01-01 10:00:00'])->id;
        }

        // V první várce mřížky (240 nejnovějších) ta fotka není…
        $mrizka = $this->getJson('/api/data/knihovna')->assertOk()->json('data.PHOTOS');
        $this->assertCount(240, $mrizka);
        $this->assertNotContains($nejstarsi->uuid, array_column($mrizka, 'id'));

        // …hledání na serveru ji najde.
        $odpoved = $this->getJson('/api/hledat?q='.urlencode('borůvkový koláč'))->assertOk();
        $odpoved->assertJsonPath('celkem', 1)
            ->assertJsonPath('strana', 1)
            ->assertJsonPath('dalsi', false)
            ->assertJsonPath('polozky.0.id', $nejstarsi->uuid);
        $this->assertSame('no-store, private', $odpoved->headers->get('Cache-Control'));
        $this->assertNotEmpty($radky);
    }

    public function test_dlazdice_ma_tvar_jako_v_mrizce(): void
    {
        $foto = $this->hledatelna(['caption' => 'Západ slunce nad Pálavou', 'location_name' => 'Pálava']);

        $zMrizky = collect($this->getJson('/api/data/knihovna')->assertOk()->json('data.PHOTOS'))->firstWhere('id', $foto->uuid);
        $zHledani = $this->getJson('/api/hledat?q=palava')->assertOk()->json('polozky.0');

        $this->assertNotNull($zMrizky);
        $this->assertSame($zMrizky, $zHledani);
    }

    public function test_strankovani_prototypu(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->hledatelna(['caption' => 'Jablka na zahradě', 'taken_at' => "2024-0{$i}-01 10:00:00"]);
        }

        $prvni = $this->getJson('/api/hledat?q=jablka&limit=2')->assertOk();
        $prvni->assertJsonPath('celkem', 5)->assertJsonPath('dalsi', true)->assertJsonCount(2, 'polozky');

        $posledni = $this->getJson('/api/hledat?q=jablka&limit=2&strana=3')->assertOk();
        $posledni->assertJsonPath('dalsi', false)->assertJsonCount(1, 'polozky')->assertJsonPath('polozky.0.n', 5);
    }

    public function test_bez_dotazu_prototyp_vrati_422(): void
    {
        $this->getJson('/api/hledat')->assertStatus(422);
        $this->getJson('/api/hledat?q=x&limit=500')->assertStatus(422);
    }

    public function test_kos_trezor_a_cizi_prostor_se_nenajdou(): void
    {
        $videt = $this->hledatelna(['caption' => 'Svatba v Olomouci']);
        $this->hledatelna(['caption' => 'Svatba v Olomouci', 'trashed_at' => now()]);
        $this->hledatelna(['caption' => 'Svatba v Olomouci', 'is_hidden' => true]);

        $cizi = User::factory()->create();
        $ciziProstor = $this->novyProstor($cizi);
        $this->hledatelna(['caption' => 'Svatba v Olomouci', 'gallery_space_id' => $ciziProstor->id, 'owner_user_id' => $cizi->id]);

        $this->assertSame([$videt->uuid], $this->v1('svatba olomouc'));
        $this->assertSame([$videt->uuid], $this->prototyp('svatba olomouc'));
        // Ani v částečné shodě (druhý stupeň) se neobjeví nic z toho.
        $this->assertSame([$videt->uuid], $this->prototyp('svatba xyzzy'));
        $this->assertSame([$videt->uuid], $this->v1('svatba xyzzy'));
    }

    public function test_fazety_jednim_dotazem_sedi(): void
    {
        $this->hledatelna(['caption' => 'Jarní louka', 'is_favorite' => true, 'latitude' => 49.5, 'longitude' => 18.4]);
        $this->hledatelna(['caption' => 'Jarní louka', 'media_type' => 'video']);
        $this->hledatelna(['caption' => 'Podzimní les']);

        $this->getJson('/api/v1/search?q=louka')->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('facets', ['photos' => 1, 'videos' => 1, 'favorites' => 1, 'with_gps' => 1]);
    }

    public function test_nasepkavac_bez_prostoru_vrati_403(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/search/suggestions?q=a')->assertStatus(403);
    }

    public function test_skryta_osoba_se_neobjevi_v_nasepkavaci_ani_jako_filtr(): void
    {
        $skryta = Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Tajemná Bára', 'is_hidden' => true, 'created_by' => $this->adri->id]);
        Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Tajemný Ota', 'created_by' => $this->adri->id]);
        $foto = $this->fotka();
        $foto->people()->attach($skryta->id, ['created_at' => now()]);
        app(ObnovaHledani::class)->obnov([$foto->id]);

        $navrhy = collect($this->getJson('/api/v1/search/suggestions?q=Tajemn')->assertOk()->json())->where('type', 'person');
        $this->assertSame(['Tajemný Ota'], $navrhy->pluck('label')->values()->all());

        // Rozpoznání jména (`EntityMatcher`) z ní nesmí udělat filtr — fotka by skrytou osobu prozradila.
        $odpoved = $this->getJson('/api/v1/search?q='.urlencode('Bára'))->assertOk();
        $this->assertSame([], $odpoved->json('data'));
        $this->assertSame([], $odpoved->json('interpreted.labels'));
    }

    public function test_host_na_prototypove_hledani_nesmi(): void
    {
        $host = User::factory()->create();
        $this->prostor->members()->syncWithoutDetaching([$host->id => ['role' => 'viewer']]);
        $this->hledatelna(['caption' => 'Svatba v Olomouci']);

        Sanctum::actingAs($host);

        $this->getJson('/api/hledat?q=svatba')->assertStatus(403);
    }

    public function test_hledani_ma_malo_dotazu(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->hledatelna(['caption' => 'Chata na Lysé hoře']);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/search?q='.urlencode('chata hoře'))->assertOk()->assertJsonPath('meta.total', 30);
        $dotazu = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Ověření přístupu, prostor, počty, stránka, náhledy — ne počet × fotka.
        $this->assertLessThanOrEqual(12, $dotazu, "Hledání potřebovalo {$dotazu} dotazů.");
    }

    /** @return list<string> */
    private function v1(string $q): array
    {
        return array_column($this->getJson('/api/v1/search?q='.urlencode($q))->assertOk()->json('data'), 'uuid');
    }

    /** @return list<string> */
    private function prototyp(string $q): array
    {
        return array_column($this->getJson('/api/hledat?q='.urlencode($q))->assertOk()->json('polozky'), 'id');
    }
}
