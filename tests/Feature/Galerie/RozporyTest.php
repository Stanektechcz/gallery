<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rozpory mezi aplikací a Diskem — od vzniku po vyřešení.
 *
 * Webhook ukládal změny z Disku se stavem `pending` a nikdo je nezpracoval:
 * `drive_conflicts` zůstávala prázdná a obrazovka kreslila ukázku. Fotka
 * smazaná na Disku tak v aplikaci zůstala jako platná.
 *
 * Rozpor tu nevzniká zápisem člověka, ale synchronizací — proto se testuje
 * příkaz, ne formulář.
 */
class RozporyTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    private int $pripojeni;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        $this->pripojeni = DB::table('storage_connections')->insertGetId([
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'provider' => 'google_drive',
            'connection_status' => 'connected',
            'scope_mode' => 'app_folder',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->adri);
    }

    /**
     * Fotka smazaná na Disku, kterou aplikace dál má, je rozpor.
     *
     * Bez toho zůstane v knihovně řádek, za kterým na Disku nic není —
     * a dvojice se to dozví až ve chvíli, kdy si ji chce otevřít.
     */
    public function test_smazani_na_disku_zalozi_rozpor(): void
    {
        $fotka = $this->fotka('IMG_2418.jpg', 'drive-1');
        $this->zmena('drive-1', 'IMG_2418.jpg', trashed: true);

        $this->artisan('gallery:process-drive-changes')->assertSuccessful();

        $rozpor = DB::table('drive_conflicts')->sole();

        $this->assertSame('media_item', $rozpor->entity_type);
        $this->assertSame($fotka, (int) $rozpor->entity_id);
        $this->assertSame('deleted_on_drive', $rozpor->conflict_type);
        $this->assertSame('unresolved', $rozpor->resolution);
        $this->assertSame('conflict', DB::table('drive_changes')->value('processed_status'));
    }

    /** Když se obě strany shodly, rozpor to není. */
    public function test_shoda_rozpor_nezaklada(): void
    {
        $this->fotka('IMG_2418.jpg', 'drive-1', trashed: true);
        $this->zmena('drive-1', 'IMG_2418.jpg', trashed: true);

        $this->artisan('gallery:process-drive-changes')->assertSuccessful();

        $this->assertSame(0, DB::table('drive_conflicts')->count());
        $this->assertSame('processed', DB::table('drive_changes')->value('processed_status'));
    }

    /** Změna souboru, který aplikace nezná, je běžný stav — ne rozpor. */
    public function test_cizi_soubor_se_preskoci(): void
    {
        $this->zmena('nikdy-nevideno', 'cizi.jpg');

        $this->artisan('gallery:process-drive-changes')->assertSuccessful();

        $this->assertSame(0, DB::table('drive_conflicts')->count());
        $this->assertSame('ignored', DB::table('drive_changes')->value('processed_status'));
    }

    /** Přejmenování na Disku je rozpor v názvu. */
    public function test_prejmenovani_na_disku_je_rozpor(): void
    {
        $this->fotka('IMG_2418.jpg', 'drive-1');
        $this->zmena('drive-1', 'Pustevny nad mlhou.jpg');

        $this->artisan('gallery:process-drive-changes')->assertSuccessful();

        $this->assertSame('renamed_on_drive', DB::table('drive_conflicts')->value('conflict_type'));
    }

    /** Druhý webhook o téže věci nehlásí dvojici totéž podruhé. */
    public function test_rozpor_nevznikne_dvakrat(): void
    {
        $this->fotka('IMG_2418.jpg', 'drive-1');
        $this->zmena('drive-1', 'IMG_2418.jpg', trashed: true);
        $this->zmena('drive-1', 'IMG_2418.jpg', trashed: true);

        $this->artisan('gallery:process-drive-changes')->assertSuccessful();

        $this->assertSame(1, DB::table('drive_conflicts')->count());
    }

    /**
     * „Vyřešeno" se zapíše do databáze, ne jen do prohlížeče.
     *
     * Dřív rozpor zůstal otevřený: při dalším načtení se vrátil a druhý
     * z dvojice ho viděl celou dobu.
     */
    public function test_vyreseni_rozporu_se_zapise(): void
    {
        $id = $this->rozpor();

        $this->postJson('/api/rozpory/vyresit', ['id' => 'r'.$id, 'volba' => 'mine'])->assertOk();

        $rozpor = DB::table('drive_conflicts')->sole();

        $this->assertSame('mine', $rozpor->resolution);
        $this->assertNotNull($rozpor->resolved_at);
        $this->assertSame($this->adri->id, $rozpor->resolved_by);

        // A z obrazovky zmizí — otevřené rozpory jsou jen ty nevyřešené.
        $this->assertSame([], $this->getJson('/api/data/system')->assertOk()->json('data.CONFLICTS') ?? []);
    }

    /** Ukázkový rozpor z `galerie-data.js` server nezná. */
    public function test_ukazkovy_rozpor_je_404(): void
    {
        $this->rozpor();

        $this->postJson('/api/rozpory/vyresit', ['id' => 'c1', 'volba' => 'mine'])->assertNotFound();
    }

    /** Cizí volba se neuloží potichu. */
    public function test_neznama_volba_neprojde(): void
    {
        $this->postJson('/api/rozpory/vyresit', ['id' => 'r1', 'volba' => 'moje'])->assertStatus(422);
    }

    /** Rozpor z cizího prostoru vyřešit nejde. */
    public function test_cizi_rozpor_nejde_vyresit(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $ciziPripojeni = DB::table('storage_connections')->insertGetId([
            'gallery_space_id' => $ciziProstor->id, 'owner_user_id' => $cizi->id,
            'provider' => 'google_drive', 'connection_status' => 'connected',
            'scope_mode' => 'app_folder',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $id = $this->rozpor($ciziPripojeni);

        $this->postJson('/api/rozpory/vyresit', ['id' => 'r'.$id, 'volba' => 'mine'])->assertNotFound();
        $this->assertNull(DB::table('drive_conflicts')->value('resolved_at'));
    }

    // ——— pomůcky ———

    private function fotka(string $jmeno, string $driveId, bool $trashed = false): int
    {
        return DB::table('media_items')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => $jmeno,
            'safe_filename' => $jmeno,
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1000,
            'uploaded_at' => now(),
            'status' => 'ready',
            'drive_file_id' => $driveId,
            'storage_status' => 'local',
            'trashed_at' => $trashed ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function zmena(string $driveId, string $jmeno, bool $trashed = false): void
    {
        DB::table('drive_changes')->insert([
            'storage_connection_id' => $this->pripojeni,
            'change_type' => 'sync',
            'file_id' => $driveId,
            'file_name' => $jmeno,
            'removed' => false,
            'trashed' => $trashed,
            'change_payload' => json_encode([]),
            'processed_status' => 'pending',
            'change_time' => CarbonImmutable::now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rozpor(?int $pripojeni = null): int
    {
        return DB::table('drive_conflicts')->insertGetId([
            'storage_connection_id' => $pripojeni ?? $this->pripojeni,
            'entity_type' => 'media_item',
            'entity_id' => $this->fotka('IMG_2418.jpg', 'drive-'.Str::random(6)),
            'drive_file_id' => 'drive-1',
            'conflict_type' => 'deleted_on_drive',
            'resolution' => 'unresolved',
            'detected_at' => CarbonImmutable::now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
