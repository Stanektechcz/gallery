<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mapa energie, rozpočet pozornosti, co čeká na okno a otázka na dva.
 *
 * Kapacita týdne ví, kdy má dvojice volno — to jde vyčíst z kalendáře. Tohle
 * je druhá vrstva: kdy má sílu. To z ničeho vyčíst nejde, musí to někdo říct.
 */
class ObsahKlidPohodaTest extends TestCase
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

    /** Prázdná mřížka samých nul by tvrdila, že nikdo nemá sílu na nic. */
    public function test_bez_zapisu_se_mapa_neposila(): void
    {
        $data = $this->getJson('/api/data/klid')->assertOk()->json('data');

        $this->assertArrayNotHasKey('KL_EN', $data);
    }

    /**
     * Mapa je sedm řetězců po třech číslicích — přesně tak ji obrazovka čte.
     *
     * `en[jméno][den][část]`, takže tvar se měnit nesmí.
     */
    public function test_mapa_energie_ma_tvar_ktery_obrazovka_cte(): void
    {
        $this->energie($this->adri, 0, 0, 2);
        $this->energie($this->adri, 0, 2, 1);
        $this->energie($this->maki, 6, 1, 2);

        $en = $this->getJson('/api/data/klid')->assertOk()->json('data.KL_EN');

        $this->assertSame(['Adrian', 'Makinka'], array_keys($en));
        $this->assertCount(7, $en['Adrian']);
        $this->assertSame('201', $en['Adrian'][0]);
        $this->assertSame('000', $en['Adrian'][3]);
        $this->assertSame('020', $en['Makinka'][6]);
    }

    /** Vlastník je první — obrazovka kreslí jeho pruh vlevo. */
    public function test_vlastnik_je_v_mape_prvni(): void
    {
        $this->energie($this->maki, 0, 0, 1);

        $en = $this->getJson('/api/data/klid')->assertOk()->json('data.KL_EN');

        $this->assertSame('Adrian', array_key_first($en));
    }

    /**
     * Dva členové se shodným jménem se do sebe nesloží.
     *
     * Mapa je klíčovaná jménem, ne identifikátorem — bez rozlišení by jeden
     * přepsal druhého a jeho řádek by vyšel jako samé nuly.
     */
    public function test_shodna_jmena_se_rozlisi(): void
    {
        $druhyAdrian = User::factory()->create(['name' => 'Adrian']);
        $this->prostor->members()->syncWithoutDetaching([$druhyAdrian->id => ['role' => 'editor']]);

        $this->energie($this->adri, 0, 0, 1);
        $this->energie($druhyAdrian, 1, 1, 2);

        $en = $this->getJson('/api/data/klid')->assertOk()->json('data.KL_EN');

        $this->assertSame(['Adrian', 'Makinka', 'Adrian (2)'], array_keys($en));
        $this->assertSame('100', $en['Adrian'][0]);
        $this->assertSame('020', $en['Adrian (2)'][1]);
    }

    /**
     * Co nic neměří, má nulu a řekne to.
     *
     * Dopočítaný odhad by byl výtka za čas, který nikdo nesledoval.
     */
    public function test_nemerena_polozka_to_rekne(): void
    {
        DB::table('house_chore_log')->insert([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'user_id' => $this->adri->id,
            'chore_name' => 'Vyprat',
            'minutes' => 60,
            'done_at' => now()->subDays(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $p = collect($this->getJson('/api/data/klid')->assertOk()->json('data.KL_ATTN'))->keyBy('key');

        // Dělba práce se měří z jejího vlastního protokolu.
        $this->assertSame(100, $p['byt']['real']);
        $this->assertSame('Dělba práce, lhůty, inventář.', $p['byt']['note']);
        // Rodinu ani čas pro sebe nic nesleduje.
        $this->assertSame(0, $p['rodina']['real']);
        $this->assertStringContainsString('zatím nic neměří', $p['rodina']['note']);
    }

    /** Vlastní nastavení rozpočtu pozornosti přebije nabídku. */
    public function test_vlastni_prani_prebije_nabidku(): void
    {
        DB::table('wellbeing_attention')->insert([
            'gallery_space_id' => $this->prostor->id,
            'key' => 'my',
            'name' => 'My dva',
            'want' => 35,
            'measure' => 'none',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $p = $this->getJson('/api/data/klid')->assertOk()->json('data.KL_ATTN');

        $this->assertCount(1, $p);
        $this->assertSame(35, $p[0]['want']);
    }

    /** Věc, která čeká na okno, ví, kolik lidí u toho musí být. */
    public function test_cekajici_vec_vi_kolik_lidi_potrebuje(): void
    {
        DB::table('wellbeing_tasks')->insert([
            'gallery_space_id' => $this->prostor->id,
            'name' => 'Probrat, co nás poslední měsíc štve',
            'needs_people' => 2,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $u = $this->getJson('/api/data/klid')->assertOk()->json('data.KL_TASKS.0');

        $this->assertSame('Probrat, co nás poslední měsíc štve', $u['name']);
        $this->assertSame(2, $u['need']);
    }

    /**
     * Půlka rozhovoru není rozhovor.
     *
     * Do historie se otázka dostane, až když odpověděli oba.
     */
    public function test_otazka_s_jednou_odpovedi_neni_v_historii(): void
    {
        $this->odpoved($this->adri, 'Co jsem tenhle týden odložil?', 'Zubaře.', now()->subWeek());

        $data = $this->getJson('/api/data/klid')->assertOk()->json('data');

        $this->assertArrayNotHasKey('KL_ASK_LOG', $data);

        $this->odpoved($this->maki, 'Co jsem tenhle týden odložil?', 'Nové brýle.', now()->subWeek());

        $log = $this->getJson('/api/data/klid')->assertOk()->json('data.KL_ASK_LOG.0');

        $this->assertSame('Co jsem tenhle týden odložil?', $log['q']);
        $this->assertSame('Zubaře.', $log['a']);
        $this->assertSame('Nové brýle.', $log['m']);
    }

    /**
     * Odpověď druhého se vydá, až když odpověděl i ten, kdo se dívá.
     *
     * V tom je celý smysl té obrazovky: nedá se odpovídat podle něj.
     */
    public function test_odpoved_druheho_je_zamcena_dokud_neodpovim(): void
    {
        $this->odpoved($this->maki, 'Kdy jsi měl pocit, že to zvládáme?', 'Když jsi maloval.', now());

        $ted = $this->getJson('/api/data/klid')->assertOk()->json('data.KL_ASK_NOW');

        $this->assertArrayNotHasKey('other', $ted);
        $this->assertArrayNotHasKey('done', $ted);

        $this->odpoved($this->adri, 'Kdy jsi měl pocit, že to zvládáme?', 'V sobotu bez plánu.', now());

        $ted = $this->getJson('/api/data/klid')->assertOk()->json('data.KL_ASK_NOW');

        $this->assertTrue($ted['done']);
        $this->assertSame('Když jsi maloval.', $ted['other']);
    }

    /** Klid druhého páru se do odpovědi nedostane. */
    public function test_klid_jineho_paru_se_neposila(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        DB::table('wellbeing_energy')->insert([
            'gallery_space_id' => $ciziProstor->id,
            'user_id' => $cizi->id,
            'weekday' => 0, 'slot' => 0, 'level' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertArrayNotHasKey('KL_EN', $this->getJson('/api/data/klid')->assertOk()->json('data'));
    }

    // ——— pomůcky ———

    private function energie(User $kdo, int $den, int $cast, int $uroven): void
    {
        DB::table('wellbeing_energy')->insert([
            'gallery_space_id' => $this->prostor->id,
            'user_id' => $kdo->id,
            'weekday' => $den,
            'slot' => $cast,
            'level' => $uroven,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function odpoved(User $kdo, string $otazka, string $text, $kdy): void
    {
        DB::table('wellbeing_answers')->insert([
            'gallery_space_id' => $this->prostor->id,
            'user_id' => $kdo->id,
            'question' => $otazka,
            'answer' => $text,
            'asked_on' => $kdy->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
