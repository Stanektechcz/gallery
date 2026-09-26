<?php

namespace Tests\Feature\Galerie;

use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Trezor: zámek, který doopravdy zamyká.
 *
 * Obrazovka porovnávala zadané heslo s konstantou `VAULT_PWD` z veřejného
 * souboru `galerie-data.js` — a sama ho pod kolonkou vypisovala. Druhé
 * ověření se kontrolovalo jen na šest číslic. Za tou zdí pak byly čtyři
 * napsané složky („Doklady · 12 souborů · šifrováno"), ne obsah dvojice.
 *
 * Tyhle testy hlídají obojí: že heslo ověřuje server proti `users.password`,
 * a že zamčený trezor neposílá, co je v něm.
 */
class TrezorTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian', 'password' => Hash::make('spravne-heslo')]);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    private function schovanaFotka(string $soubor = 'pas.jpg'): MediaItem
    {
        return MediaItem::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => $soubor,
            'safe_filename' => 'pas.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1000,
            'uploaded_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
            'is_hidden' => true,
        ]);
    }

    /** Trezor začíná zamčený. */
    public function test_trezor_zacina_zamceny(): void
    {
        $this->getJson('/api/trezor')->assertOk()->assertJson(['odemceno' => false, 'zbyva' => 0]);
    }

    /** Špatné heslo neodemkne a zapíše se do auditu. */
    public function test_spatne_heslo_neodemkne(): void
    {
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'zadar2026'])
            ->assertStatus(422)
            ->assertJson(['odemceno' => false]);

        $this->assertSame(1, DB::table('audit_logs')->where('action', 'vault.unlock_failed')->count());
        $this->getJson('/api/trezor')->assertOk()->assertJson(['odemceno' => false]);
    }

    /**
     * Ve veřejném souboru žádné heslo není — ani v komentáři.
     *
     * Dřív tu stálo `VAULT_PWD = '…'` a bylo to **jediné** heslo, které
     * trezor otevřelo. Test tehdy tu hodnotu ze souboru vytáhl a ověřil,
     * že už neplatí.
     *
     * Jenže když se konstanta vyprázdnila, hodnota zůstala o pár řádků výš
     * **ve vysvětlujícím komentáři** — a tenhle test ji tam pořád našel,
     * takže procházel dál a zveřejnění nikdo nezachytil. Soubor přitom
     * server podá komukoli na `GET /galerie-data.js`; komentář s heslem
     * je stejné zveřejnění jako konstanta.
     *
     * Hlídá se proto opak: v souboru nesmí být heslo nikde.
     */
    public function test_verejny_soubor_neobsahuje_heslo(): void
    {
        $verejne = file_get_contents(public_path('galerie-data.js'));

        $this->assertDoesNotMatchRegularExpression(
            "/VAULT_PWD\s*=\s*'[^']+'/",
            $verejne,
            'Heslo trezoru je zpátky ve veřejném souboru.',
        );

        // A totéž pro heslo zámku, obnovovací kód a PIN.
        $this->assertDoesNotMatchRegularExpression("/LOCKPWD\s*=\s*'[^']+'/", $verejne, 'Heslo zámku je ve veřejném souboru.');
        $this->assertDoesNotMatchRegularExpression("/LOCKREC\s*=\s*'[^']+'/", $verejne, 'Obnovovací kód je ve veřejném souboru.');
        $this->assertDoesNotMatchRegularExpression("/LOCKPIN\s*=\s*\{\s*[A-Z]\s*:/", $verejne, 'PIN zámku je ve veřejném souboru.');

        // Vymyšlené heslo trezor neotevře — odemyká jen heslo do galerie.
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'heslo-ktere-neexistuje'])->assertStatus(422);
        $this->getJson('/api/trezor')->assertOk()->assertJson(['odemceno' => false]);
    }

    /** Heslo do galerie odemkne na patnáct minut. */
    public function test_spravne_heslo_odemkne(): void
    {
        $telo = $this->postJson('/api/trezor/odemknout', ['heslo' => 'spravne-heslo'])
            ->assertOk()->assertJson(['odemceno' => true])->json();

        $this->assertGreaterThan(880, $telo['zbyva']);
        $this->assertLessThanOrEqual(900, $telo['zbyva']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'vault.unlock')->count());
    }

    /** Tři chyby po sobě přístup na půl minuty uzavřou. */
    public function test_tri_pokusy_uzavrou_pristup(): void
    {
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'a'])->assertStatus(422);
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'b'])->assertStatus(422);
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'c'])->assertStatus(429);

        // A správné heslo v té chvíli taky ne — jinak by uzavření nic neznamenalo.
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'spravne-heslo'])->assertStatus(429);
    }

    /**
     * Nové sezení pokusy nevrátí.
     *
     * Počítadlo bylo v sezení, takže stačilo smazat cookies a hádat dál.
     */
    public function test_nove_sezeni_pokusy_nevrati(): void
    {
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'a'])->assertStatus(422);
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'b'])->assertStatus(422);

        $this->flushSession();

        $this->postJson('/api/trezor/odemknout', ['heslo' => 'c'])
            ->assertStatus(429)
            ->assertJsonPath('blok', 30);

        $this->flushSession();

        $this->postJson('/api/trezor/odemknout', ['heslo' => 'spravne-heslo'])->assertStatus(429);
    }

    /** Kdo po uzavření hádá dál, čeká pokaždé dvakrát déle. */
    public function test_opakovane_uzavreni_se_prodluzuje(): void
    {
        foreach (['a', 'b', 'c'] as $heslo) {
            $this->postJson('/api/trezor/odemknout', ['heslo' => $heslo]);
        }

        $this->travel(31)->seconds();

        $this->postJson('/api/trezor/odemknout', ['heslo' => 'd'])->assertStatus(422);
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'e'])->assertStatus(422);
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'f'])
            ->assertStatus(429)
            ->assertJsonPath('blok', 60)
            ->assertJsonPath('chyba', 'Tři neúspěšné pokusy. Přístup je na minutu uzavřený a záznam šel do auditu.');

        // Správné heslo po uplynutí odemkne a počítadla vynuluje.
        $this->travel(61)->seconds();
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'spravne-heslo'])->assertOk();
        $this->postJson('/api/trezor/zamknout')->assertOk();

        foreach (['g', 'h'] as $heslo) {
            $this->postJson('/api/trezor/odemknout', ['heslo' => $heslo])->assertStatus(422);
        }
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'i'])->assertJsonPath('blok', 30);
    }

    /**
     * Zámek trezoru hlídá fotku, ne „první" prostor účtu.
     *
     * Skrytá fotka v druhém prostoru téhož účtu se dřív vydala bez odemčení,
     * protože se hledala jen v prostoru, který databáze vrátila jako první.
     */
    public function test_zamek_plati_i_pro_fotku_z_druheho_prostoru(): void
    {
        $rodina = GallerySpace::create(['name' => 'Rodinné album', 'owner_id' => $this->adri->id]);
        $rodina->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        $fotka = $this->schovanaFotka('rodny-list.jpg');
        $fotka->update(['gallery_space_id' => $rodina->id]);

        $this->getJson('/api/v1/media/'.$fotka->uuid.'/comments')->assertStatus(423);
    }

    /**
     * Přesun do trezoru a z něj nepíše jméno souboru do protokolu.
     *
     * Přehled „Dnes" jména z protokolu vypisuje i se zamčeným trezorem, takže
     * `vault.add` s názvem souboru prozradil, co se právě schovalo. Předmět
     * (id položky) zůstává, jako u trvalého smazání.
     */
    public function test_presun_do_trezoru_nezapise_jmeno_do_protokolu(): void
    {
        $fotka = $this->schovanaFotka('rodny-list.jpg');
        $fotka->update(['is_hidden' => false]);

        $this->actingAs($this->adri)
            ->postJson('/vault/media/'.$fotka->uuid.'/toggle')
            ->assertOk()
            ->assertJsonPath('is_hidden', true);

        $this->actingAs($this->adri)
            ->withSession($this->odemcenyTrezor($this->adri))
            ->postJson('/vault/media/'.$fotka->uuid.'/toggle')
            ->assertOk()
            ->assertJsonPath('is_hidden', false);

        $zaznamy = AuditLog::whereIn('action', ['vault.add', 'vault.remove'])->orderBy('id')->get();
        $this->assertSame(['vault.add', 'vault.remove'], $zaznamy->pluck('action')->all());

        foreach ($zaznamy as $zaznam) {
            $this->assertSame($fotka->id, (int) $zaznam->subject_id);
            $this->assertArrayNotHasKey('filename', (array) $zaznam->payload);
        }
    }

    /**
     * Starší záznamy přesunu do trezoru přijdou o jméno souboru.
     *
     * Nové už ho nenesou; migrace dočistí ty dřívější. Ostatní klíče i záznamy
     * jiných akcí zůstávají, jak byly.
     */
    public function test_starsi_zaznamy_prijdou_o_jmena_z_trezoru(): void
    {
        $zapis = fn (string $akce, ?array $data) => DB::table('audit_logs')->insertGetId([
            'action' => $akce,
            'subject_type' => MediaItem::class,
            'subject_id' => 1,
            'payload' => $data === null ? null : json_encode($data),
            'created_at' => now(),
        ]);

        $pridani = $zapis('vault.add', ['filename' => 'rodny-list.jpg']);
        $odebrani = $zapis('vault.remove', ['filename' => 'pas.jpg', 'via' => 'test']);
        $nahrani = $zapis('media.upload', ['filename' => 'vylet.jpg']);
        $prazdny = $zapis('vault.add', null);

        (require database_path('migrations/2026_09_29_120000_trezor_bez_jmen_v_protokolu.php'))->up();

        $payload = fn (int $id) => DB::table('audit_logs')->where('id', $id)->value('payload');

        $this->assertNull($payload($pridani));
        $this->assertSame(['via' => 'test'], json_decode((string) $payload($odebrani), true));
        $this->assertSame(['filename' => 'vylet.jpg'], json_decode((string) $payload($nahrani), true));
        $this->assertNull($payload($prazdny));
    }

    /** Uzavření z galerie platí i ve starém rozhraní — jinak by se obešlo tudy. */
    public function test_uzavreni_plati_i_ve_starem_rozhrani(): void
    {
        foreach (['a', 'b', 'c'] as $heslo) {
            $this->postJson('/api/trezor/odemknout', ['heslo' => $heslo]);
        }

        $this->actingAs($this->adri)
            ->from('/vault')
            ->post('/vault/unlock', ['password' => 'spravne-heslo'])
            ->assertSessionHasErrors('password');

        $this->assertFalse(session()->has('vault_unlocked_until'));
        $this->assertFalse(session()->has('vault_unlocked_by'));
    }

    /** Zamčený trezor neposílá, co je v něm. */
    public function test_zamceny_trezor_neposila_obsah(): void
    {
        $this->schovanaFotka();

        $data = $this->getJson('/api/data/system')->assertOk()->json('data');

        $this->assertSame([], $data['VAULT_ITEMS']);
        $this->assertSame([], $data['AL']['vault']);
        $this->assertFalse($data['TREZOR']['odemceno']);
    }

    /**
     * A neposílá ho **žádná** skupina.
     *
     * Trezor dodával i poskytovatel `sdileni`, a ten se o zámek nestaral:
     * obsah tak byl v prohlížeči na každé načtení stránky, dřív než si
     * obrazovka řekla o heslo. Zámek, který se obejde otevřením konzole,
     * není zámek — a dva poskytovatelé téže kolekce se navíc přetahovali
     * o to, který dorazí později.
     */
    public function test_zadna_skupina_neposila_trezor_pri_zamceni(): void
    {
        $this->schovanaFotka('pas.jpg');

        $skupiny = ['system', 'sdileni', 'knihovna', 'uklid'];

        foreach ($skupiny as $skupina) {
            $data = (array) $this->getJson('/api/data/'.$skupina)->assertOk()->json('data');

            $this->assertSame([], $data['VAULT_ITEMS'] ?? [], "Skupina {$skupina} posílá obsah zamčeného trezoru.");
            $this->assertStringNotContainsString('pas.jpg', json_encode($data), "Skupina {$skupina} prozrazuje, co je v trezoru.");
        }
    }

    /** Odemčený trezor pošle skutečný obsah — a bez podepsaných náhledů. */
    public function test_odemceny_trezor_posle_obsah(): void
    {
        $fotka = $this->schovanaFotka();

        $this->postJson('/api/trezor/odemknout', ['heslo' => 'spravne-heslo'])->assertOk();

        $data = $this->getJson('/api/data/system')->assertOk()->json('data');

        $this->assertTrue($data['TREZOR']['odemceno']);
        $this->assertCount(1, $data['VAULT_ITEMS']);
        $this->assertSame($fotka->uuid, $data['VAULT_ITEMS'][0]['id']);
        $this->assertSame('pas.jpg', $data['VAULT_ITEMS'][0]['name']);

        // Náhled se u trezoru záměrně neposílá: podepsaná adresa funguje bez
        // přihlášení a přežila by i zamčení.
        $this->assertArrayNotHasKey('bg', $data['VAULT_ITEMS'][0]);
        $this->assertStringNotContainsString('signature', json_encode($data['VAULT_ITEMS']));

        $this->assertSame(['Bez alba'], array_column($data['AL']['vault'], 0));
    }

    /**
     * Seznam se dělí po albech, ne podle data.
     *
     * Album se chvíli četlo zpátky z popisku (`'Bez alba · 27. 8. 2026 · …'`),
     * takže položce bez alba, ale s datem, se jako album napsalo „27. 8. 2026"
     * — a každý den focení byl vlastní složka trezoru.
     */
    public function test_seznam_se_deli_po_albech_ne_podle_data(): void
    {
        $this->schovanaFotka()->update(['taken_at' => now()->subDays(3)]);
        $this->schovanaFotka('op.jpg')->update(['taken_at' => now()->subYear()]);

        $this->postJson('/api/trezor/odemknout', ['heslo' => 'spravne-heslo'])->assertOk();

        $seznam = $this->getJson('/api/data/system')->assertOk()->json('data.AL.vault');

        $this->assertSame([['Bez alba', '2 položky · schované před mřížkou i sdílením', 'trezor']], $seznam);
    }

    /** Zamknutí platí hned. */
    public function test_zamknuti_plati_hned(): void
    {
        $this->schovanaFotka();
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'spravne-heslo'])->assertOk();

        $this->postJson('/api/trezor/zamknout')->assertOk()->assertJson(['odemceno' => false, 'zbyva' => 0]);

        $this->assertSame([], $this->getJson('/api/data/system')->assertOk()->json('data.VAULT_ITEMS'));
    }

    /**
     * Trezor jednoho páru není trezor druhého.
     *
     * Odemčení sedí v sezení a obsah se bere z prostoru — kdyby se pletly,
     * bylo by to horší než žádný zámek.
     */
    public function test_cizi_prostor_se_nepridava(): void
    {
        $this->schovanaFotka();

        $cizi = User::factory()->create(['password' => Hash::make('jine-heslo')]);
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $ciziProstor->members()->syncWithoutDetaching([$cizi->id => ['role' => 'owner']]);

        Sanctum::actingAs($cizi);
        $this->postJson('/api/trezor/odemknout', ['heslo' => 'jine-heslo'])->assertOk();

        $this->assertSame([], $this->getJson('/api/data/system')->assertOk()->json('data.VAULT_ITEMS'));
    }
}
