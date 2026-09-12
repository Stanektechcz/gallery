<?php

namespace Tests\Feature\Galerie;

use App\Http\Controllers\Galerie\PrototypController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Doručení prototypu.
 *
 * Dokumenty v `resources/galerie` jsou takové, jaké přišly ze ZIPu — testy proto
 * hlídají hlavně to, co k nim backend přidává při odeslání, a jednu věc, kterou
 * je snadné pokazit tak, že se to nepozná: hlavičku dosahu service workera.
 */
class DoruceniTest extends TestCase
{
    use RefreshDatabase;

    public function test_koren_vraci_prototyp(): void
    {
        $odpoved = $this->get('/')->assertOk();

        $odpoved->assertSee('<x-dc>', false);
        $odpoved->assertSee('support.js', false);
    }

    /** Bez adresy API jede klient v režimu „local" a data zůstanou v prohlížeči. */
    public function test_dokument_nese_adresu_api_a_token(): void
    {
        $odpoved = $this->get('/')->assertOk();

        $odpoved->assertSee("window.GALERIE_API_BASE = '/api'", false);
        $odpoved->assertSee("window.GALERIE_TOKEN_URL = '/sanctum/token'", false);
        $odpoved->assertSee('name="csrf-token"', false);
    }

    /**
     * Dokument říká, kdo se dívá.
     *
     * Hlavička s tím počítala od začátku, ale nikdo jí to nepředával, takže
     * `window.GALERIE_USER` bylo vždycky `null`. Prototyp pak neměl jak poznat,
     * které z těch dvou jmen je to jeho — a u pre-mortemu ukazoval vlastní
     * obavy ve sloupci toho druhého.
     */
    public function test_dokument_rekne_kdo_je_prihlaseny(): void
    {
        $telo = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('window.GALERIE_USER = null', $telo);

        $clovek = User::factory()->create(['name' => 'Makinka']);
        $telo = $this->actingAs($clovek)->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('"name":"Makinka"', $telo);
    }

    /**
     * Druhý musí vidět, co první napsal.
     *
     * Klient stav načte jednou při startu a pak už jen posílá vlastní změny —
     * bez dotazování by se partnerova změna objevila teprve po obnovení stránky
     * a nikdo by nepoznal, že kouká na včerejšek.
     */
    public function test_dokument_se_ptá_na_zmeny_partnera(): void
    {
        $telo = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('api.load()', $telo);
        $this->assertStringContainsString('document.hidden', $telo);
        $this->assertStringContainsString('visibilitychange', $telo);
    }

    /**
     * Hlavička musí být uvnitř `<head>`.
     *
     * `galerie-api.js` se načítá v těle dokumentu a podle `GALERIE_API_BASE`
     * si hned při načtení volí mezi režimem „http" a „local". Kdyby se adresa
     * nastavila později, klient by celou relaci ukládal jen do prohlížeče.
     */
    public function test_hlavicka_je_pred_koncem_head(): void
    {
        $telo = $this->get('/')->assertOk()->getContent();

        $this->assertLessThan(
            stripos($telo, '</head>'),
            strpos($telo, 'GALERIE_API_BASE'),
            'Adresa API se musí nastavit dřív, než se načte galerie-api.js.',
        );
    }

    /**
     * Service worker potřebuje dosah na celý web.
     *
     * Bez hlavičky by ho prohlížeč zaregistroval jen pro adresář, ve kterém leží,
     * neviděl by `/api/` a fronta offline zápisů by tiše nefungovala — aplikace
     * by vypadala v pořádku a zápisy bez signálu by mizely.
     */
    public function test_service_worker_ma_dosah_na_cely_web(): void
    {
        $this->get('/sw.js')
            ->assertOk()
            ->assertHeader('Service-Worker-Allowed', '/')
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
    }

