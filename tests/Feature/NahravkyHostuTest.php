<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\GuestUpload;
use App\Models\SharedLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fotky od hostů nezůstávají na disku napořád a nezaplní ho.
 *
 * Nahrávání přes sdílený odkaz je jediný zápis na disk bez přihlášení. Mělo
 * limit na soubor, ne na odkaz: kdokoli s odkazem mohl nahrávat bez konce
 * a všechno čekalo na disku, dokud to dvojice neprošla.
 *
 * A smazaný odkaz vzal s sebou řádky nahrávek (`cascadeOnDelete`), ale ne
 * soubory. Fotky, které se dvojice zbavila i s odkazem, ležely na serveru dál
 * a nic je už nenašlo.
 */
class NahravkyHostuTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);
    }

    public function test_smazany_odkaz_uklidi_neschvalene_soubory(): void
    {
        $odkaz = $this->odkaz();
        $cesta = $this->nahraj($odkaz)->storage_path;
        Storage::disk('local')->assertExists($cesta);

        $odkaz->delete();

        Storage::disk('local')->assertMissing($cesta);
        $this->assertSame([], Storage::disk('local')->allFiles('guest_uploads'),
            'Po smazaném odkazu zbyly na disku soubory, které už nic nenajde.');
    }

    /**
     * Odkaz má strop na to, co čeká na schválení.
     *
     * Do schválení leží všechno na disku serveru; bez stropu šlo jedním
     * odkazem nahrávat dvacet souborů po sto megabajtech třicetkrát za minutu.
     */
    public function test_odkaz_ma_strop_neschvalenych_nahravek(): void
    {
        config(['gallery.guest_upload_pending_mb' => 1]);
        $odkaz = $this->odkaz();

        $this->post("/s/{$odkaz->token}/upload", ['files' => [UploadedFile::fake()->create('a.jpg', 600, 'image/jpeg')]], ['Accept' => 'application/json'])
            ->assertCreated();

        $odpoved = $this->post("/s/{$odkaz->token}/upload", ['files' => [UploadedFile::fake()->create('b.jpg', 600, 'image/jpeg')]], ['Accept' => 'application/json']);

        $odpoved->assertStatus(413);
        $this->assertSame(1, GuestUpload::where('shared_link_id', $odkaz->id)->count(),
            'Druhá nahrávka přetekla strop odkazu a stejně se uložila.');
    }

    /** Co dvojice schválila nebo odmítla, do stropu nepočítá. */
    public function test_do_stropu_se_pocita_jen_cekajici(): void
    {
        config(['gallery.guest_upload_pending_mb' => 1]);
        $odkaz = $this->odkaz();
        $this->nahraj($odkaz, 900 * 1024)->update(['status' => 'rejected']);

        $this->post("/s/{$odkaz->token}/upload", ['files' => [UploadedFile::fake()->create('c.jpg', 600, 'image/jpeg')]], ['Accept' => 'application/json'])
            ->assertCreated();
    }

    /**
     * Soubory, ke kterým už žádná čekající nahrávka nepatří, úklid smaže.
     *
     * Jsou to pozůstatky odkazů smazaných dřív, než se tohle opravilo. Čekající
     * nahrávka zůstává, stejně jako čerstvý soubor bez řádku — ten se možná
     * právě ukládá.
     */
    public function test_uklid_smaze_osirele_soubory_hostu(): void
    {
        $cekajici = $this->nahraj($this->odkaz());
        $sirotek = 'guest_uploads/'.Str::uuid().'/stara.jpg';
        $cerstvy = 'guest_uploads/'.Str::uuid().'/prave.jpg';
        Storage::disk('local')->put($sirotek, 'x');
        Storage::disk('local')->put($cerstvy, 'x');
        touch(Storage::disk('local')->path($sirotek), now()->subDays(3)->getTimestamp());
        touch(Storage::disk('local')->path($cekajici->storage_path), now()->subDays(30)->getTimestamp());

        $this->artisan('gallery:clean-temp')->assertExitCode(0);

        Storage::disk('local')->assertMissing($sirotek);
        Storage::disk('local')->assertExists($cekajici->storage_path);
        Storage::disk('local')->assertExists($cerstvy);
    }

    // ——— pomocné ———

    private function odkaz(): SharedLink
    {
        return SharedLink::create([
            'created_by' => $this->adri->id,
            'gallery_space_id' => $this->prostor->id,
            'target_type' => 'selection',
            'allow_guest_upload' => true,
            'is_active' => true,
        ]);
    }

    private function nahraj(SharedLink $odkaz, int $bajtu = 1000): GuestUpload
    {
        $uuid = (string) Str::uuid();
        $cesta = "guest_uploads/{$uuid}/vylet.jpg";
        Storage::disk('local')->put($cesta, 'obsah');

        return GuestUpload::create([
            'uuid' => $uuid,
            'shared_link_id' => $odkaz->id,
            'original_filename' => 'vylet.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => $bajtu,
            'storage_path' => $cesta,
        ]);
    }
}
