<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\GallerySpace;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Zrušení účtu se po lhůtě opravdu provede.
 *
 * Nastavení slibovalo „Po čtrnácti dnech se smaže profil, deník i zprávy —
 * nevratně" a žádná úloha to nedělala: žádost se jen zapsala do předvoleb.
 */
class ZruseniUctuTest extends TestCase
{
    use RefreshDatabase;

    private User $vlastnik;

    private User $partnerka;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->vlastnik = User::factory()->create(['role' => 'owner']);
        $this->partnerka = User::factory()->create(['role' => 'partner', 'email' => 'makinka@example.com', 'password' => Hash::make('heslo-heslo-1')]);
        $this->prostor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Společně', 'slug' => 'spolecne', 'owner_id' => $this->vlastnik->id]);
        $this->prostor->members()->attach($this->vlastnik->id, ['role' => 'owner']);
        $this->prostor->members()->attach($this->partnerka->id, ['role' => 'editor']);
    }

    public function test_po_lhute_zmizi_deniky_zpravy_hlasovky_prihlaseni_a_profil(): void
    {
        $mujZapis = $this->zapis($this->partnerka, 'Můj zápis');
        $jehoZapis = $this->zapis($this->vlastnik, 'Jeho zápis');
        Storage::disk('local')->put('chat/fotka.jpg', 'jpeg');
        $mojeZprava = ChatMessage::create(['gallery_space_id' => $this->prostor->id, 'created_by' => $this->partnerka->id, 'body' => 'Ahoj', 'media_path' => 'chat/fotka.jpg']);
        $jehoZprava = ChatMessage::create(['gallery_space_id' => $this->prostor->id, 'created_by' => $this->vlastnik->id, 'body' => 'Čau']);
        Storage::disk('local')->put('voice-notes/hlas.webm', 'webm');
        DB::table('voice_notes')->insert(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->partnerka->id, 'title' => 'Hlas', 'path' => 'voice-notes/hlas.webm', 'mime_type' => 'audio/webm', 'size_bytes' => 4, 'duration_ms' => 1000, 'created_at' => now(), 'updated_at' => now()]);
        $this->partnerka->createToken('telefon');

        Sanctum::actingAs($this->partnerka);
        $this->postJson('/api/v1/ucet/zruseni', ['current_password' => 'heslo-heslo-1'])->assertOk();

        // Před lhůtou se nic neděje.
        $this->artisan('gallery:zrus-ucty')->assertSuccessful();
        $this->assertDatabaseHas('journal_entries', ['id' => $mujZapis->id]);

        $this->travel(15)->days();
        $this->artisan('gallery:zrus-ucty --nanecisto')->assertSuccessful();
        $this->assertDatabaseHas('journal_entries', ['id' => $mujZapis->id]);

        $this->artisan('gallery:zrus-ucty')->assertSuccessful();

        $this->assertDatabaseMissing('journal_entries', ['id' => $mujZapis->id]);
        $this->assertDatabaseHas('journal_entries', ['id' => $jehoZapis->id]);
        $this->assertDatabaseMissing('chat_messages', ['id' => $mojeZprava->id]);
        $this->assertDatabaseHas('chat_messages', ['id' => $jehoZprava->id]);
        $this->assertDatabaseMissing('voice_notes', ['created_by' => $this->partnerka->id]);
        Storage::disk('local')->assertMissing('chat/fotka.jpg');
        Storage::disk('local')->assertMissing('voice-notes/hlas.webm');

        $ucet = $this->partnerka->fresh();
        $this->assertSame('Zrušený účet', $ucet->name);
        $this->assertStringEndsWith('@ucet.invalid', $ucet->email);
        $this->assertFalse((bool) $ucet->is_active);
        $this->assertSame(0, $ucet->tokens()->count());
        $this->assertSame(0, $ucet->gallerySpaces()->count());
        $this->assertFalse(Hash::check('heslo-heslo-1', $ucet->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.deleted']);

        // Podruhé už není co rušit.
        $this->artisan('gallery:zrus-ucty')->expectsOutput('Žádný účet nečeká na zrušení.')->assertSuccessful();
    }

    public function test_odvolana_zadost_se_neprovede(): void
    {
        $zapis = $this->zapis($this->partnerka, 'Zůstane');

        Sanctum::actingAs($this->partnerka);
        $this->postJson('/api/v1/ucet/zruseni', ['current_password' => 'heslo-heslo-1'])->assertOk();
        $this->deleteJson('/api/v1/ucet/zruseni')->assertOk();

        $this->travel(15)->days();
        $this->artisan('gallery:zrus-ucty')->assertSuccessful();

        $this->assertDatabaseHas('journal_entries', ['id' => $zapis->id]);
        $this->assertTrue((bool) $this->partnerka->fresh()->is_active);
    }

    public function test_vlastnik_galerie_ucet_nezrusi(): void
    {
        $this->vlastnik->forceFill(['password' => Hash::make('heslo-heslo-2'), 'role' => 'partner'])->save();

        Sanctum::actingAs($this->vlastnik);
        $this->postJson('/api/v1/ucet/zruseni', ['current_password' => 'heslo-heslo-2'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Jste vlastník galerie. Nejdřív předejte vlastnictví druhému z dvojice, pak účet zrušte.');

        // Ani žádost zapsaná dřív (před touhle zábranou) prostor nenechá bez vlastníka.
        $this->vlastnik->forceFill(['preferences' => ['delete_requested_at' => now()->subDay()->toIso8601String()]])->save();
        $this->artisan('gallery:zrus-ucty')->assertSuccessful();
        $this->assertTrue((bool) $this->vlastnik->fresh()->is_active);
    }

    public function test_uloha_je_v_planovaci(): void
    {
        $nazvy = collect(app(Schedule::class)->events())->map(fn ($u) => $u->description)->all();

        $this->assertContains('account-deletion', $nazvy);
    }

    private function zapis(User $kdo, string $nadpis): JournalEntry
    {
        return JournalEntry::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $kdo->id,
            'title' => $nadpis,
            'body' => 'Text',
            'entry_date' => now()->toDateString(),
            'visibility' => JournalEntry::VISIBILITY_SHARED,
        ]);
    }
}
