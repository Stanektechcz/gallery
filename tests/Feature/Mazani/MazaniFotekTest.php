<?php

namespace Tests\Feature\Mazani;

use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Mazání z prototypu přes API — „Do koše", schválení, ponechání a režim.
 *
 * Pravidlo dvojice: „Mazat fotky mohou jen po společném schválení pokud si
 * po vzájemném schválení nenastaví jinak." Obrazovka se o výsledku dozví
 * z odpovědi — `ids` jsou jen fotky, které opravdu odešly do koše, a podle
 * nich je klient odebere z knihovny. Navržená fotka v knihovně zůstává.
 */
class MazaniFotekTest extends TestCase
{
    use DvojiceSHostem, RefreshDatabase;

    private User $vlastnik;

    private User $partner;

    private User $host;

    private GallerySpace $prostor;

    private Carbon $ted;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ted = Carbon::parse('2026-09-25 10:00:00');
        $this->travelTo($this->ted);
        config(['gallery.trash_retention_days' => 30]);

        [$this->vlastnik, $this->partner, $this->host, $this->prostor] = $this->dvojiceSHostem();
        $this->vlastnik->update(['name' => 'Bára']);
        $this->partner->update(['name' => 'Ctibor']);
    }

    public function test_delete_ve_dvojici_fotku_jen_navrhne(): void
    {
        $fotka = $this->fotka($this->prostor, $this->vlastnik, 'more.jpg');
        Sanctum::actingAs($this->vlastnik);

        $this->deleteJson('/api/media/'.$fotka->uuid)
            ->assertOk()
            ->assertJsonPath('id', $fotka->uuid)
            ->assertJsonPath('status', 'proposed')
            ->assertJsonPath('rezim', 'spolecne')
            ->assertJsonPath('zprava', 'Navrženo ke smazání · čeká, až to potvrdí Ctibor');

        $fotka->refresh();
        $this->assertNull($fotka->trashed_at, 'Navržená fotka zůstává v knihovně.');
        $this->assertSame($this->vlastnik->id, (int) $fotka->trash_requested_by);

        // Druhé kliknutí nic nového nenavrhne.
        $this->deleteJson('/api/media/'.$fotka->uuid)->assertOk()->assertJsonPath('status', 'uz_navrzeno');
        $this->assertSame(1, AuditLog::where('action', 'media.trash_proposed')->count());
    }

    public function test_hromadne_do_kose_ve_dvojici_vraci_navrzene_zvlast(): void
    {
        $prvni = $this->fotka($this->prostor, $this->vlastnik, 'a.jpg');
        $druha = $this->fotka($this->prostor, $this->vlastnik, 'b.jpg');
        Sanctum::actingAs($this->vlastnik);

        $odpoved = $this->postJson('/api/media/do-kose', ['ids' => [$prvni->uuid, $druha->uuid, 'neexistuje']])
            ->assertOk()
            ->assertJsonPath('ids', [])
            ->assertJsonPath('status', 'proposed')
            ->assertJsonPath('rezim', 'spolecne');

        $this->assertEqualsCanonicalizing([$prvni->uuid, $druha->uuid], $odpoved->json('navrzeno'));
        $this->assertSame([], $odpoved->json('uzNavrzeno'));
        $this->assertStringContainsString('2 položky', (string) $odpoved->json('zprava'));
        $this->assertSame(0, MediaItem::whereNotNull('trashed_at')->count());
    }

    public function test_partner_schvali_a_fotka_jde_do_kose_na_tricet_dni(): void
    {
        $fotka = $this->fotka($this->prostor, $this->vlastnik, 'more.jpg');
        Sanctum::actingAs($this->vlastnik);
        $this->deleteJson('/api/media/'.$fotka->uuid)->assertOk();

        Sanctum::actingAs($this->partner);
        $odpoved = $this->postJson('/api/kos/schvalit', ['ids' => [$fotka->uuid]])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ids', [$fotka->uuid])
            ->assertJsonPath('zprava', 'Smazáno po společném schválení · 30 dní na vrácení');

        $fotka->refresh();
        $this->assertTrue($fotka->trashed_at->equalTo($this->ted));
        $this->assertTrue($fotka->purge_after->equalTo($this->ted->copy()->addDays(30)));
        $this->assertSame($this->partner->id, (int) $fotka->trashed_by);

        // Odpověď nese obsah obrazovky, ať koš i seznam ke schválení sedí hned.
        $this->assertArrayHasKey('prazdne', $odpoved->json());
        $this->assertSame($fotka->uuid, $odpoved->json('data.TRASH.0.id'));
        $this->assertSame('Ctibor', $odpoved->json('data.TRASH.0.by'), 'V koši je, kdo fotku odstranil, ne kdo ji nahrál.');
        $this->assertSame([], $odpoved->json('data.KE_SCHVALENI'));
        $this->assertSame(0, $odpoved->json('data.MAZANI.cekaNaMe'));
    }

    public function test_vlastni_navrh_schvalit_nejde(): void
    {
        $fotka = $this->fotka($this->prostor, $this->vlastnik, 'more.jpg');
        Sanctum::actingAs($this->vlastnik);
        $this->deleteJson('/api/media/'.$fotka->uuid)->assertOk();

        $this->postJson('/api/kos/schvalit', ['ids' => [$fotka->uuid]])->assertForbidden();

        $this->assertNull($fotka->fresh()->trashed_at);
    }

    public function test_ponechat_partnerem_i_navrhujicim(): void
    {
        $prvni = $this->fotka($this->prostor, $this->vlastnik, 'a.jpg');
        $druha = $this->fotka($this->prostor, $this->vlastnik, 'b.jpg');
        Sanctum::actingAs($this->vlastnik);
        $this->postJson('/api/media/do-kose', ['ids' => [$prvni->uuid, $druha->uuid]])->assertOk();

        Sanctum::actingAs($this->partner);
        $this->postJson('/api/kos/ponechat', ['ids' => [$prvni->uuid]])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ids', [$prvni->uuid])
            ->assertJsonPath('zprava', 'Ponecháno v knihovně');

        Sanctum::actingAs($this->vlastnik);
        $this->postJson('/api/kos/ponechat', ['ids' => [$druha->uuid]])
            ->assertOk()
            ->assertJsonPath('ids', [$druha->uuid])
            ->assertJsonPath('zprava', 'Návrh stažen');

        $this->assertSame(0, MediaItem::cekaNaSmazani()->count());
        $this->assertSame(0, MediaItem::whereNotNull('trashed_at')->count());
        $this->assertSame(1, AuditLog::where('action', 'media.trash_rejected')->count());
        $this->assertSame(1, AuditLog::where('action', 'media.trash_withdrawn')->count());
    }

    public function test_do_kose_partnera_na_navrzenou_fotku_ji_smaze(): void
    {
        $fotka = $this->fotka($this->prostor, $this->vlastnik, 'more.jpg');
        Sanctum::actingAs($this->vlastnik);
        $this->postJson('/api/media/do-kose', ['ids' => [$fotka->uuid]])->assertOk();

        Sanctum::actingAs($this->partner);
        $odpoved = $this->postJson('/api/media/do-kose', ['ids' => [$fotka->uuid]])
            ->assertOk()
            ->assertJsonPath('ids', [$fotka->uuid])
            ->assertJsonPath('navrzeno', [])
            ->assertJsonPath('status', 'trashed');

        $this->assertStringContainsString('Smazáno po společném schválení', (string) $odpoved->json('zprava'));
        $this->assertNotNull($fotka->fresh()->trashed_at);
    }

    public function test_host_nesmi_nic(): void
    {
        $fotka = $this->fotka($this->prostor, $this->vlastnik, 'more.jpg');
        Sanctum::actingAs($this->vlastnik);
        $this->deleteJson('/api/media/'.$fotka->uuid)->assertOk();

        Sanctum::actingAs($this->host);
        $this->deleteJson('/api/media/'.$fotka->uuid)->assertForbidden();
        $this->postJson('/api/media/do-kose', ['ids' => [$fotka->uuid]])->assertForbidden();
        $this->postJson('/api/kos/schvalit', ['ids' => [$fotka->uuid]])->assertForbidden();
        $this->postJson('/api/kos/ponechat', ['ids' => [$fotka->uuid]])->assertForbidden();
        $this->postJson('/api/mazani/rezim', ['rezim' => 'kazdy'])->assertForbidden();
        $this->postJson('/api/mazani/rezim/potvrdit', ['heslo' => 'password'])->assertForbidden();
        $this->postJson('/api/mazani/rezim/zrusit')->assertForbidden();

        $fotka->refresh();
        $this->assertNull($fotka->trashed_at);
        $this->assertSame($this->vlastnik->id, (int) $fotka->trash_requested_by);
        $this->assertSame('spolecne', $this->prostor->fresh()->media_delete_mode);
    }

    public function test_jediny_z_dvojice_maze_rovnou(): void
    {
        $sam = User::factory()->create(['is_active' => true]);
        $prostor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Jen já', 'slug' => 'jen-ja-'.Str::random(5), 'owner_id' => $sam->id, 'is_default' => true]);
        $prostor->members()->attach($sam->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $prvni = $this->fotka($prostor, $sam, 'a.jpg');
        $druha = $this->fotka($prostor, $sam, 'b.jpg');
        Sanctum::actingAs($sam);

        $this->deleteJson('/api/media/'.$prvni->uuid)
            ->assertOk()
            ->assertJsonPath('status', 'trashed')
            ->assertJsonPath('zprava', 'Přesunuto do koše · 30 dní na vrácení');
        $this->postJson('/api/media/do-kose', ['ids' => [$druha->uuid]])
            ->assertOk()
            ->assertJsonPath('ids', [$druha->uuid])
            ->assertJsonPath('status', 'trashed');

        $this->assertNotNull($prvni->fresh()->trashed_at);
        $this->assertNotNull($druha->fresh()->purge_after);

        // Co už v koši je, znovu smazat nejde — jako by tu nebylo.
        $this->deleteJson('/api/media/'.$prvni->uuid)->assertNotFound();
    }

    public function test_skryta_fotka_se_zamcenym_trezorem_neexistuje(): void
    {
        $skryta = $this->fotka($this->prostor, $this->vlastnik, 'pas.jpg', ['is_hidden' => true]);
        $videt = $this->fotka($this->prostor, $this->vlastnik, 'more.jpg');
        Sanctum::actingAs($this->vlastnik);

        $this->deleteJson('/api/media/'.$skryta->uuid)
            ->assertNotFound()
            ->assertJsonPath('message', 'Takový soubor tu není.');

        $odpoved = $this->postJson('/api/media/do-kose', ['ids' => [$skryta->uuid, $videt->uuid]])->assertOk();

        $this->assertSame([$videt->uuid], $odpoved->json('navrzeno'));
        $this->assertStringNotContainsString($skryta->uuid, $odpoved->getContent(), 'Odpověď nesmí prozradit, že fotka v trezoru existuje.');
        $this->assertStringNotContainsString('trezor', mb_strtolower((string) $odpoved->json('zprava')));
        $this->assertNull($skryta->fresh()->trash_requested_by);

        // Jen skryté: nic se nestalo a odpověď o nich mlčí.
        $this->postJson('/api/media/do-kose', ['ids' => [$skryta->uuid]])
            ->assertOk()
            ->assertJsonPath('ids', [])
            ->assertJsonPath('navrzeno', [])
            ->assertJsonPath('status', 'skipped');
    }

    public function test_skryta_fotka_s_odemcenym_trezorem_se_navrhne(): void
    {
        $skryta = $this->fotka($this->prostor, $this->vlastnik, 'pas.jpg', ['is_hidden' => true]);

        $this->sOdemcenymTrezorem($this->vlastnik)
            ->deleteJson('/api/media/'.$skryta->uuid)
            ->assertOk()
            ->assertJsonPath('status', 'proposed');

        $this->assertSame($this->vlastnik->id, (int) $skryta->fresh()->trash_requested_by);
    }

    public function test_navrh_dostane_partner_ne_host_ani_navrhujici(): void
    {
        $prvni = $this->fotka($this->prostor, $this->vlastnik, 'dovolena-u-more.jpg');
        $druha = $this->fotka($this->prostor, $this->vlastnik, 'b.jpg');
        Sanctum::actingAs($this->vlastnik);

        $this->postJson('/api/media/do-kose', ['ids' => [$prvni->uuid, $druha->uuid]])->assertOk();

        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $this->partner->id)->count(), 'Jedno oznámení na požadavek, ne na fotku.');
        $this->assertSame(0, DatabaseNotification::where('notifiable_id', $this->host->id)->count());
        $this->assertSame(0, DatabaseNotification::where('notifiable_id', $this->vlastnik->id)->count());

        $oznameni = DatabaseNotification::where('notifiable_id', $this->partner->id)->sole();
        $this->assertSame('media.trash_proposed', $oznameni->data['type']);
        $this->assertStringContainsString('Bára', $oznameni->data['message']);
        $this->assertStringContainsString('2 položky', $oznameni->data['message']);
        $this->assertStringNotContainsString('dovolena', json_encode($oznameni->data), 'Oznámení nenese jména souborů.');

        // Opakované kliknutí nic nového nenavrhne, a tak ani neupozorní.
        $this->postJson('/api/media/do-kose', ['ids' => [$prvni->uuid]])->assertOk();
        $this->assertSame(1, DatabaseNotification::where('notifiable_id', $this->partner->id)->count());
    }

    public function test_navrh_jen_z_trezoru_neupozorni(): void
    {
        $skryta = $this->fotka($this->prostor, $this->vlastnik, 'pas.jpg', ['is_hidden' => true]);

        $this->sOdemcenymTrezorem($this->vlastnik)
            ->postJson('/api/media/do-kose', ['ids' => [$skryta->uuid]])
            ->assertOk()
            ->assertJsonPath('navrzeno', [$skryta->uuid]);

        $this->assertSame(0, DatabaseNotification::count(), 'Oznámení by prozradilo, že se v trezoru něco děje.');
    }

    public function test_rezim_kazdy_plati_az_po_potvrzeni_partnerem(): void
    {
        Sanctum::actingAs($this->vlastnik);

        $odpoved = $this->postJson('/api/mazani/rezim', ['rezim' => 'kazdy'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('vysledek', 'navrzeno')
            ->assertJsonPath('rezim', 'spolecne')
            ->assertJsonPath('zprava', 'Návrh odeslán — platí, až ho Ctibor potvrdí');

        $this->assertSame('spolecne', $this->prostor->fresh()->media_delete_mode);
        $this->assertSame('kazdy', $odpoved->json('data.MAZANI.navrhRezimu.rezim'));
        $this->assertTrue($odpoved->json('data.MAZANI.navrhRezimu.ja'));
        $this->assertSame('Bára', $odpoved->json('data.MAZANI.navrhRezimu.kdo'));

        Sanctum::actingAs($this->partner);
        $this->postJson('/api/mazani/rezim/potvrdit', ['heslo' => 'spatne-heslo'])->assertStatus(422);
        $this->assertSame('spolecne', $this->prostor->fresh()->media_delete_mode);

        $this->postJson('/api/mazani/rezim/potvrdit', ['heslo' => 'password'])
            ->assertOk()
            ->assertJsonPath('vysledek', 'potvrzeno')
            ->assertJsonPath('rezim', 'kazdy')
            ->assertJsonPath('zprava', 'Každý teď maže sám — potvrdili jste oba')
            ->assertJsonPath('data.MAZANI.muzuSam', true)
            ->assertJsonPath('data.MAZANI.navrhRezimu', null);

        // Teď maže každý sám.
        $fotka = $this->fotka($this->prostor, $this->vlastnik, 'more.jpg');
        Sanctum::actingAs($this->vlastnik);
        $this->deleteJson('/api/media/'.$fotka->uuid)->assertOk()->assertJsonPath('status', 'trashed')->assertJsonPath('rezim', 'kazdy');
    }

    public function test_navrhujici_vlastni_navrh_rezimu_nepotvrdi(): void
    {
        Sanctum::actingAs($this->vlastnik);
        $this->postJson('/api/mazani/rezim', ['rezim' => 'kazdy'])->assertOk();

        $this->postJson('/api/mazani/rezim/potvrdit', ['heslo' => 'password'])->assertForbidden();
        $this->assertSame('spolecne', $this->prostor->fresh()->media_delete_mode);
    }

    public function test_zprisneni_plati_hned(): void
    {
        $this->prostor->forceFill(['media_delete_mode' => 'kazdy'])->save();
        Sanctum::actingAs($this->partner);

        $this->postJson('/api/mazani/rezim', ['rezim' => 'spolecne'])
            ->assertOk()
            ->assertJsonPath('vysledek', 'zprisneno')
            ->assertJsonPath('rezim', 'spolecne')
            ->assertJsonPath('zprava', 'Mazání zase jen po společném schválení')
            ->assertJsonPath('data.MAZANI.rezim', 'spolecne');

        $this->assertSame('spolecne', $this->prostor->fresh()->media_delete_mode);
    }

    public function test_zruseni_navrhu_rezimu(): void
    {
        Sanctum::actingAs($this->vlastnik);
        $this->postJson('/api/mazani/rezim', ['rezim' => 'kazdy'])->assertOk();

        Sanctum::actingAs($this->partner);
        $this->postJson('/api/mazani/rezim/zrusit')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('zprava', 'Návrh stažen')
            ->assertJsonPath('data.MAZANI.navrhRezimu', null);

        $this->postJson('/api/mazani/rezim/zrusit')->assertOk()->assertJsonPath('ok', false);
        $this->assertNull($this->prostor->fresh()->media_delete_mode_requested);
    }

    public function test_vstupy_se_overuji(): void
    {
        Sanctum::actingAs($this->vlastnik);

        $this->postJson('/api/mazani/rezim', ['rezim' => 'nekdo'])->assertUnprocessable();
        $this->postJson('/api/mazani/rezim', [])->assertUnprocessable();
        $this->postJson('/api/mazani/rezim/potvrdit', ['heslo' => str_repeat('x', 300)])->assertUnprocessable();
        $this->postJson('/api/kos/schvalit', ['ids' => []])->assertUnprocessable();
        $this->postJson('/api/kos/ponechat', ['ids' => [str_repeat('x', 65)]])->assertUnprocessable();
        $this->postJson('/api/media/do-kose', ['ids' => array_fill(0, 501, 'x')])->assertUnprocessable();
    }
}