    /**
     * Verze paměti se mění s nasazením.
     *
     * V souboru je napsané `v4` a zůstalo by tam napořád. Skořápka se přitom
     * uklízí podle názvu: co se jmenuje stejně, zůstane v paměti i po nasazení
     * — a prohlížeč pak podával starý balík designového systému, dokud si někdo
     * nevymazal paměť ručně.
     */
    public function test_verze_workera_se_meni_s_nasazenim(): void
    {
        $worker = (string) $this->get('/sw.js')->assertOk()->getContent();

        $this->assertMatchesRegularExpression("/const VERSION = 'v4-[0-9a-f]{10}';/", $worker);

        $puvodni = $this->verze($worker);

        touch(resource_path('galerie/sw.js'));
        clearstatcache();

        $this->assertNotSame($puvodni, $this->verze((string) $this->get('/sw.js')->getContent()));
    }

    /**
     * Změna náhradní obrazovky přejmenuje paměť.
     *
     * Otisk se počítal jen z dokumentů a manifestu sestavení, takže nasazení,
     * které měnilo jen `offline.html`, nechalo název paměti stejný. V prohlížeči
     * pak zůstala kopie z doby, kdy se aplikace jmenovala jinak — a dvojice při
     * každém výpadku spojení četla „Maki čeká na připojení".
     */
    public function test_zmena_nahradni_obrazovky_prejmenuje_pamet(): void
    {
        $puvodni = $this->verze((string) $this->get('/sw.js')->assertOk()->getContent());

        touch(public_path('offline.html'));
        clearstatcache();

        $this->assertNotSame($puvodni, $this->verze((string) $this->get('/sw.js')->getContent()));
    }

    /**
     * Co si worker ukládá do skořápky, musí controller vědět.
     *
     * Otisk paměti se počítá z těch souborů. Kdyby seznam v `sw.js` narostl
     * a v controlleru ne, nová položka by se po nasazení nikdy neobnovila —
     * přesně tak v paměti přežila náhradní obrazovka se starým jménem.
     */
    public function test_seznam_skorapky_sedi_s_workerem(): void
    {
        $worker = File::get(resource_path('galerie/sw.js'));

        preg_match('/const SHELL_FILES = \[(.*?)\];/s', $worker, $c);
        preg_match_all("/'([^']+)'/", $c[1] ?? '', $souboryVeWorkeru);

        $controller = new \ReflectionClass(PrototypController::class);

        $this->assertSame(
            $souboryVeWorkeru[1],
            $controller->getConstant('SKORAPKA'),
            'SHELL_FILES v sw.js a SKORAPKA v PrototypControlleru se rozešly.',
        );
    }

    /**
     * Náhradní obrazovka až po druhém pokusu.
     *
     * Navigace se stahuje s vlastním nastavením (`cache: 'no-store'`), což
     * v některých prohlížečích selže i při zapnutém připojení. Bez druhého,
     * prostého pokusu se dvojice dívala na „bez signálu" a nemohla se dostat
     * dovnitř ani po obnovení stránky.
     */
    public function test_worker_zkusi_navigaci_podruhe(): void
    {
        $worker = (string) $this->get('/sw.js')->assertOk()->getContent();

        $this->assertStringContainsString("await fetch(url.href, { credentials: 'same-origin' })", $worker);
        // A skořápku obnoví při každém probuzení, ne jen při přejmenování paměti.
        $this->assertStringContainsString('SHELL_FILES.map(f => shell.add(', $worker);
    }

    /**
     * Na telefonu aplikace, ne vitrína prototypu.
     *
     * Telefonní dokument je z návrhářského balíku a je postavený jako ukázka:
     * nadpis, přepínač velikostí displeje a uprostřed nakreslený telefon,
     * ve kterém teprve běží aplikace. Na skutečném telefonu z toho byl telefon
     * v telefonu, s cizím časem 9:41 nahoře. Vitrína zůstává, ale rozhoduje
     * o ní šířka okna — a strop, který ji drží, musí v dokumentu zůstat.
     */
    public function test_telefonni_dokument_ma_vitrinu_podminenou(): void
    {
        $telo = (string) $this->get('/rozvrzeni-telefon')->assertOk()->getContent();

        $this->assertStringContainsString('<sc-if value="{{ vitrina }}">', $telo);
        $this->assertStringContainsString('{{ ramecekStyl }}', $telo);

        // Napevno napsaný čas patřil k nakreslenému telefonu.
        $this->assertStringNotContainsString('<span>9:41</span>', $telo);
    }

