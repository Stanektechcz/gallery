<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\GalleryNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Souhrn má z čeho počítat a ví, co počítá.
 *
 * Dvě chyby dohromady: souhrn četl `category_label`, které se do upozornění
 * neukládá (jen `category`), takže psal pokaždé „Ostatní (N)". A drobnosti,
 * kvůli kterým si člověk souhrn zapnul, `via()` rovnou zahodilo — do databáze
 * se nedostaly, a souhrn je tak nemohl ani spočítat.
 */
class NotificationDigestObsahTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create([
            'is_active' => true,
            'preferences' => ['notifications' => ['digest' => true]],
        ]);
    }

    public function test_drobnost_se_souhrnem_se_ulozi_jen_do_schranky(): void
    {
        $drobnost = new GalleryNotification('media.added', 'Maki přidala nové médium.', '/timeline');

        $this->assertSame(['database'], $drobnost->via($this->adri), 'Jen do schránky — žádný push ani e-mail.');

        $this->adri->notify($drobnost);

        $radek = $this->adri->notifications()->sole();
        $this->assertTrue((bool) ($radek->data['digest'] ?? false), 'Drobnost má nést značku, že patří do souhrnu.');

        $this->actingAs($this->adri)->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.data.type', 'media.added');
    }

    public function test_souhrn_pise_skutecne_kategorie(): void
    {
        $this->adri->notify(new GalleryNotification('calendar.task.due_soon', 'Brzy je potřeba dokončit: Nákup.'));
        $this->adri->notify(new GalleryNotification('media.added', 'Maki přidala nové médium.'));

        $this->artisan('gallery:notification-digest', ['--force' => true])
            ->expectsOutputToContain('Odesláno souhrnů: 1')
            ->assertSuccessful();

        $souhrn = DB::table('notifications')->where('notifiable_id', $this->adri->id)->get()
            ->map(fn ($radek) => json_decode($radek->data, true))
            ->firstWhere('type', 'system.digest');

        $this->assertNotNull($souhrn, 'Souhrn se měl poslat.');
        $this->assertStringContainsString('Plány a úkoly (1)', $souhrn['message']);
        $this->assertStringContainsString('Galerie a vzpomínky (1)', $souhrn['message']);
        $this->assertStringNotContainsString('Ostatní', $souhrn['message']);
    }
}
