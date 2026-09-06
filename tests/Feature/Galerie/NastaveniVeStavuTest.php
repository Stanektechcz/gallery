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
 * Přepnutý přepínač opravdu něco přepne.
 *
 * Prototyp si jejich polohu drží v `state.sw` pod klíčem složeným z pořadí
 * (`revolut0-1`), takže „sync každé čtyři hodiny" změnilo jen barvu
 * v prohlížeči toho, kdo klikl.
 */
class NastaveniVeStavuTest extends TestCase
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

    /** Formulář kreslí skutečná napojení, ne napsaná. */
    public function test_formular_kresli_skutecna_napojeni(): void
    {
        $this->napojeni('ČSOB', true);
        $this->financniNastaveni();

        $f = $this->getJson('/api/data/system')->assertOk()->json('data.AFORMS.revolut');

        $this->assertSame('Napojení', $f[0][0]);
        $this->assertSame('ČSOB', $f[0][1][0][0]);
        $this->assertSame(1, $f[0][1][0][2]);
        $this->assertSame('Upozornění', $f[1][0]);
        $this->assertSame('Upozornit na duplicitní transakci', $f[1][1][0][0]);
    }

    /** Vypnutý sync se opravdu vypne. */
    public function test_vypnuti_synchronizace_se_ulozi(): void
    {
        $id = $this->napojeni('ČSOB', true);

        $odpoved = $this->stav(['sw' => ['revolut0-0' => false]])->assertOk();

        $this->assertFalse((bool) DB::table('bank_connections')->where('id', $id)->value('sync_enabled'));
        // Odpověď nese skutečnou polohu, ne tu z kliknutí.
        $this->assertFalse($odpoved->json('data.sw.revolut0-0'));
        $this->assertContains('sw', $odpoved->json('docasne'));
        $this->assertArrayNotHasKey('sw', (array) $this->getJson('/api/state')->assertOk()->json('data'));
    }

    /** Upozornění rozpočtu se přepne ve `finance_settings`. */
    public function test_upozorneni_rozpoctu_se_prepne(): void
    {
        $this->financniNastaveni();

        // Bez napojení je „Upozornění" první sekcí.
        $this->stav(['sw' => ['revolut0-1' => false]])->assertOk();

        $radek = DB::table('finance_settings')->first();

        $this->assertTrue((bool) $radek->warn_duplicates);
        $this->assertFalse((bool) $radek->warn_unusual_amount);
    }

    /** Předvolba promítání se uloží člověku, ne páru. */
    public function test_predvolba_promitani_patri_cloveku(): void
    {
        $this->stav(['sw' => ['tv1-1' => true]])->assertOk();

        $this->assertSame('1', DB::table('user_settings')
            ->where('user_id', $this->adri->id)
            ->where('key', 'slideshow.shuffle')
            ->value('value'));

        // Makinka má své vlastní; tohle jí nic nepřenastavilo.
        $this->assertSame(0, DB::table('user_settings')->where('user_id', $this->maki->id)->count());
    }

    /** Nenastavená předvolba má výchozí hodnotu, ne nulu. */
    public function test_nenastavena_predvolba_ma_vychozi_hodnotu(): void
    {
        $f = $this->getJson('/api/data/system')->assertOk()->json('data.AFORMS.tv');

        // Prolínání je zapnuté, náhodné pořadí vypnuté — jako v prototypu.
        $this->assertSame(['Prolínání', 'místo tvrdého střihu', 1], $f[1][1][0]);
        $this->assertSame(['Náhodné pořadí', '', 0], $f[1][1][1]);
    }

    /** Důvěrník se zapíná v dědickém plánu. */
    public function test_duvernik_se_zapina_v_planu(): void
    {
        $id = DB::table('legacy_plans')->insertGetId([
            'user_id' => $this->adri->id,
            'contact_name' => 'Klára',
            'contact_email' => 'klara@example.com',
            'status' => 'disabled',
            'inactivity_months' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $f = $this->getJson('/api/data/system')->assertOk()->json('data.AFORMS.vault');

        // Vlastník první — je to jeho archiv.
        $this->assertSame(['Adrian', 'vlastník archivu', 1], $f[0][1][0]);
        $this->assertSame('Dědictví', $f[1][0]);
        $this->assertSame(['Důvěrník Klára', 'po 3 měsících nečinnosti', 0], $f[1][1][0]);

        $this->stav(['sw' => ['vault1-0' => true]])->assertOk();

        $this->assertSame('active', DB::table('legacy_plans')->where('id', $id)->value('status'));
    }

    /**
     * Přístup do trezoru se přepínačem neodebírá.
     *
     * Odebrat druhému z dvojice trezor jedním přepnutím bez potvrzení je něco
     * jiného než zapnout prolínání — patří to do Administrace.
     */
    public function test_pristup_do_trezoru_prepinac_nemeni(): void
    {
        $this->stav(['sw' => ['vault0-1' => false]])->assertOk();

        $this->assertSame(2, $this->prostor->members()->count());
        // A formulář dál tvrdí, že přístup má.
        $f = $this->getJson('/api/data/system')->assertOk()->json('data.AFORMS.vault');
        $this->assertSame(1, $f[0][1][1][2]);
    }

    /** Nesmyslný klíč ze stavu neudělá nic. */
    public function test_neznamy_klic_nic_nezmeni(): void
    {
        $id = $this->napojeni('ČSOB', true);

        $this->stav(['sw' => ['revolut9-9' => false, 'nesmysl' => true, 'revolut0-99' => false]])->assertOk();

        $this->assertTrue((bool) DB::table('bank_connections')->where('id', $id)->value('sync_enabled'));
    }

    /** Bez jediného skutečného přepínače se obrazovka neposílá. */
    public function test_obrazovka_bez_prepinacu_se_neposila(): void
    {
        $data = $this->getJson('/api/data/system')->assertOk()->json('data.AFORMS');

        // Bez napojení i bez nastavení financí nemá „revolut" co ukázat.
        $this->assertArrayNotHasKey('revolut', $data);
        $this->assertArrayHasKey('tv', $data);
    }

    // ——— pomůcky ———

    private function stav(array $patch)
    {
        return $this->patchJson('/api/state', ['data' => $patch]);
    }

    private function napojeni(string $banka, bool $sync): int
    {
        return DB::table('bank_connections')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'connected_by' => $this->adri->id,
            'provider' => 'csob',
            'institution_name' => $banka,
            'status' => 'active',
            'sync_enabled' => $sync,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function financniNastaveni(): void
    {
        DB::table('finance_settings')->insert([
            'gallery_space_id' => $this->prostor->id,
            'name' => 'Výchozí',
            'home_currency' => 'CZK',
            'warn_duplicates' => true,
            'warn_unusual_amount' => true,
            'warn_low_balance' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