    /**
     * Přihlašovací obrazovka ví, jestli už je do čeho se přihlásit.
     *
     * Data o dvojici chodí až za přihlášením, takže nepřihlášený návštěvník
     * viděl „První spuštění" i u galerie, která běží roky. Posílá se jen
     * ano/ne — kolik účtů je a jak se jmenují, nepřihlášený vědět nemá.
     */
    public function test_dokument_rekne_jestli_existuji_ucty(): void
    {
        $this->assertStringContainsString(
            'window.GALERIE_UCTY_EXISTUJI = false',
            (string) $this->get('/')->assertOk()->getContent(),
        );

        $ucet = User::factory()->create(['name' => 'Markéta Kubíčková', 'email' => 'marketa@vzpominky.test']);
        $telo = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('window.GALERIE_UCTY_EXISTUJI = true', $telo);
        $this->assertStringNotContainsString($ucet->email, $telo);
        $this->assertStringNotContainsString('Kubíčková', $telo);
    }

    /** A nesmí ho zastínit statický soubor, který by šel kolem PHP. */
    public function test_service_worker_neni_v_public(): void
    {
        $this->assertFalse(
            File::exists(public_path('sw.js')),
            'Statický public/sw.js by web server vydal bez hlavičky Service-Worker-Allowed.',
        );
    }

