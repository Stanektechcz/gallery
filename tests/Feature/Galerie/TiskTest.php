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
 * Objednávka tisku — a její postup.
 *
 * „Kniha odeslána do tisku" hlásilo tlačítko u fotoknihy. Nikam se nic
 * neodeslalo a nikde nevznikl záznam: tabulka `print_orders` se v celé
 * aplikaci jen četla.
 */
class TiskTest extends TestCase
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

    /** Bez objednávek se nic neposílá. */
    public function test_bez_objednavek_se_nic_neposila(): void
    {
        $this->assertArrayNotHasKey('PORDERS', $this->getJson('/api/data/pribeh')->assertOk()->json('data'));
    }

    /**
     * Objednávka vzniká ve stavu „Přijato" a s odhadem termínu.
     *
     * Termín aplikace od tiskárny nemá, takže je označený jako odhad —
     * datum bez té značky by vypadalo jako slib.
     */
    public function test_objednavka_se_zapise_jako_prijato(): void
    {
        $odpoved = $this->postJson('/api/tisk/objednavka', [
            'title' => 'Náš příběh',
            'kind' => 'kniha',
            'price' => 1480,
            'note' => '96 stran',
        ])->assertOk()->assertJsonPath('ok', true);

        $radek = DB::table('print_orders')->first();

        $this->assertSame('Náš příběh', $radek->title);
        $this->assertSame(0, (int) $radek->step);
        $this->assertSame(1, (int) $radek->due_estimated);

        // Odpověď nese seznam objednávek rovnou — obrazovka se nemá na co doptávat.
        $porders = $odpoved->json('data.PORDERS');
        $this->assertCount(1, $porders);
        $this->assertSame('Náš příběh', $porders[0][1]);
        $this->assertSame(0, $porders[0][4]);
    }

    /** Aplikace netvrdí, že objednávku někam odeslala. */
    public function test_odpoved_netvrdi_ze_se_odeslalo_do_tiskarny(): void
    {
        $zprava = $this->postJson('/api/tisk/objednavka', ['title' => 'Kniha'])
            ->assertOk()
            ->json('zprava');

        $this->assertStringContainsString('nezadává', $zprava);
    }

    /** Stav posouvá člověk, ne tiskárna. */
    public function test_stav_jde_posunout(): void
    {
        $this->postJson('/api/tisk/objednavka', ['title' => 'Kniha'])->assertOk();
        $uuid = DB::table('print_orders')->value('uuid');

        $this->postJson('/api/tisk/stav', ['id' => $uuid, 'step' => 2, 'tracking' => 'CZ123456789'])
            ->assertOk()
            ->assertJsonPath('zprava', 'Stav objednávky: Expedováno');

        $radek = DB::table('print_orders')->first();
        $this->assertSame(2, (int) $radek->step);
        $this->assertSame('CZ123456789', $radek->tracking);
    }

    /** Doručená zásilka už termín neodhaduje — je doma. */
    public function test_dorucena_zasilka_neodhaduje_termin(): void
    {
        $this->postJson('/api/tisk/objednavka', ['title' => 'Kniha'])->assertOk();
        $uuid = DB::table('print_orders')->value('uuid');

        $this->postJson('/api/tisk/stav', ['id' => $uuid, 'step' => 3])->assertOk();

        $this->assertSame(0, (int) DB::table('print_orders')->value('due_estimated'));
    }

    /** Kroky zásilky chodí ze serveru, ať se nerozejdou s tím, co je v databázi. */
    public function test_kroky_zasilky_chodi_ze_serveru(): void
    {
        $this->postJson('/api/tisk/objednavka', ['title' => 'Kniha'])->assertOk();

        $this->assertSame(
            ['Přijato', 'V tisku', 'Expedováno', 'Doručeno'],
            $this->getJson('/api/data/pribeh')->assertOk()->json('data.POSTEPS'),
        );
    }

    /** Cizí objednávka se posunout nedá. */
    public function test_cizi_objednavka_se_neposune(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        DB::table('print_orders')->insert([
            'uuid' => $uuid = (string) Str::uuid(),
            'gallery_space_id' => $ciziProstor->id,
            'title' => 'Cizí kniha',
            'kind' => 'kniha',
            'price' => 100,
            'currency' => 'CZK',
            'step' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/tisk/stav', ['id' => $uuid, 'step' => 2])
            ->assertStatus(422)
            ->assertJsonValidationErrors('id');
    }
}
