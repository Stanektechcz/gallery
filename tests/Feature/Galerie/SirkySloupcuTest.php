<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Co projde přes stav, se musí vejít do sloupce.
 *
 * MySQL má `'strict' => true`, vývojová SQLite spolkne cokoli. Celý
 * `PATCH /api/state` je jedna transakce, takže jediný dlouhý řetězec shodí
 * uložení **všeho ostatního** — a `galerie-api.js` bere 500 jako výpadek sítě,
 * takže patch vrátí do fronty a zkouší ho znovu s odstupem, týden, bez hlášky.
 * Aplikace tím přestane ukládat cokoli a nikde to nevypadá jako porucha.
 *
 * Test se neptá člověka, ale schématu: pošle přes stav dlouhé texty a pak
 * projde **každý** znakový sloupec v databázi. Co je delší než jeho šířka, by
 * na produkci shodilo zápis. Díky tomu chytí obojí — `Vejde` s větším stropem,
 * než má sloupec, i místo, kde `Vejde` chybí úplně.
 */
class SirkySloupcuTest extends TestCase
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
    }

    /** Pojistka testu: sken musí mít co kontrolovat a patch musí něco zapsat. */
    public function test_sken_neni_prazdny(): void
    {
        $this->assertGreaterThan(50, count($this->znakoveSloupce()),
            'Bez znakových sloupců by hlavní test prošel, i kdyby se zapisovalo cokoli.');

        $this->actingAs($this->adri)
            ->patchJson('/api/state', ['data' => $this->dlouhyPatch()])->assertOk();

        $this->assertGreaterThan(0, DB::table('house_chores')->count(), 'Patch nic nezapsal — tvar klíčů nesedí.');
        $this->assertGreaterThan(0, DB::table('calendar_events')->count());
        $this->assertGreaterThan(0, DB::table('couple_story_chapters')->count());
    }

    public function test_zadny_prevodnik_nezapise_vic_nez_se_vejde(): void
    {
        $this->actingAs($this->adri)
            ->patchJson('/api/state', ['data' => $this->dlouhyPatch()])
            ->assertOk();

        $prehresky = $this->cosePresahuje();

        $this->assertSame([], $prehresky,
            "Na MySQL by tyhle zápisy shodily celý PATCH /api/state:\n".implode("\n", $prehresky));
    }

    /**
     * Identifikátor z prohlížeče je vlastní past.
     *
     * `client_id` má všude 64 znaků, `Vejde` ho ale ořezávalo na 80 — a klient
     * si identifikátory skládá sám, takže délku nikdo nehlídá.
     */
    public function test_dlouhy_identifikator_z_prohlizece_se_vejde(): void
    {
        $dlouheId = 'c-'.str_repeat('x', 200);

        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => ['chores' => [[
            'id' => $dlouheId, 'name' => 'Vysát', 'who' => 'Adrian', 'mins' => 20, 'every' => 'týdně',
        ]]]])->assertOk();

        $radek = DB::table('house_chores')->where('gallery_space_id', $this->prostor->id)->first();

        $this->assertNotNull($radek);
        $this->assertLessThanOrEqual(64, mb_strlen((string) $radek->client_id));
    }

    /**
     * Každý znakový sloupec v databázi proti tomu, co v něm leží.
     *
     * @return list<string>
     */
    private function cosePresahuje(): array
    {
        $prehresky = [];

        foreach ($this->znakoveSloupce() as [$tabulka, $sloupec, $sirka]) {
            $nejdelsi = (int) DB::table($tabulka)
                ->selectRaw('MAX(LENGTH("'.$sloupec.'")) AS nejdelsi')
                ->value('nejdelsi');

            if ($nejdelsi > $sirka) {
                $prehresky[] = "  {$tabulka}.{$sloupec}: sloupec {$sirka}, zapsáno {$nejdelsi}";
            }
        }

        return $prehresky;
    }

    /**
     * Šířky sloupců z migrací.
     *
     * Ne ze schématu testovací databáze: SQLite délky ignoruje, takže je
     * Laravel do `CREATE TABLE` vůbec nezapíše — `Schema::getColumns()`
     * i `PRAGMA table_info` hlásí holé `varchar`. Sken by pak neměl co
     * kontrolovat a hlavní test by prošel, i kdyby se zapisovalo cokoli.
     * Migrace jsou zdroj pravdy pro MySQL, kde na délce záleží.
     *
     * @return list<array{string, string, int}> tabulka, sloupec, šířka
     */
    private function znakoveSloupce(): array
    {
        $sloupce = [];
        $existujici = array_map(
            fn (string $t) => str_contains($t, '.') ? substr((string) strrchr($t, '.'), 1) : $t,
            Schema::getTableListing(),
        );

        foreach (glob(database_path('migrations/*.php')) ?: [] as $soubor) {
            $kod = (string) file_get_contents($soubor);

            // Rozdělí soubor na bloky podle toho, které tabulky se týkají.
            $casti = preg_split(
                "/Schema::(?:create|table)\(\s*'([a-z0-9_]+)'/i",
                $kod, -1, PREG_SPLIT_DELIM_CAPTURE,
            ) ?: [];

            for ($i = 1; $i < count($casti); $i += 2) {
                $tabulka = $casti[$i];

                if (! in_array($tabulka, $existujici, true)) {
                    continue;
                }

                // `string('x')` bez čísla je v Laravelu 255.
                preg_match_all(
                    "/->(?:string|char)\(\s*'([a-z0-9_]+)'\s*(?:,\s*(\d+))?\s*\)/i",
                    $casti[$i + 1] ?? '', $shody, PREG_SET_ORDER,
                );

                foreach ($shody as $shoda) {
                    $sloupce[$tabulka.'.'.$shoda[1]] = [$tabulka, $shoda[1], (int) ($shoda[2] ?? 255)];
                }
            }
        }

        return array_values($sloupce);
    }

    /**
     * Patch, který sahá na převodníky s nejužšími sloupci.
     *
     * Texty jsou schválně mnohem delší, než co by kdo napsal ručně: jde o to,
     * co se stane, když někdo vlepí odstavec z e-mailu.
     *
     * @return array<string, mixed>
     */
    private function dlouhyPatch(): array
    {
        $dlouhy = str_repeat('Příliš žluťoučký kůň úpěl ďábelské ódy. ', 30);
        $dlouheId = 'x-'.str_repeat('a', 120);

        return [
            'chores' => [[
                'id' => $dlouheId.'1', 'name' => $dlouhy, 'who' => 'Adrian',
                'every' => $dlouhy, 'mins' => 20, 'icon' => $dlouhy, 'day' => 'po',
            ]],
            'choreLog' => [[
                'id' => $dlouheId.'2', 'chore' => $dlouhy, 'who' => 'Adrian', 'mins' => 15,
            ]],
            'dues' => [[
                'id' => $dlouheId.'3', 'what' => $dlouhy, 'kind' => $dlouhy,
                'due' => now()->addDays(3)->format('Y-m-d'), 'amount' => 500, 'who' => 'Adrian',
            ]],
            'inv' => [[
                'id' => $dlouheId.'4', 'name' => $dlouhy, 'sub' => $dlouhy, 'room' => $dlouhy,
            ]],
            // Identifikátor `ev-n…` a měsíce od nuly — tak je posílá prototyp.
            'evList' => [[
                'id' => 'ev-n1', 'y' => (int) now()->format('Y'),
                'm' => (int) now()->format('n') - 1, 'd' => (int) now()->format('j'),
                'time' => '11:00', 't' => $dlouhy, 'kind' => 'cesta', 'who' => 'spolu',
            ]],
            'proms' => [[
                'id' => $dlouheId.'6', 'who' => 'Adrian', 'to' => 'Makinka',
                'what' => $dlouhy, 'state' => 'open', 'due' => $dlouhy,
            ]],
            'nudges' => [[
                'id' => $dlouheId.'7', 'kind' => $dlouhy, 'text' => $dlouhy, 'who' => 'Adrian',
            ]],
            'decs' => [[
                'id' => $dlouheId.'8', 'title' => $dlouhy, 'status' => $dlouhy, 'who' => 'Adrian',
            ]],
            'storyList' => [[
                'id' => $dlouheId.'9', 'title' => $dlouhy, 'year' => $dlouhy,
                'status' => 'hotovo', 'text' => $dlouhy,
            ]],
            'msList' => [[
                'id' => $dlouheId.'10', 'title' => $dlouhy, 'icon' => $dlouhy,
                'date' => now()->format('Y-m-d'),
            ]],
            'emItems' => [[
                'id' => $dlouheId.'11', 'label' => $dlouhy, 'note' => $dlouhy,
            ]],
            'paper' => [[
                'id' => $dlouheId.'12', 'label' => $dlouhy, 'value' => $dlouhy,
            ]],
            'rules' => [[
                'id' => $dlouheId.'13', 'name' => $dlouhy, 'trigger' => 'upload', 'action' => 'tag',
            ]],
            'klTasks' => [[
                'id' => 1, 'text' => $dlouhy, 'route' => $dlouhy, 'tab' => $dlouhy,
            ]],
            'ideas' => [[
                'id' => $dlouheId.'14', 'title' => $dlouhy, 'who' => 'Makinka', 'occasion' => $dlouhy,
            ]],
            'wishes' => [[
                'id' => $dlouheId.'15', 'title' => $dlouhy, 'who' => 'Makinka', 'price' => 500,
            ]],
        ];
    }
}
