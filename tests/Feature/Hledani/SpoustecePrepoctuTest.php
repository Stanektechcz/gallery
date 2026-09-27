<?php

namespace Tests\Feature\Hledani;

use App\Models\Album;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\Place;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Změny vazeb, které `search_text` dřív nechaly starý.
 *
 * Zařazení do alba a vyjmutí z něj, sloučení alb, štítků a osob a smazání
 * štítku, osoby nebo místa mění vazby přímo v tabulkách (nebo řádek zmizí
 * i s vazbami), takže přejmenování v modelu je nezachytí. Fotka se pak
 * hledala podle alba, ve kterém už není, nebo podle smazaného štítku.
 */
class SpoustecePrepoctuTest extends TestCase
{
    use RefreshDatabase;
    use VytvariFotky;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zalozDvojici();
        Sanctum::actingAs($this->adri);
    }

    public function test_zarazeni_do_alba_prepocita_text(): void
    {
        $album = $this->album('Svatba Petry');
        $foto = $this->hledatelna();
        $jina = $this->hledatelna();
        $puvodniJine = $jina->search_text;

        $this->postJson('/api/alba/zaradit', ['album' => $album->uuid, 'media' => [$foto->uuid]])->assertOk();

        $this->assertStringContainsString('Svatba Petry', $foto->refresh()->search_text);
        $this->assertSame($puvodniJine, $jina->refresh()->search_text);
    }

    public function test_vyjmuti_z_alb_prepocita_text(): void
    {
        $album = $this->album('Svatba Petry');
        $foto = $this->fotka(['primary_album_id' => $album->id]);
        DB::table('album_media')->insert(['album_id' => $album->id, 'media_item_id' => $foto->id, 'added_at' => now()]);
        $foto = $this->prepocitana($foto);
        $this->assertStringContainsString('Svatba Petry', $foto->search_text);

        $this->postJson('/api/alba/zaradit', ['album' => null, 'media' => [$foto->uuid]])->assertOk();

        $this->assertStringNotContainsString('Svatba Petry', $foto->refresh()->search_text);
    }

    public function test_slouceni_alb_prepocita_text(): void
    {
        $zdroj = $this->album('Dovolená Itálie');
        $cil = $this->album('Léto u moře');
        $pripojena = $this->fotka();
        $hlavni = $this->fotka(['primary_album_id' => $zdroj->id]);
        DB::table('album_media')->insert(['album_id' => $zdroj->id, 'media_item_id' => $pripojena->id, 'added_at' => now()]);
        $pripojena = $this->prepocitana($pripojena);
        $hlavni = $this->prepocitana($hlavni);

        $this->postJson('/api/alba/'.$zdroj->uuid.'/sloucit', ['do' => $cil->uuid])->assertOk();

        foreach ([$pripojena->refresh(), $hlavni->refresh()] as $foto) {
            $this->assertStringContainsString('Léto u moře', $foto->search_text);
            $this->assertStringNotContainsString('Dovolená Itálie', $foto->search_text);
        }
    }

    public function test_slouceni_stitku_prepocita_text(): void
    {
        $velky = $this->stitek('Chorvatsko', 'chorvatsko');
        $maly = $this->stitek('chorvatsko', 'chorvatsko-2');
        [$a, $b, $c] = [$this->fotka(), $this->fotka(), $this->fotka()];
        $a->tags()->attach($velky->id, ['created_at' => now()]);
        $b->tags()->attach($velky->id, ['created_at' => now()]);
        $c->tags()->attach($maly->id, ['created_at' => now()]);
        $c = $this->prepocitana($c);
        $this->assertStringNotContainsString('Chorvatsko', $c->search_text);

        $this->postJson('/api/stitky/sloucit', ['stitky' => ['#Chorvatsko', '#chorvatsko']])->assertOk();

        $this->assertStringContainsString('Chorvatsko', $c->refresh()->search_text);
    }

    public function test_slouceni_osob_prepocita_text(): void
    {
        $zdroj = $this->osoba('Kája');
        $cil = $this->osoba('Karolína');
        $foto = $this->fotka();
        $foto->people()->attach($zdroj->id, ['created_at' => now()]);
        $foto = $this->prepocitana($foto);

        $this->postJson('/api/osoby/'.$zdroj->id.'/sloucit', ['do' => $cil->id])->assertOk();

        $text = $foto->refresh()->search_text;
        $this->assertStringContainsString('Karolína', $text);
        $this->assertStringNotContainsString('Kája', $text);
        $this->assertStringNotContainsString('kaja', $text);
    }

    public function test_smazani_stitku_prepocita_text(): void
    {
        $stitek = $this->stitek('Zabijačka', 'zabijacka');
        $foto = $this->fotka();
        $foto->tags()->attach($stitek->id, ['created_at' => now()]);
        $foto = $this->prepocitana($foto);

        $stitek->delete();

        $this->assertStringNotContainsString('zabijacka', $foto->refresh()->search_text);
    }

    public function test_smazani_osoby_prepocita_text(): void
    {
        $osoba = $this->osoba('Bývalý Honza');
        $foto = $this->fotka();
        $foto->people()->attach($osoba->id, ['created_at' => now()]);
        $foto = $this->prepocitana($foto);

        $osoba->delete();

        $this->assertStringNotContainsString('honza', $foto->refresh()->search_text);
    }

    public function test_smazani_mista_prepocita_text(): void
    {
        $misto = Place::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Hostinec U Vola', 'created_by' => $this->adri->id]);
        $foto = $this->fotka();
        DB::table('media_place')->insert(['media_item_id' => $foto->id, 'place_id' => $misto->id, 'is_primary' => true]);
        $foto = $this->prepocitana($foto);

        $misto->delete();

        $this->assertStringNotContainsString('vola', $foto->refresh()->search_text);
    }

    private function prepocitana(MediaItem $foto): MediaItem
    {
        $foto->rebuildSearchText();

        return $foto->refresh();
    }

    private function album(string $nazev): Album
    {
        return Album::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'title' => $nazev,
            'full_display_path' => $nazev,
            'slug' => Str::slug($nazev).'-'.Str::random(4),
            'created_by' => $this->adri->id,
        ]);
    }

    private function stitek(string $nazev, string $slug): Tag
    {
        return Tag::create(['gallery_space_id' => $this->prostor->id, 'name' => $nazev, 'slug' => $slug, 'depth' => 0, 'materialized_path' => '', 'created_by' => $this->adri->id]);
    }

    private function osoba(string $jmeno): Person
    {
        return Person::create(['gallery_space_id' => $this->prostor->id, 'name' => $jmeno, 'created_by' => $this->adri->id]);
    }
}
