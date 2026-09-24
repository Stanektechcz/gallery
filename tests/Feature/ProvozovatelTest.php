<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Vlastník galerie není provozovatel celé instalace.
 *
 * `users.role = owner` dostane každý, kdo si založí vlastní galerii — i každý
 * zákazník, až se registrace otevře (`GALLERY_REGISTRATION_OPEN`). A právě
 * tahle role otvírala provozní část: tržby všech galerií, změnu tarifů a cen
 * pro všechny, seznam všech účtů, zakládání správců, klíče integrací platformy
 * a spouštění či pozastavování plánovaných úloh, které běží pro všechny.
 *
 * Provozovatel je teď zvlášť: e-mail v `GALLERY_OPERATOR_EMAILS`, a bez
 * nastavení vlastník instalace (`GALLERY_OWNER_EMAIL`) — dnešní provoz se tím
 * nemění.
 */
class ProvozovatelTest extends TestCase
{
    use RefreshDatabase;

    private User $zakaznik;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['gallery.operator_emails' => 'provoz@vzpominky.test']);

        $this->zakaznik = $this->vlastnik('zakaznik@jinde.test');
    }

    public function test_vlastnik_galerie_nevidi_ani_nemeni_provoz_platformy(): void
    {
        Sanctum::actingAs($this->zakaznik);

        $this->getJson('/api/v1/admin/billing/revenue')->assertForbidden();
        $this->getJson('/api/v1/admin/billing/matrix')->assertForbidden();
        $this->putJson('/api/v1/admin/billing/plans/premium', ['price_monthly' => 1])->assertForbidden();
        $this->postJson('/api/admin/jobs/trash-purge/run')->assertForbidden();
        $this->postJson('/api/admin/jobs/trash-purge/pause')->assertForbidden();
    }

    public function test_vlastnik_galerie_nevidi_ucty_cele_instalace(): void
    {
        $this->actingAs($this->zakaznik);

        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/audit')->assertForbidden();
        $this->get('/admin/integrations')->assertForbidden();
        $this->get('/admin')->assertForbidden();
    }

    /**
     * Pozvat někoho do **vlastní** galerie ale vlastník smí dál.
     *
     * Pozvánka hlídá tarif (limit členů) a přidává do galerie toho, kdo zve —
     * je to věc zákazníka, ne provozu.
     */
    public function test_vlastnik_galerie_dal_zve_do_sve_galerie(): void
    {
        $this->actingAs($this->zakaznik)
            ->post('/admin/users/invite', ['name' => 'Partnerka', 'email' => 'partnerka@jinde.test', 'role' => 'partner'])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'partnerka@jinde.test']);
    }

    public function test_provozovatel_projde(): void
    {
        $provoz = $this->vlastnik('Provoz@Vzpominky.test');

        Sanctum::actingAs($provoz);
        $this->getJson('/api/v1/admin/billing/revenue')->assertOk();
        $this->postJson('/api/admin/jobs/trash-purge/run')->assertOk();

        $this->actingAs($provoz)->get('/admin/users')->assertOk();
    }

    /** Bez nastavení je provozovatelem vlastník instalace — dnešní stav. */
    public function test_bez_nastaveni_je_provozovatelem_vlastnik_instalace(): void
    {
        config(['gallery.operator_emails' => null, 'gallery.owner_email' => 'majitel@vzpominky.test']);

        $this->assertTrue($this->vlastnik('majitel@vzpominky.test')->isOperator());
        $this->assertFalse($this->zakaznik->isOperator());
    }

    private function vlastnik(string $email): User
    {
        $u = User::factory()->create(['email' => $email, 'role' => 'owner', 'is_active' => true]);
        $prostor = GallerySpace::create(['name' => 'Galerie '.$email, 'owner_id' => $u->id]);
        $prostor->members()->syncWithoutDetaching([$u->id => ['role' => 'owner']]);

        return $u;
    }
}
