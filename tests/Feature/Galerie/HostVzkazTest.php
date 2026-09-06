<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\SharedLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Vzkaz od hosta u sdíleného odkazu.
 *
 * Tabulka `guest_comments` v aplikaci existovala a obrazovka z ní četla — jenže
 * nikde se do ní nezapisovalo. Babička, které dvojice pošle odkaz na fotky,
 * neměla jak nechat vzkaz.
 */
class HostVzkazTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);
    }

    /** Vzkaz od hosta se opravdu uloží — bez přihlášení. */
    public function test_host_nechá_vzkaz(): void
    {
        $odkaz = $this->odkaz(['allow_comments' => true]);

        $this->postJson('/s/'.$odkaz->token.'/vzkaz', [
            'jmeno' => 'Babička',
            'text' => 'Kluci, ten stromek je letos nádherný.',
        ])->assertStatus(201)->assertJsonPath('ok', true);

        $this->assertDatabaseHas('guest_comments', [
            'guest_name' => 'Babička',
            'kind' => 'text',
            'body' => 'Kluci, ten stromek je letos nádherný.',
        ]);
    }

    /** Dvojice ho pak vidí ve svém seznamu. */
    public function test_vzkaz_se_objevi_dvojici(): void
    {
        $odkaz = $this->odkaz(['allow_comments' => true, 'name' => 'Vánoce pro babičku']);

        $this->postJson('/s/'.$odkaz->token.'/vzkaz', ['jmeno' => 'Babička', 'text' => 'Díky!'])->assertStatus(201);

        Sanctum::actingAs($this->adri);
        $gv = $this->getJson('/api/data/pribeh')->assertOk()->json('data.GV_C');

        $this->assertCount(1, $gv);
        $this->assertSame('Babička', $gv[0]['who']);
        $this->assertSame('Díky!', $gv[0]['text']);
        $this->assertSame('Vánoce pro babičku', $gv[0]['share']);
    }

    /**
     * Hlasovka se uloží i s nahrávkou — a bez přepisu.
     *
     * Aplikace řeč na text nepřevádí. Prototyp si dosud bral jeden z osmi
     * napsaných přepisů („to je teta Věra před chalupou v Rusavě") a tvářil
     * se, že to babička řekla.
     */
    public function test_hlasovka_se_ulozi_bez_prepisu(): void
    {
        $odkaz = $this->odkaz(['allow_comments' => true]);

        $this->post('/s/'.$odkaz->token.'/vzkaz', [
            'jmeno' => 'Babička',
            'vterin' => 84,
            'nahravka' => UploadedFile::fake()->create('vzkaz.webm', 40, 'audio/webm'),
        ])->assertStatus(201);

        $radek = DB::table('guest_comments')->first();

        $this->assertSame('voice', $radek->kind);
        $this->assertNull($radek->body, 'Hlasovka nemá přepis — aplikace řeč na text nepřevádí.');
        $this->assertSame('1:24', $radek->duration);
        $this->assertNotNull($radek->audio_path);
        Storage::disk('public')->assertExists($radek->audio_path);
    }

    /** Kde dvojice vzkazy nezapnula, se nepíše. */
    public function test_bez_povoleni_se_nepise(): void
    {
        $odkaz = $this->odkaz(['allow_comments' => false]);

        $this->postJson('/s/'.$odkaz->token.'/vzkaz', ['jmeno' => 'Kdosi', 'text' => 'Ahoj'])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('guest_comments')->count());
    }

    /** Vypršelý odkaz nepustí ani vzkaz. */
    public function test_vyprseny_odkaz_nepusti(): void
    {
        $odkaz = $this->odkaz(['allow_comments' => true, 'expires_at' => now()->subDay()]);

        $this->postJson('/s/'.$odkaz->token.'/vzkaz', ['jmeno' => 'Kdosi', 'text' => 'Ahoj'])
            ->assertStatus(404);
    }

    /** Odkaz chráněný heslem nepustí, dokud se heslo nezadá. */
    public function test_odkaz_s_heslem_chce_nejdriv_heslo(): void
    {
        $odkaz = $this->odkaz(['allow_comments' => true, 'password_hash' => bcrypt('tajne123')]);

        $this->postJson('/s/'.$odkaz->token.'/vzkaz', ['jmeno' => 'Kdosi', 'text' => 'Ahoj'])
            ->assertStatus(403);
    }

    /** Prázdný vzkaz není vzkaz. */
    public function test_prazdny_vzkaz_neprojde(): void
    {
        $odkaz = $this->odkaz(['allow_comments' => true]);

        $this->postJson('/s/'.$odkaz->token.'/vzkaz', ['jmeno' => 'Babička'])
            ->assertStatus(422);
    }

    private function odkaz(array $navic = []): SharedLink
    {
        return SharedLink::create(array_merge([
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'token' => 'tok'.Str::random(8),
            'name' => 'Beskydy',
            'target_type' => 'album',
            'target_id' => 1,
        ], $navic));
    }
}
