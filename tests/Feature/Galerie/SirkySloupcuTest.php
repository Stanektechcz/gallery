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

        /*
         * Tabulky, do kterých patch dřív vůbec nedošel.
         *
         * Lhůta chce datum „14. 10. 2026" v `date` (ne `due` jako Y-m-d),
         * žádost potřebuje `from` a `to`, úkol pro Klid čte `name`. Bez nich
         * převodník řádek tiše přeskočil a sken neměl co kontrolovat — test
         * tak „hlídal" sloupce, do kterých se nikdy nic nezapsalo.
         */
        foreach (['house_dues', 'house_chore_log', 'house_inventory', 'house_week', 'couple_nudges', 'couple_decisions',
            'couple_cooling_purchases', 'couple_veto_proposals', 'wellbeing_tasks', 'time_capsules', 'couple_favours',
            'couple_forgiven', 'couple_anti_budget', 'couple_family_contacts', 'couple_truths', 'cycle_days',
            'chat_messages', 'inbox_states'] as $tabulka) {
            $this->assertGreaterThan(0, DB::table($tabulka)->count(), "Patch nezapsal nic do {$tabulka} — tvar klíčů nesedí.");
        }
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
     * Úprava existujícího řádku je jiná cesta kódu než založení.
     *
     * Převodníky ořezávaly text při zakládání, ale při úpravě ho často braly
     * rovnou z prohlížeče (termín slibu, poznámka k žádosti, stav rozhodnutí,
     * den domácí práce). Jeden PATCH by tu cestu nikdy nepotkal — řádek se
     * napřed musí založit a teprve druhý zápis ho mění.
     */
    public function test_ani_uprava_existujiciho_radku_nezapise_vic_nez_se_vejde(): void
    {
        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => [
            'chores' => [['id' => 'c1', 'name' => 'Vysát', 'who' => 'Adrian', 'mins' => 20, 'day' => 'po']],
            'capWeek' => [['key' => 'po', 'note' => 'krátká']],
            'proms' => [['id' => 'p1', 'who' => 'Adrian', 'to' => 'Makinka', 'what' => 'Umýt auto', 'state' => 'open']],
            'nudges' => [['id' => 'n1', 'text' => 'Koupit mléko', 'from' => 'Adrian', 'to' => 'Makinka']],
            'decs' => [['id' => 'd1', 'title' => 'Dovolená v září', 'status' => 'platí']],
        ]])->assertOk();

        $dlouhy = str_repeat('Příliš žluťoučký kůň úpěl ďábelské ódy. ', 30);

        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => [
            'chores' => [['id' => 'c1', 'name' => 'Vysát', 'who' => 'Adrian', 'day' => $dlouhy]],
            'capWeek' => [['key' => 'po', 'note' => $dlouhy]],
            'proms' => [['id' => 'p1', 'who' => 'Adrian', 'to' => 'Makinka', 'what' => 'Umýt auto', 'state' => 'open', 'due' => $dlouhy]],
            'nudges' => [['id' => 'n1', 'text' => 'Koupit mléko', 'from' => 'Adrian', 'to' => 'Makinka', 'note' => $dlouhy, 'kind' => $dlouhy]],
            'decs' => [['id' => 'd1', 'title' => 'Dovolená v září', 'status' => $dlouhy]],
        ]])->assertOk();

        $this->assertSame(1, DB::table('couple_promises')->count(), 'Druhý zápis měl slib upravit, ne založit nový.');
        $this->assertSame(1, DB::table('couple_nudges')->count());
        $this->assertSame(1, DB::table('couple_decisions')->count());

        $prehresky = $this->cosePresahuje();

        $this->assertSame([], $prehresky,
            "Na MySQL by tyhle úpravy shodily celý PATCH /api/state:\n".implode("\n", $prehresky));
    }

    /**
     * Čísla z prohlížeče se musí vejít do celočíselných sloupců.
     *
     * `unsignedSmallInteger` unese 65 535, `unsignedTinyInteger` 255 a nic
     * záporného; MySQL ve striktním režimu větší číslo odmítne stejně jako
     * dlouhý text. SQLite uloží cokoli, takže se to ověřuje proti migracím.
     */
    public function test_zadny_prevodnik_nezapise_cislo_mimo_rozsah_sloupce(): void
    {
        $this->actingAs($this->adri)
            ->patchJson('/api/state', ['data' => $this->patchSVelkymiCisly()])
            ->assertOk();

        $this->assertGreaterThan(20, count($this->ciselneSloupce()), 'Bez číselných sloupců by test prošel naprázdno.');
        $this->assertGreaterThan(0, DB::table('house_inventory')->count(), 'Patch nic nezapsal — tvar klíčů nesedí.');
        $this->assertGreaterThan(0, DB::table('couple_cooling_purchases')->count());
        $this->assertGreaterThan(0, DB::table('cycle_days')->count());

        $prehresky = [];

        foreach ($this->ciselneSloupce() as [$tabulka, $sloupec, $min, $max]) {
            $meze = DB::table($tabulka)
                ->selectRaw('MIN("'.$sloupec.'") AS nejmene, MAX("'.$sloupec.'") AS nejvic')
                ->first();

            if ($meze === null || $meze->nejmene === null) {
                continue;
            }

            if ((float) $meze->nejmene < $min || (float) $meze->nejvic > $max) {
                $prehresky[] = "  {$tabulka}.{$sloupec}: rozsah {$min}…{$max}, zapsáno {$meze->nejmene}…{$meze->nejvic}";
            }
        }

        $this->assertSame([], $prehresky,
            "Na MySQL by tyhle zápisy shodily celý PATCH /api/state:\n".implode("\n", $prehresky));
    }

    /**
     * Datum, které neexistuje, do sloupce `date` nepatří.
     *
     * Převodníky kontrolovaly jen tvar (`\d{4}-\d{2}-\d{2}`), takže prošel
     * i 45. třináctý měsíc. SQLite ho uloží jako text, MySQL zápis odmítne.
     * A okamžik za rokem 2037 se nevejde do `timestamp`.
     */
    public function test_zadny_prevodnik_nezapise_neplatne_datum(): void
    {
        $this->actingAs($this->adri)->patchJson('/api/state', ['data' => [
            'favList' => [['id' => 'f-1', 'from' => 'Makinka', 'what' => 'Pojištění', 'date' => '2026-13-45', 'w' => 3]],
            'forgList' => [['id' => 'o-1', 'by' => 'Adrian', 'what' => 'Zapomenuté výročí', 'date' => '2026-02-30']],
            'kapsules' => [['id' => 'z1', 'from' => 'Adrian', 'title' => 'Za rok', 'open' => '2026-13-45', 'body' => 'Ahoj']],
            'dues' => [['id' => 'q1', 'what' => 'STK', 'date' => '45. 13. 2026', 'who' => 'Adrian']],
            'cools' => [['id' => 'k1', 'what' => 'Kolo', 'price' => 9000, 'left' => 10 ** 9]],
            'cycDays' => ['2026-13-45' => ['flow' => 'light'], '2026-09-20' => ['flow' => 'light']],
        ]])->assertOk();

        $this->assertSame(1, DB::table('couple_favours')->count(), 'Laskavost s neplatným datem má dostat dnešek, ne zmizet.');
        $this->assertSame(1, DB::table('time_capsules')->count());
        $this->assertSame(0, DB::table('house_dues')->count(), 'Lhůta bez platného data se nezakládá.');
        $this->assertSame(1, DB::table('cycle_days')->count());

        foreach ([['couple_favours', 'happened_on'], ['couple_forgiven', 'happened_on'], ['time_capsules', 'deliver_at'], ['cycle_days', 'day']] as [$tabulka, $sloupec]) {
            foreach (DB::table($tabulka)->pluck($sloupec) as $hodnota) {
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}/', (string) $hodnota);
                [$r, $m, $d] = array_map('intval', explode('-', substr((string) $hodnota, 0, 10)));
                $this->assertTrue(checkdate($m, $d, $r), "{$tabulka}.{$sloupec}: {$hodnota} není skutečné datum.");
            }
        }

        $konec = (string) DB::table('couple_cooling_purchases')->value('cools_until');
        $this->assertLessThanOrEqual(2037, (int) substr($konec, 0, 4), "cools_until {$konec} se nevejde do TIMESTAMP.");
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
     * Celočíselné a desetinné sloupce z migrací a jejich rozsah v MySQL.
     *
     * `bigInteger`/`foreignId` se nekontroluje — tam se z prohlížeče nic
     * nepíše a rozsah PHP `int` je stejný.
     *
     * @return list<array{string, string, float, float}> tabulka, sloupec, min, max
     */
    private function ciselneSloupce(): array
    {
        $rozsahy = [
            'tinyinteger' => [-128, 127, 255],
            'smallinteger' => [-32768, 32767, 65535],
            'mediuminteger' => [-8388608, 8388607, 16777215],
            'integer' => [-2147483648, 2147483647, 4294967295],
        ];
        $sloupce = [];
        $existujici = array_map(
            fn (string $t) => str_contains($t, '.') ? substr((string) strrchr($t, '.'), 1) : $t,
            Schema::getTableListing(),
        );

        foreach (glob(database_path('migrations/*.php')) ?: [] as $soubor) {
            $casti = preg_split(
                "/Schema::(?:create|table)\(\s*'([a-z0-9_]+)'/i",
                (string) file_get_contents($soubor), -1, PREG_SPLIT_DELIM_CAPTURE,
            ) ?: [];

            for ($i = 1; $i < count($casti); $i += 2) {
                $tabulka = $casti[$i];

                if (! in_array($tabulka, $existujici, true)) {
                    continue;
                }

                // Celý příkaz až po středník, aby se chytilo i `->unsigned()` za ním.
                preg_match_all(
                    "/->(unsigned)?(tiny|small|medium)?integer\(\s*'([a-z0-9_]+)'\s*\)([^;]*);/i",
                    $casti[$i + 1] ?? '', $shody, PREG_SET_ORDER,
                );

                foreach ($shody as $s) {
                    [$min, $max, $maxBez] = $rozsahy[strtolower($s[2].'integer')];
                    $bezZnamenka = $s[1] !== '' || str_contains($s[4], '->unsigned()');
                    $sloupce[$tabulka.'.'.$s[3]] = [$tabulka, $s[3], $bezZnamenka ? 0.0 : (float) $min, (float) ($bezZnamenka ? $maxBez : $max)];
                }

                preg_match_all(
                    "/->(?:unsigned)?decimal\(\s*'([a-z0-9_]+)'\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/i",
                    $casti[$i + 1] ?? '', $shody, PREG_SET_ORDER,
                );

                foreach ($shody as $s) {
                    $max = 10 ** ((int) $s[2] - (int) $s[3]) - 10 ** (-(int) $s[3]);
                    $sloupce[$tabulka.'.'.$s[1]] = [$tabulka, $s[1], -$max, (float) $max];
                }
            }
        }

        return array_values($sloupce);
    }

    /**
     * Čísla, která by člověk napsal omylem nebo útočník schválně.
     *
     * @return array<string, mixed>
     */
    private function patchSVelkymiCisly(): array
    {
        $obri = 10 ** 12;

        return [
            'chores' => [['id' => 'c1', 'name' => 'Vysát', 'who' => 'Adrian', 'mins' => $obri, 'day' => 'po']],
            'choreLog' => [['id' => 'l1', 'chore' => 'Vysát', 'who' => 'Adrian', 'mins' => $obri, 'when' => 'právě teď']],
            'dues' => [['id' => 'q1', 'what' => 'STK', 'date' => now()->addDays(3)->format('j. n. Y'),
                'amount' => $obri, 'delayCost' => -500, 'who' => 'Adrian']],
            'inv' => [['id' => 'i1', 'name' => 'Pračka', 'price' => -1, 'life' => 1000, 'energy' => $obri,
                'servicePrice' => '1e20', 'upkeep' => '-3']],
            'cools' => [['id' => 'k1', 'what' => 'Kolo', 'price' => $obri, 'left' => 72]],
            'vetoProps' => [['id' => 'v1', 'text' => 'Nové auto', 'by' => 'Adrian', 'price' => -5]],
            'forgList' => [['id' => 'o-1', 'by' => 'Adrian', 'what' => 'Výročí', 'tries' => $obri, 'date' => '2026-09-01']],
            'antiList' => [['id' => 'a-1', 'name' => 'Netflix', 'saved' => $obri, 'type' => 'předplatné', 'month' => 'leden']],
            'fam' => [['id' => 'r-1', 'name' => 'Babička', 'every' => $obri, 'side' => 'Adrian']],
            'cycDays' => ['2026-09-20' => ['flow' => 'light', 'pain' => 300, 'temp' => 370]],
        ];
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
                'every' => $dlouhy, 'mins' => 20, 'icon' => $dlouhy, 'day' => $dlouhy,
            ]],
            // Záznam se zapisuje, jen když je první a „právě teď" (tlačítko „mám hotovo").
            'choreLog' => [[
                'id' => $dlouheId.'2', 'chore' => $dlouhy, 'who' => 'Adrian', 'mins' => 15, 'when' => 'právě teď',
            ]],
            'capWeek' => [[
                'key' => 'po', 'note' => $dlouhy, 'fixA' => true, 'a' => 3,
            ]],
            // Lhůta se zakládá jen s datem v tvaru, jaký posílá prototyp.
            'dues' => [[
                'id' => $dlouheId.'3', 'what' => $dlouhy, 'kind' => $dlouhy,
                'date' => now()->addDays(3)->format('j. n. Y'), 'amount' => 500, 'who' => 'Adrian',
                'note' => $dlouhy, 'delayNote' => $dlouhy, 'change' => $dlouhy,
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
            // Žádost bez `from` a `to` se nezaloží — dřív tu chyběly a tabulka zůstala prázdná.
            'nudges' => [[
                'id' => $dlouheId.'7', 'kind' => $dlouhy, 'text' => $dlouhy, 'from' => 'Adrian', 'to' => 'Makinka',
                'note' => $dlouhy,
            ]],
            'decs' => [[
                'id' => $dlouheId.'8', 'title' => $dlouhy, 'status' => $dlouhy, 'who' => 'Adrian', 'review' => $dlouhy,
            ]],
            'cools' => [[
                'id' => $dlouheId.'16', 'what' => $dlouhy, 'price' => 900, 'opinion' => $dlouhy, 'verdict' => $dlouhy,
            ]],
            'vetoProps' => [[
                'id' => $dlouheId.'17', 'text' => $dlouhy, 'by' => 'Adrian', 'done' => $dlouhy,
            ]],
            'kapsules' => [[
                'id' => 'z1757000000000', 'from' => 'Adrian', 'title' => $dlouhy, 'open' => '2027-09-04', 'body' => $dlouhy,
            ]],
            'favList' => [['id' => 'f-1', 'from' => 'Makinka', 'what' => $dlouhy, 'date' => '2026-08-28', 'w' => 3]],
            'forgList' => [['id' => 'o-1', 'by' => 'Adrian', 'what' => $dlouhy, 'date' => '2026-08-28']],
            'antiList' => [['id' => 'a-1', 'name' => $dlouhy, 'saved' => 100, 'type' => 'věc', 'month' => 'leden']],
            'fam' => [['id' => 'r-1', 'name' => $dlouhy, 'side' => 'Adrian', 'every' => 14, 'note' => $dlouhy]],
            'truths' => [['id' => 't-1', 'title' => $dlouhy, 'when' => $dlouhy, 'a' => 'moje', 'm' => 'tvoje']],
            'cycDays' => ['2026-09-20' => ['flow' => 'light', 'note' => str_repeat('Poznámka k cyklu. ', 200)]],
            'chat' => [['id' => 'm-n1', 'who' => 'a', 'text' => $dlouhy, 'meta' => '7:11', 'audio' => str_repeat('r', 300)]],
            // Klíč krátký (vejde se), text řádku dlouhý — ten se ukládá jako název.
            'rowDone' => ['inbox:fotky-bez-data' => true],
            'xRows' => ['inbox' => [['klic' => 'inbox:fotky-bez-data', 't' => $dlouhy]]],
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
            // Klid čte název úkolu z `name` (s `text` se úkol nezaložil) a nová
            // věc z obrazovky má `id` 0 — číslo vydává server.
            'klTasks' => [[
                'id' => 0, 'name' => $dlouhy, 'text' => $dlouhy, 'route' => $dlouhy, 'tab' => $dlouhy, 'label' => $dlouhy,
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
