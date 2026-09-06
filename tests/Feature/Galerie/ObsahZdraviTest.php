<?php

namespace Tests\Feature\Galerie;

use App\Models\CycleDay;
use App\Models\CycleSetting;
use App\Models\GallerySpace;
use App\Models\User;
use App\Models\WellbeingMood;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cyklus a nálada ve tvaru, ve kterém je kreslí prototyp — a zpátky.
 *
 * Kalendář cyklu je soukromý zápis jednoho člověka, ne společný obsah páru.
 * Aplikace na to má nastavení sdílení a tenhle test hlídá, že se drží: poslat
 * partnerovi všechno „protože jsou pár" je přesně to, čemu se ta volba vyhýbá.
 */
class ObsahZdraviTest extends TestCase
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

        Sanctum::actingAs($this->maki);
    }

    /** Bez zápisů se posílají jen popisky dnů, na kterých stojí křivka nálady. */
    public function test_bez_zaznamu_se_posilaji_jen_popisky_dnu(): void
    {
        $data = $this->getJson('/api/data/zdravi')->assertOk()->json('data');

        $this->assertSame(['KL_DAYS'], array_keys($data));
        $this->assertCount(14, $data['KL_DAYS']);
    }

    /** Zapsaný den nese průtok, příznaky i bolest — a nikdy se netváří jako odhad. */
    public function test_zapsany_den_ma_tvar_ktery_prototyp_kresli(): void
    {
        $this->den($this->maki, '2026-08-01', [
            'flow' => 'medium',
            'symptoms' => ['křeče', 'únava'],
            'moods' => ['podrážděná'],
            'pain' => 6,
            'is_cycle_start' => true,
        ]);

        $den = $this->getJson('/api/data/zdravi')->assertOk()->json('data.CYC_BASE.2026-08-01');

        $this->assertSame('medium', $den['flow']);
        $this->assertSame(['křeče', 'únava'], $den['symptoms']);
        $this->assertSame(['podrážděná'], $den['moods']);
        $this->assertSame(6, $den['pain']);
        $this->assertTrue($den['start']);
        // Zapsaný den má přednost před odhadem z průměrů.
        $this->assertFalse($den['predicted']);
    }

    /**
     * Začátky cyklů se odvozují ze zapsaných dnů.
     *
     * Z nich se počítá délka cyklu i odhad toho příštího — dva seznamy by
     * znamenaly dva různé odhady na jedné obrazovce.
     */
    public function test_zacatky_cyklu_se_odvozuji_ze_zapsanych_dnu(): void
    {
        foreach (['2026-08-01' => 'medium', '2026-08-02' => 'heavy', '2026-08-03' => 'light'] as $den => $tok) {
            $this->den($this->maki, $den, ['flow' => $tok, 'is_cycle_start' => $den === '2026-08-01']);
        }

        // Den bez krvácení řadu ukončí.
        $this->den($this->maki, '2026-08-05', ['flow' => 'spotting']);

        $zacatky = $this->getJson('/api/data/zdravi')->assertOk()->json('data.CYC_STARTS');

        $this->assertSame([['2026-08-01', 3]], $zacatky);
    }

    /**
     * Bez svolení partner neuvidí nic.
     *
     * Výchozí úroveň sdílení je „nic" a znamená to doslova nic — ani termíny.
     */
    public function test_bez_svoleni_partner_neuvidi_cyklus(): void
    {
        $this->den($this->maki, '2026-08-01', ['flow' => 'medium', 'symptoms' => ['křeče']]);
        $this->nastaveni($this->maki, CycleSetting::SHARE_NONE);

        Sanctum::actingAs($this->adri);

        $this->assertArrayNotHasKey('CYC_BASE', $this->getJson('/api/data/zdravi')->assertOk()->json('data'));
    }

    /**
     * „Jen termíny" znamená jen termíny.
     *
     * Partner uvidí, kdy čekat a kolikátý den je — příznaky, nálada, bolest ani
     * poznámka mu do toho nic nejsou.
     */
    public function test_jen_terminy_neposilaji_priznaky(): void
    {
        $this->den($this->maki, '2026-08-01', [
            'flow' => 'medium',
            'symptoms' => ['křeče', 'únava'],
            'moods' => ['podrážděná'],
            'pain' => 6,
            'note' => 'Zůstala jsem doma.',
            'is_cycle_start' => true,
        ]);
        $this->nastaveni($this->maki, CycleSetting::SHARE_DATES);

        Sanctum::actingAs($this->adri);

        $den = $this->getJson('/api/data/zdravi')->assertOk()->json('data.CYC_BASE.2026-08-01');

        $this->assertSame('medium', $den['flow']);
        $this->assertTrue($den['start']);
        $this->assertSame([], $den['symptoms']);
        $this->assertSame([], $den['moods']);
        $this->assertNull($den['pain']);
        $this->assertNull($den['note']);
    }

    /** S plným svolením vidí partner i příznaky. */
    public function test_cely_denik_posle_i_priznaky(): void
    {
        $this->den($this->maki, '2026-08-01', ['flow' => 'medium', 'symptoms' => ['křeče']]);
        $this->nastaveni($this->maki, CycleSetting::SHARE_FULL);

        Sanctum::actingAs($this->adri);

        $this->assertSame(
            ['křeče'],
            $this->getJson('/api/data/zdravi')->assertOk()->json('data.CYC_BASE.2026-08-01.symptoms'),
        );
    }

    /** Svůj zápis vidí člověk vždycky, ať je nastavení jakékoli. */
    public function test_svuj_zapis_vidi_clovek_vzdycky(): void
    {
        $this->den($this->maki, '2026-08-01', ['flow' => 'medium', 'symptoms' => ['křeče']]);
        $this->nastaveni($this->maki, CycleSetting::SHARE_NONE);

        $this->assertSame(
            ['křeče'],
            $this->getJson('/api/data/zdravi')->assertOk()->json('data.CYC_BASE.2026-08-01.symptoms'),
        );
    }

    /**
     * Chybějící den v křivce nálady je `null`, ne nula.
     *
     * „Nezapsáno" a „bylo mi mizerně" nejsou totéž a křivka by z toho udělala pád.
     */
    public function test_nalada_ma_pro_nezapsany_den_null(): void
    {
        WellbeingMood::create([
            'gallery_space_id' => $this->prostor->id,
            'user_id' => $this->maki->id,
            'day' => now()->toDateString(),
            'value' => 4,
        ]);

        $nalada = $this->getJson('/api/data/zdravi')->assertOk()->json('data.KL_MOOD');

        $this->assertSame(4, $nalada['Makinka'][13]);
        $this->assertNull($nalada['Makinka'][12]);
        $this->assertNull($nalada['Adrian'][13]);
    }

    // ——— zpátky ze stavu do databáze ———

    /** Zápis dne z obrazovky končí v databázi, ne ve stavu. */
    public function test_zapis_dne_se_ulozi_a_ze_stavu_zmizi(): void
    {
        $odpoved = $this->patchJson('/api/state', ['data' => ['cycDays' => [
            '2026-09-01' => [
                'day' => '2026-09-01', 'flow' => 'heavy',
                'symptoms' => ['křeče'], 'moods' => ['smutek'],
                'pain' => 5, 'temp' => 36.6, 'note' => 'Doma.', 'start' => true,
            ],
        ]]])->assertOk();

        $den = CycleDay::where('user_id', $this->maki->id)->firstOrFail();

        $this->assertSame('heavy', $den->flow);
        $this->assertSame(['křeče'], $den->symptoms);
        $this->assertSame(5, (int) $den->pain);
        $this->assertTrue($den->is_cycle_start);
        $this->assertArrayNotHasKey('cycDays', (array) $odpoved->json('data'));
    }

    /** Zápis se ukládá pod přihlášeného člověka — cizí den nikdo přepsat nemůže. */
    public function test_zapis_patri_prihlasenemu_cloveku(): void
    {
        $this->den($this->adri, '2026-09-01', ['flow' => 'none']);

        $this->patchJson('/api/state', ['data' => ['cycDays' => [
            '2026-09-01' => ['day' => '2026-09-01', 'flow' => 'heavy'],
        ]]])->assertOk();

        $this->assertSame('none', CycleDay::where('user_id', $this->adri->id)->firstOrFail()->flow);
        $this->assertSame('heavy', CycleDay::where('user_id', $this->maki->id)->firstOrFail()->flow);
    }

    /** Smazaný den je smazaný, ne prázdný — prázdná tečka by kazila odhad. */
    public function test_smazany_den_zmizi(): void
    {
        $this->den($this->maki, '2026-09-01', ['flow' => 'medium']);

        $this->patchJson('/api/state', ['data' => ['cycDays' => [
            '2026-09-01' => ['day' => '2026-09-01', 'deleted' => true],
        ]]])->assertOk();

        $this->assertSame(0, CycleDay::where('user_id', $this->maki->id)->count());
    }

    /** Nesmyslný průtok se neuloží — kalendář z něj kreslí barvu. */
    public function test_neznamy_prutok_spadne_na_zadny(): void
    {
        $this->patchJson('/api/state', ['data' => ['cycDays' => [
            '2026-09-01' => ['day' => '2026-09-01', 'flow' => 'vodopád'],
        ]]])->assertOk();

        $this->assertSame('none', CycleDay::where('user_id', $this->maki->id)->firstOrFail()->flow);
    }

    /** Nálada se zapíše jen za toho, kdo ji posílá. */
    public function test_nalada_se_zapise_jen_za_sebe(): void
    {
        $ctrnact = array_fill(0, 14, null);
        $ctrnact[13] = 4;
        $ctrnact[12] = 2;

        $this->patchJson('/api/state', ['data' => ['klMood' => [
            'Makinka' => $ctrnact,
            'Adrian' => array_fill(0, 14, 5),
        ]]])->assertOk();

        $moje = WellbeingMood::where('user_id', $this->maki->id)->orderBy('day')->get();

        $this->assertCount(2, $moje);
        $this->assertSame(2, (int) $moje[0]->value);
        $this->assertSame(4, (int) $moje[1]->value);
        $this->assertTrue($moje[1]->day->isToday());
        // Cizí řádek se ignoruje: náladu za druhého nikdo vyplňovat nemůže.
        $this->assertSame(0, WellbeingMood::where('user_id', $this->adri->id)->count());
    }

    /** Nálada mimo rozsah 1–5 se zahodí. */
    public function test_nalada_mimo_rozsah_se_zahodi(): void
    {
        $ctrnact = array_fill(0, 14, null);
        $ctrnact[13] = 9;

        $this->patchJson('/api/state', ['data' => ['klMood' => ['Makinka' => $ctrnact]]])->assertOk();

        $this->assertSame(0, WellbeingMood::where('user_id', $this->maki->id)->count());
    }

    /** Táž nálada podruhé jen přepíše hodnotu, nezaloží druhý řádek. */
    public function test_nalada_se_prepisuje(): void
    {
        $prvni = array_fill(0, 14, null);
        $prvni[13] = 3;
        $druhy = array_fill(0, 14, null);
        $druhy[13] = 5;

        $this->patchJson('/api/state', ['data' => ['klMood' => ['Makinka' => $prvni]]])->assertOk();
        $this->patchJson('/api/state', ['data' => ['klMood' => ['Makinka' => $druhy]]])->assertOk();

        $moje = WellbeingMood::where('user_id', $this->maki->id)->get();

        $this->assertCount(1, $moje);
        $this->assertSame(5, (int) $moje[0]->value);
    }

    // ——— pomůcky ———

    private function den(User $kdo, string $datum, array $navic = []): CycleDay
    {
        return CycleDay::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'user_id' => $kdo->id,
            'gallery_space_id' => $this->prostor->id,
            'day' => $datum,
            'flow' => 'none',
        ], $navic));
    }

    private function nastaveni(User $kdo, string $uroven): void
    {
        CycleSetting::updateOrCreate(
            ['user_id' => $kdo->id, 'gallery_space_id' => $this->prostor->id],
            ['share_level' => $uroven],
        );
    }
}
