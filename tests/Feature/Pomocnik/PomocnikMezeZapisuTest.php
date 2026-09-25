<?php

namespace Tests\Feature\Pomocnik;

use App\Models\GallerySpace;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\SpaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pomocník zapisuje, co z textu vyčte — v mezích sloupců a rozumně.
 *
 * SQLite v testech šířku sloupců nehlídá; na MySQL by přetečení bylo
 * „Data too long" / „Out of range" a uživatel by dostal 500. Testy proto
 * kontrolují uloženou délku a rozsah proti skutečným šířkám z migrací.
 * Vedle toho: každý rozpoznaný titul znamenal dotaz do databáze filmů bez
 * stropu a bez omezení počtu požadavků, a chyba spojení se i s klíčem
 * k API v adrese zapsala do logu.
 */
class PomocnikMezeZapisuTest extends TestCase
{
    use RefreshDatabase;

    private User $vlastnik;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();
        SpaceContext::forget();
        Queue::fake();

        $this->vlastnik = User::factory()->create(['role' => 'owner']);
        $this->prostor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Galerie', 'slug' => 'galerie', 'owner_id' => $this->vlastnik->id]);
        $this->prostor->members()->attach($this->vlastnik->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->actingAs($this->vlastnik);
    }

    public function test_dlouha_cesta_a_zastavky_se_vejdou_do_sloupcu(): void
    {
        $od = now()->addDays(10)->toDateString();
        $do = now()->addDays(12)->toDateString();
        $nazev = str_repeat('Ř', 300);
        $zastavka = str_repeat('Ů', 300);

        $this->postJson('/api/v1/assistant/apply', ['message' => "/cesta {$nazev} | {$od} | {$do} | {$zastavka}"])->assertCreated();

        $this->assertLessThanOrEqual(160, mb_strlen((string) DB::table('calendar_events')->value('title')));
        $this->assertLessThanOrEqual(255, mb_strlen((string) DB::table('trips')->value('name')));
        $mista = DB::table('trip_waypoints')->pluck('place_name');
        $this->assertNotEmpty($mista);
        foreach ($mista as $misto) {
            $this->assertLessThanOrEqual(255, mb_strlen((string) $misto));
        }
    }

    public function test_dlouhy_nazev_filmu_se_vejde(): void
    {
        $this->postJson('/api/v1/assistant/apply', ['message' => 'filmy: '.str_repeat('Š', 300)])->assertCreated();

        $nazev = (string) DB::table('entertainment_titles')->value('title');
        $this->assertNotSame('', $nazev);
        $this->assertLessThanOrEqual(255, mb_strlen($nazev));
    }

    public function test_jednoradkovy_recept_drzi_delky_i_rozsahy(): void
    {
        $surovina = str_repeat('Č', 250);
        $adresa = 'https://example.test/'.str_repeat('a', 3000);
        $zprava = "recept: Guláš\nsuroviny: {$surovina}, sůl\npostup: uvařit\nporce: 99999999\npříprava: 999999 min\nvaření: 999999 min\n{$adresa}\nnákup: ".str_repeat('M', 300)."\ndárek: ".str_repeat('D', 300).' | '.str_repeat('O', 200);

        $this->postJson('/api/v1/assistant/apply', ['message' => $zprava])->assertCreated();

        $recept = DB::table('recipes')->first();
        $this->assertNotNull($recept);
        $this->assertLessThanOrEqual(1000, (float) $recept->base_servings);        // decimal(8,2), validace receptu ≤ 1000
        $this->assertLessThanOrEqual(10080, (int) $recept->prep_minutes);          // unsigned smallint, validace ≤ 10080
        $this->assertLessThanOrEqual(10080, (int) $recept->cook_minutes);
        $this->assertLessThanOrEqual(2048, mb_strlen((string) $recept->source_url));
        foreach (DB::table('recipe_ingredients')->pluck('name') as $nazev) {
            $this->assertLessThanOrEqual(180, mb_strlen((string) $nazev));
        }
        foreach (DB::table('shared_todos')->pluck('title') as $ukol) {
            $this->assertLessThanOrEqual(255, mb_strlen((string) $ukol));
        }
        $darek = DB::table('gift_ideas')->first();
        $this->assertNotNull($darek);
        $this->assertLessThanOrEqual(255, mb_strlen((string) $darek->title));
        $this->assertLessThanOrEqual(80, mb_strlen((string) $darek->occasion));
    }

    public function test_mnozstvi_suroviny_nepretece_decimal(): void
    {
        $zprava = "Bábovka\nSuroviny\n- 99999999999999 g mouky\n- 2 vejce\nPostup\n1. Smíchat\nVše smíchat a upéct.";

        $this->postJson('/api/v1/assistant/apply', ['message' => $zprava])->assertCreated();

        $mnozstvi = DB::table('recipe_ingredients')->whereNotNull('quantity')->pluck('quantity');
        $this->assertNotEmpty($mnozstvi);
        foreach ($mnozstvi as $hodnota) {
            $this->assertLessThan(100000000, (float) $hodnota);   // decimal(12,4)
        }
    }

    public function test_nahled_se_sto_tituly_nezahlti_databazi_filmu(): void
    {
        $this->tmdb();
        Http::fake(['api.themoviedb.org/*' => Http::response(['results' => []])]);
        $tituly = implode(', ', array_map(fn (int $i) => "Film{$i}", range(1, 100)));

        $plan = $this->postJson('/api/v1/assistant/preview', ['message' => "filmy: {$tituly}"])->assertOk()->json();

        $this->assertLessThanOrEqual(20, count($plan['titles']));
        Http::assertSentCount(count($plan['titles']));
        $this->assertLessThanOrEqual(20, count(Http::recorded()));
    }

    public function test_pomocnik_ma_omezeny_pocet_pozadavku(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/v1/assistant/preview', ['message' => 'filmy: Matrix'])->assertOk();
        }

        $this->postJson('/api/v1/assistant/preview', ['message' => 'filmy: Matrix'])->assertStatus(429);
    }

    public function test_chyba_databaze_filmu_nezapise_klic_do_logu(): void
    {
        $this->tmdb();
        Http::fake(['api.themoviedb.org/*' => Http::failedConnection()]);
        $zaznamy = [];
        Event::listen(MessageLogged::class, function (MessageLogged $zaznam) use (&$zaznamy) {
            $vyjimka = $zaznam->context['exception'] ?? null;
            $zaznamy[] = $zaznam->message.' '.json_encode(array_diff_key($zaznam->context, ['exception' => 1]))
                .($vyjimka instanceof \Throwable ? ' '.$vyjimka->getMessage().' '.($vyjimka->getPrevious()?->getMessage() ?? '') : '');
        });

        $this->postJson('/api/v1/assistant/preview', ['message' => 'filmy: Matrix'])->assertOk();

        $this->assertNotEmpty($zaznamy, 'Chyba spojení se má do logu zapsat — jen bez klíče.');
        foreach ($zaznamy as $zaznam) {
            $this->assertStringNotContainsString('tajny-klic-tmdb', $zaznam);
        }
    }

    private function tmdb(): void
    {
        $nastaveni = new IntegrationSetting(['provider' => 'tmdb', 'is_enabled' => true]);
        $nastaveni->replaceConfig(['api_key' => 'tajny-klic-tmdb']);
        $nastaveni->save();
    }
}
