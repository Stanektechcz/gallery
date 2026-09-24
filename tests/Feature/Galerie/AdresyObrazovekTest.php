<?php

namespace Tests\Feature\Galerie;

use App\Support\TrasyPrototypu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Každá obrazovka má vlastní adresu.
 *
 * Prototyp jich má padesát šest a všechny běžely na `/`: nedalo se nikam
 * odkázat, nic přidat do oblíbených, obnovení stránky vrátilo dvojici na úvod
 * a tlačítko Zpět zavřelo celou aplikaci.
 */
class AdresyObrazovekTest extends TestCase
{
    use RefreshDatabase;

    public function test_galerie_otevre_uvodni_obrazovku(): void
    {
        $this->get('/galerie')->assertOk()->assertSee('window.GALERIE_TRASA = "home"', false);
    }

    /** Každá trasa ze seznamu musí mít funkční adresu — ne jen ta jedna zkoušená. */
    public function test_kazda_trasa_ma_vlastni_adresu(): void
    {
        foreach (array_keys(TrasyPrototypu::ADRESY) as $trasa) {
            $this->get(TrasyPrototypu::cesta($trasa))
                ->assertOk()
                ->assertSee('window.GALERIE_TRASA = '.json_encode($trasa), false);
        }
    }

    /** Překlep v odkazu se má poznat, ne tiše otevřít úvod. */
    public function test_neznama_adresa_je_404(): void
    {
        $this->get('/galerie/neexistujici-obrazovka')->assertNotFound();
    }

    /**
     * `<base href="/">` musí stát dřív než první relativní odkaz.
     *
     * Dokument si skripty načítá relativně (`./support.js`, `galerie-api.js`).
     * Na adrese `/galerie/alba` by se z nich bez základu staly
     * `/galerie/support.js` a aplikace by se nespustila vůbec.
     */
    public function test_dokument_ma_zaklad_adresy_pred_skripty(): void
    {
        $telo = $this->get('/galerie/alba')->assertOk()->getContent();

        $zaklad = strpos($telo, '<base href="/">');
        $skript = strpos($telo, './support.js');

        $this->assertNotFalse($zaklad, 'Bez `<base>` míří relativní skripty do neexistujícího adresáře.');
        $this->assertNotFalse($skript);
        $this->assertLessThan($skript, $zaklad, '`<base>` po prvním skriptu už je pozdě.');
    }

    /**
     * Kořen zůstává funkční — service worker i manifest na něj míří.
     *
     * Adresa `/` žádnou obrazovku nejmenuje, takže server neurčuje žádnou.
     * Prohlížeč si z ní odvodí úvod a adresu srovná na `/galerie`, aby bylo
     * odkud odkazovat.
     */
    public function test_koren_dal_podava_aplikaci(): void
    {
        $this->get('/')->assertOk()->assertSee('window.GALERIE_TRASA = null', false);
    }

    /** Mapa adres nesmí mít dva názvy pro tutéž obrazovku. */
    public function test_adresy_jsou_jedinecne(): void
    {
        $kousky = array_values(TrasyPrototypu::ADRESY);

        $this->assertSame(count($kousky), count(array_unique($kousky)),
            'Dvě trasy se stejnou adresou znamenají, že jedna z nich nejde otevřít.');
    }

    /** V adrese nemá co dělat diakritika ani velké písmeno. */
    public function test_adresy_jsou_ciste(): void
    {
        foreach (TrasyPrototypu::ADRESY as $trasa => $kousek) {
            if ($kousek === '') {
                continue;
            }

            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $kousek,
                "Trasa `{$trasa}` má adresu, kterou by prohlížeč zakódoval.");
        }
    }

    /** Aplikace musí znát každou trasu z nabídky, jinak na ni nejde odkázat. */
    public function test_vsechny_trasy_z_nabidky_maji_adresu(): void
    {
        $data = (string) file_get_contents(public_path('galerie-data.js'));

        preg_match('/var NAV_GROUPS = \[(.*?)\n    \];/s', $data, $shoda);
        $this->assertNotEmpty($shoda, 'V `galerie-data.js` se nenašla nabídka NAV_GROUPS.');

        preg_match_all("/\['([^']+)', '[^']*', 'ph-/u", $shoda[1], $trasy);

        foreach ($trasy[1] as $trasa) {
            $this->assertArrayHasKey($trasa, TrasyPrototypu::ADRESY,
                "Trasa `{$trasa}` je v nabídce, ale nemá vlastní adresu.");
        }
    }
}
