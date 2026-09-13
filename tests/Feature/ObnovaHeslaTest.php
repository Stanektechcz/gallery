<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ObnovaHeslaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Zapomenuté heslo.
 *
 * Přihlašovací obrazovka aplikace na obnovu hesla neodkazovala, e-mail chodil
 * anglicky, po obnovení zůstala přihlášená všechna zařízení a formulář tvrdil
 * „odkaz byl odeslán" i tam, kde e-maily nechodí vůbec.
 */
class ObnovaHeslaTest extends TestCase
{
    use RefreshDatabase;

    public function test_formular_rekne_kdyz_emaily_nechodi(): void
    {
        config(['mail.default' => 'log']);
        $this->get('/forgot-password')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Auth/ForgotPassword')->where('emailyChodi', false));

        config(['mail.default' => 'smtp']);
        $this->get('/forgot-password')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('emailyChodi', true));
    }

    public function test_odkaz_prijde_cesky_a_odpoved_neprozradi_ucet(): void
    {
        Notification::fake();
        $clovek = User::factory()->create(['email' => 'makinka@example.com', 'name' => 'Makinka']);

        $prvni = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'makinka@example.com']);
        $druha = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nikdo@example.com']);

        $prvni->assertRedirect('/forgot-password')->assertSessionHas('success');
        $this->assertSame(session('success'), $druha->getSession()->get('success'), 'Existující a neexistující adresa musí dostat stejnou odpověď.');

        Notification::assertSentTo($clovek, ObnovaHeslaNotification::class, function (ObnovaHeslaNotification $n) use ($clovek) {
            $mail = $n->toMail($clovek);

            return $mail->subject === 'Nové heslo do galerie'
                && str_contains((string) $mail->actionUrl, '/reset-password/'.$n->token)
                && str_contains((string) $mail->actionUrl, 'email=makinka%40example.com');
        });
    }

    public function test_nove_heslo_odhlasi_vsechna_zarizeni_a_vrati_do_aplikace(): void
    {
        $clovek = User::factory()->create(['email' => 'adri@example.com', 'password' => Hash::make('stare-heslo-123')]);
        $clovek->createToken('telefon');
        $clovek->createToken('počítač');
        DB::table('sessions')->insert(['id' => 'sezeni-1', 'user_id' => $clovek->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);

        $token = Password::broker()->createToken($clovek);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'adri@example.com',
            'password' => 'nove-heslo-456',
            'password_confirmation' => 'nove-heslo-456',
        ])->assertRedirect('/?heslo=zmeneno');

        $clovek->refresh();
        $this->assertTrue(Hash::check('nove-heslo-456', $clovek->password));
        $this->assertSame(0, $clovek->tokens()->count(), 'Klíče zařízení musí po obnovení hesla přestat platit.');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $clovek->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password.reset']);

        // A v historii zabezpečení účtu je vidět — kdo ji nedělal sám, musí to poznat.
        Sanctum::actingAs($clovek);
        $this->getJson('/api/v1/ucet/aktivita')->assertOk()->assertJsonFragment(['action' => 'auth.password.reset']);
    }

    public function test_kratke_heslo_a_neplatny_odkaz_cesky(): void
    {
        $clovek = User::factory()->create(['email' => 'adri@example.com']);
        $token = Password::broker()->createToken($clovek);

        $this->from('/reset-password/'.$token)->post('/reset-password', [
            'token' => $token, 'email' => 'adri@example.com',
            'password' => 'kratke12', 'password_confirmation' => 'kratke12',
        ])->assertSessionHasErrors('password');

        $this->from('/reset-password/spatny')->post('/reset-password', [
            'token' => 'spatny', 'email' => 'adri@example.com',
            'password' => 'nove-heslo-456', 'password_confirmation' => 'nove-heslo-456',
        ])->assertSessionHasErrors(['email' => 'Odkaz na nové heslo už neplatí — nechte si poslat nový.']);
    }
}
