<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Záznam jedné galerie neukazuje do druhé — a nic z druhé nevrací.
 *
 * Druhý průchod starého API našel stejný tvar chyby na dalších místech:
 * číslo z požadavku (partner, cesta, blok příběhu) se zapsalo nebo přečetlo
 * bez ověření, že patří do téže galerie.
 *
 * U partnerů a cest to cizí data neukáže — globální rozsah je přihlášenému
 * schová —, ale zápis v naší galerii by ukazoval do cizí. U příběhu alba šlo
 * o skutečný únik: odpověď na úpravu četla blok podle čísla odkudkoli.
 */
class CiziOdkazyVeFinancichAPribehuTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    private GallerySpace $cizi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$this->prostor->id => ['role' => 'owner']]);

        $soused = User::factory()->create(['name' => 'Soused']);
        $this->cizi = GallerySpace::create(['name' => 'Cizí galerie', 'owner_id' => $soused->id]);
        $soused->gallerySpaces()->syncWithoutDetaching([$this->cizi->id => ['role' => 'owner']]);

        $this->actingAs($this->adri);
    }

    /**
     * Úprava bloku příběhu vrací jen blok z vlastního alba.
     *
     * Zápis byl omezený na album, odpověď ale četla `find($blockId)` odkudkoli.
     * Čísla bloků jdou po sobě, takže prázdný požadavek na vlastní album
     * s cizím číslem vrátil text cizího příběhu.
     */
    public function test_uprava_pribehu_nevrati_cizi_blok(): void
    {
        $moje = $this->album($this->prostor, 'Léto');
        $cizi = $this->album($this->cizi, 'Cizí léto');
        $ciziBlok = DB::table('album_story_blocks')->insertGetId([
            'album_id' => $cizi->id, 'type' => 'text', 'sort_order' => 0,
            'content' => json_encode(['text' => 'Soukromý zápis sousedů']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $odpoved = $this->patchJson("/api/v1/albums/{$moje->uuid}/story/{$ciziBlok}", []);

        $odpoved->assertNotFound();
        $this->assertStringNotContainsString('Soukromý zápis sousedů', $odpoved->getContent());
    }

    public function test_transakce_nevezme_ciziho_partnera(): void
    {
        $ucet = Wallet::create([
            'gallery_space_id' => $this->prostor->id, 'name' => 'Účet', 'kind' => 'bank',
            'currency' => 'CZK', 'opening_balance' => 0, 'is_active' => true,
        ]);

        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'expense',
            'occurred_at' => now()->toDateString(),
            'wallet_from' => $ucet->uuid,
            'amount_from' => 100,
            'payer_partner_id' => $this->partner($this->cizi),
            // Výdaj bez kategorie se jinak jen ptá na potvrzení (409).
            'potvrzeno' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('payer_partner_id');

        $this->assertSame(0, DB::table('transactions')->count());
    }

    public function test_transakce_s_vlastnim_partnerem_projde(): void
    {
        $ucet = Wallet::create([
            'gallery_space_id' => $this->prostor->id, 'name' => 'Účet', 'kind' => 'bank',
            'currency' => 'CZK', 'opening_balance' => 0, 'is_active' => true,
        ]);
        $partner = $this->partner($this->prostor);

        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'expense',
            'occurred_at' => now()->toDateString(),
            'wallet_from' => $ucet->uuid,
            'amount_from' => 100,
            'payer_partner_id' => $partner,
            'potvrzeno' => true,
        ])->assertCreated();

        $this->assertSame($partner, (int) DB::table('transactions')->value('payer_partner_id'));
    }

    public function test_pravidelna_platba_ani_sablona_nevezmou_ciziho_partnera(): void
    {
        $ucet = Wallet::create([
            'gallery_space_id' => $this->prostor->id, 'name' => 'Účet', 'kind' => 'bank',
            'currency' => 'CZK', 'opening_balance' => 0, 'is_active' => true,
        ]);
        $cizi = $this->partner($this->cizi);

        $this->postJson('/api/v1/rozpocet/pravidelne', [
            'name' => 'Nájem', 'amount' => 1000, 'wallet_uuid' => $ucet->uuid,
            'day_of_month' => 1, 'starts_on' => now()->toDateString(), 'payer_partner_id' => $cizi,
        ])->assertStatus(422)->assertJsonValidationErrors('payer_partner_id');

        $this->postJson('/api/v1/rozpocet/sablony', [
            'name' => 'Káva', 'payer_partner_id' => $cizi,
        ])->assertStatus(422)->assertJsonValidationErrors('payer_partner_id');

        $this->assertSame(0, DB::table('finance_recurring')->count());
        $this->assertSame(0, DB::table('finance_templates')->count());
    }

    public function test_rozpocet_nevezme_cizi_cestu(): void
    {
        $odpoved = $this->postJson('/api/v1/rozpocet/rozpocty', [
            'name' => 'Výlet', 'budget_kind' => 'trip', 'currency' => 'CZK', 'amount' => 5000,
            'starts_on' => now()->toDateString(),
            'finance_project_id' => $this->cesta($this->cizi),
        ]);

        $this->assertArrayHasKey('finance_project_id', (array) $odpoved->json('errors'),
            'Rozpočet vzal cizí cestu: '.$odpoved->status().' '.$odpoved->getContent());

        $this->assertSame(0, DB::table('budgets')->count());
    }

    /** `store()` cestu ověřoval, `update()` ji zapsal, ať byla čí chtěla. */
    public function test_uprava_ukolu_nevezme_cizi_cestu(): void
    {
        $ukol = $this->postJson('/api/v1/todos', [
            'gallery_space_id' => $this->prostor->id, 'title' => 'Zabalit',
        ])->assertCreated()->json();

        $ciziCesta = DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->cizi->id, 'created_by' => $this->cizi->owner_id,
            'name' => 'Cizí výlet', 'start_date' => '2026-10-01', 'end_date' => '2026-10-05',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->patchJson('/api/v1/todos/'.$ukol['uuid'], ['trip_id' => $ciziCesta])->assertNotFound();

        $this->assertNull(DB::table('shared_todos')->where('uuid', $ukol['uuid'])->value('trip_id'));
    }

    // ——— pomocné ———

    private function album(GallerySpace $prostor, string $nazev): Album
    {
        return Album::withoutGlobalScopes()->create([
            'gallery_space_id' => $prostor->id, 'title' => $nazev, 'slug' => Str::slug($nazev),
            'visibility' => 'shared', 'created_by' => $prostor->owner_id, 'updated_by' => $prostor->owner_id,
        ]);
    }

    private function partner(GallerySpace $prostor): int
    {
        return DB::table('partners')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $prostor->id,
            'kind' => 'person', 'name' => 'Partner '.$prostor->name, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cesta(GallerySpace $prostor): int
    {
        return DB::table('finance_projects')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $prostor->id,
            'kind' => 'trip', 'name' => 'Cesta '.$prostor->name, 'base_currency' => 'CZK',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
