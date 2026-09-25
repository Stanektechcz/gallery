<?php

namespace Tests\Feature\Propojeni;

use App\Models\GallerySpace;
use App\Models\StorageConnection;
use App\Models\User;
use App\Services\Storage\WebDavClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Vlastní úložiště (WebDAV) nesmí sloužit jako průzkumník vnitřní sítě.
 *
 * Adresu zadává kterýkoli vlastník galerie — a registrace ho založí každému.
 * Kontrolovalo se jen `https://` na začátku, takže `https://127.0.0.1:9000`,
 * `https://169.254.169.254` nebo `https://10.0.0.5` server poslušně zkoušel,
 * klient následoval přesměrování a hláška („HTTP 404" / úspěch) prozradila,
 * co na té adrese běží. Úlohy zrcadlení pak na tu adresu posílaly fotky.
 *
 * A nepovedené nové připojení smazalo to původní, funkční: přihlašovací údaje
 * se přepsaly dřív, než se ověřily, a po chybě se smazal celý řádek.
 */
class WebDavBezpecnostTest extends TestCase
{
    use RefreshDatabase;

    private const CESTA = '/api/v1/propojeni/token/webdav';

    /** Veřejná adresa jako IP — test tak nezávisí na DNS. */
    private const VEREJNA = 'https://93.184.215.14/dav';

    private User $vlastnik;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vlastnik = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->vlastnik->id, 'is_default' => true]);
        $this->prostor->members()->syncWithoutDetaching([$this->vlastnik->id => ['role' => 'owner']]);
    }

    /** @return array<string, array{string}> */
    public static function neverejneAdresy(): array
    {
        return [
            'smyčka' => ['https://127.0.0.1:9000/dav'],
            'metadata cloudu' => ['https://169.254.169.254/latest/meta-data'],
            'vnitřní síť' => ['https://10.0.0.5/dav'],
            'IPv6 smyčka' => ['https://[::1]/dav'],
            'sdílený rozsah poskytovatele' => ['https://100.64.0.1/dav'],
            'localhost' => ['https://localhost/dav'],
        ];
    }

    #[DataProvider('neverejneAdresy')]
    public function test_neverejna_adresa_se_vubec_nezkousi(string $adresa): void
    {
        Http::fake(['*' => Http::response('', 207)]);
        Sanctum::actingAs($this->vlastnik);

        $this->postJson(self::CESTA, ['token' => $adresa.'|uzivatel|heslo'])->assertStatus(422);

        Http::assertNothingSent();
        $this->assertFalse(StorageConnection::where('provider', 'webdav')->exists());
    }

    /** Přesměrování se nenásleduje a za úspěch se nebere — mohlo by vést dovnitř. */
    public function test_presmerovani_neni_uspech(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/'])]);
        Sanctum::actingAs($this->vlastnik);

        $this->postJson(self::CESTA, ['token' => self::VEREJNA.'|uzivatel|heslo'])->assertStatus(422);

        $this->assertFalse(StorageConnection::where('provider', 'webdav')->exists());
    }

    /** Hláška neprozradí, co přesně server odpověděl. */
    public function test_chyba_serveru_se_hlasi_obecne(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        Sanctum::actingAs($this->vlastnik);

        $odpoved = $this->postJson(self::CESTA, ['token' => self::VEREJNA.'|uzivatel|heslo'])->assertStatus(422);

        $this->assertStringNotContainsString('404', (string) $odpoved->json('message'));
        $this->assertStringNotContainsString('HTTP', (string) $odpoved->json('message'));
    }

    public function test_verejna_adresa_se_pripoji(): void
    {
        Http::fake(['*' => Http::response('', 207)]);
        Sanctum::actingAs($this->vlastnik);

        $this->postJson(self::CESTA, ['token' => self::VEREJNA.'|uzivatel|heslo'])->assertCreated();

        $radek = StorageConnection::where('provider', 'webdav')->firstOrFail();
        $this->assertSame(StorageConnection::STATUS_HEALTHY, $radek->connection_status);
        $this->assertSame(self::VEREJNA, $this->ulozenaAdresa($radek));
    }

    public function test_nepovedene_nove_pripojeni_nesmaze_puvodni(): void
    {
        $puvodni = $this->pripojeni('https://93.184.215.14/stary');
        Http::fake(['*' => Http::response('', 401)]);
        Sanctum::actingAs($this->vlastnik);

        $this->postJson(self::CESTA, ['token' => 'https://93.184.215.14/novy|jiny|spatne'])->assertStatus(422);

        $radek = StorageConnection::find($puvodni->id);
        $this->assertNotNull($radek, 'Funkční připojení zůstává.');
        $this->assertSame('https://93.184.215.14/stary', $this->ulozenaAdresa($radek));
        $this->assertSame(StorageConnection::STATUS_HEALTHY, $radek->connection_status);
    }

    public function test_povedene_nove_pripojeni_nahradi_puvodni(): void
    {
        $puvodni = $this->pripojeni('https://93.184.215.14/stary');
        Http::fake(['*' => Http::response('', 207)]);
        Sanctum::actingAs($this->vlastnik);

        $this->postJson(self::CESTA, ['token' => 'https://93.184.215.14/novy|jiny|dobre'])->assertCreated();

        $this->assertSame(1, StorageConnection::where('provider', 'webdav')->count());
        $this->assertSame('https://93.184.215.14/novy', $this->ulozenaAdresa($puvodni->fresh()));
    }

    /** Uložená adresa se ověřuje i při nahrávání — řádek mohl vzniknout dřív. */
    public function test_nahrani_na_neverejnou_adresu_se_neodesle(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $radek = $this->pripojeni('https://10.0.0.5/dav');

        $vysledek = app(WebDavClient::class)->upload($radek, 'MAKI Gallery/prostor-1/foto.jpg', 'obsah');

        $this->assertFalse($vysledek['ok']);
        Http::assertNothingSent();
    }

    public function test_nahrani_na_verejnou_adresu_projde(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $radek = $this->pripojeni(self::VEREJNA);

        $vysledek = app(WebDavClient::class)->upload($radek, 'MAKI Gallery/prostor-1/foto.jpg', 'obsah');

        $this->assertTrue($vysledek['ok']);
        Http::assertSent(fn ($pozadavek) => $pozadavek->method() === 'PUT'
            && str_starts_with($pozadavek->url(), self::VEREJNA.'/MAKI%20Gallery/'));
    }

    private function pripojeni(string $adresa): StorageConnection
    {
        return StorageConnection::create([
            'provider' => 'webdav',
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->vlastnik->id,
            'account_email' => 'uzivatel',
            'encrypted_access_token' => Crypt::encryptString(json_encode([
                'url' => $adresa, 'user' => 'uzivatel', 'pass' => 'heslo',
            ])),
            'connection_status' => StorageConnection::STATUS_HEALTHY,
        ]);
    }

    private function ulozenaAdresa(StorageConnection $radek): string
    {
        return json_decode(Crypt::decryptString($radek->encrypted_access_token), true)['url'];
    }
}
