<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Host vidí jen odkazy, které dostane — ne data dvojice.
 *
 * Administrace to tak popisuje a API galerie hosta odmítá (`dvojice`).
 * Starší API `v1` ale kontrolovalo jen klíč: host přihlášený do starého
 * rozhraní si přes něj přečetl deník, finance, chat i celou knihovnu.
 */
class HostBezDatDvojiceTest extends TestCase
{
    use RefreshDatabase;

    private User $vlastnik;

    private User $host;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vlastnik = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->host = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $this->prostor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Společně', 'slug' => 'spolecne', 'owner_id' => $this->vlastnik->id, 'is_default' => true]);
        $this->prostor->members()->attach($this->vlastnik->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->prostor->members()->attach($this->host->id, ['role' => 'viewer', 'can_delete' => false, 'can_share' => false, 'joined_at' => now()]);
    }

    public function test_host_neprecte_data_dvojice_pres_starsi_api(): void
    {
        JournalEntry::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->vlastnik->id,
            'title' => 'Tajný zápis',
            'body' => 'Jen pro nás dva.',
            'entry_date' => now()->toDateString(),
            'visibility' => JournalEntry::VISIBILITY_SHARED,
        ]);

        Sanctum::actingAs($this->host);

        foreach (['journal', 'media/compare', 'albums', 'rozpocet/transakce', 'chat', 'timeline', 'people', 'todos', 'calendar/events', 'voice-notes', 'places', 'search?q=zápis'] as $cesta) {
            $odpoved = $this->getJson('/api/v1/'.$cesta);

            $this->assertContains($odpoved->getStatusCode(), [403], $cesta.' vrátil '.$odpoved->getStatusCode().' — host by k datům dvojice přístup mít neměl.');
            $this->assertStringNotContainsString('Tajný zápis', (string) $odpoved->getContent());
        }
    }

    public function test_host_si_smi_upravit_vlastni_ucet_a_odhlasit_se(): void
    {
        Sanctum::actingAs($this->host);

        $this->getJson('/api/v1/profil')->assertOk();
    }

    public function test_dvojice_starsi_api_pouziva_dal(): void
    {
        Sanctum::actingAs($this->vlastnik);

        $this->getJson('/api/v1/journal')->assertOk();
        $this->getJson('/api/v1/albums')->assertOk();
    }

    /** Předplatné a faktury patří galerii — host je nečte ani nemění. */
    public function test_host_nevidi_predplatne_galerie(): void
    {
        Sanctum::actingAs($this->host);

        $this->getJson('/api/v1/billing/overview')->assertForbidden();
        $this->getJson('/api/v1/billing/faktury')->assertForbidden();
        $this->getJson('/api/v1/onboarding')->assertForbidden();
    }

    /**
     * Staré webové rozhraní vydávalo alba, koš, trezor i export přímo ze serveru.
     * Host se odhlásí a přihlašovací formulář mu řekne proč.
     */
    public function test_host_se_do_stareho_rozhrani_nedostane(): void
    {
        foreach (['/albums', '/trash', '/vault', '/prehled', '/timeline', '/favorites'] as $cesta) {
            $this->actingAs($this->host)
                ->get($cesta)
                ->assertRedirect(route('login'))
                ->assertSessionHasErrors('email');

            $this->assertGuest();
        }

        $this->actingAs($this->host)
            ->postJson('/export/download')
            ->assertForbidden();
    }

    public function test_host_se_heslem_neprihlasi(): void
    {
        $this->host->forceFill(['password' => bcrypt('heslo-hosta-123')])->save();

        $this->from('/login')
            ->post('/login', ['email' => $this->host->email, 'password' => 'heslo-hosta-123'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_dvojice_stare_rozhrani_pouziva_dal(): void
    {
        $this->actingAs($this->vlastnik)->get('/albums')->assertOk();
        $this->assertAuthenticatedAs($this->vlastnik);
    }
}
