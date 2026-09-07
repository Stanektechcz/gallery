<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Posledních sedm seznamů `xRows`, které kreslila ukázka.
 *
 * `datesGen`, `balancing`, `snoozed`, `inboxDone`, `dupes`, `api` a `tarify`
 * — u každého stálo na obrazovce něco, co dvojice nemá: „Slepá mapa — kam
 * ukáže prst", „Restaurace do Potravin · vyrovnáno 500 Kč", „Klíč …8f2a"
 * a „Rodinný 200 GB · aktivní".
 */
class ZbyleSeznamyTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian', 'role' => 'owner']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    private function fotka(array $navic = [], string $soubor = 'IMG_1.jpg'): MediaItem
    {
        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => $soubor,
            'safe_filename' => Str::slug(pathinfo($soubor, PATHINFO_FILENAME)).'.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1000,
            'uploaded_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }

    /** Vygenerované návrhy chodí z tabulky a nesou datum, ne slovo „nové". */
    public function test_vygenerovana_randicka_nesou_datum(): void
    {
        foreach ([['Lanovka na Ještěd', 'generated', now()], ['Keramika', 'generated', now()->subDays(4)]] as $i => [$nazev, $stav, $kdy]) {
            DB::table('couple_date_ideas')->insert([
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $this->prostor->id,
                'created_by' => $this->adri->id,
                'generation_key' => 'g'.$i,
                'title' => $nazev,
                'summary' => '', 'theme' => 'venku', 'estimated_minutes' => 0,
                'parameters' => json_encode([]), 'plan' => json_encode([]),
                'status' => $stav,
                'created_at' => $kdy, 'updated_at' => $kdy,
            ]);
        }

        $navrhy = collect($this->getJson('/api/data/vztah')->assertOk()->json('data.AL.datesGen'))->keyBy(0);

        $this->assertStringContainsString('vygenerováno dnes', $navrhy['Lanovka na Ještěd'][1]);
        $this->assertSame('nové', $navrhy['Lanovka na Ještěd'][2]);

        // Návrh, na který se čtyři dny nesáhlo, není novinka.
        $this->assertStringNotContainsString('dnes', $navrhy['Keramika'][1]);
        $this->assertSame('návrh', $navrhy['Keramika'][2]);
    }

    /** Uložený nápad mezi vygenerovanými není — je na druhé záložce. */
    public function test_ulozeny_napad_neni_mezi_vygenerovanymi(): void
    {
        DB::table('couple_date_ideas')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'generation_key' => '', 'title' => 'Kolo k přehradě',
            'summary' => '', 'theme' => '', 'estimated_minutes' => 0,
            'parameters' => json_encode([]), 'plan' => json_encode([]),
            'status' => 'saved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->getJson('/api/data/vztah')->assertOk()->json('data.AL');

        $this->assertSame('Kolo k přehradě', $data['datesSaved'][0][0]);
        $this->assertArrayNotHasKey('datesGen', $data);
    }

    /**
     * Vyrovnání se podepisuje tím, kdo ho zapsal.
     *
     * Ukázka u každého řádku tvrdila „automaticky", jako by aplikace peníze
     * mezi rozpočty přesouvala sama. Nepřesouvá.
     */
    public function test_vyrovnani_chodi_z_tabulky(): void
    {
        $rozpocet = DB::table('budgets')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'name' => 'Společný',
            'currency' => 'CZK',
            'starts_on' => now()->startOfMonth()->toDateString(),
            'created_by' => $this->adri->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('budget_settlements')->insert([
            'uuid' => (string) Str::uuid(),
            'budget_id' => $rozpocet,
            'currency' => 'CZK',
            'settled_through' => CarbonImmutable::parse('2026-08-27')->toDateString(),
            'amount' => 500,
            'created_by' => $this->adri->id,
            'note' => 'Restaurace do Potravin',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $radek = $this->getJson('/api/data/finance')->assertOk()->json('data.AL.balancing.0');

        $this->assertSame('Restaurace do Potravin', $radek[0]);
        $this->assertStringContainsString('500 Kč', $radek[1]);
        $this->assertStringContainsString('27. 8.', $radek[1]);
        $this->assertSame('Adrian', $radek[2], 'Kdo to zapsal — ne „automaticky".');
    }

    /** Duplicity jako seznam se počítají z týchž nálezů jako karty nahoře. */
    public function test_duplicity_v_seznamu_sedi_s_nalezy(): void
    {
        $a = $this->fotka(['size_bytes' => 5_000_000], 'nad-mlhou.jpg');
        $b = $this->fotka(['size_bytes' => 800_000], 'nad-mlhou-kopie.jpg');

        $skupina = DB::table('duplicate_groups')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'match_type' => 'exact',
            'resolution' => 'pending',
            'detected_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([[$a, true], [$b, false]] as [$m, $vitez]) {
            DB::table('duplicate_group_items')->insert([
                'duplicate_group_id' => $skupina,
                'media_item_id' => $m->id,
                'is_kept' => $vitez,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $data = $this->getJson('/api/data/knihovna')->assertOk()->json('data');

        $this->assertCount(1, $data['DUP_GROUPS']);
        $this->assertCount(1, $data['AL']['dupes']);
        $this->assertStringContainsString('2 kopie', $data['AL']['dupes'][0][0]);
        $this->assertStringContainsString('shoda 100 %', $data['AL']['dupes'][0][1]);
        $this->assertSame('sloučit', $data['AL']['dupes'][0][2]);
    }

    /** Účty a tarify vidí oba; klíče a úlohy jen správce. */
    public function test_sprava_v_seznamech(): void
    {
        $data = $this->getJson('/api/data/system')->assertOk()->json('data.AL');

        $this->assertSame('Adrian', $data['users'][0][0]);
        $this->assertStringContainsString('vlastník', $data['users'][0][1]);
        $this->assertArrayHasKey('jobs', $data, 'Vlastník je správce a úlohy vidí.');
        $this->assertStringNotContainsString('…8f2a', json_encode($data), 'Ukázkový klíč z galerie-data.js.');
    }

    /** Kdo správce není, klíče ani plánované úlohy nedostane. */
    public function test_host_neuvidi_klice_ani_ulohy(): void
    {
        $host = User::factory()->create(['name' => 'Klára', 'role' => 'member']);
        $this->prostor->members()->syncWithoutDetaching([$host->id => ['role' => 'viewer']]);

        Sanctum::actingAs($host);

        $data = $this->getJson('/api/data/system')->assertOk()->json('data.AL');

        // Prázdné, ne chybějící: kdyby se neposlaly, zůstala by na obrazovce
        // ukázka — klíč „Mobilní aplikace · aktivní" a „Noční záloha · hotovo"
        // jako ujištění, že někdo někam přistupuje a zálohy běží.
        $this->assertSame([], $data['api']);
        $this->assertSame([], $data['jobs']);
        // Účty a tarify jsou naopak společné — vidí je oba.
        $this->assertNotSame([], $data['users']);
    }

    /** Vyřešený řádek inboxu zmizí z inboxu a objeví se mezi hotovými. */
    public function test_vyreseny_radek_inboxu_se_ulozi(): void
    {
        $this->fotka();

        $inbox = $this->getJson('/api/data/system')->assertOk()->json('data.AL.inbox');
        $this->assertContains('inbox:fotky-bez-data', array_column($inbox, 7));

        $this->patchJson('/api/state', ['data' => [
            'rowDone' => ['inbox:fotky-bez-data' => true],
            'xRows' => ['inbox' => [['t' => '1 fotka bez data', 'klic' => 'inbox:fotky-bez-data']]],
        ]])->assertOk();

        $data = $this->getJson('/api/data/system')->assertOk()->json('data.AL');

        $this->assertNotContains('inbox:fotky-bez-data', array_column($data['inbox'] ?? [], 7));
        $this->assertSame('1 fotka bez data', $data['inboxDone'][0][0]);
        $this->assertStringContainsString('Adrian', $data['inboxDone'][0][1]);

        // A do stavu se to neukládá — má tabulku.
        $stav = (array) $this->getJson('/api/state')->assertOk()->json('data');
        $this->assertArrayNotHasKey('rowDone', $stav);
        /*
         * Ani samotný seznam.
         *
         * Posílá se k rozhodnutí jen proto, aby k němu server znal text řádku.
         * Když zůstal ležet ve stavu, obrazovka ho pak kreslila z něj místo ze
         * serveru — vyřešený řádek se vracel na místo a vypadalo to, že se
         * rozhodnutí neuložilo.
         */
        $this->assertArrayNotHasKey('xRows', $stav);
    }

    /** Odložený řádek se po týdnu sám vrátí. */
    public function test_odlozeny_radek_se_vrati(): void
    {
        $this->fotka();

        $this->patchJson('/api/state', ['data' => [
            'inboxSt' => ['inbox:fotky-bez-data' => 'snooze'],
            'xRows' => ['inbox' => [['t' => '1 fotka bez data', 'klic' => 'inbox:fotky-bez-data']]],
        ]])->assertOk();

        $data = $this->getJson('/api/data/system')->assertOk()->json('data.AL');

        $this->assertNotContains('inbox:fotky-bez-data', array_column($data['inbox'] ?? [], 7));
        $this->assertStringContainsString('odloženo do', $data['snoozed'][0][1]);

        // Po týdnu je zpátky — odložit není totéž co smazat.
        $this->travel(8)->days();

        $data = $this->getJson('/api/data/system')->assertOk()->json('data.AL');

        $this->assertContains('inbox:fotky-bez-data', array_column($data['inbox'], 7));
        $this->assertSame([], $data['snoozed']);
    }

    /** „Probudit" odložení zruší. */
    public function test_probuzeni_zrusi_odlozeni(): void
    {
        $this->fotka();

        $this->patchJson('/api/state', ['data' => [
            'inboxSt' => ['inbox:fotky-bez-data' => 'snooze'],
            'xRows' => ['inbox' => [['t' => '1 fotka bez data', 'klic' => 'inbox:fotky-bez-data']]],
        ]])->assertOk();

        $this->patchJson('/api/state', ['data' => ['inboxSt' => ['inbox:fotky-bez-data' => 'act']]])->assertOk();

        $data = $this->getJson('/api/data/system')->assertOk()->json('data.AL');

        $this->assertContains('inbox:fotky-bez-data', array_column($data['inbox'], 7));
        $this->assertSame([], $data['snoozed']);
    }

    /**
     * Rozhodnutí drží klíč, ne text řádku.
     *
     * Kdyby se ukládal popisek, přibyla by jedna fotka bez data, řádek by se
     * z „1 fotky" přejmenoval na „2 fotky" a odložení by tiše přestalo platit.
     */
    public function test_rozhodnuti_prezije_zmenu_poctu(): void
    {
        $this->fotka();

        $this->patchJson('/api/state', ['data' => [
            'rowDone' => ['inbox:fotky-bez-data' => true],
            'xRows' => ['inbox' => [['t' => '1 fotka bez data', 'klic' => 'inbox:fotky-bez-data']]],
        ]])->assertOk();

        $this->fotka([], 'IMG_2.jpg');

        $data = $this->getJson('/api/data/system')->assertOk()->json('data.AL');

        $this->assertNotContains('inbox:fotky-bez-data', array_column($data['inbox'] ?? [], 7));
        $this->assertSame('1 fotka bez data', $data['inboxDone'][0][0]);
    }

    /**
     * Nákupní seznam ze surovin naplánovaných jídel — a odškrtnutí se uloží.
     *
     * Ukázka měla „Rajčata 1 kg · z receptu Rajčatová polévka" u dvojice, která
     * si žádné jídlo nenaplánovala, a „Koupeno" přeškrtlo řádek jen v prohlížeči.
     */
    public function test_nakupni_seznam_a_odskrtnuti(): void
    {
        $recept = DB::table('recipes')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Rajčatová polévka',
            'base_servings' => 2,
            'status' => 'published',
            'created_by' => $this->adri->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([['Rajčata', 'kg', 1, false], ['Sůl', '', null, true]] as $i => [$nazev, $jednotka, $kolik, $spiz]) {
            DB::table('recipe_ingredients')->insert([
                'recipe_id' => $recept,
                'name' => $nazev,
                'unit' => $jednotka,
                'quantity' => $kolik,
                'is_scalable' => true,
                'is_optional' => false,
                'is_pantry' => $spiz,
                'sort_order' => $i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('planned_meals')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'recipe_id' => $recept,
            'planned_for' => now()->addDay(),
            'meal_type' => 'dinner',
            'servings' => 4,
            'status' => 'planned',
            'created_by' => $this->adri->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $seznam = $this->getJson('/api/data/kucharka')->assertOk()->json('data.AL.shopping');

        // Čtyři porce z receptu na dvě: rajčata se zdvojnásobí.
        $this->assertSame('Rajčata 2 kg', $seznam[0][0]);
        $this->assertSame('z receptu Rajčatová polévka', $seznam[0][1]);
        $this->assertNull($seznam[0][2]);
        // Sůl je spížová — o té aplikace neví, jestli doma je.
        $this->assertCount(1, $seznam);

        $klic = $seznam[0][7];
        $this->patchJson('/api/state', ['data' => ['rowDone' => [$klic => true]]])->assertOk();

        $seznam = $this->getJson('/api/data/kucharka')->assertOk()->json('data.AL.shopping');
        $this->assertSame('koupeno', $seznam[0][2]);

        // A do stavu se to neukládá — má tabulku.
        $stav = (array) $this->getJson('/api/state')->assertOk()->json('data');
        $this->assertArrayNotHasKey('rowDone', $stav);
    }

    /**
     * „Prodloužit o 30 dní" doopravdy prodlouží.
     *
     * Tlačítko ve statistice odkazu ohlásilo „Expirace prodloužena" a odkaz
     * vypršel přesně tak, jak měl — přitom je to to, čím člověk zachraňuje
     * odkaz, který má někomu ještě fungovat.
     */
    public function test_prodlouzeni_odkazu(): void
    {
        $odkaz = DB::table('shared_links')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'token' => Str::random(24),
            'created_by' => $this->adri->id,
            'gallery_space_id' => $this->prostor->id,
            'target_type' => 'selection',
            'name' => 'Fotky pro babičku',
            'expires_at' => now()->addDays(3),
            'is_active' => true,
            'use_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/sdileni/'.$odkaz.'/prodlouzit')->assertOk()->assertJson(['ok' => true]);

        /*
         * Počítá se od stávající platnosti, ne ode dneška: u odkazu, který
         * platí ještě tři dny, by prodloužení od dneška bylo zkrácení o tři.
         *
         * Porovnává se **datum**, ne rozdíl ve dnech. `round()` na rozdílu od
         * půlnoci vycházel odpoledne o den výš, takže test procházel dopoledne
         * a po obědě padal — a nešlo poznat, jestli je rozbité prodlužování,
         * nebo jen hodina, ve které se pouští testy.
         */
        $do = CarbonImmutable::parse(DB::table('shared_links')->where('id', $odkaz)->value('expires_at'));
        $this->assertSame(now()->addDays(33)->toDateString(), $do->toDateString());
    }

    /** Prošlý odkaz se prodlužuje ode dneška, ne od data, které minulo. */
    public function test_prodlouzeni_prosleho_odkazu(): void
    {
        $odkaz = DB::table('shared_links')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'token' => Str::random(24),
            'created_by' => $this->adri->id,
            'gallery_space_id' => $this->prostor->id,
            'target_type' => 'selection',
            'name' => 'Starý odkaz',
            'expires_at' => now()->subMonths(2),
            'is_active' => false,
            'use_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/sdileni/'.$odkaz.'/prodlouzit')->assertOk();

        $radek = DB::table('shared_links')->where('id', $odkaz)->first();
        $do = CarbonImmutable::parse($radek->expires_at);

        // Datum, ne rozdíl ve dnech — viz test výš.
        $this->assertSame(now()->addDays(30)->toDateString(), $do->toDateString());
        $this->assertTrue((bool) $radek->is_active);
    }

    /** Cizí klíče v `rowDone` projdou beze změny — patří jiným obrazovkám. */
    public function test_cizi_klice_v_rowdone_zustanou(): void
    {
        $this->patchJson('/api/state', ['data' => [
            'rowDone' => ['inbox:fotky-bez-data' => true, 'shopping-3' => true],
        ]])->assertOk();

        $stav = (array) $this->getJson('/api/state')->assertOk()->json('data');

        $this->assertSame(['shopping-3' => true], $stav['rowDone']);
    }
}
