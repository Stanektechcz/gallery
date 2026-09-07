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
 * Seznamy `AL` ze skutečných tabulek — a zpátky.
 *
 * `xRows` je společný sklad třiceti dvou seznamů napříč aplikací a většina
 * z nich se kreslila z `galerie-data.js`: vedle skutečné cesty do Chorvatska
 * stál Lisabon někoho cizího, v kuchařce cizí recepty, v účtech „Revolut ·
 * Adrian" se synchronizací, která nikdy neproběhla.
 */
class SeznamyTest extends TestCase
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

    /**
     * Jedna fotka v knihovně.
     *
     * Prázdná knihovna se celá neposílá — prázdná mřížka a nenačtená aplikace
     * vypadají z pohledu člověka stejně — takže testy, které se ptají na její
     * seznamy, potřebují mít v knihovně aspoň něco.
     */
    private function fotka(string $soubor = 'IMG_1.jpg'): MediaItem
    {
        return MediaItem::create([
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
        ]);
    }

    /** Uložená randíčka chodí z tabulky, ne z ukázky. */
    public function test_randicka_chodi_ze_serveru(): void
    {
        DB::table('couple_date_ideas')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'generation_key' => '',
            'title' => 'Kolo k přehradě a zmrzlina',
            'summary' => '',
            'theme' => '',
            'estimated_minutes' => 0,
            'parameters' => json_encode([]),
            'plan' => json_encode([]),
            'status' => 'saved',
            'estimated_cost' => 320,
            'currency' => 'CZK',
            'created_at' => CarbonImmutable::parse('2026-06-12'),
            'updated_at' => now(),
        ]);

        $radek = $this->getJson('/api/data/vztah')->assertOk()->json('data.AL.datesSaved.0');

        $this->assertSame('Kolo k přehradě a zmrzlina', $radek[0]);
        $this->assertStringContainsString('uloženo 12. 6.', $radek[1]);
        $this->assertStringContainsString('320 CZK', $radek[1]);
        $this->assertSame('uloženo', $radek[2]);
    }

    /** Nový nápad na randíčko se zapíše do tabulky. */
    public function test_novy_napad_na_randicko_se_ulozi(): void
    {
        $this->patchJson('/api/state', ['data' => ['xRows' => [
            'datesSaved' => [['t' => 'Noční pozorování hvězd', 'm' => 'právě uloženo', 'g' => 'uloženo', 'id' => 'datesSaved-n1']],
        ]]])->assertOk();

        $this->assertSame('Noční pozorování hvězd', DB::table('couple_date_ideas')->value('title'));
        $this->assertSame('saved', DB::table('couple_date_ideas')->value('status'));

        // A do stavu se neukládá — má tabulku.
        $stav = (array) $this->getJson('/api/state')->assertOk()->json('data');
        $this->assertArrayNotHasKey('xRows', $stav);
    }

    /** Tentýž nápad podruhé druhý řádek nezaloží. */
    public function test_stejny_napad_nevznikne_dvakrat(): void
    {
        $telo = ['data' => ['xRows' => ['datesSaved' => [['t' => 'Keramika pro dva', 'm' => '', 'g' => 'uloženo']]]]];

        $this->patchJson('/api/state', $telo)->assertOk();
        $this->patchJson('/api/state', $telo)->assertOk();

        $this->assertSame(1, DB::table('couple_date_ideas')->count());
    }

    /**
     * Seznam, který počítá server, ve stavu nezůstane.
     *
     * Prototyp čte `xRows[klíč]` přednostně před tím, co dorazilo ze serveru,
     * takže uložený snímek by ten serverový navždy zastínil: vyřešený řádek
     * inboxu by se vracel na místo a nikdo by nepoznal proč. Klíč, který
     * tabulku nemá, naopak zůstává — pro ten je stav jediné místo.
     */
    public function test_spocitany_seznam_ve_stavu_nezustane(): void
    {
        $this->patchJson('/api/state', ['data' => ['xRows' => [
            'datesSaved' => [['t' => 'Keramika pro dva', 'm' => '', 'g' => 'uloženo']],
            'shopping' => [['t' => 'Rajčata 1 kg', 'm' => 'ručně přidáno', 'g' => null]],
            'poznamky' => [['t' => 'Zavolat instalatérovi', 'm' => 'ručně přidáno', 'g' => null]],
        ]]])->assertOk();

        $stav = (array) $this->getJson('/api/state')->assertOk()->json('data');

        $this->assertSame('Zavolat instalatérovi', $stav['xRows']['poznamky'][0]['t']);
        $this->assertArrayNotHasKey('datesSaved', $stav['xRows']);
        $this->assertArrayNotHasKey('shopping', $stav['xRows']);

        // A nápad se přesto uložil tam, kam patří.
        $this->assertSame('Keramika pro dva', DB::table('couple_date_ideas')->value('title'));
    }

    /** Nová jízdenka vznikne, trasa se z názvu nehádá. */
    public function test_jizdenka_se_ulozi_bez_hadani_trasy(): void
    {
        $this->patchJson('/api/state', ['data' => ['xRows' => [
            'ticket' => [['t' => 'Letenky BRQ – LIS', 'm' => 'doplňte trasu a datum', 'g' => 'rezervace']],
        ]]])->assertOk();

        $radek = DB::table('saved_transport_routes')->sole();

        $this->assertSame('Letenky BRQ – LIS', $radek->name);
        $this->assertSame('', $radek->origin, 'Odkud kam doplní člověk — z názvu se to nepozná.');

        $seznam = $this->getJson('/api/data/cesty')->assertOk()->json('data.AL.ticket.0');
        $this->assertSame('Letenky BRQ – LIS', $seznam[0]);
        $this->assertSame('uloženo', $seznam[2]);
    }

    /** Nápad na dárek se pozná podle předpony; příležitost do nápadů nepatří. */
    public function test_z_milniku_se_uklada_jen_napad(): void
    {
        $this->patchJson('/api/state', ['data' => ['xRows' => [
            'gifts' => [
                ['t' => 'Deset let spolu', 'm' => '18. 10. 2026 · za 42 dní', 'g' => 'blíží se'],
                ['t' => 'Nápad: kurz keramiky', 'm' => 'pro Makinku', 'g' => 'nápad'],
            ],
        ]]])->assertOk();

        $this->assertSame(['kurz keramiky'], DB::table('gift_ideas')->pluck('title')->all());
        $this->assertSame('idea', DB::table('gift_ideas')->value('status'));
    }

    /** Zařazení v cestovní schránce se zapíše; nová položka odsud nevzniká. */
    public function test_zarazeni_v_cestovni_schrance(): void
    {
        DB::table('travel_inbox_items')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'added_by' => $this->adri->id,
            'title' => 'Odkaz na apartmán v Sintře',
            'kind' => 'odkaz',
            'state' => 'new',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->patchJson('/api/state', ['data' => ['xRows' => [
            'travelInbox' => [
                ['t' => 'Odkaz na apartmán v Sintře', 'm' => 'odkaz · 2. 8.', 'g' => 'zařazeno'],
                ['t' => 'Něco, co v schránce není', 'm' => '', 'g' => 'zařazeno'],
            ],
        ]]])->assertOk();

        $this->assertSame('filed', DB::table('travel_inbox_items')->value('state'));
        $this->assertSame(1, DB::table('travel_inbox_items')->count(), 'Z téhle obrazovky se nové položky nezakládají.');
    }

    /** Účty v seznamu jsou skutečné peněženky. */
    public function test_ucty_v_seznamu_jsou_skutecne(): void
    {
        DB::table('wallets')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'name' => 'EUR karta',
            'kind' => 'bank',
            'currency' => 'EUR',
            'opening_balance' => 500,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $radek = $this->getJson('/api/data/finance')->assertOk()->json('data.AL.accounts.0');

        $this->assertSame('EUR karta', $radek[0]);
        $this->assertSame('napojeno', $radek[2]);
    }

    /**
     * Akční inbox se počítá z toho, co opravdu chybí.
     *
     * Obrazovka měla čtyři napsané řádky — „12 fotek bez data",
     * „Nezařazená transakce 1 240 Kč". U dvojice, která má uklizeno,
     * to byla práce, kterou nikdo nemá.
     */
    public function test_akcni_inbox_se_pocita(): void
    {
        // Prázdný, ne chybějící: prototyp má pro prázdný inbox napsané
        // „Inbox je prázdný", takže ukázka na jeho místě není potřeba.
        $this->assertSame([], $this->getJson('/api/data/system')->assertOk()->json('data.AL.inbox'));

        $this->fotka();

        $inbox = $this->getJson('/api/data/system')->assertOk()->json('data.AL.inbox');
        $popisky = array_column($inbox, 0);

        $this->assertContains('1 fotka bez data', $popisky);
        $this->assertContains('1 fotka bez místa', $popisky);
    }

    /**
     * Návrh na sloučení štítků vzniká z opravdu dvojího zápisu, ne z ukázky.
     *
     * Obrazovka nabízela sloučit `#hory + #kopce` u dvojice, která ani jeden
     * z těch štítků nemá. Teď se nabízí jen to, co v tabulce vedle sebe
     * skutečně stojí — a když nic, nenabízí se nic.
     */
    public function test_slucitelne_stitky_se_poznaji_z_dvojiho_zapisu(): void
    {
        $this->fotka();

        // `#letní tábor` a `#letnítábor` mají různý slug, takže vedle sebe
        // existovat můžou. `#hory` nemá s čím splynout.
        foreach (['letní tábor', 'letnítábor', 'hory'] as $jmeno) {
            DB::table('tags')->insert([
                'gallery_space_id' => $this->prostor->id,
                'name' => $jmeno,
                'slug' => Str::slug($jmeno) ?: $jmeno,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $navrhy = $this->getJson('/api/data/knihovna')->assertOk()->json('data.AL.tagMerge');

        $this->assertCount(1, $navrhy, '#hory nemá s čím splynout.');
        $this->assertStringContainsString('#letní tábor', $navrhy[0][0]);
        $this->assertStringContainsString('#letnítábor', $navrhy[0][0]);
    }

    /**
     * Bez dvojího zápisu se posílá prázdný seznam, ne ukázka.
     *
     * Tohle je celý smysl klíče: `tagMerge` chodí i prázdný, aby přepsal
     * `#jidlo + #jídlo` z `galerie-data.js`. Kdyby se neposlal, obrazovka by
     * u uklizené knihovny dál nabízela slučovat štítky, které dvojice nemá.
     */
    public function test_bez_dvojiho_zapisu_chodi_prazdny_seznam(): void
    {
        $this->fotka();

        DB::table('tags')->insert([
            'gallery_space_id' => $this->prostor->id,
            'name' => 'hory',
            'slug' => 'hory',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([], $this->getJson('/api/data/knihovna')->assertOk()->json('data.AL.tagMerge'));
    }
}
