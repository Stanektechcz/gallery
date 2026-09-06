<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deset kolekcí, které tabulku nepotřebovaly — jen někoho, kdo je spočítá.
 *
 * Kdy fotíme, rekonstrukce dne, čas versus služba, co ta útrata znamenala,
 * cena odkladu, odhad proti skutečnosti, nečekané výdaje, rozpory mezi
 * zařízeními, vracející se témata a čas pro sebe.
 */
class ObsahOdvozeneTest extends TestCase
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

        Sanctum::actingAs($this->adri);
    }

    /**
     * Kdy fotíme: `n` je snímků, `reg` kolik různých dnů.
     *
     * Jedno odpoledne se sto snímky není zvyk; deset odpolední po deseti ano.
     */
    public function test_hodiny_rozlisi_zvyk_od_jedne_serie(): void
    {
        // Tři snímky jednoho odpoledne.
        $this->fotka(['taken_at' => '2026-08-01 14:00:00'], 1);
        $this->fotka(['taken_at' => '2026-08-01 14:20:00'], 2);
        $this->fotka(['taken_at' => '2026-08-01 15:00:00'], 3);
        // Dva večery po jednom.
        $this->fotka(['taken_at' => '2026-08-02 21:00:00'], 4);
        $this->fotka(['taken_at' => '2026-08-03 21:30:00'], 5);

        $h = collect($this->getJson('/api/data/knihovna')->assertOk()->json('data.HOURS'))->keyBy('label');

        $this->assertSame(3, $h['12–17']['n']);
        $this->assertSame(1, $h['12–17']['reg']);
        $this->assertSame(2, $h['20–22']['n']);
        $this->assertSame(2, $h['20–22']['reg']);
        // Pásmo, ve kterém se nefotí, se neposílá.
        $this->assertArrayNotHasKey('do 12:00', $h);
    }

    /**
     * Rekonstrukce skládá den z fotek a plateb — a nic si nevypráví.
     *
     * Díra v datech se hlásí, ne zaplňuje.
     */
    public function test_rekonstrukce_sklada_den_z_dat(): void
    {
        $this->fotka(['taken_at' => '2026-08-01 08:00:00', 'location_name' => 'Zadar'], 1);
        $this->fotka(['taken_at' => '2026-08-01 08:10:00', 'location_name' => 'Zadar'], 2);
        $this->fotka(['taken_at' => '2026-08-01 18:00:00', 'location_name' => 'Přístav'], 3);

        DB::table('transactions')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'type' => 'expense',
            'occurred_at' => '2026-08-01 12:30:00',
            'amount_from' => 690,
            'currency_from' => 'CZK',
            'description' => 'Oběd',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $d = $this->getJson('/api/data/pribeh')->assertOk()->json('data.RECON.2026-08-01');

        $this->assertSame('Sobota 1. srpna 2026', $d['title']);
        $this->assertSame(['8:00', '2 fotky za 10 minut — Zadar.', 'fotky · 2 snímky za sebou', 'ph-camera'], $d['steps'][0]);
        $this->assertSame('12:30', $d['steps'][1][0]);
        $this->assertStringContainsString('690 Kč', $d['steps'][1][1]);
        // Mezi obědem a přístavem je pět a půl hodiny prázdna.
        $this->assertStringContainsString('nejsou žádná data', $d['gap']);
    }

    /** Den o jednom kroku není rekonstrukce, je to jedna fotka. */
    public function test_den_o_jednom_kroku_se_neposila(): void
    {
        $this->fotka(['taken_at' => '2026-08-01 08:00:00']);

        $data = $this->getJson('/api/data/pribeh')->assertOk()->json('data');

        $this->assertArrayNotHasKey('RECON', $data);
    }

    /**
     * Čas versus služba: hodiny z protokolu, cena služby nula.
     *
     * Ceník úklidové firmy aplikace nezná a vymyslet ho by znamenalo tvrdit
     * dvojici, že se jí vyplatí něco, co nikdo nenacenil.
     */
    public function test_cas_versus_sluzba_necenikuje(): void
    {
        foreach ([[$this->maki, 120], [$this->maki, 240], [$this->adri, 60]] as $i => [$kdo, $minut]) {
            DB::table('house_chore_log')->insert([
                'uuid' => (string) Str::uuid(),
                'gallery_space_id' => $this->prostor->id,
                'user_id' => $kdo->id,
                'chore_name' => 'Úklid celého bytu',
                'minutes' => $minut,
                'done_at' => now()->subDays($i + 1),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $c = $this->getJson('/api/data/mechanismy')->assertOk()->json('data.CAS_ROWS.0');

        $this->assertSame('Úklid celého bytu', $c['task']);
        // 420 minut za čtvrt roku → 2,3 hodiny měsíčně.
        $this->assertEqualsWithDelta(2.3, $c['hours'], 0.05);
        $this->assertSame(0, $c['service']);
        // Kdo to dělá nejčastěji, ne kdo naposledy.
        $this->assertSame('Makinka', $c['doer']);
    }

    /** Co ta útrata znamenala: podíl na letošních výdajích, žádné příběhy. */
    public function test_co_to_znamenalo_pocita_podil(): void
    {
        $jidlo = $this->kategorie('Potraviny');
        $this->transakce(7500, $jidlo);
        $this->transakce(2500, $this->kategorie('Kultura'));

        $z = collect($this->getJson('/api/data/rozbory')->assertOk()->json('data.COSTMEAN'))->keyBy(0);

        $this->assertSame(7500, $z['Potraviny'][1]);
        $this->assertSame('75 % letošních výdajů', $z['Potraviny'][2]);
        $this->assertSame('1 platba za tenhle rok.', $z['Potraviny'][3]);
    }

    /** Cena odkladu se bere ze zapsané, ne dopočítává. */
    public function test_cena_odkladu_je_zapsana(): void
    {
        DB::table('house_dues')->insert([
            [
                'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id,
                'what' => 'Reklamace pračky', 'kind' => 'lhůta',
                'due_on' => now()->subDays(41)->toDateString(),
                'delay_cost' => 4200, 'delay_note' => 'Reklamace pračky po lhůtě',
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                // Bez zapsané ceny odkladu se řádek neposílá.
                'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id,
                'what' => 'Servis kola', 'kind' => 'lhůta',
                'due_on' => now()->subDays(10)->toDateString(),
                'delay_cost' => null, 'delay_note' => null,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $d = $this->getJson('/api/data/rozbory')->assertOk()->json('data.DELAY');

        $this->assertCount(1, $d);
        $this->assertSame('Reklamace pračky po lhůtě', $d[0]['what']);
        $this->assertSame(41, $d[0]['days']);
        $this->assertSame(4200, $d[0]['cost']);
        $this->assertTrue($d[0]['open']);
    }

    /** Odhad proti skutečnosti potřebuje obojí — polovina neříká nic. */
    public function test_odhad_potrebuje_obe_strany(): void
    {
        $rozpocet = $this->rozpocet();
        $jidlo = $this->kategorie('Potraviny');
        $kultura = $this->kategorie('Kultura');

        $this->limit($rozpocet, $jidlo, 6000);
        $this->limit($rozpocet, $kultura, 1200);
        $this->transakce(8450, $jidlo);

        $e = $this->getJson('/api/data/rozbory')->assertOk()->json('data.EST');

        $this->assertCount(1, $e);
        $this->assertSame('Potraviny', $e[0]['name']);
        $this->assertSame(6000, $e[0]['est']);
        $this->assertSame(8450, $e[0]['real']);
    }

    /**
     * Nečekaný je ten výdaj, na který si dvojice nedala hranici.
     *
     * A jen ten velký — nečekaný výdaj za osmdesát korun je oběd.
     */
    public function test_necekany_vydaj_nema_limit_a_je_velky(): void
    {
        $rozpocet = $this->rozpocet();
        $jidlo = $this->kategorie('Potraviny');
        $this->limit($rozpocet, $jidlo, 6000);

        // Deset běžných útrat, aby se dala spočítat hranice.
        foreach (range(1, 10) as $i) {
            $this->transakce(200, $jidlo);
        }

        $this->transakce(9200, null, 'Zubní korunka');
        $this->transakce(150, null, 'Drobnost');

        $n = $this->getJson('/api/data/rozbory')->assertOk()->json('data.SURPRISE');

        $this->assertCount(1, $n);
        $this->assertSame('Zubní korunka', $n[0]['what']);
        $this->assertSame(9200, $n[0]['cost']);
    }

    /** Rozpor mezi zařízeními ukáže obě verze — sloučenou si vybere dvojice. */
    public function test_rozpor_ukaze_obe_verze(): void
    {
        $spojeni = DB::table('storage_connections')->insertGetId([
            'gallery_space_id' => $this->prostor->id,
            'provider' => 'google_drive',
            'owner_user_id' => $this->adri->id,
            'connection_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foto = $this->fotka(['original_filename' => 'IMG_2418.jpg']);

        DB::table('drive_conflicts')->insert([
            'storage_connection_id' => $spojeni,
            'entity_type' => 'media_item',
            'entity_id' => $foto->id,
            'conflict_type' => 'both_changed',
            'app_state' => json_encode(['caption' => 'Ráno, než se objevili ostatní']),
            'drive_state' => json_encode(['caption' => 'Nad mlhou, 5:48']),
            'detected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $r = $this->getJson('/api/data/system')->assertOk()->json('data.CONFLICTS.0');

        $this->assertSame('Knihovna — IMG_2418.jpg', $r[1]);
        $this->assertSame('Ráno, než se objevili ostatní', $r[4]);
        $this->assertSame('Nad mlhou, 5:48', $r[6]);
        // Sloučenou verzi si dvojice vybere sama.
        $this->assertSame('', $r[8]);
    }

    /** Téma je „dohoda" jen tehdy, když k němu existuje zapsané rozhodnutí. */
    public function test_tema_je_dohoda_jen_se_zapsanym_rozhodnutim(): void
    {
        DB::table('couple_disagreement_points')->insert([
            [
                'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id,
                'author_user_id' => $this->adri->id, 'topic' => 'Koupelna',
                'text' => 'Teď na to nemáme.', 'kind' => 'mine',
                'created_at' => now()->subMonths(3), 'updated_at' => now(),
            ],
            [
                'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id,
                'author_user_id' => $this->maki->id, 'topic' => 'Gauč',
                'text' => 'Ten starý ještě vydrží.', 'kind' => 'theirs',
                'created_at' => now()->subMonth(), 'updated_at' => now(),
            ],
        ]);

        DB::table('couple_decisions')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Gauč',
            'decided_by' => $this->adri->id,
            'decided_on' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $d = collect($this->getJson('/api/data/vztah')->assertOk()->json('data.DISP'))->keyBy('topic');

        $this->assertSame('odloženo', $d['Koupelna']['end']);
        $this->assertSame('dohoda', $d['Gauč']['end']);
        // Cenu odkladu nikdo neměří.
        $this->assertSame(0, $d['Koupelna']['cost']);
    }

    /** Čas pro sebe je událost, u které je jen jeden z dvojice. */
    public function test_cas_pro_sebe_je_udalost_jednoho(): void
    {
        $sam = $this->udalost(now()->subDays(2));
        $spolu = $this->udalost(now()->subDays(3));

        DB::table('event_participants')->insert([
            ['event_id' => $sam, 'user_id' => $this->adri->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()],
            ['event_id' => $spolu, 'user_id' => $this->adri->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()],
            ['event_id' => $spolu, 'user_id' => $this->maki->id, 'role' => 'guest', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $s = $this->getJson('/api/data/klid')->assertOk()->json('data.SOLO');

        $this->assertCount(1, $s);
        $this->assertSame(1, $s[0]['a']);
        $this->assertSame(0, $s[0]['k']);
    }

    // ——— pomůcky ———

    private function kategorie(string $nazev): int
    {
        return DB::table('finance_categories')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'name' => $nazev,
            'kind' => 'expense',
            'is_favourite' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rozpocet(): int
    {
        return DB::table('budgets')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'name' => 'Rozpočet',
            'currency' => 'CZK',
            'starts_on' => now()->startOfYear()->toDateString(),
            'is_shared' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function limit(int $rozpocet, int $kategorie, int $castka): void
    {
        DB::table('budget_category_limits')->insert([
            'budget_id' => $rozpocet,
            'finance_category_id' => $kategorie,
            'amount' => $castka,
            'priority' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function transakce(int $castka, ?int $kategorie = null, string $popis = 'Výdaj'): void
    {
        DB::table('transactions')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'type' => 'expense',
            'occurred_at' => now()->startOfYear()->addMonth()->toDateString(),
            'amount_from' => $castka,
            'currency_from' => 'CZK',
            'category_id' => $kategorie,
            'description' => $popis,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function udalost($kdy): int
    {
        return DB::table('calendar_events')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Trénink',
            'type' => 'event',
            'status' => 'planned',
            'starts_at' => $kdy,
            'ends_at' => $kdy->copy()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function fotka(array $navic = [], int $poradi = 1): MediaItem
    {
        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG_'.$poradi.'.jpg',
            'safe_filename' => 'img-'.$poradi.'.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 2_097_152,
            'taken_at' => now(),
            'uploaded_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }
}
