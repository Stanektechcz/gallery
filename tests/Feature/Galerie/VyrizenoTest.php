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
 * Kdo to vyřídil — jeden protokol pro dvě obrazovky.
 *
 * „Neviditelná práce" je jeho část navázaná na kontakt s rodinou, „kdo mluví
 * za koho" je týž protokol seskupený po oblastech. Obojí se dosud kreslilo
 * z ukázkových dat: tabulka kontaktů uměla říct jen „naposledy" a „jak často",
 * takže kolik hodin to komu sebralo se z ní vyčíst nedalo.
 */
class VyrizenoTest extends TestCase
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

    /** Bez zápisů se nic neposílá. */
    public function test_bez_protokolu_se_nic_neposila(): void
    {
        $data = $this->getJson('/api/data/mechanismy')->assertOk()->json('data');

        $this->assertArrayNotHasKey('VIS_ROWS', $data);
        $this->assertArrayNotHasKey('SPEAK', $data);
    }

    /**
     * Neviditelná práce se počítá z minut, ne z odhadu.
     *
     * Dřív se k hodinám přičítal průměr z dosavadních — číslo, které nikdo
     * neměřil, a přitom je to jediný sloupec, kvůli kterému ta obrazovka
     * existuje.
     */
    public function test_navsteva_se_zapise_i_s_minutami(): void
    {
        $this->kontakt('Máma, Olomouc', $this->maki);

        $this->postJson('/api/zaznamy/vyrizeno', [
            'contact' => 'Máma, Olomouc',
            'area' => 'Rodina',
            'minutes' => 180,
            'asked' => true,
        ])->assertOk();

        $this->postJson('/api/zaznamy/vyrizeno', [
            'contact' => 'Máma, Olomouc',
            'area' => 'Rodina',
            'minutes' => 120,
        ])->assertOk();

        $vis = $this->getJson('/api/data/mechanismy')->assertOk()->json('data.VIS_ROWS');

        $this->assertCount(1, $vis);
        $this->assertSame('Máma, Olomouc', $vis[0]['label']);
        $this->assertSame('Makinka', $vis[0]['side']);
        $this->assertSame(2, $vis[0]['y']);
        $this->assertSame(5, $vis[0]['hours']);
        $this->assertSame('Adrian', $vis[0]['init']);
    }

    /** Zápis posune i rotaci kontaktu — jinak by lhůta běžela dál. */
    public function test_zapis_posune_rotaci_kontaktu(): void
    {
        $id = $this->kontakt('Máma, Olomouc', $this->maki, now()->subDays(200));

        $this->postJson('/api/zaznamy/vyrizeno', ['contact' => 'Máma, Olomouc', 'area' => 'Rodina'])->assertOk();

        $kontakt = DB::table('couple_family_contacts')->find($id);

        $this->assertSame(now()->toDateString(), $kontakt->last_contact_on);
        $this->assertSame($this->adri->id, $kontakt->last_contact_by);
    }

    /** Neznámý kontakt se nezaloží potichu. */
    public function test_neznamy_kontakt_je_chyba(): void
    {
        $this->postJson('/api/zaznamy/vyrizeno', ['contact' => 'Kdosi', 'area' => 'Rodina'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact');
    }

    /**
     * Kdo mluví za koho — a kde se přitom nikdo neptá.
     *
     * `ask` je jediná věc, kterou odvodit nejde. Bez ní by obrazovka tvrdila
     * buď že se ptá vždycky, nebo nikdy — a obojí by byla lež.
     */
    public function test_oblast_ukaze_kdo_odpovida_a_jestli_se_pta(): void
    {
        foreach (range(1, 4) as $i) {
            $this->postJson('/api/zaznamy/vyrizeno', ['area' => 'Úřady a pojišťovny', 'minutes' => 20])->assertOk();
        }

        Sanctum::actingAs($this->maki);
        $this->postJson('/api/zaznamy/vyrizeno', ['area' => 'Úřady a pojišťovny', 'minutes' => 15, 'asked' => true])->assertOk();
        $this->postJson('/api/zaznamy/vyrizeno', ['area' => 'Škola a doktoři', 'minutes' => 30, 'asked' => true])->assertOk();

        Sanctum::actingAs($this->adri);
        $speak = collect($this->getJson('/api/data/mechanismy')->assertOk()->json('data.SPEAK'));

        $urady = $speak->firstWhere('area', 'Úřady a pojišťovny');
        $this->assertSame(4, $urady['a']);
        $this->assertSame(1, $urady['k']);
        // Jeden dotaz z pěti je pod čtvrtinou → rozhoduje se tu bez ptaní.
        $this->assertTrue($urady['ask']);

        $skola = $speak->firstWhere('area', 'Škola a doktoři');
        $this->assertSame(0, $skola['a']);
        $this->assertSame(1, $skola['k']);
        $this->assertFalse($skola['ask']);
    }

    /** Rodina se počítá jako jeden kanál, ne deset řádků po jednom. */
    public function test_rodina_je_jeden_kanal_na_kontakt(): void
    {
        $this->kontakt('Máma, Olomouc', $this->maki);

        $this->postJson('/api/zaznamy/vyrizeno', ['contact' => 'Máma, Olomouc', 'area' => 'Cokoliv'])->assertOk();

        $speak = collect($this->getJson('/api/data/mechanismy')->assertOk()->json('data.SPEAK'));

        $this->assertNotNull($speak->firstWhere('area', 'Rodina — Máma, Olomouc'));
    }

    private function kontakt(string $jmeno, User $ciStrana, $naposledy = null): int
    {
        return DB::table('couple_family_contacts')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'name' => $jmeno,
            'side_user_id' => $ciStrana->id,
            'every_days' => 30,
            'last_contact_on' => ($naposledy ?? now()->subDays(10))->toDateString(),
            'last_contact_by' => $ciStrana->id,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
