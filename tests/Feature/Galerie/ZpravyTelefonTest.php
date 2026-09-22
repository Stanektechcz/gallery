<?php

namespace Tests\Feature\Galerie;

use App\Models\ChatMessage;
use App\Models\GallerySpace;
use App\Models\User;
use App\Support\SpaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Zprávy z telefonu — stejnou cestou jako z počítače.
 *
 * Telefon měl hovor dvojice jen jako náhled bez psaní. Nová obrazovka posílá
 * text i fotku přes `/v1/chat` a hlasovku jako odkaz na nahraný záznam,
 * aby šla v hovoru přehrát na obou zařízeních.
 */
class ZpravyTelefonTest extends TestCase
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
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id, 'is_default' => true]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    public function test_text_z_telefonu_je_ve_vlakne_obou(): void
    {
        $this->postJson('/api/v1/chat', ['body' => 'Ahoj z telefonu'])->assertCreated();

        $radek = collect($this->getJson('/api/data/zpravy')->assertOk()->json('data.MSGS'))->firstWhere(5, 'Ahoj z telefonu');
        $this->assertNotNull($radek);
        $this->assertSame('A', $radek[1]);
        $this->assertSame('t', $radek[4]);
    }

    public function test_hlasovka_jde_do_hovoru_jako_odkaz_na_zaznam(): void
    {
        $uuid = $this->hlasovka($this->adri, 'Hlasovka z 9:12', 4200);

        $this->postJson('/api/v1/chat', ['voice_note' => $uuid])->assertCreated();

        $zprava = ChatMessage::withoutGlobalScope(SpaceContext::SCOPE)->latest('id')->firstOrFail();
        $this->assertSame('voice', $zprava->attachment_type);
        $this->assertSame($uuid, $zprava->attachment_ref);
        $this->assertSame('Hlasovka z 9:12', $zprava->body);

        // V obsahu pro obrazovku je to hlasovka s nahrávkou, kterou jde přehrát.
        $radek = collect($this->getJson('/api/data/zpravy')->json('data.MSGS'))->firstWhere(0, $zprava->uuid);
        $this->assertSame('v', $radek[4]);
        $this->assertSame($uuid, $radek[7]);
        $this->assertSame('0:04', $radek[6]);
    }

    public function test_cizi_hlasovku_poslat_nejde(): void
    {
        $cizi = $this->hlasovka($this->maki, 'Její', 1000);

        $this->postJson('/api/v1/chat', ['voice_note' => $cizi])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tahle hlasovka tu není.');
    }

    public function test_smazat_jde_jen_vlastni_zpravu(): void
    {
        $moje = $this->postJson('/api/v1/chat', ['body' => 'Moje'])->assertCreated()->json('uuid');
        $jeji = ChatMessage::withoutGlobalScope(SpaceContext::SCOPE)->create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->maki->id, 'body' => 'Její',
        ]);

        $this->deleteJson('/api/v1/chat/'.$jeji->uuid)->assertForbidden();
        $this->deleteJson('/api/v1/chat/'.$moje)->assertOk();

        // Smazaná zmizí z vlákna u obou; cizí zůstává.
        $texty = collect($this->getJson('/api/data/zpravy')->json('data.MSGS'))->pluck(5)->all();
        $this->assertNotContains('Moje', $texty);
        $this->assertContains('Její', $texty);
    }

    private function hlasovka(User $kdo, string $nazev, int $ms): string
    {
        $uuid = (string) Str::uuid();
        DB::table('voice_notes')->insert([
            'uuid' => $uuid, 'gallery_space_id' => $this->prostor->id, 'created_by' => $kdo->id,
            'title' => $nazev, 'path' => 'voice-notes/'.$uuid.'.webm', 'mime_type' => 'audio/webm',
            'size_bytes' => 10, 'duration_ms' => $ms, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $uuid;
    }
}
