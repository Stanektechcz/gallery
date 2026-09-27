<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PresmerujStareRozhrani;
use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\User;
use App\Notifications\EventReminderNotification;
use App\Support\TrasyPrototypu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Staré rozhraní vede do aplikace, sdílené odkazy a API zůstávají.
 *
 * Rozhodnutí 27. 9. 2026: „Stránky /prehled a starého rozhraní přesměrují
 * na /. API zůstane pro sdílené odkazy a staré klienty." Seznam je jediný,
 * v `PresmerujStareRozhrani`; tenhle test hlídá, že každá položka opravdu
 * přesměruje tam, kam má, a že to, co zůstat mělo, zůstalo.
 */
class StareRozhraniPresmerovaniTest extends TestCase
{
    use RefreshDatabase;

    private User $vlastnik;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vlastnik = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $prostor = GallerySpace::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Naše', 'slug' => 'nase-'.Str::random(6),
            'owner_id' => $this->vlastnik->id, 'is_default' => true,
        ]);
        $prostor->members()->attach($this->vlastnik->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
    }

    /** Ručně vypsané cíle — ne odvozené ze seznamu, ať se překlep v něm pozná. */
    public function test_hlavni_stranky_vedou_na_obrazovky_aplikace(): void
    {
        $this->actingAs($this->vlastnik);

        foreach ([
            '/prehled' => '/',
            '/home' => '/',
            '/login' => '/',
            '/timeline' => '/galerie/casova-osa',
            '/albums' => '/galerie/alba',
            '/albums/'.Str::uuid() => '/galerie/alba',
            '/media/'.Str::uuid() => '/galerie/knihovna',
            '/calendar' => '/galerie/kalendar',
            '/calendar/events/'.Str::uuid() => '/galerie/kalendar',
            '/vault' => '/galerie/trezor',
            '/trash' => '/galerie/kos',
            '/rozpocty' => '/galerie/rozpocty',
            '/rozpocet' => '/galerie/rozpocty',
            '/kniha' => '/galerie/transakce',
            '/tickets' => '/galerie/cesty',
            '/trips/5/now' => '/galerie/cesta-nyni',
            '/recipes/'.Str::uuid() => '/galerie/kucharka',
            '/shares' => '/galerie/sdilene',
            '/settings/storage/google' => '/galerie/uloziste',
            '/admin/integrations' => '/galerie/administrace',
            '/krkance' => '/',
        ] as $stara => $cil) {
            $this->get($stara)->assertStatus(302)->assertRedirect($cil);
        }
    }

    /** Každá položka seznamu přesměruje přihlášeného vlastníka (302) na svůj cíl. */
    public function test_kazda_stara_stranka_ze_seznamu_presmeruje(): void
    {
        $this->actingAs($this->vlastnik);

        foreach (PresmerujStareRozhrani::PRESMEROVANI as $jmeno => $trasa) {
            $odpoved = $this->get($this->adresa($jmeno));

            $odpoved->assertStatus(302);
            $this->assertSame(
                url(PresmerujStareRozhrani::cil($jmeno)),
                $odpoved->headers->get('Location'),
                "Stránka {$jmeno} vede jinam, než říká seznam."
            );
        }
    }

    /** Jména v seznamu existují, patří jen čtení a cíle jsou obrazovky, které aplikace zná. */
    public function test_seznam_obsahuje_jen_existujici_cteci_cesty_a_zname_cile(): void
    {
        foreach (PresmerujStareRozhrani::PRESMEROVANI as $jmeno => $trasa) {
            $cesta = Route::getRoutes()->getByName($jmeno);

            $this->assertNotNull($cesta, "Cesta {$jmeno} neexistuje.");
            $this->assertSame(['GET', 'HEAD'], $cesta->methods(), "Cesta {$jmeno} není jen ke čtení.");

            if ($trasa !== null) {
                $this->assertNotNull(TrasyPrototypu::adresa($trasa), "Trasa {$trasa} (u {$jmeno}) v aplikaci není.");
            }
        }
    }

    /**
     * Otevřená záložka starého rozhraní (Inertia XHR) se nechá načíst celá.
     *
     * Se starou verzí sestavení by ji Inertia nejdřív poslala znovu na tutéž
     * adresu (a odtud by šla sem obyčejným přesměrováním); tady verze sedí.
     */
    public function test_inertia_pozadavek_dostane_celou_novou_adresu(): void
    {
        $verze = (string) app(HandleInertiaRequests::class)->version(request());

        $this->actingAs($this->vlastnik)
            ->get('/albums', ['X-Inertia' => 'true', 'X-Inertia-Version' => $verze, 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', '/galerie/alba');
    }

    /** Zápisy a API na týchž adresách jdou dál do kontroleru. */
    public function test_zapisy_a_api_zustavaji(): void
    {
        $this->actingAs($this->vlastnik);

        // Stejná adresa jako stránka alba, jiná metoda: kontroler odpoví, ne přesměrování.
        $this->patchJson('/albums/'.Str::uuid(), ['title' => 'Nic'])->assertNotFound();
        $this->getJson('/albums/tree')->assertOk();
        $this->getJson('/api/v1/albums')->assertOk();

        // Sdílený odkaz založený přes staré API.
        $this->postJson('/shares', ['target_type' => 'selection'])
            ->assertOk()->assertJsonStructure(['token', 'url']);
    }

    /** Sdílený odkaz, obnova hesla a veřejné stránky se dál vykreslují. */
    public function test_verejne_stranky_zustavaji(): void
    {
        $token = $this->actingAs($this->vlastnik)
            ->postJson('/shares', ['target_type' => 'selection', 'allow_download' => true])
            ->assertOk()->json('token');

        $this->app['auth']->guard()->logout();

        $this->get('/s/'.$token)->assertOk()
            ->assertInertia(fn (Assert $stranka) => $stranka->component('Shares/Show'));
        $this->get('/forgot-password')->assertOk()
            ->assertInertia(fn (Assert $stranka) => $stranka->component('Auth/ForgotPassword'));
        $this->get('/reset-password/nejaky-token?email=nekdo@example.test')->assertOk()
            ->assertInertia(fn (Assert $stranka) => $stranka->component('Auth/ResetPassword'));
        $this->get('/sluzba')->assertOk();
        $this->get('/cenik')->assertOk();
    }

    /** Výběr alba po sdílení do PWA (`manifest.webmanifest`) zůstává. */
    public function test_cil_sdileni_z_pwa_zustava(): void
    {
        $this->actingAs($this->vlastnik)->get('/share-target')->assertOk()
            ->assertInertia(fn (Assert $stranka) => $stranka->component('ShareTarget/Index'));
    }

    /** Jediný pomocník pro odkazy ven: kořen, obrazovky, a překlep nepromine. */
    public function test_odkaz_na_obrazovku_aplikace(): void
    {
        $this->assertSame('/', TrasyPrototypu::url('home'));
        $this->assertSame('/galerie/kalendar', TrasyPrototypu::url('calendar'));
        $this->assertSame('/galerie/planovani', TrasyPrototypu::url('x-plan'));

        $this->expectException(\InvalidArgumentException::class);
        TrasyPrototypu::url('planning');
    }

    /** Připomínka akce v e-mailu i ve zvonku vede rovnou do aplikace, ne na starou stránku. */
    public function test_pripominka_akce_odkazuje_do_aplikace(): void
    {
        $akce = new CalendarEvent(['title' => 'Snídaně']);
        $akce->uuid = (string) Str::uuid();
        $oznameni = new EventReminderNotification($akce, 'email');

        $this->assertSame('/galerie/kalendar', $oznameni->toDatabase($this->vlastnik)['link']);
        $this->assertSame(url('/galerie/kalendar'), $oznameni->toMail($this->vlastnik)->actionUrl);
    }

    /** Úkol přidělený druhému: upozornění vede na Plánování v aplikaci, bez staré kotvy. */
    public function test_upozorneni_na_ukol_vede_na_planovani(): void
    {
        $partner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $prostor = $this->vlastnik->gallerySpaces()->first();
        $prostor->members()->attach($partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);

        $this->actingAs($this->vlastnik)->postJson('/api/v1/todos', [
            'gallery_space_id' => $prostor->id, 'title' => 'Koupit mléko', 'assigned_to' => $partner->id,
        ])->assertSuccessful();

        $oznameni = $partner->notifications()->latest()->first();
        $this->assertNotNull($oznameni, 'Přidělený úkol nedal vědět.');
        $this->assertSame('/galerie/planovani', $oznameni->data['link']);
    }

    /** Návrat z platby a odhlášení končí rovnou v aplikaci, s hláškou v sezení. */
    public function test_navraty_vedou_rovnou_do_aplikace(): void
    {
        $this->get('/platby/comgate/navrat?status=cancelled')
            ->assertRedirect('/galerie/administrace')
            ->assertSessionHas('warning');

        $this->actingAs($this->vlastnik)->post('/logout')->assertRedirect('/');
    }

    /** Adresa cesty s vyplněnými parametry — na hodnotách nezáleží, kontroler se nespustí. */
    private function adresa(string $jmeno): string
    {
        $cesta = Route::getRoutes()->getByName($jmeno);
        $parametry = [];

        foreach ($cesta->parameterNames() as $parametr) {
            $parametry[$parametr] = $parametr === 'id' ? 7 : (string) Str::uuid();
        }

        return route($jmeno, $parametry, false);
    }
}
