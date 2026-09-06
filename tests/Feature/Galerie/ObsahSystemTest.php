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
 * Zdraví dat, život sekcí a sloupce administrace.
 *
 * „Zdraví dat" je jediné místo, kde aplikace přiznává, čemu se dá věřit —
 * a psalo se v něm o zůstatku 38 412 Kč a portfoliu za 961 700 Kč, které
 * dvojice nemá. Obrazovka o důvěryhodnosti čísel byla nejmíň důvěryhodná.
 */
class ObsahSystemTest extends TestCase
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

    /** Prázdná aplikace o sobě nic netvrdí. */
    public function test_bez_dat_se_zdravi_neposila(): void
    {
        $data = $this->getJson('/api/data/system')->assertOk()->json('data');

        $this->assertArrayNotHasKey('DATA_HEALTH', $data);
    }

    /** Knihovna je tvrdé číslo — a je to skutečný počet. */
    public function test_knihovna_je_tvrde_cislo(): void
    {
        $this->fotka();
        $this->fotka([], 2);
        $this->fotka(['trashed_at' => now()], 3);
        $this->fotka(['is_archived' => true], 4);

        $r = collect($this->getJson('/api/data/system')->assertOk()->json('data.DATA_HEALTH'))
            ->firstWhere('label', 'Počet položek v knihovně');

        // Koš ani karanténa se nepočítají.
        $this->assertSame('2', $r['value']);
        $this->assertSame('hard', $r['kind']);
        $this->assertFalse($r['stale']);
    }

    /** Účet bez napojení na banku je ruční zápis, ne tvrdé číslo. */
    public function test_ucet_bez_napojeni_je_rucni(): void
    {
        $this->penezenka();

        $r = collect($this->getJson('/api/data/system')->assertOk()->json('data.DATA_HEALTH'))
            ->firstWhere('label', 'Zůstatek na účtech');

        $this->assertSame('manual', $r['kind']);
        $this->assertSame(72, $r['conf']);
        $this->assertStringContainsString('nejsou napojené', $r['note']);
    }

    /** S napojenou bankou je zůstatek nejtvrdší číslo v aplikaci. */
    public function test_napojeny_ucet_je_tvrde_cislo(): void
    {
        $this->penezenka();

        DB::table('bank_connections')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'connected_by' => $this->adri->id,
            'provider' => 'csob',
            'institution_name' => 'ČSOB',
            'status' => 'active',
            'sync_enabled' => true,
            'last_synced_at' => now()->subMinutes(12),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $r = collect($this->getJson('/api/data/system')->assertOk()->json('data.DATA_HEALTH'))
            ->firstWhere('label', 'Zůstatek na účtech');

        $this->assertSame('hard', $r['kind']);
        $this->assertSame(99, $r['conf']);
        $this->assertSame('Bankovní napojení · ČSOB', $r['where']);
        $this->assertSame('před 12 minutami', $r['age']);
    }

    /**
     * Jistota odhadu roste s délkou řady, ne s ničím jiným.
     *
     * Napsané číslo by tvrdilo totéž prvního dne i po pěti letech.
     */
    public function test_jistota_odhadu_roste_s_delkou_rady(): void
    {
        $this->rozpocet();
        $this->transakce(['occurred_at' => now()->subMonths(10)->toDateString()]);

        $r = collect($this->getJson('/api/data/system')->assertOk()->json('data.DATA_HEALTH'))
            ->firstWhere('label', 'Zbývá v rozpočtu tento měsíc');

        $this->assertSame('guess', $r['kind']);
        // 45 + 10 měsíců × 4, zastropováno na 84.
        $this->assertSame(84, $r['conf']);
    }

    /** Ze dvou začátků je jedna délka — z jedné délky se průměr nedělá. */
    public function test_cyklus_potrebuje_aspon_tri_zacatky(): void
    {
        $this->fotka();
        $this->zacatekCyklu(now()->subDays(56));
        $this->zacatekCyklu(now()->subDays(28));

        $data = collect($this->getJson('/api/data/system')->assertOk()->json('data.DATA_HEALTH'))
            ->pluck('label');

        $this->assertNotContains('Délka cyklu', $data);

        $this->zacatekCyklu(now());

        $r = collect($this->getJson('/api/data/system')->assertOk()->json('data.DATA_HEALTH'))
            ->firstWhere('label', 'Délka cyklu');

        $this->assertSame('28 dní', $r['value']);
        $this->assertSame('2 zaznamenané cykly', $r['where']);
    }

    /** Nesouměrná řada dá desetinné číslo — a to se čte „dne". */
    public function test_nesoumerna_rada_da_desetinnou_delku(): void
    {
        $this->fotka();
        $this->zacatekCyklu(now()->subDays(57));
        $this->zacatekCyklu(now()->subDays(28));
        $this->zacatekCyklu(now());

        $r = collect($this->getJson('/api/data/system')->assertOk()->json('data.DATA_HEALTH'))
            ->firstWhere('label', 'Délka cyklu');

        $this->assertSame('28,5 dne', $r['value']);
    }

    /**
     * Odhadnutý rok se ve Zdraví dat pozná od změřeného.
     *
     * Obrazovka Datování to slibuje dvakrát — tohle je místo, kde se to plní.
     */
    public function test_odhadnuta_data_maji_vlastni_radek(): void
    {
        $this->fotka(['taken_at' => '1988-01-01 12:00:00', 'taken_at_estimated' => true]);
        $this->fotka(['taken_at' => '2019-05-12 08:00:00'], 2);

        $r = collect($this->getJson('/api/data/system')->assertOk()->json('data.DATA_HEALTH'))
            ->firstWhere('label', 'Odhadnutá data fotek');

        $this->assertSame('1 fotka', $r['value']);
        $this->assertSame('guess', $r['kind']);
        $this->assertStringContainsString('první leden', $r['note']);
    }

    /** Bez jediného odhadu se ten řádek neposílá. */
    public function test_bez_odhadu_zadny_radek(): void
    {
        $this->fotka(['taken_at' => '2019-05-12 08:00:00']);

        $stitky = collect($this->getJson('/api/data/system')->assertOk()->json('data.DATA_HEALTH'))->pluck('label');

        $this->assertNotContains('Odhadnutá data fotek', $stitky);
    }

    /** Sekce ví, kdo do ní zapisuje — a jménem, ne napevno. */
    public function test_sekce_vi_kdo_ji_zivi(): void
    {
        $this->fotka(['uploaded_by' => $this->adri->id]);
        $this->fotka(['uploaded_by' => $this->maki->id], 2);
        $this->fotka(['uploaded_by' => $this->maki->id], 3);

        $s = collect($this->getJson('/api/data/system')->assertOk()->json('data.SECLIFE'))
            ->firstWhere('name', 'Knihovna');

        $this->assertSame(1, $s['a']);
        $this->assertSame(2, $s['m']);
        $this->assertSame('Adrian', $s['aName']);
        $this->assertSame('Makinka', $s['mName']);
        $this->assertFalse($s['cold']);
    }

    /** Do čeho tři měsíce nikdo nesáhl, se nabídne ke skrytí. */
    public function test_sekce_bez_zapisu_je_studena(): void
    {
        $this->fotka();

        $sekce = collect($this->getJson('/api/data/system')->assertOk()->json('data.SECLIFE'))->keyBy('name');

        $this->assertTrue($sekce['Deník']['cold']);
        // Sekce, do které nikdo nikdy nezapsal, nemá „poslední zápis" žádný.
        $this->assertNull($sekce['Deník']['last']);
        $this->assertFalse($sekce['Knihovna']['cold']);
    }

    /** Sloupce administrace mluví o skutečném úložišti. */
    public function test_sloupce_administrace_ctou_uloziste(): void
    {
        $this->fotka(['storage_status' => 'local_only']);

        $abars = $this->getJson('/api/data/system')->assertOk()->json('data.ABARS');
        $riziko = collect($abars['risk'])->keyBy(0);

        $this->assertArrayHasKey('Zaplněnost úložiště', $riziko);
        $this->assertStringContainsString('riziko', $riziko['Originály jen v jedné kopii'][1]);
        // Bez připojeného disku se nemá kam kopírovat, a obrazovka to řekne.
        $this->assertSame('zatím žádná', $riziko['Poslední kopie do cloudu'][1]);

        $zdravi = collect($abars['health'])->keyBy(0);

        $this->assertSame('0 chyb za 7 dní', $zdravi['Chybové úlohy'][1]);
        $this->assertSame('Druhá kopie není nastavená', $zdravi['Druhá kopie'][1]);
    }

    /**
     * Rok v číslech se poměřuje s loňskem, ne s vymyšleným cílem.
     *
     * „O třetinu víc zápisů než loni" je věta, která něco znamená.
     */
    public function test_rok_v_cislech_porovnava_s_lonskem(): void
    {
        $this->fotka(['uploaded_at' => now()->startOfYear()->addMonth()], 1);
        $this->fotka(['uploaded_at' => now()->startOfYear()->addMonths(2)], 2);
        $this->fotka(['uploaded_at' => now()->startOfYear()->subMonths(3)], 3);

        $r = collect($this->getJson('/api/data/system')->assertOk()->json('data.ABARS.zprCisla'))->keyBy(0);

        $this->assertSame('2 · loni 1', $r['Knihovna'][1]);
        $this->assertSame(100, $r['Knihovna'][2]);
        // Víc než loni — základní barva, ne varovná.
        $this->assertSame(0, $r['Knihovna'][3]);
    }

    /** Sekce, do které letos nic nepřibylo, do ročního přehledu nepatří. */
    public function test_letos_prazdna_sekce_v_prehledu_neni(): void
    {
        $this->fotka(['uploaded_at' => now()->startOfYear()->addMonth()]);

        $r = collect($this->getJson('/api/data/system')->assertOk()->json('data.ABARS.zprCisla'))->pluck(0);

        $this->assertSame(['Knihovna'], $r->all());
    }

    /** Obě kolekce server dodává celé. */
    public function test_zdravi_i_sekce_prichazeji_cele(): void
    {
        $this->fotka();

        $this->assertSame(
            ['DATA_HEALTH', 'SECLIFE'],
            $this->getJson('/api/data/system')->assertOk()->json('uplne'),
        );
    }

    /** Čísla druhého páru se do odpovědi nedostanou. */
    public function test_cisla_jineho_paru_se_neposilaji(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $this->fotka();
        $this->fotka([
            'gallery_space_id' => $ciziProstor->id,
            'owner_user_id' => $cizi->id,
            'uploaded_by' => $cizi->id,
        ], 2);

        $r = collect($this->getJson('/api/data/system')->assertOk()->json('data.DATA_HEALTH'))
            ->firstWhere('label', 'Počet položek v knihovně');

        $this->assertSame('1', $r['value']);
    }

    // ——— pomůcky ———

    private function penezenka(): void
    {
        DB::table('wallets')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'name' => 'Společný účet',
            'kind' => 'bank',
            'currency' => 'CZK',
            'opening_balance' => 38412,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rozpocet(): void
    {
        $rozpocet = DB::table('budgets')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'name' => 'Rozpočet',
            'currency' => 'CZK',
            'starts_on' => now()->startOfMonth()->toDateString(),
            'is_shared' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $kategorie = DB::table('finance_categories')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'name' => 'Potraviny',
            'kind' => 'expense',
            'is_favourite' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('budget_category_limits')->insert([
            'budget_id' => $rozpocet,
            'finance_category_id' => $kategorie,
            'amount' => 6000,
            'priority' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function transakce(array $navic = []): void
    {
        DB::table('transactions')->insert(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'type' => 'expense',
            'occurred_at' => now()->toDateString(),
            'amount_from' => 100,
            'currency_from' => 'CZK',
            'description' => 'Výdaj',
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }

    private function zacatekCyklu($den): void
    {
        DB::table('cycle_days')->insert([
            'uuid' => (string) Str::uuid(),
            'user_id' => $this->maki->id,
            'gallery_space_id' => $this->prostor->id,
            'day' => $den->toDateString(),
            'is_cycle_start' => true,
            'is_predicted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Aplikace ví, jak se ti dva jmenují.
     *
     * Na desítkách míst se jména porovnávala s „Adrian" a „Makinka" napsanými
     * v kódu; u dvojice, která se jmenuje jinak, z toho vycházely prázdné
     * sloupce a štítky bez barvy. První je ten, kdo se dívá — polovina vět
     * je psaná z jeho pohledu.
     */
    public function test_dvojice_zna_skutecna_jmena(): void
    {
        $this->assertSame(['Adrian', 'Makinka'],
            $this->getJson('/api/data/system')->assertOk()->json('data.DVOJICE'));

        Sanctum::actingAs($this->maki);

        $this->assertSame(['Makinka', 'Adrian'],
            $this->getJson('/api/data/system')->assertOk()->json('data.DVOJICE'));
    }

    /**
     * První spuštění nabízí skutečné účty, ne dvě napsané adresy.
     *
     * Stálo tam `adrian.stanek@gmail.com` a `makinka@gmail.com`. U jiné
     * dvojice to byly cizí adresy — a člověk si podle nich vybíral, kdo je.
     */
    public function test_ucty_nesou_skutecne_adresy(): void
    {
        $ucty = $this->getJson('/api/data/system')->assertOk()->json('data.UCTY');

        $this->assertSame([$this->adri->name, $this->adri->email], $ucty[0]);
        $this->assertSame([$this->maki->name, $this->maki->email], $ucty[1]);

        // Přihlášený první, ve stejném pořadí jako `DVOJICE` — obrazovka
        // podle indexu pozná, který účet je ten její.
        Sanctum::actingAs($this->maki);

        $this->assertSame($this->maki->email,
            $this->getJson('/api/data/system')->assertOk()->json('data.UCTY.0.1'));
    }

    /**
     * Dva stejně pojmenovaní lidé se neslijí do jednoho.
     *
     * Bez pořadového čísla by mapa energie i dělba práce počítaly práci
     * jednoho z nich dvakrát a druhý by z obrazovky zmizel.
     */
    public function test_stejna_jmena_se_rozlisi(): void
    {
        $dvojnik = User::factory()->create(['name' => 'Adrian']);
        $this->prostor->members()->syncWithoutDetaching([$dvojnik->id => ['role' => 'editor']]);

        Sanctum::actingAs($dvojnik);

        $this->assertSame(['Adrian', 'Adrian (2)'],
            $this->getJson('/api/data/system')->assertOk()->json('data.DVOJICE'));
    }

    /**
     * Bez připojeného Disku obrazovka neříká, že je záloha hotová.
     *
     * Celá obrazovka úložiště byla napsaná v designovém souboru — „Připojeno —
     * adrian.stanek@gmail.com" a „24 316 originálů bezpečně uloženo". Dvojici,
     * která Disk připojený nemá, tvrdila, že jsou její fotky ve dvou kopiích.
     */
    public function test_bez_disku_se_netvrdi_ze_je_zaloha(): void
    {
        $this->fotka();

        $disk = $this->getJson('/api/data/system')->assertOk()->json('data.DISK');

        $this->assertFalse($disk['connected']);
        $this->assertNull($disk['account']);
        $this->assertSame('Účet není připojený', $disk['headline']);
        $this->assertSame('Připojit Google Disk', $disk['cta']);
        $this->assertStringContainsString('druhou kopii nemá kdo udělat', $disk['intro']);
    }

    /**
     * Čtyři dlaždice rozdělí celou knihovnu.
     *
     * Kdyby se počítaly podle stavu, položky se stavem mimo výčet by se mezi
     * dlaždicemi ztratily a součet by neseděl s počtem fotek. Rozhoduje proto
     * id souboru na Disku, ne to, co o sobě záznam tvrdí.
     */
    public function test_dlazdice_rozdeli_celou_knihovnu(): void
    {
        $this->fotka(['drive_file_id' => 'drv-1'], 1);
        $this->fotka(['storage_status' => 'uploading'], 2);
        // Tvrdí o sobě, že je na Disku, ale nemá k čemu se vrátit.
        $this->fotka(['storage_status' => 'synced'], 3);
        $this->fotka(['storage_status' => 'local_only'], 4);
        // Stav mimo známý výčet — takové řádky v datech jsou a nesmí se ztratit.
        $this->fotka(['storage_status' => 'local'], 5);
        $this->fotka(['trashed_at' => now()], 6);

        $stavy = $this->getJson('/api/data/system')->assertOk()->json('data.DISK.states');

        $this->assertSame(['1', '1', '2', '1'], array_column($stavy, 'value'));
        $this->assertSame(5, array_sum(array_map('intval', array_column($stavy, 'value'))));
    }

    /** Rozdělení kapacity je ze skutečných bajtů, ne 52/19/5 %. */
    public function test_kapacita_vychazi_ze_skutecnych_bajtu(): void
    {
        $this->fotka(['size_bytes' => 3_000_000], 1);
        $this->fotka(['media_type' => 'video', 'size_bytes' => 1_000_000], 2);

        $casti = $this->getJson('/api/data/system')->assertOk()->json('data.DISK.capacity');

        $this->assertSame('Fotografie 3 MB', $casti[0]['label']);
        $this->assertSame('75%', $casti[0]['w']);
        $this->assertSame('Videa 1 MB', $casti[1]['label']);
        $this->assertSame('25%', $casti[1]['w']);
    }

    /** Bez Disku se přenos nezařadí — slíbil by kopii, která nemá kam jít. */
    public function test_prenos_bez_disku_se_nezaradi(): void
    {
        $this->fotka();

        $this->postJson('/api/uloziste/prenest')
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
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
            'taken_at' => now()->subDays($poradi),
            'uploaded_at' => now()->subDays($poradi),
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }
}
