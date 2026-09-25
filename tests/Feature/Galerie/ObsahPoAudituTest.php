<?php

namespace Tests\Feature\Galerie;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\StorageConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Nálezy auditu obsahu `system`, `knihovna` a `dnes`.
 *
 * Každý test je jedna věc, kterou obrazovka tvrdila špatně: řádek, který na
 * MySQL shodil celou skupinu, zašifrovaná zpráva, host na místě partnera,
 * partnerův soukromý cíl, fotky z trezoru v počtech.
 */
class ObsahPoAudituTest extends TestCase
{
    use DvojiceSHostem, RefreshDatabase;

    private User $vlastnik;

    private User $partner;

    private User $host;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 10:00:00'));
        [$this->vlastnik, $this->partner, $this->host, $this->prostor] = $this->dvojiceSHostem();
        $this->vlastnik->update(['name' => 'Bára']);
        $this->partner->update(['name' => 'Ctibor']);
        $this->host->update(['name' => 'Hostina', 'email' => 'hostina@host.test']);
        Sanctum::actingAs($this->vlastnik);
    }

    /**
     * `trips` nemá `deleted_at`. Na MySQL podmínka na něj shodila celou skupinu
     * `system` (koš, trezor, zámek, oznámení); SQLite ji tiše vyhodnotí jako
     * řetězec a řádek jen zmizí.
     */
    public function test_utrata_na_ceste_se_ukaze_jen_ze_skutecnych_utrat(): void
    {
        $cesta = DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->prostor->id, 'created_by' => $this->vlastnik->id, 'name' => 'Beskydy',
            'start_date' => '2026-09-01', 'end_date' => '2026-09-05', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->utrata($cesta, 1200, 'actual');
        $this->utrata($cesta, 300, 'actual');
        // Plán ještě utracený není.
        $this->utrata($cesta, 9000, 'planned');
        // Jiná měna se ke korunám nepřičítá.
        $this->utrata($cesta, 50, 'actual', 'EUR');

        $radek = collect($this->getJson('/api/data/system')->assertOk()->json('data.DATA_HEALTH'))
            ->firstWhere('label', 'Útrata na cestě Beskydy');

        $this->assertNotNull($radek, 'Řádek o cestě chybí — dotaz na neexistující sloupec.');
        $this->assertSame('1 500 Kč', str_replace("\u{00A0}", ' ', $radek['value']));
        $this->assertStringContainsString('2 položky', $radek['where']);
    }

    /** Tělo zprávy je v databázi zašifrované; přehled dne ukazoval šifru. */
    public function test_zprava_dne_je_citelna_a_jen_z_mych_hovoru(): void
    {
        ChatMessage::create(['gallery_space_id' => $this->prostor->id, 'created_by' => $this->partner->id, 'body' => 'Ahoj z kopce']);

        // Soukromý hovor hosta s partnerem — do mého dne nepatří.
        $cizi = Conversation::create(['gallery_space_id' => $this->prostor->id, 'created_by' => $this->partner->id, 'kind' => Conversation::KIND_DIRECT]);
        $cizi->participants()->create(['user_id' => $this->partner->id]);
        $cizi->participants()->create(['user_id' => $this->host->id]);
        ChatMessage::create(['gallery_space_id' => $this->prostor->id, 'conversation_id' => $cizi->id, 'created_by' => $this->partner->id, 'body' => 'Tajnost pro hosta']);

        $odpoved = $this->getJson('/api/data/dnes')->assertOk();
        $texty = array_column($odpoved->json('data.DNES.den.radky'), 3);

        $this->assertContains('Ctibor: „Ahoj z kopce"', $texty);
        $this->assertStringNotContainsString('eyJpdiI6', $odpoved->getContent());
        $this->assertStringNotContainsString('Tajnost', $odpoved->getContent());
    }

    /** Partnerův soukromý rozpočet (a jeho cíl) se na úvodní obrazovce neukáže. */
    public function test_cil_dne_jen_z_viditelneho_rozpoctu(): void
    {
        $soukromy = $this->rozpocet($this->partner->id);
        $this->cil($soukromy, 'Partnerovo překvapení');

        $this->assertNull($this->getJson('/api/data/dnes')->assertOk()->json('data.DNES.cil'));

        $spolecny = $this->rozpocet(null);
        $this->cil($spolecny, 'Dovolená');

        $this->assertSame('Dovolená', $this->getJson('/api/data/dnes')->assertOk()->json('data.DNES.cil.name'));
    }

    /** Host není partner — ani ve jménech dvojice, ani v přihlášení, ani u Disku. */
    public function test_host_neni_ve_dvojici(): void
    {
        StorageConnection::create([
            'provider' => 'google_drive', 'gallery_space_id' => $this->prostor->id, 'owner_user_id' => $this->host->id,
            'account_email' => 'hostina@host.test', 'connection_status' => 'error', 'connected_at' => now(),
        ]);
        $this->snimek(['uploaded_by' => $this->partner->id]);

        $odpoved = $this->getJson('/api/data/system')->assertOk();
        $data = $odpoved->json('data');

        $this->assertSame(['Bára', 'Ctibor'], $data['DVOJICE']);
        $this->assertSame(['A' => 'Bára', 'M' => 'Ctibor'], $data['LOCKWHO']);
        $this->assertNotContains('hostina@host.test', $data['LOCKMAIL']);
        $this->assertSame(['Bára', 'Ctibor'], array_column($data['UCTY'], 0));
        $knihovna = collect($data['SECLIFE'])->firstWhere('id', 'l4');
        $this->assertSame('Ctibor', $knihovna['mName']);
        // Jinde host být nemá; seznam účtů ve správě (`AL.users`) ho vypisuje schválně, jako hosta.
        $mimoSpravu = json_encode(array_diff_key($data, ['AL' => 1]), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Hostina', $mimoSpravu);
        $this->assertStringNotContainsString('hostina@host.test', $mimoSpravu);
        $this->assertContains('host · nikdy', array_column($data['AL']['users'], 1));
    }

    /** Lidé na fotkách se počítají jen z mřížky — ne z trezoru a koše. */
    public function test_pocty_lidi_bez_trezoru_a_kose(): void
    {
        $klara = Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Klára']);
        $jan = Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Jan']);
        $videt = $this->snimek();
        $skryta = $this->snimek(['is_hidden' => true]);
        $vKosi = $this->snimek(['trashed_at' => now()]);

        foreach ([$videt, $skryta, $vKosi] as $f) {
            foreach ([$klara, $jan] as $o) {
                DB::table('media_person')->insert(['media_item_id' => $f->id, 'person_id' => $o->id, 'created_at' => now()]);
            }
        }

        $lide = $this->sOdemcenymTrezorem($this->vlastnik)->getJson('/api/data/knihovna')->assertOk()->json('data.PERSONS');

        $this->assertStringStartsWith('1 fotka', $lide['Klára']['meta']);
        $this->assertSame('1 spolu', $lide['Klára']['co'][0][1]);
    }

    /** Převod mezi vlastními účty ani koncept nejsou útrata. */
    public function test_zbyva_v_rozpoctu_pocita_jen_utraty(): void
    {
        $rozpocet = $this->rozpocet(null);
        DB::table('budget_category_limits')->insert([
            'budget_id' => $rozpocet, 'finance_category_id' => $this->kategorie(), 'amount' => 10000, 'priority' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->transakce(['amount_from' => 1000]);
        $this->transakce(['amount_from' => 5000, 'type' => 'transfer']);
        $this->transakce(['amount_from' => 700, 'state' => 'draft']);
        $this->transakce(['amount_from' => 400, 'excluded_from_budget' => true, 'exclusion_reason' => 'Vratka']);
        $this->transakce(['amount_from' => 20, 'currency_from' => 'EUR']);

        $radek = collect($this->getJson('/api/data/system')->assertOk()->json('data.DATA_HEALTH'))
            ->firstWhere('label', 'Zbývá v rozpočtu tento měsíc');

        $this->assertSame('9 000 Kč', str_replace("\u{00A0}", ' ', $radek['value']));
    }

    /** Se zamčeným trezorem se fotky z něj nepočítají nikde — rozdíl by ho prozradil. */
    public function test_pocty_bez_trezoru(): void
    {
        // S místem, ať úklid nemá co doplnit — počítají se jen duplicity.
        $this->snimek(['location_name' => 'Praha']);
        $skryta = $this->snimek(['is_hidden' => true]);
        $skryta2 = $this->snimek(['is_hidden' => true]);
        $skupina = DB::table('duplicate_groups')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'match_type' => 'exact',
            'resolution' => 'unresolved', 'detected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$skryta, $skryta2] as $m) {
            DB::table('duplicate_group_items')->insert(['duplicate_group_id' => $skupina, 'media_item_id' => $m->id, 'is_kept' => false, 'created_at' => now(), 'updated_at' => now()]);
        }

        $system = $this->getJson('/api/data/system')->assertOk()->json('data');
        $this->assertSame('1', collect($system['DATA_HEALTH'])->firstWhere('label', 'Počet položek v knihovně')['value']);
        $this->assertSame(1, collect($system['SECLIFE'])->firstWhere('id', 'l4')['a']);

        $knihovna = $this->getJson('/api/data/knihovna')->assertOk()->json('data');
        $this->assertSame('', $knihovna['NAVCNT']['x-uklid'], 'Skupina duplicit jen z trezoru se nepočítá.');

        $navrhy = $this->getJson('/api/data/dnes')->assertOk()->json('data.DNES.navrhy');
        $this->assertNotContains('x-uklid', array_column($navrhy, 'route'));
    }

    /** Nezařazené transakce jsou jen živé výdaje a příjmy, ne převody a smazané. */
    public function test_inbox_pocita_jen_zaraditelne_transakce(): void
    {
        $this->transakce(['category_id' => null]);
        $this->transakce(['category_id' => null, 'type' => 'transfer']);
        $this->transakce(['category_id' => null, 'deleted_at' => now()]);

        $inbox = $this->getJson('/api/data/system')->assertOk()->json('data.AL.inbox');

        $this->assertContains('1 nezařazená transakce', array_column($inbox, 0));
    }

    /** Odznak oblíbených je můj, jako mřížka — ne partnerovo srdíčko. */
    public function test_oblibene_v_odznaku_jsou_moje(): void
    {
        $moje = $this->snimek();
        $partnerova = $this->snimek(['is_favorite' => true]);
        DB::table('user_favorites')->insert(['user_id' => $this->vlastnik->id, 'media_item_id' => $moje->id, 'created_at' => now()]);
        DB::table('user_favorites')->insert(['user_id' => $this->partner->id, 'media_item_id' => $partnerova->id, 'created_at' => now()]);

        $data = $this->getJson('/api/data/knihovna')->assertOk()->json('data');

        $this->assertSame('1', $data['NAVCNT']['favorites']);
        $this->assertSame(1, $data['LIBSTATS']['favs']);
    }

    /** Lidé se vybírají podle počtu fotek, ne jak je databáze zrovna vrátí. */
    public function test_do_lidi_se_vejdou_ti_s_nejvic_fotkami(): void
    {
        $fotka = $this->snimek();
        for ($i = 1; $i <= 31; $i++) {
            Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Osoba '.$i]);
        }
        $posledni = Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Vyfocená']);
        DB::table('media_person')->insert(['media_item_id' => $fotka->id, 'person_id' => $posledni->id, 'created_at' => now()]);

        $lide = $this->getJson('/api/data/knihovna')->assertOk()->json('data.PERSONS');

        $this->assertArrayHasKey('Vyfocená', $lide);
        $this->assertSame('Vyfocená', array_key_first($lide));
    }

    // ——— pomůcky ———

    private function utrata(int $cesta, float $castka, string $stav, string $mena = 'CZK'): void
    {
        DB::table('trip_expenses')->insert([
            'trip_id' => $cesta, 'created_by' => $this->vlastnik->id, 'title' => 'Útrata', 'amount' => $castka,
            'currency' => $mena, 'state' => $stav, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rozpocet(?int $vlastnik): int
    {
        return DB::table('budgets')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $vlastnik ?? $this->vlastnik->id,
            'owner_user_id' => $vlastnik, 'name' => 'Rozpočet', 'currency' => 'CZK', 'starts_on' => now()->startOfMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cil(int $rozpocet, string $nazev): void
    {
        DB::table('budget_goals')->insert([
            'uuid' => (string) Str::uuid(), 'budget_id' => $rozpocet, 'name' => $nazev, 'target_amount' => 10000,
            'saved_amount' => 1000, 'currency' => 'CZK', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function kategorie(): int
    {
        return DB::table('finance_categories')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'name' => 'Potraviny', 'kind' => 'expense',
            'is_favourite' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function transakce(array $navic = []): void
    {
        DB::table('transactions')->insert(array_merge([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->vlastnik->id,
            'type' => 'expense', 'state' => 'approved', 'occurred_at' => now()->toDateString(), 'amount_from' => 100,
            'currency_from' => 'CZK', 'description' => 'Výdaj', 'created_at' => now(), 'updated_at' => now(),
        ], $navic));
    }

    private function snimek(array $navic = []): MediaItem
    {
        return $this->fotka($this->prostor, $this->vlastnik, 'IMG_'.Str::random(6).'.jpg', $navic);
    }
}