    /** Dokument nese token proti CSRF — do sdílené cache nepatří. */
    public function test_dokument_se_neuklada_do_sdilene_cache(): void
    {
        $hlavicka = $this->get('/')->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $hlavicka);
        $this->assertStringNotContainsString('public', $hlavicka);
    }

    /**
     * Dokument má dva megabajty a jde po drátě při každém načtení.
     *
     * Balí se v aplikaci, ne až ve webovém serveru — prototyp má běžet stejně
     * rychle i tam, kde je před Laravelem server bez zapnuté komprese.
     */
    public function test_dokument_chodi_zabaleny(): void
    {
        $odpoved = $this->withHeader('Accept-Encoding', 'gzip, deflate')->get('/')->assertOk();

        $this->assertSame('gzip', $odpoved->headers->get('Content-Encoding'));
        $this->assertStringContainsString('Accept-Encoding', (string) $odpoved->headers->get('Vary'));

        $rozbalene = gzdecode($odpoved->getContent());

        $this->assertStringContainsString('<x-dc>', $rozbalene);
        $this->assertLessThan(
            strlen($rozbalene) / 4,
            strlen($odpoved->getContent()),
            'Zabalený dokument má být řádově menší, jinak se komprese nekoná.',
        );
    }

    /** Kdo gzip neumí, dostane dokument tak jako tak. */
    public function test_bez_gzipu_prijde_prosty_dokument(): void
    {
        $odpoved = $this->withHeader('Accept-Encoding', 'identity')->get('/')->assertOk();

        $this->assertNull($odpoved->headers->get('Content-Encoding'));
        $this->assertStringContainsString('<x-dc>', $odpoved->getContent());
    }

    public function test_telefon_dostane_telefonni_rozvrzeni(): void
    {
        $telefon = $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148')
            ->get('/')->assertOk()->getContent();

        $pocitac = $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120')
            ->get('/')->assertOk()->getContent();

        $this->assertNotSame(strlen($telefon), strlen($pocitac), 'Telefon a počítač mají dostat jiný dokument.');
    }

    /** Tablet unese širokou verzi — telefonní by na deseti palcích byla úsporná zbytečně. */
    public function test_tablet_dostane_sirokou_verzi(): void
    {
        $tablet = $this->withHeader('User-Agent', 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)')
            ->get('/')->assertOk()->getContent();

        $pocitac = $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120')
            ->get('/')->assertOk()->getContent();

        $this->assertSame(strlen($tablet), strlen($pocitac));
    }

    /**
     * Rozvržení jde vynutit vlastní cestou.
     *
     * Ne parametrem v dotazu: service worker prototypu ukládá dokument pod adresu
     * **bez dotazu** a čte ji s `ignoreSearch`, takže jedno otevření
     * `/?rozvrzeni=telefon` přepsalo uloženou kopii `/` — a prohlížeč pak i na
     * počítači nabízel telefonní verzi, dokud se paměť nevymazala.
     *
     * Cesta je jednosegmentová schválně: dokument si skripty načítá relativně
     * (`galerie-api.js`), takže z `/rozvrzeni/telefon` by mířily do
     * neexistujícího `/rozvrzeni/` a telefonní verze by se nespustila.
     */
    public function test_rozvrzeni_jde_vynutit_vlastni_cestou(): void
    {
        $pocitac = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120';

        $telefonni = $this->withHeader('User-Agent', $pocitac)
            ->get('/rozvrzeni-telefon')->assertOk()->getContent();

        $siroke = $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148')
            ->get('/rozvrzeni-siroke')->assertOk()->getContent();

        $this->assertNotSame(strlen($telefonni), strlen($siroke));
        $this->assertSame(
            strlen($telefonni),
            strlen($this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148')->get('/')->getContent()),
        );
    }

    /** Jiná hodnota než ta dvojice cestu nemá — ať se z ní nestane skládání souborů. */
    public function test_nezname_rozvrzeni_neexistuje(): void
    {
        $this->get('/rozvrzeni-../../etc/passwd')->assertNotFound();
        $this->get('/rozvrzeni-cokoliv')->assertNotFound();
        // Původní cesta se dvěma segmenty rozbíjela relativní adresy skriptů.
        $this->get('/rozvrzeni/telefon')->assertNotFound();
    }

    /** Statické soubory prototypu musí být tam, kam si o ně dokument říká. */
    public function test_soubory_prototypu_jsou_na_svem_miste(): void
    {
        foreach ([
            'support.js', 'image-slot.js', 'galerie-store.js', 'galerie-api.js',
            'galerie-data.js', 'galerie-mechanismy.js', 'galerie-mechanismy-logika.js',
            'galerie-admin.js', 'offline.html',
            '_ds/broadsheet-a4da30e6-ea56-42b3-88f2-00edc07c2f31/styles.css',
            '_ds/broadsheet-a4da30e6-ea56-42b3-88f2-00edc07c2f31/_ds_bundle.js',
            'icons/icon-192.png', 'icons/icon.svg',
        ] as $soubor) {
            $this->assertTrue(File::exists(public_path($soubor)), "V public chybí {$soubor}, na který se dokument odkazuje.");
        }
    }

    /**
     * Dokumenty zůstávají takové, jaké přišly ze ZIPu.
     *
     * Kdyby se do nich napojení zapsalo natvrdo, byla by každá další verze
     * prototypu ruční sloučení. Napojení se proto vkládá až při odeslání.
     */
    public function test_dokumenty_nejsou_upravene(): void
    {
        foreach (['galerie-desktop.dc.html', 'galerie-mobil.dc.html'] as $dokument) {
            $obsah = File::get(resource_path('galerie/'.$dokument));

            // Zmínka v popisku nevadí (mobilní verze o té proměnné píše
            // v nastavení); nesmí tam být její **nastavení**.
            $this->assertStringNotContainsString('window.GALERIE_API_BASE =', $obsah,
                "Do {$dokument} se nesmí zapisovat napojení — vkládá se při odeslání.");
            $this->assertStringNotContainsString('csrf-token', $obsah);
        }
    }

    /** Nasazení bez dokumentů má skončit srozumitelnou hláškou, ne bílou stránkou. */
    public function test_chybejici_dokument_hlasi_503(): void
    {
        config(['galerie.prototyp_path' => storage_path('framework/testing/bez-prototypu')]);

        $this->get('/')->assertStatus(503);
        $this->get('/sw.js')->assertNotFound();
    }

    private function verze(string $worker): string
    {
        preg_match("/const VERSION = '([^']+)';/", $worker, $c);

        return $c[1] ?? '';
    }
}
