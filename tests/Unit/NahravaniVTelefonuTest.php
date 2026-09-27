<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Nahrávání z telefonu v prototypu — i celé album z Androidu.
 *
 * „Nefunguje nahrávání z telefonu": po výběru se ukázala jedna hláška a pak
 * nic. Každá chyba serveru (413 od nginxu, 500 ze skládání pod
 * `open_basedir`, 507 bez práva zápisu) skončila v jediné větě bez důvodu,
 * a to až po minutách. Teď list „Nahrát do galerie" ukáže souhrn, nabídne
 * „Uložit jako album", během nahrávání n z N a nahrané jméno, na konci
 * seznam nenahraných se „Zkusit znovu". Pruh nad lištou nese postup,
 * i když je list zavřený.
 *
 * Chování datové vrstvy (části, opakování, 413, 507, album) prochází
 * `tests/js/nahravani-telefonu.cjs` nad skutečným `public/galerie-api.js`.
 */
class NahravaniVTelefonuTest extends TestCase
{
    private const METODY = [
        'pickMedia(fotak, slozka) {', 'nahrUmiSlozku() {', 'nahrJeMedium(f) {', 'nahrSlozka(files) {',
        'nahrNazevVychozi() {', 'nahrUprav(zmena) {', 'nahrPriprav(files, slozka, hned) {', 'async nahrSpust() {',
        'async nahrDavka(indexy, album) {', 'nahrPolozka(i, stav, zprava) {', 'nahrPrubeh(i, odeslano) {',
        'nahrNechRozsviceno(ano) {', 'nahrZahod() {', 'nahrVals() {',
    ];

