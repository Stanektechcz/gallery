<?php

namespace Tests\Feature\Hledani;

use App\Jobs\Media\ExtractXmpMetadataJob;
use App\Models\Album;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\Place;
use App\Models\Tag;
use App\Services\Hledani\ObnovaHledani;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `search_text` je úplný a nestárne.
 *
 * Chybělo místo zapsané v prototypu, alba mimo hlavní, měsíc, rok, roční
 * doba, druh a přípona souboru. A text se skládal jen při zpracování nahrané
 * fotky: úprava v prototypu, hromadná akce nebo přejmenování štítku ho
 * nechaly starý.
 */
class HledanyTextTest extends TestCase
{
    use RefreshDatabase;
    use VytvariFotky;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zalozDvojici();
    }

    public function test_text_nese_mesic_rok_rocni_dobu_druh_a_priponu(): void
    {
        $video = $this->hledatelna([
            'media_type' => 'video',
            'extension' => 'mp4',
            'original_filename' => 'VID_0001.mp4',
            'taken_at' => '2025-08-10 18:00:00',
        ]);

        foreach (['srpen', 'srpna', '2025', 'léto', 'létě', 'video', 'mp4', 'leto'] as $slovo) {
            $this->assertStringContainsString($slovo, $video->search_text, "V textu chybí „{$slovo}“.");
        }

        $this->assertStringNotContainsString('fotka', $video->search_text);
    }

    public function test_text_nese_misto_z_prototypu_vsechna_alba_a_kopii_bez_diakritiky(): void
    {
        $hlavni = $this->album('Beskydy');
        $dalsi = $this->album('Nejhezčí výlety');
        $foto = $this->fotka(['location_name' => 'Chata na Lysé hoře', 'primary_album_id' => $hlavni->id]);
        DB::table('album_media')->insert(['album_id' => $dalsi->id, 'media_item_id' => $foto->id, 'added_at' => now()]);

        app(ObnovaHledani::class)->obnov([$foto->id]);
        $text = $foto->refresh()->search_text;

        $this->assertStringContainsString('Chata na Lysé hoře', $text);
        $this->assertStringContainsString('Beskydy', $text);
        $this->assertStringContainsString('Nejhezčí výlety', $text);
        $this->assertStringContainsString('chata na lyse hore', $text);
        $this->assertStringContainsString('nejhezci vylety', $text);
    }

    public function test_skryta_osoba_do_textu_nepatri(): void
    {
        $foto = $this->fotka();
        $viditelna = Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Klárka', 'created_by' => $this->adri->id]);
        $skryta = Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Tajemná', 'is_hidden' => true, 'created_by' => $this->adri->id]);
        $foto->people()->attach([$viditelna->id => ['created_at' => now()], $skryta->id => ['created_at' => now()]]);

        app(ObnovaHledani::class)->obnov([$foto->id]);

        $this->assertStringContainsString('Klárka', $foto->refresh()->search_text);
        $this->assertStringNotContainsString('Tajemná', $foto->search_text);

        // Skrytí je změna textu: osoba z něj zmizí i bez dalšího zásahu.
        $viditelna->update(['is_hidden' => true]);
        $this->assertStringNotContainsString('Klárka', $foto->refresh()->search_text);
    }

    public function test_uprava_v_prototypu_slozi_text_znovu(): void
    {
        $foto = $this->hledatelna();

        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['edits' => [$foto->uuid => [
            'caption' => 'Borůvkové knedlíky',
            'place' => 'Pustevny',
            'tags' => ['hory'],
            'people' => ['Babička Věra'],
        ]]]])->assertOk();

        $text = $foto->refresh()->search_text;

        foreach (['Borůvkové knedlíky', 'Pustevny', 'hory', 'Babička Věra', 'boruvkove knedliky'] as $slovo) {
            $this->assertStringContainsString($slovo, $text, "V textu chybí „{$slovo}“.");
        }
    }

    public function test_hromadny_stitek_slozi_text_znovu(): void
    {
        $prvni = $this->hledatelna();
        $druha = $this->hledatelna();
        $stitek = Tag::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Svatba', 'slug' => 'svatba', 'depth' => 0, 'materialized_path' => '', 'created_by' => $this->adri->id]);

        Sanctum::actingAs($this->adri);
        $this->postJson('/api/v1/media/bulk', [
            'action' => 'tag',
            'uuids' => [$prvni->uuid, $druha->uuid],
            'tag_id' => $stitek->id,
        ])->assertOk()->assertJsonPath('processed', 2);

        $this->assertStringContainsString('Svatba', $prvni->refresh()->search_text);
        $this->assertStringContainsString('Svatba', $druha->refresh()->search_text);
    }

    public function test_prejmenovani_mista_slozi_text_jeho_fotek(): void
    {
        $misto = Place::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Lysá hora', 'created_by' => $this->adri->id]);
        $foto = $this->fotka();
        $cizi = $this->hledatelna();
        DB::table('media_place')->insert(['media_item_id' => $foto->id, 'place_id' => $misto->id, 'is_primary' => true]);
        app(ObnovaHledani::class)->obnov([$foto->id]);
        $puvodniCizi = $cizi->search_text;

        $misto->update(['name' => 'Smrk']);

        $this->assertStringContainsString('Smrk', $foto->refresh()->search_text);
        $this->assertStringNotContainsString('Lysá', $foto->search_text);
        $this->assertSame($puvodniCizi, $cizi->refresh()->search_text);
    }

    public function test_prejmenovani_stitku_osoby_a_alba_slozi_text_znovu(): void
    {
        $album = $this->album('Chorvatsko');
        $foto = $this->fotka(['primary_album_id' => $album->id]);
        $stitek = Tag::create(['gallery_space_id' => $this->prostor->id, 'name' => 'more', 'slug' => 'more', 'depth' => 0, 'materialized_path' => '', 'created_by' => $this->adri->id]);
        $osoba = Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Makinka', 'created_by' => $this->adri->id]);
        $foto->tags()->attach($stitek->id, ['created_at' => now()]);
        $foto->people()->attach($osoba->id, ['created_at' => now()]);
        app(ObnovaHledani::class)->obnov([$foto->id]);

        $stitek->update(['name' => 'moře']);
        $osoba->update(['name' => 'Makinka Nováková']);
        $album->update(['title' => 'Istrie', 'full_display_path' => 'Istrie']);

        $text = $foto->refresh()->search_text;
        $this->assertStringContainsString('moře', $text);
        $this->assertStringContainsString('Makinka Nováková', $text);
        $this->assertStringContainsString('Istrie', $text);
        $this->assertStringNotContainsString('Chorvatsko', $text);
    }

    public function test_klicova_slova_z_xmp_se_slozi_do_textu_bez_zmeny_updated_at(): void
    {
        $foto = $this->fotka(['caption' => 'Ráno na chatě']);
        DB::table('media_items')->where('id', $foto->id)->update(['updated_at' => '2024-05-01 12:00:00']);

        app()->call([new ExtractXmpMetadataJob($foto->id, ['Beskydy', 'Mlha']), 'handle']);

        $foto->refresh();
        $this->assertStringContainsString('Beskydy', $foto->search_text);
        $this->assertStringContainsString('Mlha', $foto->search_text);
        $this->assertStringContainsString('Ráno na chatě', $foto->search_text);
        // Hledaný text je odvozený; podle `updated_at` se pozná zaseknuté nahrávání na Disk.
        $this->assertSame('2024-05-01 12:00:00', $foto->updated_at->format('Y-m-d H:i:s'));
    }

    public function test_prepocet_padesati_fotek_ma_staly_pocet_dotazu(): void
    {
        $album = $this->album('Léto');
        $stitek = Tag::create(['gallery_space_id' => $this->prostor->id, 'name' => 'hory', 'slug' => 'hory', 'depth' => 0, 'materialized_path' => '', 'created_by' => $this->adri->id]);
        $osoba = Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Klárka', 'created_by' => $this->adri->id]);
        $misto = Place::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Praděd', 'created_by' => $this->adri->id]);
        $id = [];

        for ($i = 0; $i < 50; $i++) {
            $foto = $this->fotka(['primary_album_id' => $album->id]);
            $foto->tags()->attach($stitek->id, ['created_at' => now()]);
            $foto->people()->attach($osoba->id, ['created_at' => now()]);
            DB::table('media_place')->insert(['media_item_id' => $foto->id, 'place_id' => $misto->id, 'is_primary' => true]);
            DB::table('album_media')->insert(['album_id' => $album->id, 'media_item_id' => $foto->id, 'added_at' => now()]);
            $id[] = $foto->id;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $zmeneno = app(ObnovaHledani::class)->obnov($id);
        $dotazu = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(50, $zmeneno);
        $this->assertLessThanOrEqual(8, $dotazu, "Přepočet 50 fotek potřeboval {$dotazu} dotazů.");
        $this->assertSame(50, MediaItem::query()->where('search_text', 'like', '%Praděd%')->count());
    }

    public function test_prikaz_slozi_text_vsem_nebo_jen_jednomu_prostoru(): void
    {
        $moje = $this->fotka(['caption' => 'Západ slunce']);
        $druhyProstor = $this->novyProstor($this->adri);
        $cizi = $this->fotka(['caption' => 'Východ slunce', 'gallery_space_id' => $druhyProstor->id]);

        $this->artisan('gallery:rebuild-search', ['--space' => $this->prostor->id])->assertSuccessful();

        $this->assertStringContainsString('Západ slunce', (string) $moje->refresh()->search_text);
        $this->assertNull($cizi->refresh()->search_text);

        $this->artisan('gallery:rebuild-search')->assertSuccessful();
        $this->assertStringContainsString('Východ slunce', (string) $cizi->refresh()->search_text);
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
}