    private static function soubor(string $cesta): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$cesta);
    }

    private static function telefon(): string
    {
        return self::soubor('resources/galerie/galerie-mobil.dc.html');
    }

    /** Tělo metody třídy `Component` od hlavičky po další člen na dvou mezerách. */
    private static function metoda(string $dokument, string $hlavicka): string
    {
        $zacatek = strpos($dokument, "\n  ".$hlavicka);
        self::assertNotFalse($zacatek, 'Metoda '.$hlavicka.' v dokumentu není.');

        preg_match('/\n  (?:async )?[A-Za-z_$][\w$]*\s*(?:\(|=)/', $dokument, $dalsi, PREG_OFFSET_CAPTURE, $zacatek + 3);

        return substr($dokument, $zacatek, ($dalsi[0][1] ?? strlen($dokument)) - $zacatek);
    }

    public function test_metody_nahravani_jsou_v_telefonu_prave_jednou(): void
    {
        $telefon = self::telefon();

        foreach (self::METODY as $hlavicka) {
            $this->assertSame(1, substr_count($telefon, "\n  ".$hlavicka), $hlavicka);
        }

        // Stará verze bez složky nesmí zůstat vedle nové.
        $this->assertSame(0, substr_count($telefon, "\n  pickMedia(fotak) {"));
    }

    public function test_vyber_bere_vic_souboru_i_slozku(): void
    {
        $vyber = self::metoda(self::telefon(), 'pickMedia(fotak, slozka) {');

        $this->assertStringContainsString('inp.multiple = !fotak;', $vyber);
        $this->assertStringContainsString("inp.accept = fotak ? 'image/*' : 'image/*,video/*';", $vyber);
        $this->assertStringContainsString("inp.setAttribute('webkitdirectory', '')", $vyber);
        $this->assertStringContainsString("inp.setAttribute('capture', 'environment')", $vyber);
        // Obsluha přes `addEventListener`, ne atribut — politika obsahu inline obsluhy nepouští.
        $this->assertStringContainsString("inp.addEventListener('change',", $vyber);
        $this->assertStringContainsString('this.nahrPriprav(files, slozka ? this.nahrSlozka(files) : null, !!fotak);', $vyber);

        $this->assertStringContainsString("'webkitdirectory' in document.createElement('input')", self::metoda(self::telefon(), 'nahrUmiSlozku() {'));
        $this->assertStringContainsString('webkitRelativePath', self::metoda(self::telefon(), 'nahrSlozka(files) {'));
        $this->assertStringContainsString("'Album ' + d.getDate()", self::metoda(self::telefon(), 'nahrNazevVychozi() {'));
        $this->assertStringContainsString('heic|heif', self::metoda(self::telefon(), 'nahrJeMedium(f) {'));
    }

    public function test_nabidka_nahrat_ma_slozku_jako_album(): void
    {
        $telefon = self::telefon();

        $this->assertStringContainsString("label: 'Celá složka jako album'", $telefon);
        $this->assertStringContainsString('go: () => this.pickMedia(false, true) } : null,', $telefon);
        $this->assertStringContainsString("label: 'Z galerie telefonu', icon: 'ph-images', note: 'vyberte klidně stovky fotek a videí — můžete je uložit jako album', go: () => this.pickMedia() },", $telefon);
    }

    /** Album se zakládá přes `/api/alba` a každý soubor se do něj zařadí na serveru (`album` v nahrajVse). */
    public function test_album_se_zalozi_a_soubory_jdou_rovnou_do_nej(): void
    {
        $telefon = self::telefon();

        $spust = self::metoda($telefon, 'async nahrSpust() {');
        $this->assertStringContainsString("api.post('alba', { nazev: nazev.slice(0, 160) })", $spust);
        $this->assertStringContainsString('window.GalerieObsahNavlec(res.data, res.prazdne)', $spust);
        $this->assertStringContainsString("' — nic se zatím nenahrálo.'", $spust, 'Bez alba se nenahrává nic — fotky by skončily mimo album.');

        $davka = self::metoda($telefon, 'async nahrDavka(indexy, album) {');
        $this->assertStringContainsString('{ album: album ? album.uuid : null, soubezne: 2, onCast: (k, odeslano) => this.nahrPrubeh(indexy[k], odeslano) }', $davka);
        $this->assertStringContainsString('(k, stav, zprava) => this.nahrPolozka(indexy[k], stav, zprava)', $davka);
        $this->assertStringContainsString('this.nahrNechRozsviceno(true);', $davka);
        $this->assertStringContainsString("window.GalerieObnovit('knihovna')", $davka);

        $this->assertStringContainsString("navigator.wakeLock.request('screen')", self::metoda($telefon, 'nahrNechRozsviceno(ano) {'));
    }

    /** Stav nahrávání patří telefonu — do společného stavu dvojice nesmí. */
    public function test_stav_nahravani_se_neuklada_do_spolecneho_stavu(): void
    {
        $telefon = self::telefon();
        $klice = self::metoda($telefon, 'persistKey(k) {');

        $this->assertDoesNotMatchRegularExpression('/[\' ]nahr[\' ]/', $klice);
        $this->assertStringNotContainsString('nahrAlbum', self::metoda($telefon, 'zarizeniKlice() {'));
        // Soubory (`File`) nikdy do stavu — jen do pole instance.
        $this->assertStringContainsString('this._nahrSoubory = media;', self::metoda($telefon, 'nahrPriprav(files, slozka, hned) {'));
        $this->assertStringContainsString('this.setState({ nahr: Object.assign({}, this.state.nahr || {}, zmena) });', self::metoda($telefon, 'nahrUprav(zmena) {'));
    }

    /** Hláška je zápis stavu — jen `silent`, a žádná za jednotlivý soubor. */
    public function test_hlasky_nahravani_jsou_tiche_a_ne_v_cyklu(): void
    {
        $telefon = self::telefon();

        foreach (self::METODY as $hlavicka) {
            preg_match_all('/this\.toast\([^;]*;/', self::metoda($telefon, $hlavicka), $hlasky);

            foreach ($hlasky[0] as $hlaska) {
                $this->assertStringContainsString('silent: true', $hlaska, $hlavicka);
            }
        }

        foreach (['nahrPolozka(i, stav, zprava) {', 'nahrPrubeh(i, odeslano) {'] as $hlavicka) {
            $this->assertStringNotContainsString('this.toast(', self::metoda($telefon, $hlavicka), $hlavicka);
        }
        $this->assertSame(1, substr_count(self::metoda($telefon, 'async nahrDavka(indexy, album) {'), 'this.toast('));
        // Postup po částech překresluje nejvýš dvakrát za vteřinu.
        $this->assertStringContainsString('< 500) return;', self::metoda($telefon, 'nahrPrubeh(i, odeslano) {'));
    }

    public function test_list_ukazuje_postup_chyby_a_zkusit_znovu(): void
    {
        $telefon = self::telefon();

        $this->assertSame(1, substr_count($telefon, '<sc-if value="{{ sheetIsNahr }}">'));
        $this->assertStringContainsString("sheetIsNahr: s.sheet === 'nahrAlbum', nahr: this.nahrVals(),", $telefon);
        $this->assertStringContainsString("s.sheet === 'nahrAlbum' ? 'Nahrát do galerie'", $telefon);

        foreach ([
            'onClick="{{ nahr.prepniAlbum }}" role="switch" aria-checked="{{ nahr.albumStav }}"',
            'value="{{ nahr.nazev }}" onInput="{{ nahr.nastavNazev }}" aria-label="Název alba"',
            'onClick="{{ nahr.spust }}"',
            '<div role="status" aria-live="polite" style="font-size:15px">{{ nahr.stavText }}</div>',
            'onClick="{{ nahr.prepniPauzu }}"',
            '<sc-for list="{{ nahr.selhane }}" as="f"',
            'onClick="{{ nahr.znovu }}"',
            'onClick="{{ nahr.otevritAlbum }}"',
            '<sc-if value="{{ nahr.pruhOn }}">',
            'onClick="{{ nahr.otevri }}"',
        ] as $kus) {
            $this->assertSame(1, substr_count($telefon, $kus), $kus);
        }

        $vals = self::metoda($telefon, 'nahrVals() {');
        // Každá obsluha i hodnota, kterou šablona čte, v `nahrVals` je.
        preg_match_all('/\{\{ nahr\.(\w+) \}\}/', $telefon, $pouzite);
        foreach (array_unique($pouzite[1]) as $klic) {
            $this->assertMatchesRegularExpression('/\n      '.$klic.':/', $vals, 'nahrVals() nevrací '.$klic);
        }
    }

    public function test_datova_vrstva_posila_vse_po_castech(): void
    {
        $api = self::soubor('public/galerie-api.js');

        $this->assertSame(1, substr_count($api, '    upload: function (file, meta, naPrubeh) {'));
        $this->assertSame(1, substr_count($api, '    nahrajVse: function (files, onProgress, onItem, volby) {'));
        $this->assertSame(1, substr_count($api, '    limityNahravani: function () {'));
        $this->assertStringContainsString("h['X-Album'] = meta.album;", $api);
        $this->assertStringContainsString("h['X-Taken-At'] = String(Math.round(meta.taken_at));", $api);
        $this->assertStringContainsString("fetch(base + '/media/chunk'", $api);
        $this->assertStringNotContainsString("fetch(base + '/media', { method: 'POST'", $api, 'Formulář naráží na upload_max_filesize — i malé fotky jdou po částech.');

        // Worker nahrávání nechytá: POST mimo /api/state jde rovnou na síť.
        $sw = self::soubor('resources/galerie/sw.js');
        $this->assertStringContainsString("if (req.method === 'PATCH' && url.pathname.indexOf('/api/state') >= 0) {", $sw);
        $this->assertStringContainsString("if (req.method !== 'GET') return;", $sw);
    }

    /** Průchod nad skutečným `galerie-api.js` s falešným serverem (413, výpadek, 507, album). */
    public function test_pruchod_nahravanim_v_node(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node.js není na PATH — průchod nahráváním se nedá pustit.');
        }

        $proces = new Process([$node, dirname(__DIR__).'/js/nahravani-telefonu.cjs']);
        $proces->setTimeout(120);
        $proces->run();

        $this->assertTrue($proces->isSuccessful(), $proces->getOutput()."\n".$proces->getErrorOutput());
        $this->assertStringContainsString('scénářů prošlo', $proces->getOutput());
    }
}
